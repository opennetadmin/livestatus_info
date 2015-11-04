livestatus plugin
-----------------

This plugin uses the excellent livestatus http://mathias-kettner.de/checkmk_livestatus.html Nagios extension.
Other projects such as Icinga and Shinken have livestatus capabilities as well and this should work with them but that has not yet been tested.

The goal is to use livestatus to gather and display basic service status from devices in your environment.
You will have the ability to Acknowledge, disable alerts, and disable active checks for each service. There
are also quick links directly into your livestatus servers native GUI. There is no plan to try and re-create
all of the features in the native GUI.  Just the common stuff that I use is available.

Currently this plugin supports two methods of talking to livestatus.  Native local unix socket on the same box
as ONA, or via tcp exposed using xinetd on the livestatus server.  This is the most common method.

This plugin will assume that your name references on the livestatus server use FQDN.  This is how
the data is searched and displayed.. If you are not using FQDN then I encourage you to change that. (plus this plugin may not function well without modificaitons and could match too much data at once)

XINETD Method
-------------

This is probably easiest and most common way to set things up.  You can find documentation: http://mathias-kettner.de/checkmk_livestatus.html#H1:Remote access to Livestatus via SSH or xinetd.  

Unix socket Method
------------------

This method only works when you have a local unix socket available on the ONA server.  It is likely that you have ONA and
your livestatus monitoring on the same box.


Configuration
-------------

Copy the `livestatus_info.conf.php.example` file to `/opt/ona/etc/livestatus_info.conf.php` and add your appropriate values to the PHP array.  You can define
more than one livestatus server by simply adding new array instances.  The file should be pretty self documenting.

Here is an example conf file
```
<?php

$livestatusservers = Array();
/*
 * Add all of your livestatus servers here.
 * You can add multiple by incrementing the instance name
 */

// Create a new named livestatusserver instance
// Should be unique and have no spaces
$livestatusservers['Main'] = Array(
    // The socket type can be 'unix' for connecting with the unix socket or 'tcp'
    // to connect to a tcp socket.
    'socketType'       => 'tcp',
    // When using a unix socket the path to the socket needs to be set
    // Not used when using the TCP socketType.
    'socketPath'       => '/var/run/nagios/rw/live',
    // When using a tcp socket the address and port needs to be set
    'socketAddress'    => 'livestatus.example.com',
    'socketPort'       => '6557',
    // The main URL to access the view of this host natively
    // The FQDN of the host will be appended to the end of the url
    // so you must ensure the query string ends appropriately
    //'viewURL'          => 'https://thruk.example.com/thruk/#cgi-bin/status.cgi?s0_type=search&s0_value=',
    'viewURL'          => 'https://nagios.example/cgi-bin/nagios3/status.cgi?host=',
);
?>
```

SSH Method
----------
This is currently just an idea.  I know it can work as my first itteration used it.  However I 
scrapped that code and used xinetd instead.  I may come back around and try and get this method to work
but it is not a priority for me.

* requires the ssh2 php module.
  sudo apt-get install libssh2-php

* make a ssh key
  ssh-keygen -b 1024 -f ona_nagios -C "OpenNetAdmin nagios plugin access"
* distribute the .pub file to your livestatus hosts
  Add it to the main monitoring users .ssh directory in the authorized_keys file.
  * you can add it to limit the IPs its usable from.. this is a good idea.
* test it works
   If you can do this from the livestatus server locally: "echo 'GET contacts' | unixcat /var/lib/nagios3/rw/live"
   Then do this from your ONA server
   ssh -q -i ./ona_nagios user@nagios.example.com "echo 'GET contacts' | unixcat /var/lib/nagios3/rw/live"
*  ------NOTE----- make sure that the ssh keys are readable by the apache server. not great for security!
