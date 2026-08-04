<?php
/*
ON FREEPBX 17 (DEBIAN), USE THE PORTED VERSION INSTEAD:
https://github.com/sorvani/freepbx17-phonebooks
See https://github.com/sorvani/freepbx-helper-scripts/issues/25 -- when a phone fetches this
script on 17, /etc/freepbx.conf runs the GUI auth layer, which wants an admin session this
script never has. Current 17 builds no longer fatal on it, but only because authentication
fails open. The ported version sets $bootstrap_settings['freepbx_auth'] = false before the
bootstrap, and fixes the unreachable DB::IsError() check and the missing XML escaping.

ylab.php (short for yealink address book) was taken directly from yl.php and modified.
https://github.com/sorvani/freepbx-helper-scripts/blob/master/yl.php

The purpose of this file is to read all the extensions in the system and then
output them in a Yealink Remote Address Book formatted XML syntax.

Updated December 24, 2019 to use FreePBX bootstrap
*/

header("Content-Type: text/xml");

// Load FreePBX bootstrap environment
require_once('/etc/freepbx.conf');

// Initialize a database connection
global $db;

// This pulls every standard phone/endpoint extension in the system, and is a recommended default. See below for customization.
$sql = "SELECT `id`,`description` FROM `devices`;";

// The SQL command on Line 21 can be customized to pull extension numbers for ring groups, misc applications, queues, and possibly more.
// Modified copies of this file may be saved alongside the original file, which will allow you to designate up to 5 separate Remote Phone Book tabs in the phone config.

// To pull ring groups instead, uncomment the line below and comment out Line 21:
// $sql = "SELECT `grpnum`,`description` FROM `ringgroups`;";
// IMPORTANT - You must also change 'id' on Line 70 to 'grpnum'.

// To pull Misc Applications, uncomment the line below and comment out Line 21:
// $sql = "SELECT `ext`,`description` FROM `miscapps`;";
// IMPORTANT - You must also change 'id' on Line 70 to 'ext'.

// To pull Queues, uncomment the line below and comment out Line 21:
// $sql = "SELECT `extension`,`descr` FROM `queues_config`;";
// IMPORTANT - You must also change 'description' on Line 69 to 'descr' AND change 'id' on Line 70 to 'extension'

// If you would like to include virtual extensions alongside your SIP extensions, uncomment the line below and comment out Line 21:
// $sql = "SELECT `extension`,`name` FROM `users`;";
// IMPORTANT - You must also change 'description' on Line 69 to 'name' AND change 'id' on Line 70 to 'extension'.

// You can restrict the output of the command with standard SQL syntax, as shown below.

// This example only shows extensions with a number below 200:
// $sql = "SELECT `id`,`description` FROM `devices` WHERE `id` < 200;";

// This example will pull all ring groups from 300 to 400 (remember to change Line 70 as mentioned above):
// $sql = "SELECT `grpnum`,`description` FROM `ringgroups` WHERE `grpnum` BETWEEN 300 and 400;";

// Execute the SQL statement
$res = $db->prepare($sql);
$res->execute();
// Check that something is returned
if (DB::IsError($res)) {
    // Potentially clean this up so that it outputs pretty if not valid                
    error_log( "There was an error attempting to query the extensions<br>($sql)<br>\n" . $res->getMessage() . "\n<br>\n");
} else {
    $extensions = $res->fetchAll(PDO::FETCH_ASSOC);
    // output the XML header info
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    // Output the XML root. This tag must be in the format XXXIPPhoneDirectory
    // You may change the word Company below, but no other part of the root tag. Make sure to change it on Line 74 too.
    echo "<CompanyIPPhoneDirectory  clearlight=\"true\">\n";

    // Loop through the results and output them correctly.
    // Spacing is setup below in case you wish to look at the result in a browser.
    foreach ($extensions as $extension) {
        echo "    <DirectoryEntry>\n";
        echo "        <Name>" . $extension['description'] . "</Name>\n";
        echo "        <Telephone>" . $extension['id'] . "</Telephone>\n";
        echo "    </DirectoryEntry>\n";
    }
    // Output the closing tag of the root. If you changed the tag above, make sure you change it here.
    echo "</CompanyIPPhoneDirectory>\n";
}

?>
