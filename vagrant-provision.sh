#!/usr/bin/env sh

# Make sure apt-git has a current list of repos.
sudo apt-get update

# Install any required packages.
sudo apt-get install -y php5 git

# Install Composer's dependencies.
cd /vagrant/
curl -sS https://getcomposer.org/installer | php
php composer.phar install


# Update the php.ini file as necessary: #

# Make sure phar.readonly is off.
sudo sed -i".bak" "s/^\;phar.readonly.*$/phar.readonly = Off/g" /etc/php5/cli/php.ini

# Make sure a timezone is set.
sudo sed -i "s/^\;date\.timezone.*$/date\.timezone = \"America\\/Chicago\" /g" /etc/php5/cli/php.ini
