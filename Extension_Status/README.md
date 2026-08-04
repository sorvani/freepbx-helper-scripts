# Extension Status

An admin-only page listing every PJSIP contact: extension, name, device
brand/model/firmware parsed out of the User-Agent, transport, status, round-trip
time, known device IPs, and registration expiry. Handsets that support it get
per-contact SIP NOTIFY buttons to reload config, reboot, or factory reset.

Sortable, filterable, and auto-refreshing.

> [!IMPORTANT]
> **This targets FreePBX 14/15/16 on Sangoma OS (CentOS 7).** FreePBX 17 is
> Debian with PHP 8 and a different Apache layout — use
> **[sorvani/freepbx17-extension-status](https://github.com/sorvani/freepbx17-extension-status)**
> there. The installer refuses to run on the wrong one.

Tested on:

| | Sangoma Linux 7.8 | Sangoma Linux 7.8 |
| --- | --- | --- |
| FreePBX | 15 | 16 |
| PHP | 5.6.40 (`php5_module`) | 7.4.16 (`php7_module`) |
| Asterisk | 18.26.4 | 20.17.0 |

PHP 5.6 is the floor, and it is a real constraint rather than a cautious one:
FreePBX 15 has neither `random_bytes()` nor the `??` operator, and *neither*
version has `str_starts_with()` — that is PHP 8.0, later than both.

## Install

```bash
git clone https://github.com/sorvani/freepbx-helper-scripts.git
cd freepbx-helper-scripts/Extension_Status
sudo ./install.sh
```

Then open `https://YOUR-PBX/custom/extensionstatus.php` while logged in to the
FreePBX admin GUI.

To also enable NOTIFY verification, which needs one log permission change:

```bash
ENABLE_VERIFY_LOG=1 sudo -E ./install.sh
```

### Install details

Installs to `/var/www/html/custom/` (override with `DEST=... sudo -E ./install.sh`),
owned by whatever user `httpd.conf` actually runs Apache as — read from the file
rather than assumed, though on FreePBX it is `asterisk`. It backs up any file it
would change, runs `php -l` over the result, and refuses to run if mod_php is
not loaded (matching both `php5_module` and `php7_module`), since Apache would
otherwise serve the PHP source instead of executing it.

Three files, all in the same directory:

| File | Contents |
| --- | --- |
| `extensionstatus.php` | configuration, access control, request routing |
| `extensionstatus.lib.php` | AMI access, User-Agent parsing, NOTIFY dispatch |
| `extensionstatus.view.php` | markup, styling, browser code |

By hand instead:

```bash
for f in extensionstatus.php extensionstatus.lib.php extensionstatus.view.php; do
  sudo install -o asterisk -g asterisk -m 644 "$f" "/var/www/html/custom/$f"
done
```

`/var/www/html/custom/` is the right home for these: it is served by the admin
vhost and FreePBX never writes there, so upgrades leave it alone.

Earlier versions of this page shipped a separate `extensionstatus_header.php`.
The rewrite folded it in; the installer renames any leftover copy aside.

## Access control

The page checks for a logged-in FreePBX admin session and refuses everything
else with a 403 — the HTML page, the JSON refresh, and the NOTIFY endpoint
alike. That check is the access control: the page exposes every extension's
public IP, device model and firmware, and it can reboot and factory-reset
handsets.

Do **not** add `$bootstrap_settings['freepbx_auth'] = false` here. The phonebook
generators use it because phones fetch them and can never hold a session. This
page is the opposite case.

## NOTIFY actions

Buttons appear on rows whose brand has an action set defined.

| Brand | Actions |
| --- | --- |
| Yealink | Reload config, Reboot, Factory reset |
| Snom, Sangoma | Reload config, Reboot |
| Polycom, Grandstream, Fanvil, Cisco, Algo, OBIHAI | Reload config |

Softphones get no buttons. Reboot and Factory reset prompt for confirmation.

The NOTIFY headers are sent inline over AMI, so **no Asterisk config file is
involved** — `sip_notify_custom.conf` does not need an entry for any of this.
Add or change actions in the `$es_notify_actions` block at the top of the file;
each entry notes the `sip_notify_*.conf` section it mirrors. This does mean the
AMI user needs the `system` privilege, which FreePBX grants by default.

Asterisk reports success as soon as it dispatches the NOTIFY, including to a URI
nothing is listening on. A success message means dispatched, not delivered —
which is what the verification below is for.

### Addressing: one handset or the whole extension

`$es_notify_target` decides how a NOTIFY is aimed:

| | Reaches | From header |
| --- | --- | --- |
| `'uri'` (default) | only the handset whose row you clicked | the default outbound endpoint's |
| `'endpoint'` | **every** device registered to that extension | the extension's |

URI mode routes through pjsip's `default_outbound_endpoint`, so the request
carries that identity rather than the extension's. Everything else about the two
packets is identical and the phone returns 200 OK immediately either way.

AMI documents URI mode as requiring a configured `default_outbound_endpoint`,
which reads like it would fail on a box that has never had one set. Measured,
that reading is wrong — the FreePBX 15 test box still has Asterisk's placeholder
default there, and a URI-mode `PJSIPNotify` on it answers `Success: NOTIFY sent`
regardless. So URI mode is simply the default. If your box does reject it, the
error surfaced in the page says so and names this setting.

The confirmation dialog and the pill in the toolbar both state which mode is
active, so the blast radius is on screen before the click rather than inferred.

### Verification

A check-sync makes the handset re-read its provisioning files, which lands in
the web server access log. After a button click the row shows `Verifying…` and
polls for that fetch, then reports `✓ Config fetched 17:10:08` or, after 30s,
`✕ No config fetch in 30s`. The log is only read after a click — never on page
load or auto-refresh.

**This only works if your phones provision over HTTP(S) from Apache.** Endpoint
Manager over TFTP leaves no access-log trail, so there is nothing to find; the
buttons still work, the check just always times out.

It also needs the web server to be able to read the log. The two test boxes
differed here, which is why the page decides at runtime rather than assuming:

| | `/var/log/httpd` | readable by `asterisk`? |
| --- | --- | --- |
| FreePBX 15 box | `root:root 0700` | no — needs the change below |
| FreePBX 16 box | `asterisk:asterisk 0700` | yes, already |

```bash
ENABLE_VERIFY_LOG=1 sudo -E ./install.sh
```

That grants it with an ACL — `asterisk` gets traverse on the directory and read
on that one file, nothing else:

```bash
sudo setfacl -m u:asterisk:x /var/log/httpd
sudo setfacl -m u:asterisk:r /var/log/httpd/access_log
```

and, to survive rotation, in the `postrotate` block of `/etc/logrotate.d/httpd`:

```bash
setfacl -m u:asterisk:r /var/log/httpd/access_log 2>/dev/null || true
```

The installer falls back to `chgrp asterisk` + `chmod 750` on the directory if
the filesystem has no usable ACL support, and says so when it does — that
version grants read on *every* file in `/var/log/httpd`, `error_log` included.

If a provisioning vhost on another port writes its own `CustomLog`, add it to
the `$es_access_logs` list; the page scans all of them.

**Verification is entirely optional.** If you would rather not touch log
permissions, change nothing, or set `$es_access_logs = array()`. The page
detects that no log is readable and simply omits the check — no warning, no
error line, everything else works exactly the same. The NOTIFY buttons do not
depend on it.

## Configuration

At the top of `extensionstatus.php`:

| Setting | Default | Meaning |
| --- | --- | --- |
| `$es_refresh_seconds` | `30` | Auto-refresh interval |
| `$es_refresh_default_on` | `true` | Whether auto-refresh starts enabled |
| `$es_showdebug` | `false` | Append a dump of the raw AMI response |
| `$es_notify_target` | `'uri'` | `'uri'` for one handset, `'endpoint'` for the whole extension |
| `$es_access_logs` | `array('/var/log/httpd/access_log')` | Logs watched to confirm a check-sync landed; `array()` disables verification |
| `$es_verify_window` | `30` | Seconds to watch before reporting failure |
| `$es_notify_actions` | see file | Per-brand NOTIFY actions |
