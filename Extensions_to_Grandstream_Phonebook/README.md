> [!IMPORTANT]
> **On FreePBX 17 (Debian), use [sorvani/freepbx17-phonebooks](https://github.com/sorvani/freepbx17-phonebooks) instead.**
> See [#25](https://github.com/sorvani/freepbx-helper-scripts/issues/25). When a phone fetches this
> script on 17, `/etc/freepbx.conf` runs the GUI auth layer, which wants the admin session a
> provisioning script never has — originally a fatal `Undefined variable $username` at
> `admin/libraries/gui_auth.php:21`. Current 17 builds no longer throw that, but only because
> authentication fails open: the script runs unauthenticated by accident rather than by design.
> The ported version sets `$bootstrap_settings['freepbx_auth'] = false` before the bootstrap, and
> also fixes the unreachable `DB::IsError()` check, the missing XML escaping, and the
> `<AddresBook>` typo in the newer-model output below.

# Extensions to Grandstream Phonebook

Reads every extension in the system and outputs it as a Grandstream Phonebook XML document.

Based on `Extensions_to_Yealink_AddressBook` and arielgrin's post in the FreePBX community:
https://community.freepbx.org/t/yealink-xml-phonebook-xml-auto-generation/51988/25

**Still a work in progress** — this has never been tested against real Grandstream
hardware. That remains true of the ported version.
