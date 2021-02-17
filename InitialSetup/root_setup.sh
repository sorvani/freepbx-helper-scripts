#!/bin/bash

# Prompt for a username and password
# Possibly change this to a parameter
read -p "Enter a new username to use for SSH access: " myUserName

# Create user account with default password of ChangeMe
echo "Creating the user $myUserName and assigning permissions"
echo ""
useradd --create-home $myUserName --password $(openssl passwd -1 ChangeMe) >> setup.log
# expire the password to force reset on first login
chage -d 0 $myUserName >> setup.log
# Add user to wheel and asterisk groups
gpasswd -a $myUserName wheel >> setup.log
gpasswd -a $myUserName asterisk >> setup.log

# Disable root login via ssh
echo "Disabling root login to SSH."
echo ""
sed -i 's/#\?\(PerminRootLogin\s*\).*$/\1 no/' /etc/ssh/sshd_config >> setup.log

# Restart SSH service to apply changes
echo "Restarting the SSH service"
echo ""
systemctl restart sshd >> setup.log

# Pre download the setup script into the user's home directory.
echo "Downloading the main setup script"
echo ""
wget -O /home/$myUserName/setup.sh https://raw.githubusercontent.com/sorvani/freepbx-helper-scripts/master/InitialSetup/setup.sh >> setup.log
echo "Downloading the upgrade script"
echo ""
wget -O /home/$myUserName/update.sh https://raw.githubusercontent.com/sorvani/freepbx-helper-scripts/master/InitialSetup/update.sh >> setup.log
chown $myUserName:$myUserName /home/$myUserName/setup.sh >> setup.log
chmod +x /home/$myUserName/setup.sh >> setup.log
chmod +x /home/$myUserName/update.sh >> setup.log


ipaddress=`ifconfig | grep -Eo 'inet (addr:)?([0-9]*\.){3}[0-9]*' | grep -Eo '([0-9]*\.){3}[0-9]*' | grep -v '127.0.0.1'`

echo "Root setup complete."
echo "Please log out from the root user and login, via SSH to $ipaddress or the FQDN you have setup, with username: $myUserName."
echo "You will be required to change your password. It is currently set to: ChangeMe"
echo ""
echo "Then execute 'sudo ./setup.sh'"
