#!/bin/bash
# Install the Extension Status page on a FreePBX 14/15/16 (Sangoma OS / CentOS 7)
# system. Idempotent: safe to re-run after a git pull.
#
# FreePBX 17 is Debian and needs the other repo:
#   https://github.com/sorvani/freepbx17-extension-status

set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# /var/www/html/custom is the conventional home for hand-added scripts on
# FreePBX: it is served by the admin vhost and FreePBX itself never writes
# there, so an upgrade will not clobber it. Override with DEST=... ./install.sh
DEST="${DEST:-/var/www/html/custom}"

# Opt in to the verification feature, which needs the web server to be able to
# read the Apache access log:  ENABLE_VERIFY_LOG=1 ./install.sh
# Off by default - it is a permission change, and the page works without it.
ENABLE_VERIFY_LOG="${ENABLE_VERIFY_LOG:-0}"
ACCESS_LOG="${ACCESS_LOG:-/var/log/httpd/access_log}"
LOGROTATE_CONF="${LOGROTATE_CONF:-/etc/logrotate.d/httpd}"

FILES=(
    extensionstatus.php
    extensionstatus.lib.php
    extensionstatus.view.php
)

die() { echo "ERROR: $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "run me as root (sudo ./install.sh)"
[ -f /etc/freepbx.conf ] || die "/etc/freepbx.conf not found -- is this a FreePBX box?"

if command -v apache2ctl >/dev/null 2>&1; then
    die "this box has apache2ctl, so it is the Debian-based FreePBX 17 -- use
       https://github.com/sorvani/freepbx17-extension-status instead"
fi
HTTPD=/usr/sbin/httpd
[ -x "$HTTPD" ] || die "$HTTPD not found -- this script is for Sangoma OS / CentOS"

# The page is served by mod_php as the Apache user, which FreePBX sets to
# asterisk. Without mod_php Apache would hand out the PHP source instead.
# The module is php5_module on FreePBX 15 and php7_module on 16, so match both.
MODULES="$("$HTTPD" -M 2>/dev/null || true)"
if ! grep -qE 'php[0-9]*_module' <<<"$MODULES"; then
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
echo "==> web server runs as $WEBUSER:$WEBGROUP"

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
# Use the same PHP the web server runs, not whatever is first on root's PATH.
echo "==> php syntax check ($(php -r 'echo PHP_VERSION;'))"
for f in "${FILES[@]}"; do
    php -l "$DEST/$f" >/dev/null || die "$f failed php -l"
done
echo "    all ${#FILES[@]} files parse cleanly"

# ---------------------------------------------------------------------------
# Optional: let the web server read the access log, so a NOTIFY can be verified
# ---------------------------------------------------------------------------
if [ "$ENABLE_VERIFY_LOG" = "1" ]; then
    echo "==> enabling NOTIFY verification (access log read for $WEBUSER)"
    if [ ! -f "$ACCESS_LOG" ]; then
        echo "    WARNING: $ACCESS_LOG does not exist -- skipping"
    else
        LOGDIR="$(dirname "$ACCESS_LOG")"
        # On CentOS 7 /var/log/httpd is 0700 root:root, so the web user cannot
        # even traverse into it. An ACL grants exactly one user exactly one
        # file; widening the mode would hand over every log in the directory,
        # error_log and ssl_request_log included. Fall back to the group only
        # if this box has no working ACL support.
        METHOD=""
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
            echo "    $ACCESS_LOG is now $(stat -c '%U:%G %a' "$ACCESS_LOG")"
        fi

        # logrotate recreates the file as root:root 0644, which silently undoes
        # the file half of the above at the next rotation. The directory half
        # survives, so only the file needs re-applying.
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
else
    echo "==> NOTIFY verification not enabled (no permission changes made)"
    echo "    the page works fully without it; it just will not confirm that a"
    echo "    handset acted on a check-sync. To enable:"
    echo "      ENABLE_VERIFY_LOG=1 ./install.sh"
fi

cat <<EOF

Done. Open it while logged in to the FreePBX admin GUI:

  https://YOUR-PBX/custom/extensionstatus.php

The page refuses anything without an admin session. It exposes every
extension's public IP, device model and firmware, and it can reboot and
factory-reset handsets -- do not weaken that check.
EOF
