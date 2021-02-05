<?php

// Load FreePBX bootstrap environment
require_once('/etc/freepbx.conf');

// Make sure we are logged in to FreePBX
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

// Load	AMI
global $astman;

$results = $astman->PJSIPShowRegistrationInboundContactStatuses();

include 'templates/contacthead.php';

foreach ($results as $data) {
  echo '    <tr>' . "\n";

  // The extension
  echo '      <td>' . $data['AOR'] . '</td>' . "\n";

  // The URI is the AOR that we will send commands back to eventually
  echo '      <td style="display:none;">' . $data['URI'] . '</td>' . "\n";

  // The user agent contains information about the device.
  // Break it into pieces as Brand/Model/Firmware
  // TODO turn this into function to handle various formats
  /********** Examples
    ["UserAgent"]=>
    string(26) "Yealink SIP-T54W 96.85.0.5"

    ["UserAgent"]=>
    string(17) "Zoiper rv2.10.8.2"

    ["UserAgent"]=>
    string(26) "Grandstream HT802 1.0.17.5"

    ["UserAgent"]=>
    string(16) "snomPA1/8.7.3.19"

    ["UserAgent"]=>
    string(54) "LinphoneiOS/4.3.0 (Bob's iPhone) LinphoneSDK/4.4.0"
  **********/
  $useragent = explode(' ',$data['UserAgent']);
  echo '      <td>' . $useragent[0] . '</td>' . "\n";
  echo '      <td>' . $useragent[1] . '</td>' . "\n";
  echo '      <td>' . $useragent[2] . ' ' . $useragent[3] . ' ' . $useragent[4] . ' ' . $useragent[5] . '</td>' . "\n";
  echo '      <td>' . $data['Status'] . '</td>' . "\n";

  // Show RTT times in milliseconds
  echo '      <td>' . $data['RoundtripUsec'] / 1000 . ' ms</td>' . "\n";

  // Pull out the various IP addresses known to Asterisk.
  /********* Examples
    // Yealink phones (all)
    ["CallID"]=>
    string(28) "0_1362581122@192.168.101.161"
    ["ViaAddress"]=>
    string(17) "10.202.40.37:5060"

    // Grandstream HT802
    ["CallID"]=>
    string(32) "1893618396-5060-2@BJC.BGI.BAC.HA"
    ["ViaAddress"]=>
    string(18) "10.202.40.121:5062"

    // Zoiper
    ["CallID"]=>
    string(24) "5nw8H9tLIoXbkewN-pn_1w.."

    //Snom PA1
    ["CallID"]=>
    string(25) "386d43a45de1-l88ln518lzf9"
    ["ViaAddress"]=>
    string(17) "10.202.40.33:5060"

    //LinphoneiOS
    ["CallID"]=>
    string(10) "Ar-U8i4THj"
    ["ViaAddress"]=>
    string(20) "10.254.103.179:65310" // on wifi
    ["ViaAddress"]=>
    string(45) "2607:fb90:e120:b95f:c91c:ebd4:e11f:f45e:53362" // on cellular
  *********/
  $callid = end(explode('@',$data['CallID']));
  $viaaddress = explode(':',$data['ViaAddress']);
  $uri = explode(':',end(explode('@',$data['URI'])));
  echo '      <td>' . "\n";
  if (!filter_var($uri[0], FILTER_VALIDATE_IP)) { $uri[0] = 'Not an IP'; }
  if (!filter_var($viaaddress[0], FILTER_VALIDATE_IP)) { $viaaddress[0] = 'Not an IP'; }
  if (!filter_var($callid, FILTER_VALIDATE_IP)) { $callid = 'Not an IP'; }
  echo '        <b>URI:</b> ' . $uri[0] . '<br />' . "\n";
  echo '        <b>Via:</b> ' . $viaaddress[0] . '<br />' . "\n";
  echo '        <b>CallID:</b> ' . $callid . '<br />' . "\n";
  echo '      </td>' . "\n";

  // Make RegExpire human readable                         
  $regexpire = $data['RegExpire'];
  $regexpire = new DateTime("@$regexpire", new DateTimeZone("UTC"));
  $regexpire->setTimezone(new DateTimeZone(date_default_timezone_get()));
  echo '      <td>' . $regexpire->format('Y/m/d H:i:s') . '</td>' . "\n";

  echo '    </tr>' . "\n";
}

include 'templates/contactfoot.php';

//FOR DEBUG AND TESTING
//echo "<br />BEGIN RESULTS DUMP<br />\r\n<pre>\r\n";
//var_dump($results);
//echo "\r\n</pre><br />\r\nEND RESULTS DUMP\r\n";

?>
