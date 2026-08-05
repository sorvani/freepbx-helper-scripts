# freepbx-helper-scripts

Assorted scripts and files that work with FreePBX.

> [!IMPORTANT]
> **These target FreePBX 14/15/16 on Sangoma OS (CentOS).** FreePBX 17 moved to Debian,
> and several of these do not work there as-is. Ported versions live in their own repos:
>
> | If you are on FreePBX 17, use | Instead of |
> | --- | --- |
> | **[sorvani/freepbx17-phonebooks](https://github.com/sorvani/freepbx17-phonebooks)** | the five phonebook / address book generators below |
> | **[sorvani/freepbx17-yealink-backup](https://github.com/sorvani/freepbx17-yealink-backup)** | [Backup_Yealink_Local_Contacts](Backup_Yealink_Local_Contacts/) |
> | **[sorvani/freepbx17-extension-status](https://github.com/sorvani/freepbx17-extension-status)** | [Extension_Status](Extension_Status/) |
> | **[sorvani/freepbx17-initial-setup-scripts](https://github.com/sorvani/freepbx17-initial-setup-scripts)** | [InitialSetup](InitialSetup/) |
>
> Anything that loads `/etc/freepbx.conf` hits the same FreePBX 17 problem: the bootstrap
> runs the GUI auth layer, which wants the admin session a script fetched by a phone will
> never have. See [#25](https://github.com/sorvani/freepbx-helper-scripts/issues/25) for
> the detail and the one-line fix.

## Phone directories

| Folder | What it does | On FreePBX 17 |
| --- | --- | --- |
| [ContactManager_to_Yealink_AddressBook](ContactManager_to_Yealink_AddressBook/) | Contact Manager group → Yealink remote address book | [ported](https://github.com/sorvani/freepbx17-phonebooks) |
| [ContactManager_to_Fanvil_AddressBook](ContactManager_to_Fanvil_AddressBook/) | Contact Manager group → Fanvil remote address book | [ported](https://github.com/sorvani/freepbx17-phonebooks) |
| [ContactManager_to_Digium_AddressBook](ContactManager_to_Digium_AddressBook/) | Contact Manager group → Digium A-Series contacts | [ported](https://github.com/sorvani/freepbx17-phonebooks) |
| [Extensions_to_Yealink_AddressBook](Extensions_to_Yealink_AddressBook/) | System extensions → Yealink remote address book | [ported](https://github.com/sorvani/freepbx17-phonebooks) |
| [Extensions_to_Grandstream_Phonebook](Extensions_to_Grandstream_Phonebook/) | System extensions → Grandstream phonebook | [ported](https://github.com/sorvani/freepbx17-phonebooks) |
| [AsteriskDB_to_Yealink_AddressBook](AsteriskDB_to_Yealink_AddressBook/) | Astdb `/AMPUSER` entries → Yealink address book, in Python | untested |
| [George1421_Yealink_AddressBook](George1421_Yealink_AddressBook/) | Alternate Yealink address book generator, by George Kanicki | untested |

## Phone provisioning and control

| Folder | What it does | On FreePBX 17 |
| --- | --- | --- |
| [Backup_Yealink_Local_Contacts](Backup_Yealink_Local_Contacts/) | Lets Yealink phones PUT their local contacts and settings back into `/tftpboot` | [ported](https://github.com/sorvani/freepbx17-yealink-backup) |
| [Reload_Reboot_Yealink](Reload_Reboot_Yealink/) | Web page to reload or reboot Yealink phones over AMI — superseded by [Extension_Status](Extension_Status/), which does the same per handset, for more brands, with confirmation | superseded |
| [Example_Yealink_XML_Menu](Example_Yealink_XML_Menu/) | Sample XML menu and shared contacts for Yealink | static XML, unaffected |

## Other

| Folder | What it does | On FreePBX 17 |
| --- | --- | --- |
| [Extension_Status](Extension_Status/) | Admin page listing every PJSIP contact, with per-handset SIP NOTIFY buttons | [ported](https://github.com/sorvani/freepbx17-extension-status) |
| [Aastra_Button_Functions](Aastra_Button_Functions/) | Ring group membership page for Aastra phones | loads `freepbx.conf`, not ported |
| [Monitor_Trunk_Failure](Monitor_Trunk_Failure/) | AGI that emails when an outbound call fails to dial out over a trunk — hooks into the trunk's "Monitor Trunk Failures" field. Not trunk registration state | untested |
| [InitialSetup](InitialSetup/) | Post-install setup and update scripts for a new PBX — `yum`-based, Sangoma OS only | [ported](https://github.com/sorvani/freepbx17-initial-setup-scripts) |

"Untested" means exactly that — nobody has checked it on 17, not that it is known broken.

## Contributing

Issues and pull requests welcome. If you are reporting a bug, say which FreePBX
version and which PHP version you are on — most of what turns up here is one or
the other having moved underneath the script.

## License

GPL-3.0. See [LICENSE](LICENSE).
