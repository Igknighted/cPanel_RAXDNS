# RAXDNS for cPanel
This is just some simple scripts that will automatically sync records from your server to the Rackspace Cloud DNS via the API.

I wrote this because I use cPanel on a Rackspace Cloud Server and I've not been able to take advantage of their Cloud DNS product the way I want. Overall I just want to create an account in cPanel and I want it to just populate in Rackspace Cloud DNS. There were no plugins for this and I couldn't even write a plugin because cPanel has not implemented a documented way to hook into zone changes adequately. 


### Installation / Removal
To install, simply run this on your server:
```
wget https://raw.githubusercontent.com/Igknighted/cPanel_RAXDNS/master/setup.sh && bash setup.sh
```

If you ever want to remove it, the process is just as simple:
```
https://raw.githubusercontent.com/Igknighted/cPanel_RAXDNS/master/remove.sh
```

### Beta Quality Notice
This was a script I threw together for my own server. It's barely beta quality work and it hasn't been as rigorously tested against API hiccups. If you encounter problems, you will likely need to do some experimenting and contribute to the source code. 
I've implemented a --force-all flag that will re-try to sync over all the records. This should be your first troubleshooting step:
```
php /opt/raxdns/raxdns.php --force-all
```

The script will only sync over the following record types and will not delete any other type of record. If you need to create a different type of record, it will need to be manually done.
```
MX
A/AAAA
CNAME
NS
TXT
```

### Questions and Support
Your support services are brought to you by none other than yourself! However feel free to let me know if you have any questions at sam.igknighted@gmail.com
