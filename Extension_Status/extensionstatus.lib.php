<?php
/**
 * Extension Status - AMI access, User-Agent parsing and NOTIFY dispatch.
 *
 * Required by extensionstatus.php, which owns configuration and access
 * control. Nothing here starts a session or checks one - by the time this is
 * loaded the caller has already established that an admin is logged in.
 *
 * Every function is prefixed es_ deliberately: this runs inside the FreePBX
 * bootstrap, which defines plenty of unprefixed globals of its own (out(),
 * for one), and a collision is a fatal redeclare.
 *
 * PHP 5.6 is the floor, and it is a real one - measured on the test boxes,
 * FreePBX 15 on Sangoma OS runs PHP 5.6.40 and FreePBX 16 runs 7.4.16. So:
 * no null-coalescing operator, no random_bytes(), and no str_starts_with()
 * (PHP 8.0, later than either). es_get(), es_csrf_token() and
 * es_starts_with() below stand in for those three.
 */

// ---------------------------------------------------------------------------
// Language shims and small helpers
// ---------------------------------------------------------------------------

/**
 * $arr[$key] if set, else $default. Stands in for ?? on PHP 5.6.
 *
 * isset() semantics, so a null value falls through to the default exactly as
 * ?? would.
 */
function es_get($arr, $key, $default = '') {
    return isset($arr[$key]) ? $arr[$key] : $default;
}

/** str_starts_with(), which is PHP 8.0 - later than either target platform. */
function es_starts_with($haystack, $needle) {
    return substr((string) $haystack, 0, strlen($needle)) === $needle;
}

/**
 * A CSRF nonce.
 *
 * random_bytes() is PHP 7.0, and FreePBX 15 is on 5.6 - so openssl is the
 * fallback, and it is not a hopeful one: FreePBX depends on ext/openssl, and
 * both test boxes report openssl_random_pseudo_bytes() present.
 */
function es_csrf_token() {
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes(32));
    }
    if (function_exists('openssl_random_pseudo_bytes')) {
        $strong = false;
        $bytes = openssl_random_pseudo_bytes(32, $strong);
        if ($bytes !== false && $strong) {
            return bin2hex($bytes);
        }
    }
    // Neither available. Weaker than the above and not something to rely on,
    // but the admin session check is what actually guards this page - the
    // token only stops a third-party site from posting to it.
    return hash('sha256', uniqid(mt_rand(), true) . session_id());
}

/**
 * Force a string to valid UTF-8.
 *
 * The User-Agent arrives from whatever registers, so it can hold any byte at
 * all. json_encode() returns false on invalid UTF-8, and the row data is
 * embedded into a <script> block - one malformed registration would otherwise
 * blank the whole page. iconv is in FreePBX's PHP dependencies and present on
 * both test boxes; mb_* is not guaranteed, so it is not used.
 */
function es_utf8($s) {
    $s = (string) $s;
    if ($s === '' || preg_match('//u', $s)) {
        return $s;
    }
    $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
    return $clean === false ? preg_replace('/[\x80-\xFF]/', '?', $s) : $clean;
}

/**
 * Username to name in the audit log, from $_SESSION['AMP_user'].
 *
 * FreePBX stores an ampuser OBJECT there, not a string - see
 * admin/libraries/gui_auth.php ($_SESSION['AMP_user'] = new ampuser(...)) and
 * admin/config.php, which guards every use with is_object(). Casting it
 * straight to string throws "Object of class ampuser could not be converted to
 * string", which is a 500 on the authenticated path only - the exact shape of
 * bug a test that fakes the session with a string will never catch.
 */
function es_session_username($u) {
    // The access-control check runs session_start() BEFORE the FreePBX
    // bootstrap, deliberately - so at deserialization time the ampuser class
    // does not exist yet and PHP substitutes a __PHP_Incomplete_Class
    // placeholder. Reading a property off that placeholder is itself a fatal
    // error; round-tripping it now that the class is loaded rebuilds the real
    // object without disturbing the session.
    if ($u instanceof __PHP_Incomplete_Class) {
        $restored = @unserialize(@serialize($u));
        if (is_object($restored) && !($restored instanceof __PHP_Incomplete_Class)) {
            $u = $restored;
        } else {
            return 'unknown';
        }
    }
    if (is_object($u)) {
        return isset($u->username) ? (string) $u->username : get_class($u);
    }
    if (is_array($u)) {
        return isset($u['username']) ? (string) $u['username'] : 'unknown';
    }
    return is_scalar($u) ? (string) $u : 'unknown';
}

/** Escape for HTML. The User-Agent is supplied by whatever registers. */
function es_h($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Everything after the last $sep, or the whole string if $sep is absent. */
function es_last_after($s, $sep) {
    $parts = explode($sep, (string) $s);
    return end($parts);
}

/** Everything before the first $sep, or the whole string if $sep is absent. */
function es_first_before($s, $sep) {
    $parts = explode($sep, (string) $s);
    return $parts[0];
}

/** Send a JSON response and stop. */
function es_json($payload, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Transport actually in use for a contact, read off its URI.
 * SIP defaults to UDP when no transport parameter is present.
 */
function es_transport($uri) {
    $uri = (string) $uri;
    if (preg_match('/;transport=([A-Za-z]+)/i', $uri, $m)) {
        $t = strtolower($m[1]);
    } elseif (stripos($uri, 'sips:') === 0) {
        $t = 'tls';
    } else {
        $t = 'udp';
    }
    $known = array('udp' => 'UDP', 'tcp' => 'TCP', 'tls' => 'TLS', 'ws' => 'WS', 'wss' => 'WSS');
    return es_get($known, $t, 'Other');
}

/**
 * Pull a MAC out of a User-Agent, normalised to 12 lower-case hex digits.
 *
 * Some makes put it in the SIP User-Agent and some do not:
 *   "Fanvil V64 2.12.24.2 0c383e15bfe6"     -> 0c383e15bfe6
 *   "Yealink SIP-T44U 108.87.0.20"          -> '' (nothing to find)
 * The HTTP User-Agent separates it: "44:db:d2:c9:3c:a3".
 *
 * Anchored on a word boundary so a firmware version cannot be mistaken for a
 * MAC, and the 6-group form is tried first so a separated MAC is not truncated.
 */
function es_mac_from_ua($ua) {
    $ua = (string) $ua;
    if (preg_match('~\b([0-9a-f]{2}(?:[:\-][0-9a-f]{2}){5})\b~i', $ua, $m)) {
        return strtolower(preg_replace('~[^0-9a-f]~i', '', $m[1]));
    }
    if (preg_match('~\b([0-9a-f]{12})\b~i', $ua, $m)) {
        return strtolower($m[1]);
    }
    return '';
}

/** Validate an IP, or return a placeholder. */
function es_ip_or_placeholder($v) {
    return filter_var($v, FILTER_VALIDATE_IP) ? $v : 'Not an IP';
}

// ---------------------------------------------------------------------------
// User-Agent parsing
// ---------------------------------------------------------------------------

/**
 * Split a User-Agent into brand / model / firmware.
 *
 * Every index is defaulted. A single-token User-Agent ("Zulu") has no second
 * element and a two-token one ("Acrobits SIPIS") has no firmware; reading
 * either blind is an undefined-index notice on 5.6 and a warning on 7.4, and
 * on a box with display_errors on it lands in the middle of the JSON.
 *
 ********** Examples
   "Yealink SIP VP-T49G 51.80.0.100"
   "Yealink SIP-T54W 96.85.0.5"
   "Zoiper rv2.10.8.2"
   "Grandstream HT802 1.0.17.5"
   "snomPA1/8.7.3.19"
   "LinphoneiOS/4.3.0 (Bob's iPhone) LinphoneSDK/4.4.0"
   "OBIHAI/OBi202-3.2.2.5921"
   "MicroSIP/3.20.5"
   "Acrobits SIPIS"                                   // Sangoma Connect push service
   "Telephone 1.5.2"                                  // macOS app "Telephone"
   "Linphone Desktop/4.2.5 (macOS 10.15, Qt 5.15.2) LinphoneCore/4.4.19"
   "Sangoma Connect/1.0.1"
   "Zulu"
   "PolycomRealPresenceTrio-Trio_8500-UA/5.9.2.7727"
   "PolycomSoundPointIP-SPIP_450-UA/4.0.15.1047"
   "Jitsi2.10.5550Windows 10"
   "Z 5.5.5 v2.10.15.2"                               // potentially Jitsi on macOS
   "Algo-8201/5.2"                                    // Algo door intercom
 **********/
function es_device_info($ua) {
    $ua = es_utf8($ua);
    $ua_arr = preg_split("/[\s\/]/", $ua, 2);
    $head = es_get($ua_arr, 0, '');
    $rest = es_get($ua_arr, 1, '');

    switch ($head) {
        case 'Yealink':
        case 'Zulu':
        case 'Z':
            $mf = preg_split("/[\s]/", preg_replace("/^SIP[\s-]/", '', $rest));
            return array('brand' => $head, 'model' => es_get($mf, 0, ''), 'firmware' => es_get($mf, 1, ''));

        case 'Grandstream':
        case 'OBIHAI':
        case 'Fanvil':
        case 'Acrobits':
        case 'Cisco':
            $mf = preg_split("/[\s-]/", $rest);
            return array('brand' => $head, 'model' => es_get($mf, 0, ''), 'firmware' => es_get($mf, 1, ''));

        case 'Sangoma':
            $mf = preg_split("/[\/]/", $rest);
            return array('brand' => $head, 'model' => es_get($mf, 0, ''), 'firmware' => es_get($mf, 1, ''));

        case 'Zoiper':
        case 'MicroSIP':
        case 'Telephone':
            return array('brand' => $head, 'model' => '', 'firmware' => $rest);

        case 'snomPA1':
            return array('brand' => 'Snom', 'model' => 'PA1', 'firmware' => $rest);

        case 'LinphoneiOS':
            $mf = preg_split("/[\s]/", $rest);
            return array('brand' => $head, 'model' => '', 'firmware' => es_get($mf, 0, ''));

        case 'Linphone': // Linphone Desktop
            $mf = preg_split("/[\s\/]/", preg_replace('/\(|\)/', '', $rest));
            return array(
                'brand'    => trim($head . ' ' . es_get($mf, 0, '')),
                'model'    => es_get($mf, 2, ''),
                'firmware' => es_get($mf, 1, ''),
            );
    }

    // Messy, will look into it after more Poly devices are tested
    if (substr($head, 0, 7) === 'Polycom') {
        $mf = preg_split("/[-]/", $head);
        return array(
            'brand'    => 'Polycom',
            'model'    => preg_replace('/_/', ' ', es_get($mf, 1, '')),
            'firmware' => $rest,
        );
    }
    // Algo is Algo-NNNN/firmware
    if (substr($head, 0, 4) === 'Algo') {
        $mf = preg_split("/[-]/", $head);
        return array('brand' => es_get($mf, 0, ''), 'model' => es_get($mf, 1, ''), 'firmware' => $rest);
    }
    // Jitsi on Windows does not have a split character.
    if (substr($head, 0, 5) === 'Jitsi') {
        if (preg_match('/(\D+)([\d\.]+)(\D+.*)/', $ua, $m)) {
            return array('brand' => es_get($m, 1, ''), 'model' => es_get($m, 3, ''), 'firmware' => es_get($m, 2, ''));
        }
        return array('brand' => 'Jitsi', 'model' => '', 'firmware' => '');
    }

    return array('brand' => 'Unknown', 'model' => '', 'firmware' => '');
}

// ---------------------------------------------------------------------------
// AMI
// ---------------------------------------------------------------------------

/** Extension display name, cached so duplicate AORs cost one lookup. */
/**
 * Can this device be sent a NOTIFY at all?
 *
 * A softphone that shares a hardware vendor's User-Agent prefix would
 * otherwise inherit that vendor's buttons - Sangoma Talk is a phone app, not a
 * Sangoma desk phone, and check-sync means nothing to it.
 */
function es_actionable($brand, $model, $excluded) {
    if (!is_array($excluded) || !isset($excluded[$brand])) {
        return true;
    }
    foreach ($excluded[$brand] as $bad) {
        if (strcasecmp((string) $model, (string) $bad) === 0) {
            return false;
        }
    }
    return true;
}

/**
 * Stable identity for a physical device.
 *
 * Not the contact URI or the AMI contact ID - both are derived from the source
 * address and change whenever the phone re-registers from a new port, which
 * would make every device look new. Extension + brand + model survives that and
 * survives a firmware upgrade. Two identical models on one extension collapse
 * into one entry; that is an accepted limit.
 */
function es_device_key($aor, $brand, $model) {
    return $aor . '|' . $brand . '|' . $model;
}

/** Remembered devices, as key => record. Missing or unreadable reads as none. */
function es_state_load($file) {
    if ($file === '' || !is_readable($file)) {
        return array();
    }
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return array();
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : array();
}

/**
 * Persist remembered devices. Best effort: if the location is not writable the
 * page still works, it just cannot remember drop-offs.
 *
 * Written to a temp file and renamed so a concurrent reader never sees a
 * half-written file, and rename() is atomic within a filesystem.
 */
function es_state_save($file, $state) {
    if ($file === '') {
        return false;
    }
    $dir = dirname($file);
    if (!is_dir($dir) || !is_writable($dir)) {
        return false;
    }
    $tmp = @tempnam($dir, 'esdev');
    if ($tmp === false) {
        return false;
    }
    $ok = @file_put_contents($tmp, json_encode($state), LOCK_EX);
    if ($ok === false) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0640);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Every PJSIP device FreePBX knows about, as extension => display name.
 *
 * This is what makes the Status column mean something: without it the page can
 * only list contacts that are currently registered, so a phone that drops off
 * silently vanishes and every row always reads "Reachable".
 *
 * getAllDevicesByType('pjsip') is used rather than PJSIPShowEndpoints because
 * Asterisk's endpoint list also contains trunks, and rather than getAllUsers()
 * because that includes non-device extensions such as the UCP template.
 */
function es_pjsip_devices($fcore) {
    $out = array();
    if (!method_exists($fcore, 'getAllDevicesByType')) {
        return $out;
    }
    $devices = $fcore->getAllDevicesByType('pjsip');
    if (!is_array($devices)) {
        return $out;
    }
    foreach ($devices as $d) {
        if (!is_array($d)) {
            continue;
        }
        $ext = (string) es_get($d, 'user', es_get($d, 'id', ''));
        if ($ext === '') {
            continue;
        }
        $out[$ext] = (string) es_get($d, 'description', '');
    }
    return $out;
}

function es_display_name($fcore, $aor) {
    static $cache = array();
    if (array_key_exists($aor, $cache)) {
        return $cache[$aor];
    }
    // 90/98 prefixed AORs are FreePBX pseudo-devices; strip to the real extension.
    $ext = (es_starts_with($aor, '90') || es_starts_with($aor, '98'))
        ? substr($aor, 2)
        : $aor;
    $user = $fcore->getUser($ext);
    // getUser() returns false when the AOR has no matching extension.
    $cache[$aor] = is_array($user) ? es_utf8(es_get($user, 'name', '')) : '';
    return $cache[$aor];
}

/**
 * Fetch the contact statuses, at most once per request.
 *
 * FreePBX's PJSIPShowRegistrationInboundContactStatuses() collects events into
 * $this->response_catch via a handler it registers each call, and does not
 * clear it first - so calling it twice in one request returns every contact
 * twice (6, 12, 18, 24... on repeat calls). Everything here goes through this
 * wrapper so that cannot happen.
 */
function es_fetch_contacts($astman) {
    static $cache = null;
    if ($cache === null) {
        $r = $astman->PJSIPShowRegistrationInboundContactStatuses();
        $cache = is_array($r) ? $r : array();
    }
    return $cache;
}

/**
 * How a NOTIFY should be addressed: 'uri' or 'endpoint'.
 *
 * Straight off $es_notify_target, defaulting to URI mode. This deliberately
 * does NOT probe Asterisk first. The AMI documentation says URI mode needs a
 * configured default_outbound_endpoint, and the obvious reading is that the
 * FreePBX 15 test box - whose global still holds Asterisk's placeholder
 * default rather than a real endpoint name - could not do it. Measured, that
 * reading is wrong: a URI-mode PJSIPNotify on that box answers
 * "Success: NOTIFY sent" all the same.
 *
 * So there is no reliable thing to probe for, and a probe would cost two AMI
 * round trips on every page load to guess at it. URI mode is simply the
 * default; if a box does reject it, es_send_notify() says so and names the
 * setting to change.
 */
function es_notify_mode($configured) {
    return $configured === 'endpoint' ? 'endpoint' : 'uri';
}

/**
 * Ask Asterisk to probe an endpoint's contacts now, rather than at its next
 * scheduled qualify.
 *
 * A stock AOR carries qualify_frequency=60, so Asterisk re-probes a contact
 * once a minute and its Status trails the handset by up to that long. Both
 * edges of a reboot land on the same tick: measured on the FreePBX 17 box, a
 * T44U was marked Unreachable at 20:21:13 and Reachable at 20:22:10, having
 * gone down and come back well inside that window. Verification watches those
 * transitions, so without this it sits on "Rebooting..." for most of a minute
 * after the handset is answering again.
 *
 * An OPTIONS goes out immediately and the status follows within the round trip
 * - 25ms there - so the next poll sees it. Only called while a reboot is being
 * verified; nothing probes on a page load or an auto-refresh.
 *
 * Asterisk has no per-contact qualify, so this probes every contact on the
 * endpoint's AORs, not only the handset whose button was clicked.
 */
function es_qualify($astman, $aor) {
    // This ends up in a CLI command string, so accept only what an extension
    // name can legitimately be and drop anything else rather than escaping it.
    if (!preg_match('/^[A-Za-z0-9_-]+$/', (string) $aor)) {
        return false;
    }
    try {
        $resp = $astman->send_request('Command', array('Command' => 'pjsip qualify ' . $aor));
    } catch (Exception $e) {
        return false;
    }

    return isset($resp['Response']) && strcasecmp((string) $resp['Response'], 'Success') === 0;
}

// ---------------------------------------------------------------------------
// Verification
// ---------------------------------------------------------------------------

/** Last 256KB of a file - these logs grow without bound. */
function es_log_tail($logfile, $bytes = 262144) {
    $fh = @fopen($logfile, 'rb');
    if (!$fh) {
        return false;
    }
    fseek($fh, 0, SEEK_END);
    $size = ftell($fh);
    fseek($fh, max(0, $size - $bytes));
    $tail = stream_get_contents($fh);
    fclose($fh);
    return $tail === false ? '' : $tail;
}

/**
 * Look for a handset fetching its provisioning files since a given time.
 *
 * A check-sync makes the phone re-read its config, which lands in the web
 * server access log as a request for .cfg/.boot. That is the only end-to-end
 * confirmation available: Asterisk reports the NOTIFY dispatched, never
 * whether the phone acted on it.
 *
 * $logfiles is a list because Sangoma OS spreads vhosts over several logs -
 * the admin vhost writes /var/log/httpd/access_log, but a provisioning vhost
 * on another port can have a CustomLog of its own.
 *
 * @return array{seen: bool, at?: string, what?: string, readable: bool}
 */
function es_verify_fetch($logfiles, $ip, $brand, $model, $since, $mac = '') {
    if (!is_array($logfiles)) {
        $logfiles = array($logfiles);
    }
    $readable = false;
    $all = array();

    foreach ($logfiles as $logfile) {
        if ($logfile === '' || !is_readable($logfile)) {
            continue;
        }
        $tail = es_log_tail($logfile);
        if ($tail === false) {
            continue;
        }
        $readable = true;

        $all = array_merge($all, explode("\n", $tail));
    }

    if (!$readable) {
        return array('seen' => false, 'readable' => false);
    }

    // The MAC identifies the device; several phones share one public IP, so the
    // address alone cannot. It comes from the SIP User-Agent when the make
    // publishes it there - authoritative, and already in hand. Only when it does
    // not is it learned from the HTTP User-Agent in the log.
    $mac = strtolower(preg_replace('~[^0-9a-f]~i', '', (string) $mac));
    if (strlen($mac) !== 12) {
        $mac = '';
    }
    if ($mac === '') {
        foreach ($all as $line) {
            if (strpos($line, $ip) === false) {
                continue;
            }
            if ($brand !== '' && stripos($line, $brand) === false) {
                continue;
            }
            if ($model !== '' && stripos($line, $model) === false) {
                continue;
            }
            // Trailing quoted field is the User-Agent.
            if (!preg_match('~"([^"]*)"\s*$~', $line, $ua)) {
                continue;
            }
            $mac = es_mac_from_ua($ua[1]);
            if ($mac !== '') {
                break;
            }
        }
    }
    if ($mac === '') {
        return array('seen' => false, 'readable' => true);
    }

    // A config pull belonging to THAT MAC. One handset requests plenty of files
    // - shared templates (y-common.cfg), boot files, firmware (.rom), phonebook
    // scripts - and none of those prove this phone read its own config. So the
    // path must carry the MAC and be a config file.
    foreach ($all as $line) {
        if (stripos($line, $mac) === false) {
            continue;
        }
        // ... [10/Oct/2026:17:02:33 -0500] "GET /44dbd2c93ca3.cfg HTTP/1.1" 200 ...
        if (!preg_match('~\[([^\]]+)\]\s+"(?:GET|POST)\s+(\S+)[^"]*"\s+(\d{3})~', $line, $m)) {
            continue;
        }
        if ($m[3] !== '200') {
            continue;                       // 401 is the pre-auth probe, not a fetch
        }
        // MAC case varies between requests from one phone - 249ad8416c5c.cfg and
        // 249AD8416C5C-contact.xml both came from a single T54W.
        if (stripos($m[2], $mac) === false) {
            continue;
        }
        // cfg and boot are confirmed against live Yealink and Fanvil traffic;
        // xml and ld are a best guess for makes not available to test.
        if (!preg_match('~\.(cfg|boot|xml|ld)$~i', $m[2])) {
            continue;
        }
        $ts = strtotime(preg_replace('~^(\d+)/(\w+)/(\d+):~', '$1 $2 $3 ', $m[1]));
        if ($ts === false || $ts < $since) {
            continue;
        }
        return array('seen' => true, 'readable' => true,
                     'at' => date('H:i:s', $ts), 'what' => $m[2], 'mac' => $mac);
    }

    return array('seen' => false, 'readable' => true);
}

/**
 * Turn the request checkpoints into a per-phase breakdown.
 *
 * Reported to the browser and the error log so a slow click can be attributed
 * to a phase instead of guessed at. 'session' is time spent waiting for the
 * PHP session lock - PHP serialises concurrent requests on one session, so a
 * click that lands while another request holds it queues behind that request.
 */
function es_timings($t) {
    $ms = function ($a, $b) use ($t) {
        return (int) round((es_get($t, $b, 0) - es_get($t, $a, 0)) * 1000);
    };
    $parts = array(
        'session'   => $ms('start', 'session'),
        'bootstrap' => $ms('unlock', 'bootstrap'),
        'dispatch'  => $ms('bootstrap', 'dispatch'),
    );
    $total = (int) round((es_get($t, 'dispatch', microtime(true)) - $t['start']) * 1000);
    $out = array();
    foreach ($parts as $k => $v) {
        $out[] = "$k={$v}ms";
    }
    return array(
        'ms'     => $total,
        'timing' => implode(' ', $out) . " total={$total}ms",
    );
}

// ---------------------------------------------------------------------------
// Row building
// ---------------------------------------------------------------------------

/**
 * How many contacts each endpoint currently has registered.
 *
 * In endpoint mode a NOTIFY reaches all of them, so this is how many devices a
 * single click actually touches - stated in the UI rather than left a surprise.
 */
function es_registration_counts($contacts) {
    $counts = array();
    foreach ($contacts as $data) {
        $endpoint = (string) es_get($data, 'EndpointName', es_get($data, 'AOR', ''));
        $counts[$endpoint] = es_get($counts, $endpoint, 0) + 1;
    }
    return $counts;
}

/**
 * Minimal contact list for validating a NOTIFY target.
 *
 * Same shape es_send_notify() needs, but without the per-extension display-name
 * lookups es_build_rows() performs - a button click has no use for them.
 */
function es_build_targets($astman, $excluded = array()) {
    $contacts = es_fetch_contacts($astman);
    $counts   = es_registration_counts($contacts);
    $targets  = array();
    foreach ($contacts as $data) {
        $aor      = es_utf8(es_get($data, 'AOR', ''));
        $endpoint = es_utf8(es_get($data, 'EndpointName', $aor));
        $dev      = es_device_info(es_get($data, 'UserAgent', ''));
        $targets[] = array(
            'uri'        => es_utf8(es_get($data, 'URI', '')),
            'aor'        => $aor,
            'endpoint'   => $endpoint,
            'siblings'   => es_get($counts, $endpoint, 1),
            'brand'      => $dev['brand'],
            'model'      => $dev['model'],
            'actionable' => es_actionable($dev['brand'], $dev['model'], $excluded),
        );
    }
    return $targets;
}

/** Build the normalized row set the page and the JSON endpoint both use. */
function es_build_rows($astman, $fcore, $devices = null, $statefile = null, $retain_days = 7, $excluded = array()) {
    $results = es_fetch_contacts($astman);
    $counts  = es_registration_counts($results);

    $rows = array();
    foreach ($results as $data) {
        $aor      = es_utf8(es_get($data, 'AOR', ''));
        $uri      = es_utf8(es_get($data, 'URI', ''));
        $ua       = es_utf8(es_get($data, 'UserAgent', ''));
        $endpoint = es_utf8(es_get($data, 'EndpointName', $aor));
        $dev      = es_device_info($ua);

        // A string, not a float. PHP 5.6 encodes JSON floats at
        // serialize_precision=17, so round($x/1000, 1) reaches the browser as
        // 20.199999999999999 and the cell reads "20.199999999999999 ms".
        // number_format() settles it here; the sort still does Number("20.2").
        $rtt = es_get($data, 'RoundtripUsec', '');
        $rtt_ms = is_numeric($rtt) ? number_format($rtt / 1000, 1, '.', '') : null;

        $expire = es_get($data, 'RegExpire', '');
        $expire_unix = is_numeric($expire) ? (int) $expire : null;
        $expire_str = '-';
        if ($expire_unix !== null) {
            $dt = new DateTime('@' . $expire_unix, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
            $expire_str = $dt->format('Y/m/d H:i:s');
        }

        $rows[] = array(
            'aor'        => $aor,
            'endpoint'   => $endpoint,
            // Full contact URI - the NOTIFY target in URI mode, so one handset
            // is addressed rather than every contact on the extension.
            'uri'        => $uri,
            'name'       => es_display_name($fcore, $aor),
            'brand'      => $dev['brand'],
            'model'      => $dev['model'],
            'firmware'   => $dev['firmware'],
            'useragent'  => $ua,
            'transport'  => es_transport($uri),
            'status'     => es_utf8(es_get($data, 'Status', '')),
            'rtt_ms'     => $rtt_ms,
            'uri_ip'     => es_ip_or_placeholder(es_first_before(es_last_after($uri, '@'), ':')),
            'via_ip'     => es_ip_or_placeholder(es_first_before(es_utf8(es_get($data, 'ViaAddress', '')), ':')),
            'callid_ip'  => es_ip_or_placeholder(es_last_after(es_utf8(es_get($data, 'CallID', '')), '@')),
            'expire'     => $expire_unix,
            'expire_str' => $expire_str,
            // Devices an endpoint-mode NOTIFY to this extension will reach.
            'siblings'   => es_get($counts, $endpoint, 1),
            'registered' => true,
            'actionable' => es_actionable($dev['brand'], $dev['model'], $excluded),
        );
    }

    // ---- devices remembered from earlier, now missing ----------------------
    //
    // The configured-device list below only catches an extension with NO
    // registrations at all. An extension with several handsets keeps looking
    // registered when one of them drops, so that device would just vanish -
    // which is the case worth catching. Hence remembered state.
    $now = time();
    if ($statefile !== null && $statefile !== '') {
        $state = es_state_load($statefile);
        $live_keys = array();
        foreach ($rows as $r) {
            $k = es_device_key($r['aor'], $r['brand'], $r['model']);
            $live_keys[$k] = true;
            $state[$k] = array(
                'aor'       => $r['aor'],
                'name'      => $r['name'],
                'brand'     => $r['brand'],
                'model'     => $r['model'],
                'firmware'  => $r['firmware'],
                'transport' => $r['transport'],
                'uri_ip'    => $r['uri_ip'],
                // Kept so the MAC is still known while the handset is down and
                // no longer has a live User-Agent to read it from.
                'useragent' => $r['useragent'],
                'last_seen' => $now,
            );
        }

        $cutoff = $now - ((int) $retain_days * 86400);
        foreach ($state as $k => $rec) {
            if (!is_array($rec) || (int) es_get($rec, 'last_seen', 0) < $cutoff) {
                unset($state[$k]);          // aged out - stop reporting it
                continue;
            }
            if (isset($live_keys[$k])) {
                continue;
            }
            $last = (int) es_get($rec, 'last_seen', 0);
            $rows[] = array(
                'aor'        => (string) es_get($rec, 'aor', ''),
                'endpoint'   => (string) es_get($rec, 'aor', ''),
                'uri'        => '',
                'name'       => (string) es_get($rec, 'name', ''),
                'brand'      => (string) es_get($rec, 'brand', ''),
                'model'      => (string) es_get($rec, 'model', ''),
                'firmware'   => (string) es_get($rec, 'firmware', ''),
                'useragent'  => (string) es_get($rec, 'useragent', ''),
                'transport'  => '',
                'status'     => 'Unregistered',
                'rtt_ms'     => null,
                'uri_ip'     => (string) es_get($rec, 'uri_ip', ''),
                'via_ip'     => '',
                'callid_ip'  => '',
                'expire'     => $last,
                'expire_str' => 'last seen ' . date('Y/m/d H:i:s', $last),
                'siblings'   => 0,
                'registered' => false,
                'actionable' => false,
            );
        }
        es_state_save($statefile, $state);
    }

    // ---- configured devices that have never registered ---------------------
    $seen = array();
    foreach ($rows as $r) {
        $seen[$r['aor']] = true;
    }
    $devlist = ($devices === null) ? es_pjsip_devices($fcore) : $devices;
    foreach ($devlist as $ext => $name) {
        // PHP turns a numeric-string array key into an int, so cast back or the
        // JSON carries 101 for these rows and "101" for every other one.
        $ext = (string) $ext;
        if (isset($seen[$ext])) {
            continue;
        }
        $rows[] = array(
            'aor'        => $ext,
            'endpoint'   => $ext,
            'uri'        => '',
            'name'       => ($name !== '') ? es_utf8($name) : es_display_name($fcore, $ext),
            'brand'      => '',
            'model'      => '',
            'firmware'   => '',
            'useragent'  => '',
            'transport'  => '',
            // Distinct from Asterisk's own "Unreachable", which means a contact
            // IS registered but is not answering qualify. This one is gone.
            'status'     => 'Unregistered',
            'rtt_ms'     => null,
            'uri_ip'     => '',
            'via_ip'     => '',
            'callid_ip'  => '',
            'expire'     => null,
            'expire_str' => '-',
            'siblings'   => 0,
            'registered' => false,
            'actionable' => false,
        );
    }
    return $rows;
}

// ---------------------------------------------------------------------------
// NOTIFY dispatch
// ---------------------------------------------------------------------------

/**
 * Send a SIP NOTIFY to a contact via the AMI PJSIPNotify action.
 *
 * Two addressing modes, chosen by es_notify_mode():
 *
 *   uri       targets the contact URI, so an extension registered on several
 *             devices only gets the NOTIFY on the handset whose row was
 *             clicked. Needs a usable default_outbound_endpoint.
 *   endpoint  targets the endpoint, which reaches EVERY device registered to
 *             that extension. Always available.
 *
 * KNOWN AND ACCEPTED for URI mode: it routes through pjsip.conf's
 * default_outbound_endpoint, so the request carries that identity rather than
 * the extension's. Captured from a live Yealink T44U, the two modes differ in
 * exactly one field - request-URI, To, Event and Subscription-State are
 * identical, and the phone returns 200 OK in the same second either way:
 *
 *   URI mode       From: <sip:dpma_endpoint@104.207.139.250>
 *   endpoint mode  From: <sip:103@104.207.139.250>
 *
 * AMI accepts exactly one of Endpoint/URI/Channel, so URI mode cannot be given
 * a corrected From.
 *
 * Asterisk answers "Success: NOTIFY sent" as soon as it dispatches - including
 * for a target nothing is listening on. It confirms dispatch, never delivery.
 */
function es_send_notify($astman, $uri, $action, $actionmap, $rows, $who, $mode) {
    // Only a URI that is currently a registered contact is targetable. Asterisk
    // will happily accept anything here, so this check is the real constraint -
    // and in endpoint mode it is also what turns the browser's contact URI into
    // an endpoint name, so the browser never names an endpoint directly.
    $match = null;
    foreach ($rows as $r) {
        if ($r['uri'] === $uri) {
            $match = $r;
            break;
        }
    }
    if ($match === null) {
        return array('ok' => false, 'message' => 'That contact is no longer registered - refresh the page.');
    }
    // The action must be one defined for THAT row's brand, so a Yealink-only
    // command cannot be aimed at a Polycom by editing the request.
    // A softphone wearing a hardware vendor's User-Agent gets nothing, however
    // the request was crafted.
    if (isset($match['actionable']) && !$match['actionable']) {
        return array('ok' => false, 'message' => 'That device does not accept NOTIFY actions.');
    }
    $allowed = es_get($actionmap, $match['brand'], array());
    if (!isset($allowed[$action])) {
        return array('ok' => false, 'message' => 'That action is not available for a ' . $match['brand'] . ' device.');
    }
    // Defence in depth: a CR or LF in a header value would let the rest of the
    // string be read as further AMI headers. Both fields originate with
    // Asterisk, not the browser, but they are still put on the wire verbatim.
    if (preg_match('/[\r\n]/', $uri) || preg_match('/[\r\n]/', $match['endpoint'])) {
        return array('ok' => false, 'message' => 'Invalid contact URI.');
    }

    if ($mode === 'uri') {
        $params = array('URI' => $uri, 'Variable' => $allowed[$action]['headers']);
        $logged = $uri;
    } else {
        $params = array('Endpoint' => $match['endpoint'], 'Variable' => $allowed[$action]['headers']);
        $logged = 'endpoint ' . $match['endpoint'];
    }

    try {
        $resp = $astman->send_request('PJSIPNotify', $params);
    } catch (Exception $e) {
        return array('ok' => false, 'message' => 'AMI error: ' . $e->getMessage());
    }
    $ok = isset($resp['Response']) && strcasecmp((string) $resp['Response'], 'Success') === 0;

    error_log(sprintf(
        'extensionstatus: %s sent %s to %s (ext %s, %s mode) - %s',
        $who, $action, $logged, $match['aor'], $mode, $ok ? 'dispatched' : 'FAILED'
    ));

    if (!$ok) {
        $msg = (string) es_get($resp, 'Message', 'Asterisk rejected the request.');
        // The one failure worth explaining rather than just relaying.
        if ($mode === 'uri' && stripos($msg, 'endpoint') !== false) {
            $msg .= ' URI-mode NOTIFY needs a working default_outbound_endpoint'
                  . ' - set $es_notify_target to "endpoint" to address the'
                  . ' extension instead.';
        }
        return array('ok' => false, 'message' => $msg);
    }

    if ($mode === 'uri') {
        $message = 'NOTIFY dispatched to extension ' . $match['aor']
                 . '. The handset typically acts on it within ~10s.';
    } elseif ($match['siblings'] > 1) {
        $message = 'NOTIFY dispatched to all ' . $match['siblings'] . ' devices registered to extension '
                 . $match['aor'] . '. They typically act on it within ~10s.';
    } else {
        $message = 'NOTIFY dispatched to extension ' . $match['aor']
                 . '. The handset typically acts on it within ~10s.';
    }
    return array('ok' => true, 'message' => $message);
}
