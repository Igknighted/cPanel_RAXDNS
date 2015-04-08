#!/bin/bash
echo Removing RAXDNS scripts from your server. 
echo
echo 10 seconds to cancel if you wish.
echo
for i in {10..1}; do echo -n "$i .. "; sleep 1s; done

rm -rf /opt/raxdns
rm -rf /etc/cron.d/raxdns.cron
