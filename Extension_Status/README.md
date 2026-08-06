# Extension Status

An admin-only page listing every PJSIP contact: extension, name, device
brand/model/firmware parsed out of the User-Agent, transport, status, round-trip
time, known device IPs, and registration expiry. Handsets that support it get
per-contact SIP NOTIFY buttons to reload config, reboot, or factory reset.

Sortable, filterable, and auto-refreshing.

Backported from
**[sorvani/freepbx17-extension-status](https://github.com/sorvani/freepbx17-extension-status)**,
which is the same page for FreePBX 17.

> [!IMPORTANT]
> **This targets FreePBX 14/15/16 on Sangoma OS (CentOS 7).** FreePBX 17 is
> Debian with PHP 8 and a different Apache layout — use
> **[freepbx17-extension-status](https://github.com/sorvani/freepbx17-extension-status)**
> there instead. The installers refuse to run on the wrong platform, in both
> directions.

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

The installer does three things: copies the files, makes sure Asterisk can
actually send the NOTIFYs the buttons trigger, and grants the one log read that
lets the page confirm a handset acted. The last two are on by default — the page
is misleading without the first and blind without the second.

If the page is already open in a browser when you re-run the installer,
**force-reload it** (Ctrl-Shift-R). The old tab keeps running the old
JavaScript.

### `default_outbound_endpoint` — required

The page addresses a NOTIFY to the **contact URI**, so an extension registered
on both a desk phone and a softphone only gets it on the handset whose row you
clicked. Asterisk routes a bare-URI NOTIFY through pjsip's
`default_outbound_endpoint`.

**Older FreePBX systems do not set one, and Asterisk does not tell you.** AMI
answers `Success: NOTIFY sent`, nothing goes out, and the only trace is a line
in `/var/log/asterisk/full`:

```
WARNING res_pjsip_notify.c: No default outbound endpoint set, can not send
NOTIFY requests to arbitrary URIs.
```

The button reports success and the phone never moves. So the installer requires
it, and prompts when it is missing:

```
==> no usable default_outbound_endpoint
    pjsip reports 'default_outbound_endpoint', but no endpoint by that name exists.
    ('default_outbound_endpoint' is Asterisk's own placeholder default.)
    This is required. Until it is set, every NOTIFY button on the page
    reports success and does nothing.

    Registered endpoints to choose from:
      201
      202
      203

    Extension to use: 201
==> setting default_outbound_endpoint=201 in /etc/asterisk/pjsip_custom_post.conf
==> fwconsole reload
    confirmed: default_outbound_endpoint = 201
```

It re-asks until you give an extension that exists. Any registered one works —
it only supplies the `From` header on outbound NOTIFYs, and is not called, rung
or otherwise disturbed.

Answer ahead of time, or run non-interactively, with:

```bash
DEFAULT_OUTBOUND_ENDPOINT=201 sudo -E ./install.sh
```

Note the global is never empty. Asterisk defaults it to the literal string
`default_outbound_endpoint`, which is a *name*, not a configured endpoint — so
"set" and "usable" are separate questions and the installer asks both.

The setting is written to `/etc/asterisk/pjsip_custom_post.conf`. That file is
`#include`d from **inside** pjsip.conf's `[global]` section — verified on both
15 and 16 — so a bare `key=value` lands as a global option and needs no section
header of its own. `fwconsole reload` preserves it.

### Verification — on by default

A check-sync makes the handset re-read its provisioning files, which lands in
the Apache access log. After a button click the row shows `Verifying…` and polls
for that fetch, then reports `✓ Config fetched 18:18:46` or, after 30s,
`✕ No config fetch in 30s`. That is the only end-to-end confirmation available:
Asterisk reports a NOTIFY *dispatched*, never that the phone acted on it. The
log is read only after a click — never on page load or auto-refresh.

This needs the web server to be able to read the log, and the two test boxes
differed, which is why the page also decides at runtime:

| | `/var/log/httpd` | readable by `asterisk`? |
| --- | --- | --- |
| FreePBX 15 box | `root:root 0700` | no — needs the grant |
| FreePBX 16 box | `asterisk:asterisk 0700` | yes, already |

The installer grants it with an ACL — `asterisk` gets traverse on the directory
and read on that one file, nothing else:

```bash
setfacl -m u:asterisk:x /var/log/httpd
setfacl -m u:asterisk:r /var/log/httpd/access_log
```

and adds a `postrotate` hook to `/etc/logrotate.d/httpd` so it survives
rotation. It falls back to `chgrp asterisk` + `chmod 750` on boxes with no
usable ACL support, and says so — that version grants read on *every* file in
`/var/log/httpd`, `error_log` included.

To decline entirely:

```bash
ENABLE_VERIFY_LOG=0 sudo -E ./install.sh
```

Nothing is touched, and the page detects that no log is readable and simply
omits the check — no warning, no error line. The NOTIFY buttons do not depend
on it.

**Verification only works if your phones provision over HTTP(S) from Apache.**
Endpoint Manager over TFTP leaves no access-log trail, so there is nothing to
find and the check always times out. If a provisioning vhost on another port
writes its own `CustomLog`, add it to `$es_access_logs`; the page scans all of
them.

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

Earlier versions shipped a separate `extensionstatus_header.php`. The rewrite
folded it in; the installer renames any leftover copy aside.

`/var/www/html/custom/` is the right home for these: it is served by the admin
vhost and FreePBX never writes there, so upgrades leave it alone.

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
each entry notes the `sip_notify_*.conf` section it mirrors. The AMI user needs
the `system` privilege, which FreePBX grants by default.

Asterisk reports success as soon as it dispatches the NOTIFY, including to a URI
nothing is listening on. A success message means dispatched, not delivered —
which is what the verification above is for.

### Addressing: one handset or the whole extension

`$es_notify_target` decides how a NOTIFY is aimed:

| | Reaches | Needs |
| --- | --- | --- |
| `'uri'` (default) | only the handset whose row you clicked | a usable `default_outbound_endpoint` |
| `'endpoint'` | **every** device registered to that extension | nothing |

URI mode carries the default outbound endpoint's identity in the `From` header
rather than the extension's. Everything else about the two packets is identical
and the phone returns 200 OK immediately either way.

The pill in the toolbar and the confirmation dialog both state which mode is
active, so the blast radius is on screen before the click.

## Configuration

At the top of `extensionstatus.php`:

| Setting | Default | Meaning |
| --- | --- | --- |
| `$es_refresh_seconds` | `30` | Auto-refresh interval |
| `$es_refresh_default_on` | `true` | Whether auto-refresh starts enabled |
| `$es_showdebug` | `false` | Append a dump of the raw AMI response |
| `$es_notify_target` | `'uri'` | `'uri'` for one handset, `'endpoint'` for the whole extension |
| `$es_access_logs` | `array('/var/log/httpd/access_log')` | Logs watched to confirm a check-sync landed; `array()` disables verification |
| `$es_verify_window` | `150` | Seconds to watch before reporting failure |
| `$es_state_file` | `/var/lib/asterisk/extensionstatus-devices.json` | Remembers seen devices so one that drops off shows as Unregistered; `''` disables |
| `$es_retain_days` | `7` | Forget a remembered device unseen for this long |
| `$es_no_action_models` | see file | Models carrying a hardware brand's name that are really softphones |
| `$es_notify_actions` | see file | Per-brand NOTIFY actions |

Installer environment variables:

| Variable | Default | Meaning |
| --- | --- | --- |
| `DEST` | `/var/www/html/custom` | Where the three files go |
| `DEFAULT_OUTBOUND_ENDPOINT` | *(prompted)* | Answers the prompt; required for a non-interactive run |
| `ENABLE_VERIFY_LOG` | `1` | `0` declines the access-log read |
| `ACCESS_LOG` | `/var/log/httpd/access_log` | Log to grant read on |
| `LOGROTATE_CONF` | `/etc/logrotate.d/httpd` | Where the postrotate hook goes |

## Differences from the FreePBX 17 version

Same page, same three-file layout, same features. What the backport had to
change, against
[freepbx17-extension-status](https://github.com/sorvani/freepbx17-extension-status):

| | FreePBX 17 (Debian) | here (Sangoma OS) |
| --- | --- | --- |
| PHP | 8.2 | 5.6 / 7.4 |
| Apache | `apache2`, `apache2ctl` | `httpd` |
| mod_php | `php_module` | `php5_module` / `php7_module` |
| Access log | `/var/log/apache2/other_vhosts_access.log` | `/var/log/httpd/access_log` |
| Log grant | `chgrp` + `chmod` | ACL, group only as fallback |
| Log setting | `$es_access_log`, one path | `$es_access_logs`, a list |
| Verification | opt in, `ENABLE_VERIFY_LOG=1` | on by default, `=0` to decline |
| Outbound endpoint | assumed present | checked, and prompted for when missing |

The PHP itself is written to a 5.6 floor: no `??`, no `random_bytes()`, and no
`str_starts_with()` — the last is PHP 8.0, so it is unavailable on *both* 5.6
and 7.4 and had to go entirely. `es_get()`, `es_csrf_token()` and
`es_starts_with()` stand in. Two more 5.6-specific fixes have no counterpart
upstream: JSON floats serialise at precision 17 there, so a round-trip time
would reach the browser as `20.199999999999999`, and an invalid UTF-8 byte in a
User-Agent makes `json_encode()` return `false`, which would blank the page.
