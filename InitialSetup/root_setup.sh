#!/bin/bash

# Prompt for a username and password
# TODO: change this to a parameter?
read -p "Enter a new username: " myUserName

# Prompt for your GitLab username
# TODO: update this to something intelligent....
read -p "Enter your GitLab username: " myGitLabUsername

# Create user account with default password of ChangeMe
useradd --create-home $myUserName --password $(openssl passwd -1 ChangeMe)
# expire the password to force reset on first login
chage -d 0 $myUserName
# Add user to wheel and asterisk groups
gpasswd -a $myUserName wheel
gpasswd -a $myUserName asterisk

# Create .ssh directory, add authorized_keys file, set permissions
mkdir /home/$myUserName/.ssh
wget -O /home/$myUserName/.ssh/authorized_keys https://gitlab.com/$myGitLabUsername/public_keys/-/raw/master/authorized_keys
chown -R $myUserName:$myUserName /home/$myUserName/.ssh
chmod 700 /home/$myUserName/.ssh
chmod 600 /home/$myUserName/.ssh/authorized_keys

# Disable root login via ssh
sed -i 's/#\?\(PerminRootLogin\s*\).*$/\1 no/' /etc/ssh/sshd_config
# Require key for ssh login
# Ends up with duplicate PasswordAuthentication because it modifies both #PasswordAuthentication and PasswordAuthentication
sed -i 's/#\?\(PasswordAuthentication\s*\).*$/\1 no/' /etc/ssh/sshd_config

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
