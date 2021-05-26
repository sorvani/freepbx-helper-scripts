To "install" this, you simply need to copy the files to a directory on your FreePBX system.

I like to use `/var/ww/html/custom` for anything I add to FreepBX.

Here are the commands to run to pull it down from here.

```
sudo mkdir -p /var/www/html/custom
cd /var/www/html/custom
sudo wget https://github.com/sorvani/freepbx-helper-scripts/raw/Extension_Status/extensionstatus.php
sudo wget https://github.com/sorvani/freepbx-helper-scripts/raw/Extension_Status/templates/extensionstatus_header.php
sudo chown -R asterisk:asterisk /var/www/html/custom
cd ~
```
