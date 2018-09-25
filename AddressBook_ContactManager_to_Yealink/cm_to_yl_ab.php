<?php
/* 
ylab.php (short for yealink address book) was taken directly from yl.php and modified.
https://github.com/sorvani/freepbx-helper-scripts/blob/master/yl.php

The purpose of this file is to read all the extensions in the system and then output them in a
Yealink Remote Address Book formatted XML syntax.
*/

header("Content-Type: text/xml");

// get the MySQL/MariaDB login information from the amportal configuration file.
define("AMP_CONF", "/etc/amportal.conf");

$file = file(AMP_CONF);
if (is_array($file)) {
    foreach ($file as $line) {
        if (preg_match("/^\s*([a-zA-Z0-9_]+)=([a-zA-Z0-9 .&-@=_!<>\"\']+)\s*$/",$line,$matches)) {
            $amp_conf[ $matches[1] ] = $matches[2];
        }
    }
}

require_once('DB.php'); //PEAR must be installed
$db_user = $amp_conf["AMPDBUSER"];
$db_pass = $amp_conf["AMPDBPASS"];
$db_host = $amp_conf["AMPDBHOST"];
$db_name = $amp_conf["AMPDBNAME"];

$datasource = 'mysql://'.$db_user.':'.$db_pass.'@'.$db_host.'/'.$db_name;
$db = DB::connect($datasource); // attempt connection

$type="getAll";
// This pulls every number in contact maanger that is not part of the default "User Manager Group"
$results = $db->$type("SELECT cen.number, cge.displayname FROM contactmanager_group_entries AS cge LEFT JOIN contactmanager_entry_numbers AS cen ON cen.entryid = cge.id WHERE cge.groupid = (SELECT cg.id FROM contactmanager_groups AS cg WHERE cg.name != 'User Manager Group');", null);

//dump the result into an array.
foreach($results as $result){
    $extensions[] = array($result[0],$result[1]);
}

// output the XML header info
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
// Output the XML root. This tag must be in the format XXXIPPhoneDirectory
// You may change the word Company below, but no other part of the root tag.
echo "<CompanyIPPhoneDirectory  clearlight=\"true\">\n";
$index = 0;
if (isset($extensions)) {
    // Loop through the results and output them correctly.
    // Spacing is setup below in case you wish to look at the result in a browser.
    foreach ($extensions as $key=>$extension) {
        $index= $index + 1;
        echo "    <DirectoryEntry>\n";
        echo "        <Name>$extension[1]</Name>\n";
        echo "        <Telephone>$extension[0]</Telephone>\n";
        echo "    </DirectoryEntry>\n";
    }
}
// Output the closing tag of the root. If you changed it above, make sure you change it here.
echo "</CompanyIPPhoneDirectory>\n";
?>
