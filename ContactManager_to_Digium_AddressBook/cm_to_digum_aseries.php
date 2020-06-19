<?php
/*
The purpose of this file is to read all the Contact Manager entries for the specified group
and then output them in a Yealink Remote Address Book formatted XML syntax.

Instructions on how to use can be found here:
https://mangolassi.it/topic/18647/freepbx-contact-manager-to-yealink-address-book

Updated December 26, 2019 to use FreePBX bootstrap

Update December 27, 2019
 - to incorporate changes by mgbolts (from: https://github.com/mgbolts/fpbx-yealink-xmlcontacts)
 - to incorporate patch to mgbolts version by susedv (from: https://github.com/mgbolts/fpbx-yealink-xmlcontacts/issues/1)
 - improve logic flow and enable easy use of E164


Improvements over original:
 a) Group all numbers for a common display name
 b) Updated SQL to order by displayname
 c) Add labels to each phone number
 e) Enable E164 number convention
 f) Allow the number labels to be customized.
 g) Now you can specify the contact group in the URL, ex.: https://FQDN/cm_to_yl_ab.php?cgroup=SomeName
 h) In order to use the E164 formatted number, you must pass a URL variable (e164=1) or change the default below.

 Update June 19, 2020
  - Rewrote and renamed for the Digium A Series phones.
  - XML format taken from https://wiki.asterisk.org/wiki/display/DIGIUM/A-Series+Contacts
  - Removed label functionality as not listed as a feature of the Digium XML syntax.
  - Added rtype as a known parameter
  - Future to do: Find field in Contact Manager to use for "RingingType" per contact.
*/

// Edit these variables as neeed to:
// 1. Match the name of the group in Contact Manager or pass the group name in the URL.
//    1a. The default 'Internal' group is named 'User Manager Group' is using that on the URL, use %20 in place of the spaces.
// 2. Set the ringing type
// 3. Use E164 or not
$contact_manager_group = isset($_GET['cgroup']) ? $_GET['cgroup'] : "SomeName"; // <-- Edit "SomeName" to make your own default
$ringtype = isset($_GET['rtype']) ? $_GET['rtype'] : "2"; // <-- Sets the ringing type, from 1-9, of the phone for calls received from numbers matching this contact. Defaults to 2.
$use_e164 = isset($_GET['e164']) ? $_GET['e164'] : 0; // <-- Edit 0 to disable or 1 to use the E164 formatted numbers by default

// The Digium A Series only takes 3 types of contact
// 1. Telephone / 2. Mobile / 3. Other
// Contact Manager is aware of 5 types.
// 1. internal / 2. cell / 3. work / 4. home / 5. other
// Decide which of the Contact Manager types to map to the 3 Digium types.
$telephone = "work";
$mobile = "cell";
$other = "other";

/**********************************************************************************************************/
/********************** End Customization. Change below at your own risk **********************************/
/**********************************************************************************************************/


header("Content-Type: text/xml");

// Load FreePBX bootstrap environment
require_once('/etc/freepbx.conf');

// Initialize a database connection
global $db;

// This pulls every number in contact maanger that is part of the group specified by $contact_manager_group
$sql = "SELECT cen.number, cge.displayname, cen.type, cen.E164 FROM contactmanager_group_entries AS cge LEFT JOIN contactmanager_entry_numbers AS cen ON cen.entryid = cge.id WHERE cge.groupid = (SELECT cg.id FROM contactmanager_groups AS cg WHERE cg.name = '$contact_manager_group') ORDER BY cge.displayname, cen.number;";

// Execute the SQL statement
$res = $db->prepare($sql);
$res->execute();
// Check that something is returned
if (DB::IsError($res)) {
    // Potentially clean this up so that it outputs pretty if not valid                
    error_log( "There was an error attempting to query contactmanager<br>($sql)<br>\n" . $res->getMessage() . "\n<br>\n");
} else {
    $contacts = $res->fetchAll(PDO::FETCH_ASSOC);
    
    // output the XML header info
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    // Output the XML root. This tag must be in the format XXXIPPhoneDirectory
    // You may change the word Company below, but no other part of the root tag.
    echo "<AsteriskIPPhoneDirectory  clearlight=\"true\">\n";

    // Loop through the results and output them correctly.
    // Spacing is setup below in case you wish to look at the result in a browser.
    $previousname = "";
    $firstloop = true;
    foreach ($contacts as $contact) {
        if ($contact['displayname'] != $previousname) {
            if ($firstloop){
                // flip the bit
                $firstloop = false;
            } else {
                // close the previous entry
                echo "        <Ring>" . $ringtype . "</Ring>\n";
                echo "    </DirectoryEntry>\n";
            }
            // Start the entry
            echo "    <DirectoryEntry>\n";
            echo "        <Name>" . htmlspecialchars($contact['displayname']) . "</Name>\n";
            // set the current name to the previous name
            $previousname = $contact['displayname'];
        }
        // Output the numbers as mapped above
        if ($contact['type'] == $telephone) {
            echo "        <Telephone>";
            // use the number or E164 field is specified, unless it is an internal extension
            if ($use_e164 == 0 || ($use_e164 == 1 && $contact['type'] == $ctype['internal'])) { echo $contact['number']; } else { echo $contact['E164']; }
            echo "</Telephone>\n";
        } elseif ($contact['type'] == $mobile) {
            echo "        <Mobile>";
            // use the number or E164 field is specified, unless it is an internal extension
            if ($use_e164 == 0 || ($use_e164 == 1 && $contact['type'] == $ctype['internal'])) { echo $contact['number']; } else { echo $contact['E164']; }
            echo "</Mobile>\n";
        } elseif ($contact['type'] == $other) {
            echo "        <Other>";
            // use the number or E164 field is specified, unless it is an internal extension
            if ($use_e164 == 0 || ($use_e164 == 1 && $contact['type'] == $ctype['internal'])) { echo $contact['number']; } else { echo $contact['E164']; }
            echo "</Other>\n";
        }
    }
    // Close the last entry.
    echo "        <Ring>" . $ringtype . "</Ring>\n";
    echo "    </DirectoryEntry>\n";
    // Output the closing tag of the root. If you changed it above, make sure you change it here.
    echo "</AsteriskIPPhoneDirectory>\n";
}

?>
