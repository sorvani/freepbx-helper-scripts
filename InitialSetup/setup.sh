#!/bin/bash
logfile=setup.log
printf "Beginning FreePBX 15 initial setup...\n" | tee -a $logfile
if [ "$EUID" -ne 0 ]
  then printf "This script must be executed with sudo. Please run again: sudo ./setup.sh\n" | tee -a $logfile
  exit
fi

# Require key for ssh login
printf "Disabling password login to SSH.\n" | tee -a $logfile
# Ends up with duplicate PasswordAuthentication because it modifies both #PasswordAuthentication and PasswordAuthentication
sed -i 's/^#\?\(PasswordAuthentication\s*\).*$/\1no/' /etc/ssh/sshd_config | tee -a $logfile
systemctl restart sshd | tee -a $logfile

printf "Updating operating system.\n" | tee -a $logfile
printf "This can take a seemingly excessive amount of time depending on\n" | tee -a $logfile
printf "how many Linux updates have been released since the ISO was created.\n" | tee -a $logfile
yum update -y | tee -a $logfile
printf "Installing git\n" | tee -a $logfile
yum install git -y | tee -a $logfile
printf "Operating system upgrades completed.\n" | tee -a $logfile
printf "Removing FreePBX commerical modules, except sysadmin...\n" | tee -a $logfile
printf "Removing Oracle Connector\n" | tee -a $logfile
fwconsole ma delete oracle_connector >> $logfile
printf "Removing Advanced Recovery\n" | tee -a $logfile
fwconsole ma delete adv_recovery >> $logfile
printf "Removing Appointment Reminder\n" | tee -a $logfile
fwconsole ma delete areminder >> $logfile
printf "Removing Broadcast\n" | tee -a $logfile
fwconsole ma delete broadcast >> $logfile
printf "Removing Call Accounting\n" | tee -a $logfile
fwconsole ma delete callaccounting >> $logfile
printf "Removing CallerID Managment\n" | tee -a $logfile
fwconsole ma delete callerid >> $logfile
printf "Removing Outbound Call Limit\n" | tee -a $logfile
fwconsole ma delete calllimit >> $logfile
printf "Removing Conference Professional\n" | tee -a $logfile
fwconsole ma delete conferencespro >> $logfile
printf "Removing Extension Routes\n" | tee -a $logfile
fwconsole ma delete extensionroutes >> $logfile
printf "Removing Fax Configuration Professional\n" | tee -a $logfile
fwconsole ma delete faxpro >> $logfile
printf "Removing IoT Server\n" | tee -a $logfile
fwconsole ma delete iotserver >> $logfile
printf "Removing Paging Professional\n" | tee -a $logfile
fwconsole ma delete pagingpro >> $logfile
printf "Removing Parking Professional\n" | tee -a $logfile
fwconsole ma delete parkpro >> $logfile
printf "Removing Pinsets Professional\n" | tee -a $logfile
fwconsole ma delete pinsetspro >> $logfile
printf "Removing Property Management System\n" | tee -a $logfile
fwconsole ma delete pms >> $logfile
printf "Removing Queue Wallboard\n" | tee -a $logfile
fwconsole ma delete queuestats >> $logfile
printf "Removing Queue reports\n" | tee -a $logfile
fwconsole ma delete qxact_reports >> $logfile
printf "Removing Call Recording Report\n" | tee -a $logfile
fwconsole ma delete recording_report >> $logfile
printf "Removing Rest Apps\n" | tee -a $logfile
fwconsole ma delete restapps >> $logfile
printf "Removing Endpoint Manager\n" | tee -a $logfile
fwconsole ma delete endpoint >> $logfile
printf "Removing Sangoma CRM\n" | tee -a $logfile
fwconsole ma delete sangomacrm >> $logfile
printf "Removing SIPSTATION\n" | tee -a $logfile
fwconsole ma delete sipstation >> $logfile
printf "Removing Vega\n" | tee -a $logfile
fwconsole ma delete vega >> $logfile
printf "Removing Voicemail Notifications\n" | tee -a $logfile
fwconsole ma delete vmnotify >> $logfile
printf "Removing Voicemail Reports\n" | tee -a $logfile
fwconsole ma delete voicemail_report >> $logfile
printf "Removing Queues Professional\n" | tee -a $logfile
fwconsole ma delete vqplus >> $logfile
printf "Removing Web Callback\n" | tee -a $logfile
fwconsole ma delete webcallback >> $logfile
printf "Removing Zulu\n" | tee -a $logfile
fwconsole ma delete zulu >> $logfile
printf "Removing Sangoma Connect\n" | tee -a $logfile
fwconsole ma delete sangomaconnect >> $logfile
printf "Removing SMS\n" | tee -a $logfile
fwconsole ma delete sms >> $logfile
printf "Removing Class of Service\n" | tee -a $logfile
fwconsole ma delete cos >> $logfile
printf "Removing rarely needed Open Source modules\n" | tee -a $logfile
printf "Removing Answering Machine Detection\n" | tee -a $logfile
fwconsole ma delete amd >> $logfile
printf "Removing iSymphonyV3\n" | tee -a $logfile
fwconsole ma delete cxpanel >> $logfile
printf "Removing Digium Phones Config\n" | tee -a $logfile
fwconsole ma delete digium_phones >> $logfile
printf "Removing Digium Addons\n" | tee -a $logfile
fwconsole ma delete digiumaddoninstaller >> $logfile
printf "Removing Wake Up Calls\n" | tee -a $logfile
fwconsole ma delete hotelwakeup >> $logfile
printf "Removing IRC\n" | tee -a $logfile
fwconsole ma delete irc >> $logfile
printf "Removing DISA\n" | tee -a $logfile
fwconsole ma delete disa >> $logfile
printf "Removing PHP Info\n" | tee -a $logfile
fwconsole ma delete phpinfo >> $logfile
# printf "Removing Rest API\n" | tee -a $logfile
# fwconsole ma delete restapi >> $logfile
printf "Reloading FreePBX...\n" | tee -a $logfile
fwconsole reload >> $logfile
printf "Enabling commerical repository...\n" | tee -a $logfile
fwconsole ma enablerepo commercial >> $logfile
printf "Upgrading all installed modules...\n" | tee -a $logfile
printf "This can also take a seemingly excessive amount of time depending on\n" | tee -a $logfile
printf "how many module updates have been released since the ISO was created.\n" | tee -a $logfile
fwconsole ma upgradeall 2> /dev/null >> $logfile
printf "Updating all permissions...\n" | tee -a $logfile
fwconsole chown 2> /dev/null | tee -a $logfile
printf "Reloading FreePBX...\n" | tee -a $logfile
fwconsole reload >> $logfile
printf "Your initial FreePBX command line setup is complete.\n" | tee -a $logfile
ipaddress=`ifconfig | grep -Eo 'inet (addr:)?([0-9]*\.){3}[0-9]*' | grep -Eo '([0-9]*\.){3}[0-9]*' | grep -v '127.0.0.1'`
printf "Please reboot and then navigate to https://$ipaddress or https://YOURFQDN to complete your setup.\n" | tee -a $logfile
