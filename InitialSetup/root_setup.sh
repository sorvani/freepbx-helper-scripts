#!/bin/bash

# Prompt for a username and password
# TODO: change this to a parameter?
read -p "Enter a new username to use for SSH access: " myUserName

# Create user account with default password of ChangeMe
useradd --create-home $myUserName --password $(openssl passwd -1 ChangeMe)
# expire the password to force reset on first login
chage -d 0 $myUserName
# Add user to wheel and asterisk groups
gpasswd -a $myUserName wheel
gpasswd -a $myUserName asterisk

# Disable root login via ssh
echo "Disabling root login to SSH."
sed -i 's/#\?\(PerminRootLogin\s*\).*$/\1 no/' /etc/ssh/sshd_config

# Restart SSH service to apply changes
systemctl restart sshd

# Pre download the setup script into the user's home directory.
wget -O /home/$myUserName/setup.sh https://raw.githubusercontent.com/sorvani/freepbx-helper-scripts/master/InitialSetup/setup.sh
chown $myUserName:$myUserName /home/$myUserName/setup.sh
chmod +x /home/$myUserName/setup.sh

echo "Root setup complete."
echo "Please log out from the root user and login, via SSH, with username: $myUserName."
echo "You will be required to change your password. It is currently set to: ChangeMe"
echo "Then execute 'sudo ./setup.sh'"
