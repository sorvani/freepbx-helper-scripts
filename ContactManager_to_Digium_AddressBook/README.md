> [!IMPORTANT]
> **On FreePBX 17 (Debian), use [sorvani/freepbx17-phonebooks](https://github.com/sorvani/freepbx17-phonebooks) instead.**
> See [#25](https://github.com/sorvani/freepbx-helper-scripts/issues/25). When a phone fetches this
> script on 17, `/etc/freepbx.conf` runs the GUI auth layer, which wants the admin session a
> provisioning script never has — originally a fatal `Undefined variable $username` at
> `admin/libraries/gui_auth.php:21`. Current 17 builds no longer throw that, but only because
> authentication fails open: the script runs unauthenticated by accident rather than by design.
> The ported version sets `$bootstrap_settings['freepbx_auth'] = false` before the bootstrap, and
> also replaces the unreachable `DB::IsError()` check with a real one.

# ContactManager to Digium A-Series Address Book

Reads a Contact Manager group and outputs it as a Digium A-Series contacts XML document.

XML format: https://wiki.asterisk.org/wiki/display/DIGIUM/A-Series+Contacts

The Digium format has no per-number labels, so unlike the Yealink and Fanvil
versions there is nothing to customize there.
