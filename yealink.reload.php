<?php

// Some initial sample code found at https://www.voip-info.org/asterisk-manager-example:-php
// amportal.conf code modified from https://raw.githubusercontent.com/sorvani/freepbx-helper-scripts/master/yl.php

    // Open /etc/amportal.conf and get the Asterisk Manager connection information
    define("AMP_CONF", "/etc/amportal.conf");
    $file = file(AMP_CONF);
    if (is_array($file)) {
        foreach ($file as $line) {
            if (preg_match("/^\s*([a-zA-Z0-9_]+)=([a-zA-Z0-9 .&-@=_!<>\"\']+)\s*$/",$line,$matches)) {
                $amp_conf[ $matches[1] ] = $matches[2];
            }
        }
    }

    require_once('DB.php'); //php-pear-db must first be installed on a new FreePBX 14 system
    $db_user = $amp_conf["AMPMGRUSER"];
    $db_pass = $amp_conf["AMPMGRPASS"];
    $db_host = $amp_conf["ASTMANAGERHOST"];
    $db_port = $amp_conf["ASTMANAGERPORT"];
    $db_timeout = $amp_conf["ASTMGRWRITETIMEOUT"];

    // Connect to Asterisk Manager and run a command
    $socket = fsockopen($db_host, $db_port, $errno, $errstr, $db_timeout);
    fputs($socket, "Action: Login\r\n");
    fputs($socket, "UserName: $db_user\r\n");
    fputs($socket, "Secret: $db_pass\r\n\r\n");
    fputs($socket, "Action: Command\r\n");
    fputs($socket, "Command: pjsip list endpoints\r\n\r\n");
    fputs($socket, "Action: Logoff\r\n\r\n");
    while (!feof($socket)) {
        $wrets .= fread($socket, 8192);
    }
    fclose($socket);
    echo <<<ASTERISKMANAGEREND
<pre>
ASTERISK MANAGER OUTPUT:<br />
$wrets
</pre>
ASTERISKMANAGEREND;

?>
