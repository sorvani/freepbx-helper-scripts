Simple script that handles PUT requests from Yealink phones in order to let you backup the local contact directory to the FreePBX `/tftpboot` via HTTP(S).

1. Download and copy the `put.yealink` file to the `/tftfpboot` directory
2. Give Asterisk ownership of the file `chown asterisk:asterisk /tftpboot/put.yealink`
3. Download and copy the `yealink.conf` file to the `/etc/httpd/conf.d` directory
4. Restart Apache `systemctl restart httpd`
5. Edit your provisioning file to enable the remote backup `static.auto_provision.local_contact.backup.enable= 1`
6. Edit your provisioning file with the URL `static.auto_provision.local_contact.backup.path = `
   1. HTTPS: `https://pbx.domain.com:1443`
   2. HTTP: `http://pbx.domain.com:84`
   3. Or it could include a username and password `https://123456:0987654321@pbx.domain.com:1443`
   4. Or if you have the Commerical EPM use this in basfile edit: `__provisionAddress__`
7. Reprovision or reboot your phone to pick up the change
8. Edit your local address once to force it to upload the file

For troubleshooting, errors will log the variable contents to the the apache error_log.

This has been expanded to also allow the mac-local.cfg to be pushed back up.

To start, follow steps 1-4 above if you have not already done it for the contact backup.
5. Edit your common.cfg provisioning file (the "y" file) to enable the remote backup
   1. `static.auto_provision.custom.protect = 1`
   2. `static.auto_provision.custom.sync = 1`
   3. `static.auto_provision.custom.upload_method = 0`
6. Edit your provisioning file with the URL `static.auto_provision.custom.sync.path = `
   1. HTTPS: `https://pbx.domain.com:1443`
   2. HTTP: `http://pbx.domain.com:84`
   3. Or it could include a username and password 
      1. Inline `https://123456:0987654321@pbx.domain.com:1443`
      2. As its own parameters
         1. `static.auto_provision.server.username = USERNAME`
         2. `static.auto_provision.server.password = PASSWORD`
   4. Or if you have the Commerical EPM use this in basfile edit: `__provisionAddress__`
   
   
Discussion:
https://www.mangolassi.it/topic/19086/how-to-backup-your-yealink-local-contacts-to-the-freepbx-provisioning-directory
https://www.mangolassi.it/topic/19865/how-to-tell-yealink-phones-to-upload-user-changes-to-the-freepbx-provisioning-directory

