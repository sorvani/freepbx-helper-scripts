#!/bin/bash
logdate=`date +"%Y%m%d-%H%M%S"`
logfile=setup-$logdate.log
printf "Beginning update of FreePBX 15...\n" | tee -a $logfile
if [ "$EUID" -ne 0 ]
  then printf "This script must be executed with sudo. Please run again: sudo ./update.sh\n" | tee -a $logfile
  exit
fi

printf "Updating operating system.\n" | tee -a $logfile
printf "This can take a seemingly excessive amount of time depending on\n" | tee -a $logfile
printf "how many Linux updates have been released since the last update.\n" | tee -a $logfile
yum update -y | tee -a $logfile

printf "Upgrading all installed modules...\n" | tee -a $logfile
printf "This can also take a seemingly excessive amount of time depending on\n" | tee -a $logfile
printf "how many module updates have been released since the update.\n" | tee -a $logfile
fwconsole ma upgradeall 2> /dev/null >> $logfile

printf "Updating all permissions...\n" | tee -a $logfile
fwconsole chown 2> /dev/null | tee -a $logfile

printf "Reloading FreePBX...\n" | tee -a $logfile
fwconsole reload >> $logfile

printf "Your FreePBX system has been updated. It is strongly recommended that you schedule a reboot.\n" | tee -a $logfile
