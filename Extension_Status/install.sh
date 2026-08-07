#!/bin/bash
# Install the Extension Status page on a FreePBX 14/15/16 (Sangoma OS / CentOS 7)
# system. Idempotent: safe to re-run after a git pull.
#
# FreePBX 17 is Debian and needs the other repo:
#   https://github.com/sorvani/freepbx17-extension-status
#
# Two things beyond copying files, both on by default because the page is
# misleading without them:
#
#   default_outbound_endpoint   required, prompted for when missing. Without it
#                               every NOTIFY button reports success and does
#                               nothing.
#   access log read             lets the page confirm a handset acted on a
#                               check-sync. ENABLE_VERIFY_LOG=0 to decline.

set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# /var/www/html/custom is the conventional home for hand-added scripts on
# FreePBX: it is served by the admin vhost and FreePBX itself never writes
# there, so an upgrade will not clobber it. Override with DEST=... ./install.sh
DEST="${DEST:-/var/www/html/custom}"

# Verification is ON by default. It needs the web server to be able to read the
# Apache access log, which is a permission change -- decline with
# ENABLE_VERIFY_LOG=0 ./install.sh
ENABLE_VERIFY_LOG="${ENABLE_VERIFY_LOG:-1}"
ACCESS_LOG="${ACCESS_LOG:-/var/log/httpd/access_log}"
LOGROTATE_CONF="${LOGROTATE_CONF:-/etc/logrotate.d/httpd}"

# Answers the default_outbound_endpoint prompt ahead of time, which is also how
# to run this non-interactively:  DEFAULT_OUTBOUND_ENDPOINT=201 ./install.sh
DEFAULT_OUTBOUND_ENDPOINT="${DEFAULT_OUTBOUND_ENDPOINT:-}"
PJSIP_CUSTOM_POST="${PJSIP_CUSTOM_POST:-/etc/asterisk/pjsip_custom_post.conf}"

FILES=(
    extensionstatus.php
    extensionstatus.lib.php
    extensionstatus.view.php
)

die() { echo "ERROR: $*" >&2; exit 1; }
ast() { /usr/sbin/asterisk -rx "$*" 2>/dev/null || true; }

# ---------------------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------------------

[ "$(id -u)" -eq 0 ] || die "run me as root (sudo ./install.sh)"
[ -f /etc/freepbx.conf ] || die "/etc/freepbx.conf not found -- is this a FreePBX box?"

if command -v apache2ctl >/dev/null 2>&1; then
    die "this box has apache2ctl, so it is the Debian-based FreePBX 17 -- use
       https://github.com/sorvani/freepbx17-extension-status instead"
fi
[ -x /usr/sbin/httpd ] || die "/usr/sbin/httpd not found -- this script is for Sangoma OS / CentOS"

# The page is served by mod_php as the Apache user, which FreePBX sets to
# asterisk. Without mod_php Apache would hand out the PHP source instead.
# The module is php5_module on FreePBX 15 and php7_module on 16, so match both.
if ! /usr/sbin/httpd -M 2>/dev/null | grep -qE 'php[0-9]*_module'; then
    die "mod_php is not loaded in Apache -- 'httpd -M' shows no php*_module"
fi

# Read the actual user rather than assuming: everything installed below is
# owned by it, and it is the identity that has to read the access log.
HTTPD_CONF=/etc/httpd/conf/httpd.conf
WEBUSER="$(awk '$1=="User"  {print $2; exit}' "$HTTPD_CONF" 2>/dev/null || true)"
WEBGROUP="$(awk '$1=="Group" {print $2; exit}' "$HTTPD_CONF" 2>/dev/null || true)"
WEBUSER="${WEBUSER:-asterisk}"
WEBGROUP="${WEBGROUP:-asterisk}"
id -u "$WEBUSER" >/dev/null 2>&1 || die "web server user '$WEBUSER' does not exist"

# Everything below this point talks to Asterisk. Fail here with a clear reason
# rather than three steps later with an empty string.
[ -n "$(ast 'core show version')" ] \
    || die "Asterisk is not answering 'asterisk -rx' -- start it and re-run"

echo "==> web server runs as $WEBUSER:$WEBGROUP"

# ---------------------------------------------------------------------------
# The page itself
# ---------------------------------------------------------------------------

[ -d "$DEST" ] || { echo "==> creating $DEST"; install -d -o "$WEBUSER" -g "$WEBGROUP" -m 755 "$DEST"; }

for f in "${FILES[@]}"; do
    [ -f "$SRC/$f" ] || die "missing $SRC/$f"

    if [ -f "$DEST/$f" ] && ! cmp -s "$SRC/$f" "$DEST/$f"; then
        BACKUP="$DEST/$f.bak.$(date +%Y%m%d%H%M%S)"
        echo "==> backing up existing $f to $(basename "$BACKUP")"
        cp -a "$DEST/$f" "$BACKUP"
    fi

    echo "==> installing $DEST/$f"
    install -o "$WEBUSER" -g "$WEBGROUP" -m 644 "$SRC/$f" "$DEST/$f"
done

# Earlier releases shipped the table header as a separate include. The rewrite
# folded it in, so a leftover copy is dead weight.
if [ -f "$DEST/extensionstatus_header.php" ]; then
    echo "==> retiring obsolete extensionstatus_header.php"
    mv "$DEST/extensionstatus_header.php" \
       "$DEST/extensionstatus_header.php.obsolete.$(date +%Y%m%d%H%M%S)"
fi

# A syntax error would otherwise only show up as a blank page in the browser.
echo "==> php syntax check ($(php -r 'echo PHP_VERSION;'))"
for f in "${FILES[@]}"; do
    php -l "$DEST/$f" >/dev/null || die "$f failed php -l"
done
echo "    all ${#FILES[@]} files parse cleanly"

# ---------------------------------------------------------------------------
# default_outbound_endpoint -- required, not optional
#
# The page addresses a NOTIFY to the contact URI so it reaches one handset
# rather than every device on the extension. Asterisk routes a bare-URI NOTIFY
# through pjsip's default_outbound_endpoint, and when there isn't a usable one
# it does NOT report an error. AMI answers:
#
#     Response: Success
#     Message: NOTIFY sent
#
# ...and nothing is sent. The only trace is a line in the Asterisk log:
#
#     WARNING res_pjsip_notify.c: No default outbound endpoint set, can not
#     send NOTIFY requests to arbitrary URIs.
#
# Older FreePBX systems do not set it, so on those every button on the page
# silently does nothing. A page whose buttons lie is worse than no page, which
# is why this is required rather than offered.
#
# The global is never empty -- Asterisk defaults it to the literal string
# "default_outbound_endpoint", which is a name, not a configured endpoint. So
# "set" and "usable" are separate questions and both get asked below.
# ---------------------------------------------------------------------------

doe_current() {
    ast 'pjsip show settings' \
        | sed -n 's/^[[:space:]]*default_outbound_endpoint[[:space:]]*:[[:space:]]*\([^[:space:]]*\).*/\1/p' \
        | head -1
}

doe_usable() {
    local name="$1" out
    [ -n "$name" ] || return 1
    case "$name" in *[!A-Za-z0-9_.-]*) return 1 ;; esac
    out="$(ast "pjsip show endpoint $name")"
    [ -n "$out" ] && ! grep -qi 'Unable to find object' <<<"$out"
}

# Write the setting into pjsip_custom_post.conf.
#
# pjsip.conf #includes that file from INSIDE its [global] section -- verified
# on both FreePBX 15 and 16 -- so a bare key=value lands as a [global] option
# and must NOT be given a section header. If the file already has a section
# header of its own, the line goes in ahead of it, or it would land in that
# section instead.
doe_write() {
    local ext="$1" ts
    ts="$(date +%Y%m%d%H%M%S)"

    if [ ! -f "$PJSIP_CUSTOM_POST" ]; then
        install -o asterisk -g asterisk -m 664 /dev/null "$PJSIP_CUSTOM_POST"
    else
        cp -a "$PJSIP_CUSTOM_POST" "$PJSIP_CUSTOM_POST.bak.$ts"
        echo "    backed up to $(basename "$PJSIP_CUSTOM_POST").bak.$ts"
    fi

    awk -v line="default_outbound_endpoint=$ext" '
        function banner() {
            print "; Required for URI-mode SIP NOTIFY (custom/extensionstatus.php)."
            print "; Without it Asterisk answers \"Success: NOTIFY sent\" to a bare-URI"
            print "; PJSIPNotify and silently sends nothing."
            print ";"
            print "; pjsip.conf #includes this file INSIDE its [global] section, so a bare"
            print "; key=value here is a [global] option. Do NOT add a section header."
            print line
        }
        BEGIN { placed = 0 }
        # Drop any previous setting and the banner a previous run wrote; this
        # run is authoritative.
        /^[[:space:]]*default_outbound_endpoint[[:space:]]*=/ { next }
        /^; Required for URI-mode SIP NOTIFY/, /^; key=value here is a \[global\] option/ { next }
        /^[[:space:]]*\[/ && !placed { banner(); print ""; placed = 1 }
        { print }
        END { if (!placed) banner() }
    ' "$PJSIP_CUSTOM_POST" > "$PJSIP_CUSTOM_POST.new"

    mv "$PJSIP_CUSTOM_POST.new" "$PJSIP_CUSTOM_POST"
    chown asterisk:asterisk "$PJSIP_CUSTOM_POST"
    chmod 664 "$PJSIP_CUSTOM_POST"
}

DOE="$(doe_current)"
if doe_usable "$DOE"; then
    echo "==> default_outbound_endpoint = $DOE (exists) -- NOTIFY buttons will work"
else
    echo "==> no usable default_outbound_endpoint"
    if [ -n "$DOE" ]; then
        echo "    pjsip reports '$DOE', but no endpoint by that name exists."
        echo "    ('default_outbound_endpoint' is Asterisk's own placeholder default.)"
    fi
    echo "    This is required. Until it is set, every NOTIFY button on the page"
    echo "    reports success and does nothing -- Asterisk raises no error, only"
    echo "    a warning in /var/log/asterisk/full."

    if [ -n "$DEFAULT_OUTBOUND_ENDPOINT" ]; then
        WANT="$DEFAULT_OUTBOUND_ENDPOINT"
        doe_usable "$WANT" \
            || die "DEFAULT_OUTBOUND_ENDPOINT='$WANT' is not a pjsip endpoint on this system"
    elif [ -t 0 ]; then
        echo
        echo "    Registered endpoints to choose from:"
        ast 'pjsip show contacts' \
            | sed -n 's/^[[:space:]]*Contact:[[:space:]]*\([^/]*\)\/.*/\1/p' \
            | grep -vi 'aor' | sort -u | head -12 | sed 's/^/      /'
        echo
        echo "    Any registered extension works. It only supplies the From header"
        echo "    on outbound NOTIFYs; it is not called, rung or otherwise disturbed."
        echo
        while :; do
            read -r -p "    Extension to use: " WANT \
                || die "no answer given -- re-run with DEFAULT_OUTBOUND_ENDPOINT=<ext>"
            WANT="$(tr -d '[:space:]' <<<"${WANT:-}")"
            if [ -z "$WANT" ]; then
                echo "    Required -- there is no sensible default to fall back on."
            elif doe_usable "$WANT"; then
                break
            else
                echo "    No pjsip endpoint named '$WANT'. Try again."
            fi
        done
    else
        die "no usable default_outbound_endpoint and no terminal to ask on --
       re-run with DEFAULT_OUTBOUND_ENDPOINT=<ext>, e.g.
         DEFAULT_OUTBOUND_ENDPOINT=201 ./install.sh"
    fi

    echo "==> setting default_outbound_endpoint=$WANT in $PJSIP_CUSTOM_POST"
    doe_write "$WANT"
    echo "==> fwconsole reload"
    /usr/sbin/fwconsole reload || die "fwconsole reload failed -- check $PJSIP_CUSTOM_POST"
    NOW="$(doe_current)"
    [ "$NOW" = "$WANT" ] \
        || die "Asterisk still reports '$NOW' after the reload -- check $PJSIP_CUSTOM_POST"
    echo "    confirmed: default_outbound_endpoint = $NOW"
fi

# ---------------------------------------------------------------------------
# Let the web server read the access log, so a NOTIFY can be verified
#
# A check-sync makes the handset re-read its provisioning files, which lands in
# the access log. That is the only end-to-end confirmation available: Asterisk
# reports a NOTIFY dispatched, never that the phone acted on it.
# ---------------------------------------------------------------------------

if [ "$ENABLE_VERIFY_LOG" = "0" ]; then
    echo "==> NOTIFY verification declined (ENABLE_VERIFY_LOG=0), no permissions changed"
    echo "    Everything else works; the page just will not confirm that a"
    echo "    handset acted on a check-sync."
elif [ ! -f "$ACCESS_LOG" ]; then
    echo "==> WARNING: $ACCESS_LOG does not exist -- verification not enabled"
else
    echo "==> enabling NOTIFY verification (access log read for $WEBUSER)"
    LOGDIR="$(dirname "$ACCESS_LOG")"
    # On CentOS 7 /var/log/httpd is 0700 root:root, so the web user cannot even
    # traverse into it. An ACL grants exactly one user exactly one file;
    # widening the mode would hand over every log in the directory, error_log
    # and ssl_request_log included. Fall back to the group only if this box has
    # no working ACL support.
    if command -v setfacl >/dev/null 2>&1 \
       && setfacl -m "u:${WEBUSER}:x" "$LOGDIR" 2>/dev/null \
       && setfacl -m "u:${WEBUSER}:r" "$ACCESS_LOG" 2>/dev/null; then
        METHOD=acl
        echo "    ACL: $WEBUSER may traverse $LOGDIR and read $(basename "$ACCESS_LOG")"
    else
        chgrp "$WEBGROUP" "$LOGDIR" && chmod 750 "$LOGDIR"
        chgrp "$WEBGROUP" "$ACCESS_LOG" && chmod 640 "$ACCESS_LOG"
        METHOD=group
        echo "    NOTE: no usable ACL support, fell back to group $WEBGROUP."
        echo "          That grants read on EVERY file in $LOGDIR."
    fi

    # logrotate recreates the file as root:root 0644, which silently undoes the
    # file half of the above at the next rotation. The directory half survives,
    # so only the file needs re-applying.
    if [ -f "$LOGROTATE_CONF" ]; then
        if grep -q 'extensionstatus.php reads this log' "$LOGROTATE_CONF"; then
            echo "    logrotate hook already present"
        else
            cp -a "$LOGROTATE_CONF" "$LOGROTATE_CONF.bak.$(date +%Y%m%d%H%M%S)"
            if [ "$METHOD" = acl ]; then
                FIXUP1="setfacl -m u:${WEBUSER}:r ${ACCESS_LOG} 2>/dev/null || true"
                FIXUP2=""
            else
                FIXUP1="chgrp ${WEBGROUP} ${ACCESS_LOG} 2>/dev/null || true"
                FIXUP2="chmod 640 ${ACCESS_LOG} 2>/dev/null || true"
            fi
            awk -v fixup1="$FIXUP1" -v fixup2="$FIXUP2" '
                { lines[NR] = $0 }
                END {
                    last = 0
                    for (i = 1; i <= NR; i++) if (lines[i] ~ /^[ \t]*endscript/) last = i
                    for (i = 1; i <= NR; i++) {
                        if (i == last) {
                            print "\t\t# extensionstatus.php reads this log to confirm a check-sync landed"
                            print "\t\t" fixup1
                            if (fixup2 != "") print "\t\t" fixup2
                        }
                        print lines[i]
                    }
                }' "$LOGROTATE_CONF" > "$LOGROTATE_CONF.new"
            mv "$LOGROTATE_CONF.new" "$LOGROTATE_CONF"
            chown root:root "$LOGROTATE_CONF"
            chmod 644 "$LOGROTATE_CONF"
            logrotate -d "$LOGROTATE_CONF" >/dev/null 2>&1 \
                || die "logrotate rejected $LOGROTATE_CONF -- restore the .bak alongside it"
            echo "    logrotate postrotate hook added"
        fi
    fi

    # Say plainly whether it actually worked, rather than implying it did.
    if su -s /bin/bash -c "head -c 1 '$ACCESS_LOG' >/dev/null 2>&1" "$WEBUSER"; then
        echo "    verified: $WEBUSER can read $ACCESS_LOG"
    else
        echo "    WARNING: $WEBUSER still cannot read $ACCESS_LOG."
        echo "             The page will simply omit the verification step."
    fi
fi

cat <<EOF

Done. Open it while logged in to the FreePBX admin GUI:

  https://YOUR-PBX/custom/extensionstatus.php

The page refuses anything without an admin session. It exposes every
extension's public IP, device model and firmware, and it can reboot and
factory-reset handsets -- do not weaken that check.

If the page is already open in a browser, force-reload it (Ctrl-Shift-R).
The old page keeps running the old JavaScript otherwise.
EOF
