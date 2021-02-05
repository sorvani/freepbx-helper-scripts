To "install" this, you simply need to copy the files to a directory on your FreePBX system.

I like to use `/var/ww/html/custom` for anything I add to FreepBX.

Here are the commands to run to pull it down from here.

```
sudo mkdir -p /var/www/html/custom/templates
cd /var/www/html/custom
wget https://github.com/sorvani/freepbx-helper-scripts/raw/extensions_status/Extension_Status/extensionstatus.php
cd templates
wget https://github.com/sorvani/freepbx-helper-scripts/raw/extensions_status/Extension_Status/templates/contactfoot.php
wget https://github.com/sorvani/freepbx-helper-scripts/raw/extensions_status/Extension_Status/templates/contacthead.php
sudo chown -R asterisk:asterisk /var/www/html/custom
cd ~
```
