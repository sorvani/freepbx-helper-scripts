<?php
/**
 * Extension Status - FreePBX 14/15/16 (Sangoma OS / CentOS 7)
 *
 * Admin-only page listing every PJSIP contact, with per-contact SIP NOTIFY
 * actions for handsets that support them.
 *
 * FreePBX 17 moved to Debian and PHP 8; the port for it lives at
 * https://github.com/sorvani/freepbx17-extension-status
 *
 * Three files:
 *   extensionstatus.php       this file - configuration, access control, routing
 *   extensionstatus.lib.php   AMI access, User-Agent parsing, NOTIFY dispatch
 *   extensionstatus.view.php  markup, styling and browser code
 *
 * Four entry points, all behind the same admin session check:
 *   GET  (no params)      full HTML page, with the row data embedded as JSON
 *   GET  ?action=data     JSON rows only, for the auto-refresh
 *   GET  ?action=verify   did this handset re-fetch its config? (after a NOTIFY)
 *   POST action=notify    sends a SIP NOTIFY over AMI, returns JSON
 */

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

// Auto-refresh interval in seconds, and whether it starts enabled.
$es_refresh_seconds = 30;
$es_refresh_default_on = true;

// Append a dump of the raw AMI response to the page.
$es_showdebug = false;

// How a NOTIFY is addressed:
//   'uri'       target the contact URI - only the handset whose row was clicked
//   'endpoint'  target the endpoint - EVERY device registered to that extension
//
// URI mode is the precise one and the default, but it requires a usable
// default_outbound_endpoint, which older FreePBX systems do not set. Without
// one Asterisk still answers "Success: NOTIFY sent", sends nothing, and logs
// only a warning - so a button appears to work while the phone never moves.
// install.sh checks for this and prompts for an extension when it is missing.
//
// Use 'endpoint' instead to address the extension, which always works but
// reaches every device registered to it.
$es_notify_target = 'uri';

// After a NOTIFY, the page watches these logs for the handset re-fetching its
// provisioning files, which is how a check-sync is confirmed to have landed.
// The web server must be able to read them - see the README. A list, because
// a provisioning vhost on another port can have a CustomLog of its own; add it
// here. Set to array() to turn the verification step off.
$es_access_logs = array(
    '/var/log/httpd/access_log',
);

// How long to keep watching before calling it a failure, in seconds.
$es_verify_window = 30;

// NOTIFY actions, keyed by the brand es_device_info() reports. A brand with no
// entry here gets no buttons, which is what softphones (Acrobits, Zoiper,
// MicroSIP, Linphone, Jitsi...) want - they have nothing to check-sync.
//
// Headers are sent inline over AMI, so the page depends on no Asterisk config
// file at all. Add an action by adding an entry below; nothing needs reloading.
// Each set notes the sip_notify_*.conf section it mirrors. Those files escape
// the semicolon as "\;" for their own parser, which is not needed here.
$es_notify_actions = array(
    'Yealink' => array(
        // sip_notify_custom.conf: reload-yealink / restart-yealink / default-yealink
        'reload'  => array('label' => 'Reload config', 'confirm' => false, 'danger' => false,
                           'headers' => array('Event' => 'check-sync;reboot=false')),
        'restart' => array('label' => 'Reboot',        'confirm' => true,  'danger' => true,
                           'headers' => array('Event' => 'check-sync;reboot=true')),
        'reset'   => array('label' => 'Factory reset', 'confirm' => true,  'danger' => true,
                           'headers' => array('Content-Type' => 'message/sipfrag',
                                              'Event'        => 'ACTION-URI',
                                              'Content'      => 'key=Reset')),
    ),
    'Snom' => array(
        // sip_notify_additional.conf: snom-check-cfg / snom-reboot-cfg / reboot-snom
        'reload'  => array('label' => 'Reload config', 'confirm' => false, 'danger' => false,
                           'headers' => array('Event' => 'check-sync;reboot=false')),
        'restart' => array('label' => 'Reboot',        'confirm' => true,  'danger' => true,
                           'headers' => array('Event' => 'check-sync;reboot=true')),
    ),
    'Sangoma' => array(
        // sip_notify_additional.conf: sync-noreboot-sangoma / sync-reboot-sangoma
        'reload'  => array('label' => 'Reload config', 'confirm' => false, 'danger' => false,
                           'headers' => array('Event' => 'check-sync;reboot=false')),
        'restart' => array('label' => 'Reboot',        'confirm' => true,  'danger' => true,
                           'headers' => array('Event' => 'check-sync;reboot=true')),
    ),
    'Polycom' => array(
        // sip_notify_additional.conf: polycom-check-cfg
        'reload'  => array('label' => 'Reload config', 'confirm' => false, 'danger' => false,
                           'headers' => array('Event' => 'check-sync')),
    ),
    'Grandstream' => array(
        // sip_notify_additional.conf: grandstream-check-cfg
        'reload'  => array('label' => 'Reload config', 'confirm' => false, 'danger' => false,
                           'headers' => array('Event' => 'check-sync')),
    ),
    'Fanvil' => array(
        // sip_notify_additional.conf: fanvil-check-cfg
        'reload'  => array('label' => 'Reload config', 'confirm' => false, 'danger' => false,
                           'headers' => array('Event' => 'check-sync')),
    ),
    'Cisco' => array(
        // sip_notify_additional.conf: cisco-check-cfg
        'reload'  => array('label' => 'Reload config', 'confirm' => false, 'danger' => false,
                           'headers' => array('Event' => 'check-sync')),
    ),
    'Algo' => array(
        // sip_notify_additional.conf: algo-check-cfg
        'reload'  => array('label' => 'Reload config', 'confirm' => false, 'danger' => false,
                           'headers' => array('Event' => 'check-sync')),
    ),
    'OBIHAI' => array(
        // sip_notify_additional.conf: obihai-check-cfg
        'reload'  => array('label' => 'Reload config', 'confirm' => false, 'danger' => false,
                           'headers' => array('Event' => 'sync')),
    ),
);

// The helpers are pure PHP and touch neither the session nor the bootstrap, so
// they load first - es_csrf_token() is needed by the access-control block below
// and has to paper over random_bytes() being absent on PHP 5.6.
require __DIR__ . '/extensionstatus.lib.php';

// ---------------------------------------------------------------------------
// Access control
//
// This session check IS the access control for this page - it exposes every
// extension's public IP, device model and firmware, and it can reboot and
// factory-reset handsets. It must run before the FreePBX bootstrap.
// ---------------------------------------------------------------------------

$es_t = array('start' => microtime(true));

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// session_start() blocks until it can take the per-session lock, so this
// measures contention with any other request on the same session, not just
// the read itself.
$es_t['session'] = microtime(true);
if (empty($_SESSION['AMP_user'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    die('Not logged in! Please log in to your FreePBX dashboard before opening this page...');
}
if (empty($_SESSION['es_csrf'])) {
    $_SESSION['es_csrf'] = es_csrf_token();
}
$es_csrf = $_SESSION['es_csrf'];
$es_amp_user = $_SESSION['AMP_user'];

// Release the session lock before the slow part of the request. PHP serialises
// requests that hold the same session open, so without this a button click
// landing while the auto-refresh is in flight waits for it to finish first.
// Nothing below writes to the session.
session_write_close();
$es_t['unlock'] = microtime(true);

// Load FreePBX bootstrap environment
include '/etc/freepbx.conf';
$fcore = FreePBX::Core();
$es_t['bootstrap'] = microtime(true);

// Load AMI
global $astman;

// Name for the audit log. Must go through the helper: FreePBX stores an
// ampuser OBJECT here, and casting that to string is a fatal error.
$es_user = es_session_username($es_amp_user);

$es_is_json = (es_get($_GET, 'action', '') === 'data')
           || (es_get($_GET, 'action', '') === 'verify')
           || (es_get($_POST, 'action', '') === 'notify');

if (!is_object($astman)) {
    if ($es_is_json) {
        es_json(array('ok' => false, 'message' => 'No connection to the Asterisk Manager Interface.'), 503);
    }
    http_response_code(503);
    die('Could not connect to the Asterisk Manager Interface.');
}

// POST: send a NOTIFY.
if (es_get($_POST, 'action', '') === 'notify') {
    if (!hash_equals($es_csrf, (string) es_get($_POST, 'csrf', ''))) {
        es_json(array('ok' => false, 'message' => 'Session expired - reload the page.'), 403);
    }
    // Validation only needs uri/brand/aor/endpoint, so skip the display-name
    // lookups es_build_rows() would do - this path should stay as short as
    // possible.
    $result = es_send_notify(
        $astman,
        (string) es_get($_POST, 'uri', ''),
        (string) es_get($_POST, 'action_id', ''),
        $es_notify_actions,
        es_build_targets($astman),
        $es_user,
        es_notify_mode($es_notify_target)
    );
    $es_t['dispatch'] = microtime(true);
    $result += es_timings($es_t);
    error_log('extensionstatus: notify timing ' . $result['timing']);
    es_json($result, $result['ok'] ? 200 : 400);
}

// GET ?action=verify: has this handset re-fetched its config since $since?
// Polled by the browser after a NOTIFY; never touched on a normal page load.
if (es_get($_GET, 'action', '') === 'verify') {
    $ip    = (string) es_get($_GET, 'ip', '');
    $since = (int) es_get($_GET, 'since', 0);
    // Only an IP belonging to a currently registered contact may be queried,
    // so this cannot be used to sift the access log generally.
    $known = false;
    $model = '';
    foreach (es_build_rows($astman, $fcore) as $r) {
        if ($r['uri_ip'] === $ip) {
            $known = true;
            $model = $r['model'];
            break;
        }
    }
    if (!$known || $since <= 0) {
        es_json(array('ok' => false, 'message' => 'Unknown contact.'), 400);
    }
    $res = es_verify_fetch($es_access_logs, $ip, $model, $since - 5);
    es_json(array('ok' => true) + $res);
}

// Verification is optional: it needs the web server to be able to read the
// access log, which is a deliberate permission change an operator may decline.
// Declining is a supported configuration, not an error - when it is off the
// page simply never offers the check.
$es_verify_enabled = false;
foreach ($es_access_logs as $es_log) {
    if ($es_log !== '' && is_readable($es_log)) {
        $es_verify_enabled = true;
        break;
    }
}

$es_rows = es_build_rows($astman, $fcore);

// GET ?action=data: rows only, for the auto-refresh.
if (es_get($_GET, 'action', '') === 'data') {
    es_json(array('ok' => true, 'rows' => $es_rows, 'generated' => date('H:i:s')));
}

$es_mode = es_notify_mode($es_notify_target);
$es_json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;

// Strip the NOTIFY headers before handing the action map to the browser: the
// client names an action, the server decides what that means.
$es_actions_public = array();
foreach ($es_notify_actions as $es_brand => $es_actions) {
    foreach ($es_actions as $es_id => $es_spec) {
        $es_actions_public[$es_brand][$es_id] = array(
            'label'   => $es_spec['label'],
            'confirm' => $es_spec['confirm'],
            'danger'  => $es_spec['danger'],
        );
    }
}

require __DIR__ . '/extensionstatus.view.php';
