#!/bin/bash
# Disable root login via ssh
echo "Disabling password login to SSH."
# Require key for ssh login
# Ends up with duplicate PasswordAuthentication because it modifies both #PasswordAuthentication and PasswordAuthentication
sed -i 's/#\?\(PasswordAuthentication\s*\).*$/\1 no/' /etc/ssh/sshd_config

# Restart SSH service to apply changes
systemctl restart sshd

echo "Beginning FreePBX 15 initial setup..."
echo "Updating operating system"
yum update -y
echo "Installing git"
yum install git -y

echo "Removing commerical modules, except sysadmin..."
echo "Removing Oracle Connector"
fwconsole ma delete oracle_connector
echo "Removing Advanced Recovery"
fwconsole ma delete adv_recovery
echo "Removing areminder"
fwconsole ma delete areminder
echo "Removing broadcast"
fwconsole ma delete broadcast
echo "Removing callaccounting"
fwconsole ma delete callaccounting
echo "Removing callerid"
fwconsole ma delete callerid
echo "Removing calllimit"
fwconsole ma delete calllimit
echo "Removing conferencespro"
fwconsole ma delete conferencespro
echo "Removing extensionroutes"
fwconsole ma delete extensionroutes
echo "Removing faxpro"
fwconsole ma delete faxpro
echo "Removing iotserver"
fwconsole ma delete iotserver
echo "Removing pagingpro"
fwconsole ma delete pagingpro
echo "Removing parkpro"
fwconsole ma delete parkpro
echo "Removing pinsetpro"
fwconsole ma delete pinsetspro
echo "Removing pms"
fwconsole ma delete pms
echo "Removing queueustats"
fwconsole ma delete queuestats
echo "Removing qxact_reports"
fwconsole ma delete qxact_reports
echo "Removing recording_report"
fwconsole ma delete recording_report
echo "Removing restapps"
fwconsole ma delete restapps
echo "Removing endpoint"
fwconsole ma delete endpoint
echo "Removing sangomacrm"
fwconsole ma delete sangomacrm
echo "Removing sipstation"
fwconsole ma delete sipstation
echo "Removing vega"
fwconsole ma delete vega
echo "Removing vmnotify"
fwconsole ma delete vmnotify
echo "Removing voicemail_report"
fwconsole ma delete voicemail_report
echo "Removing vqplus"
fwconsole ma delete vqplus
echo "Removing webcallback"
fwconsole ma delete webcallback
echo "Removing zulu"
fwconsole ma delete zulu
echo "Removing sms"
fwconsole ma delete sms
echo "Removing cos"
fwconsole ma delete cos
echo "Removing rarely needed OpenSource modules"
echo "Removing cxpanel"
fwconsole ma delete cxpanel
echo "Removing digium_phones"
 fwconsole ma delete digium_phones
echo "Removing digiumaddoninstaller"
fwconsole ma delete digiumaddoninstaller
echo "Removing irc"
fwconsole ma delete irc
echo "Reloading FreePBX..."
fwconsole reload
echo "Enabling commerical repository..."
fwconsole ma enablerepo commercial
echo "Upgrading all installed modules..."
fwconsole ma upgradeall
echo "Reloading FreePBX..."
fwconsole reload
echo "Your initial FreePBX command line setup is complete."
ipaddress=`ifconfig | grep -Eo 'inet (addr:)?([0-9]*\.){3}[0-9]*' | grep -Eo '([0-9]*\.){3}[0-9]*' | grep -v '127.0.0.1'`
echo "Please navigate to https://$ipaddress or https://YOURFQDN to complete your setup."
