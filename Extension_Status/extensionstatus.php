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

// How long to keep watching before calling it a failure, in seconds. A reload
// shows up in ~2s, but a reboot has to complete a full boot cycle before the
// handset fetches anything, so this is sized for the slow case - it only
// affects how long a genuine failure takes to report.
$es_verify_window = 150;

// Devices seen registered are remembered here so one that drops off is shown
// as Unregistered rather than silently vanishing. Must be writable by the web
// server and OUTSIDE the document root - it records every extension's public
// IP. Set to '' to disable (rows then vanish on drop-off, as before).
$es_state_file = '/var/lib/asterisk/extensionstatus-devices.json';

// Forget a device that has not been seen for this long.
$es_retain_days = 7;

// Models that carry a hardware brand's name but are actually softphones, so
// they must not inherit that brand's buttons. "Sangoma Talk/1.0.18 (build ...;
// iOS 26.5.2; arm64-neon)" parses to brand Sangoma / model Talk, which would
// otherwise offer the Sangoma desk-phone reboot. Matched case-insensitively on
// the model, and enforced server-side as well as in the UI.
$es_no_action_models = array(
    'Sangoma' => array('Talk', 'Connect'),
);

// NOTIFY actions, keyed by the brand es_device_info() reports. A brand with no
// entry here gets no buttons, which is what softphones (Acrobits, Zoiper,
// MicroSIP, Linphone, Jitsi...) want - they have nothing to check-sync.
//
// Headers are sent inline over AMI, so the page depends on no Asterisk config
// file at all. Add an action by adding an entry below; nothing needs reloading.
// Each set notes the sip_notify_*.conf section it mirrors. Those files escape
// the semicolon as "\;" for their own parser, which is not needed here.
//
// 'verify' says how to confirm the action landed, because the right evidence
// differs by action:
//   'config'   the handset re-reads its provisioning files - seen in the web
//              server access log. Correct for a plain check-sync.
//   'register' the handset stops being reachable and comes back - seen over
//              AMI. Correct for anything that reboots.
//
// Config fetches are a poor signal for a reboot. Every phone re-reads its
// config on boot, and a Yealink also reads it before rebooting - so a reboot
// produces one fetch or two depending on the make, and the first of them says
// only that the NOTIFY arrived, not that the phone actually came back.
$es_notify_actions = array(
    'Yealink' => array(
        // sip_notify_custom.conf: reload-yealink / restart-yealink / default-yealink
        'reload'  => array('label' => 'Reload config', 'confirm' => false, 'danger' => false,
                           'verify' => 'config',
                           'headers' => array('Event' => 'check-sync;reboot=false')),
        'restart' => array('label' => 'Reboot',        'confirm' => true,  'danger' => true,
                           'verify' => 'register',
                           'headers' => array('Event' => 'check-sync;reboot=true')),
        'reset'   => array('label' => 'Factory reset', 'confirm' => true,  'danger' => true,
                           'verify' => 'register',
                           'headers' => array('Content-Type' => 'message/sipfrag',
                                              'Event'        => 'ACTION-URI',
                                              'Content'      => 'key=Reset')),
    ),
    'Snom' => array(
        // sip_notify_additional.conf: snom-check-cfg / snom-reboot-cfg / reboot-snom
        'reload'  => array('label' => 'Reload config', 'confirm' => false, 'danger' => false,
                           'verify' => 'config',
                           'headers' => array('Event' => 'check-sync;reboot=false')),
        'restart' => array('label' => 'Reboot',        'confirm' => true,  'danger' => true,
                           'verify' => 'register',
                           'headers' => array('Event' => 'check-sync;reboot=true')),
    ),
    'Sangoma' => array(
        // sip_notify_additional.conf: sync-noreboot-sangoma / sync-reboot-sangoma
        'reload'  => array('label' => 'Reload config', 'confirm' => false, 'danger' => false,
                           'verify' => 'config',
                           'headers' => array('Event' => 'check-sync;reboot=false')),
        'restart' => array('label' => 'Reboot',        'confirm' => true,  'danger' => true,
                           'verify' => 'register',
                           'headers' => array('Event' => 'check-sync;reboot=true')),
    ),
    'Polycom' => array(
        // sip_notify_additional.conf: polycom-check-cfg
        'reload'  => array('label' => 'Reload config', 'confirm' => false, 'danger' => false,
                           'verify' => 'config',
                           'headers' => array('Event' => 'check-sync')),
    ),
    'Grandstream' => array(
        // sip_notify_additional.conf: grandstream-check-cfg
        'reload'  => array('label' => 'Reload config', 'confirm' => false, 'danger' => false,
                           'verify' => 'config',
                           'headers' => array('Event' => 'check-sync')),
    ),
    'Fanvil' => array(
        // sip_notify_additional.conf: fanvil-check-cfg
        //
        // Deliberately NOT labelled "Reload config". A Fanvil reboots
        // immediately on check-sync rather than reading its config first the
        // way a Yealink does - it re-reads during boot, once it is already
        // down. Either way the phone goes away, so this is a reboot button and
        // is treated as one. Mislabelling it would drop a call without warning.
        'restart' => array('label' => 'Reboot', 'confirm' => true, 'danger' => true,
                           'verify' => 'register',
                           'headers' => array('Event' => 'check-sync')),
    ),
    'Cisco' => array(
        // sip_notify_additional.conf: cisco-check-cfg
        'reload'  => array('label' => 'Reload config', 'confirm' => false, 'danger' => false,
                           'verify' => 'config',
                           'headers' => array('Event' => 'check-sync')),
    ),
    'Algo' => array(
        // sip_notify_additional.conf: algo-check-cfg
        'reload'  => array('label' => 'Reload config', 'confirm' => false, 'danger' => false,
                           'verify' => 'config',
                           'headers' => array('Event' => 'check-sync')),
    ),
    'OBIHAI' => array(
        // sip_notify_additional.conf: obihai-check-cfg
        'reload'  => array('label' => 'Reload config', 'confirm' => false, 'danger' => false,
                           'verify' => 'config',
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
        es_build_targets($astman, $es_no_action_models),
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
    $key   = (string) es_get($_GET, 'key', '');
    $ip    = (string) es_get($_GET, 'ip', '');
    $since = (int) es_get($_GET, 'since', 0);
    if ($since <= 0) {
        es_json(array('ok' => false, 'message' => 'Bad request.'), 400);
    }

    // Built WITH remembered state: a handset that is mid-reboot is not in the
    // live contact list, and its stored User-Agent is where its MAC comes from.
    // Those rows carry registered=false, so the registration answer stays right.
    $live = es_build_rows($astman, $fcore, null, $es_state_file, $es_retain_days, $es_no_action_models);

    $brand = '';
    $model = '';
    $mac   = '';
    $aor   = '';
    $status = '';
    $registered = false;
    foreach ($live as $r) {
        $match = ($key !== '')
            ? (es_device_key($r['aor'], $r['brand'], $r['model']) === $key)
            : ($r['registered'] && $r['uri_ip'] === $ip);
        if (!$match) {
            continue;
        }
        $registered = $r['registered'];
        $status = $r['status'];
        $brand  = $r['brand'];
        $model  = $r['model'];
        $aor    = $r['aor'];
        // Straight from the SIP User-Agent where the make publishes it. Only
        // when it does not does es_verify_fetch() fall back to the access log.
        $mac    = es_mac_from_ua($r['useragent']);
        if ($r['uri_ip'] !== '') {
            $ip = $r['uri_ip'];
        }
        break;
    }

    // Status below is only as fresh as Asterisk's last qualify, which is once a
    // minute by default - long enough for a rebooted handset to be back and
    // answering calls while this still reports it Unreachable. When a reboot is
    // being watched, force a probe so the next poll reflects the phone rather
    // than the schedule. The caller throttles it; the name is the one resolved
    // from the live contact above, never a string from the query.
    if (es_get($_GET, 'probe', '') === '1' && $aor !== '') {
        es_qualify($astman, $aor);
    }

    // The address is needed to search the log. While the handset is down it is
    // not in the live set, so fall back to whatever the caller last knew.
    $fetch = array('seen' => false, 'readable' => true);
    if ($ip !== '') {
        $fetch = es_verify_fetch($es_access_logs, $ip, $brand, $model, $since - 5, $mac);
    }

    // Both facts, every time. They are independent and their order is not
    // fixed: a handset fetches its config during boot, which is BEFORE it can
    // register again, while a Yealink also fetches before it reboots at all.
    //
    // 'reachable', not just 'registered', is what says a handset went away. A
    // rebooting Yealink keeps its contact until the registration expires, so
    // Asterisk still lists it and only flips Status to Unreachable via qualify.
    // A Fanvil drops the contact outright. Watching registration alone sees the
    // Fanvil reboot and misses the Yealink one entirely.
    $out = array(
        'ok'         => true,
        'registered' => $registered,
        'reachable'  => ($registered && strcasecmp($status, 'Reachable') === 0),
        'status'     => $status,
        'seen'       => $fetch['seen'],
        'readable'   => $fetch['readable'],
    );
    if (isset($fetch['at'])) {
        $out['at'] = $fetch['at'];
    }
    es_json($out);
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

// A "Reload config" only means something if the handset has a provisioning
// server to reload from, and the way we confirm one worked is by watching that
// server's access log. So with verification off, assume the phones were
// programmed by hand and drop those actions entirely rather than offering a
// button that may do nothing. Reboot and Factory reset are unaffected - they
// are confirmed by re-registration and need no provisioning server.
//
// Filtering the map itself rather than just the UI means the NOTIFY endpoint
// rejects them too, since it validates against this same list.
if (!$es_verify_enabled) {
    foreach ($es_notify_actions as $es_brand => $es_actions) {
        foreach ($es_actions as $es_id => $es_spec) {
            if (es_get($es_spec, 'verify', 'config') === 'config') {
                unset($es_notify_actions[$es_brand][$es_id]);
            }
        }
        if (empty($es_notify_actions[$es_brand])) {
            unset($es_notify_actions[$es_brand]);
        }
    }
}

$es_rows = es_build_rows($astman, $fcore, null, $es_state_file, $es_retain_days, $es_no_action_models);

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
            // How to confirm it landed. Not a secret - it only selects which
            // evidence the browser watches for.
            'verify'  => es_get($es_spec, 'verify', 'config'),
        );
    }
}

require __DIR__ . '/extensionstatus.view.php';
