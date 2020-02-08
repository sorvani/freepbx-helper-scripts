<?php
/*
The purpose of this file is to read all the extensions in the system and then output them in a
Grandstream Phonebook formatted XML file.

Based of Extensions_to_Yealink_AddressBook in this repository and arielgrin's post int he FreePBX community
https://community.freepbx.org/t/yealink-xml-phonebook-xml-auto-generation/51988/25

February 7, 2020
This is not currently completed. It is a work in progress. I need to get a Grandstream phone to test with.

*/
header("Content-Type: text/xml");

// Load FreePBX bootstrap environment
require_once('/etc/freepbx.conf');

// Initialize a database connection
global $db;

// This pulls every extension in the systm. Including virtual mailboxes and is a recommended default
$sql = "SELECT `id`,`description` FROM `devices`;";
// You can restrict the output with standard SQL syntax
// This example only shows extensions prior to 200 and not virtual mailboxes
// $sql = "SELECT `id`,`description` FROM `devices` WHERE `id` < 200 AND `tech` <> 'custom';";
// This example will pull all extensions from 1000 to 1999
// $sql = "SELECT `id`,`description` FROM `devices` WHERE `id` BETWEEN 1000 and 1999;";

// Execute the SQL statement
$res = $db->prepare($sql);
$res->execute();
// Check that something is returned
if (DB::IsError($res)) {
    // Potentially clean this up so that it outputs pretty if not valid                
    error_log( "There was an error attempting to query the extensions<br>($sql)<br>\n" . $res->getMessage() . "\n<br>\n");
} else {
    $extensions = $res->fetchAll(PDO::FETCH_ASSOC);
    //gs_phonebook.xml for older models like GXP1200 
    // output the XML header info
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    // Root element of XML
    echo "<AddresBook>\n";
    // Loop through the results and output them correctly.
    // Spacing is setup below in case you wish to look at the result in a browser.
    foreach ($extensions as $extension) {
        echo "    <Contact>\n";
        echo "        <LastName>" . $extension['description'] . "</LastName>\n";
        echo "        <Phone>\n";
        echo "            <phonenumber>" . $extension['id'] . "</phonenumber>\n";
        echo "            <accountindex>0</accountindex>\n";
        echo "        </Phone>\n";
        echo "    </Contact>\n";
    }
    // Output the closing tag of the root.
    echo "</AddresBook>\n";
}
/*
    //  phonebook.xml for newer models like GXP2110
    // output the XML header info
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    // Root element of XML
    echo "<AddresBook>\n";
    // Loop through the results and output them correctly.
    // Spacing is setup below in case you wish to look at the result in a browser.
    foreach ($extensions as $extension) {
        echo "    <Contact>\n";
        echo "        <LastName>" . $extension['description'] . "</LastName>\n";
        echo "        <FirstName />\n";
        echo "        <Phone>\n";
        echo "            <phonenumber>" . $extension['id'] . "</phonenumber>\n";
        echo "            <accountindex>1</accountindex>\n";
        echo "        </Phone>\n";
        echo "    </Contact>\n";
    }
    // Output the closing tag of the root.
    echo "</AddresBook>\n";
*/
?>
