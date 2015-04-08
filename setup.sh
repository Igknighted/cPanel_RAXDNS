#!/bin/bash


read -p "Rackspace API Username: " USERNAME
read -p "Rackspace API Key:      " APIKEY

echo Switching your server over to mydns for RAXDNS. 
echo
echo 10 seconds to cancel if you wish.
echo
for i in {10..1}; do echo -n "$i .. "; sleep 1s; done


/scripts/setupnameserver mydns
/scripts/importmydnsdb --force
echo Waiting for MyDNS db import script to finish...
while [ -f /var/run/importmydnsdb.pid ]; do
	echo -n '.'
done
echo

/scripts/restartsrv_mydns


if [ ! -f raxdns.php ]; then
  echo Getting the RAXDNS files.
  mkdir -p /opt/raxdns
  cd /opt/raxdns
  wget https://raw.githubusercontent.com/Igknighted/cPanel_RAXDNS/master/raxdns.php
  wget https://raw.githubusercontent.com/Igknighted/cPanel_RAXDNS/master/raxdns.cron
  wget https://raw.githubusercontent.com/Igknighted/cPanel_RAXDNS/master/config.example.conf
  cp -vf config.example.conf  /opt/raxdns/config.conf
  chmod 600 /opt/raxdns/config.conf
else
  echo Moving RAXDNS files into place.
  mkdir -p /opt/raxdns/
  cp -vf config.example.conf  /opt/raxdns/config.conf
  chmod 600 /opt/raxdns/config.conf
  cp -vf raxdns.php /opt/raxdns/raxdns.php
  cp -vf raxdns.cron /etc/cron.d/raxdns.cron
fi


sed -i "s/USERNAME_HERE/$USERNAME/g" /opt/raxdns/config.conf
sed -i "s/API_KEY_HERE/$APIKEY/g" /opt/raxdns/config.conf

echo
echo
echo
echo First run of /opt/raxdns/raxdns.php
php /opt/raxdns/raxdns.php

echo
echo
echo Your current DNS records should now be synced over to the Rackspace Cloud DNS
echo Done now. Report any errors or problems to sam.igknighted@gmail.com
