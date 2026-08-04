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

*/

// Edit these variables as needed to:
// 1. Match the name of the group in Contact Manager or pass the group name in the URL.
//    1a. The default 'Internal' group is named 'User Manager Group' is using that on the URL, use %20 in place of the spaces.
// 2. Use E164 by default
// 3. Customize the label names of the contact types
$contact_manager_group = isset($_GET['cgroup']) ? $_GET['cgroup'] : "SomeName"; // <-- Edit "SomeName" to make your own default
$use_e164 = isset($_GET['e164']) ? $_GET['e164'] : 0; // <-- Edit 0 to 1 to use the E164 formatted numbers by default
$ctype['internal'] = "Extension"; // <-- Edit the right side to display what you want shown
$ctype['cell'] = "Mobile"; // <-- Edit the right side to display what you want shown
$ctype['work'] = "Work"; // <-- Edit the right side to display what you want shown
$ctype['home'] = "Home"; // <-- Edit the right side to display what you want shown
$ctype['other'] = "Other"; // <-- Edit the right side to display what you want shown

/**********************************************************************************************************/
/********************** End Customization. Change below at your own risk **********************************/
/**********************************************************************************************************/

header("Content-Type: text/xml");

// Load FreePBX bootstrap environment
require_once('/etc/freepbx.conf');

// Initialize a database connection
global $db;

// This pulls every number in contact manager that is part of the group specified by $contact_manager_group
$sql = "SELECT cen.number, cge.displayname, cen.type, cen.E164, 0 AS 'sortorder' 
        FROM contactmanager_group_entries AS cge 
        LEFT JOIN contactmanager_entry_numbers AS cen ON cen.entryid = cge.id 
        WHERE cge.groupid = (SELECT cg.id FROM contactmanager_groups AS cg WHERE cg.name = '$contact_manager_group') 
        ORDER BY cge.displayname, cen.number;";

// Execute the SQL statement
$res = $db->prepare($sql);
$res->execute();

// Check if there is an error with the query
if (DB::IsError($res)) {
    error_log("There was an error attempting to query contactmanager<br>($sql)<br>\n" . $res->getMessage() . "\n<br>\n");
} else {
    // Fetch all contacts
    $contacts = $res->fetchAll(PDO::FETCH_ASSOC);
    
    // Group contacts by displayname to handle multiple numbers per contact
    $groupedContacts = [];
    foreach ($contacts as $contact) {
        if (!isset($groupedContacts[$contact['displayname']])) {
            $groupedContacts[$contact['displayname']] = [];
        }
        // Add the contact information (phone numbers) to the grouped contacts
        $groupedContacts[$contact['displayname']][] = $contact;
    }

    // Output the XML header
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<CompanyIPPhoneDirectory clearlight=\"true\">\n";

    // Loop through the grouped contacts and output them in XML format
    foreach ($groupedContacts as $displayname => $contactList) {
        echo "    <DirectoryEntry>\n";
        echo "        <Name>" . htmlspecialchars($displayname) . "</Name>\n";

        // Loop through each phone number for the current contact
        foreach ($contactList as $contact) {
            // Label and number handling based on E164 and type
            if ($contact['type'] == "cell") {
                $contact['type'] = $ctype['cell'];
                $contact['sortorder'] = 3;
            }
            if ($contact['type'] == "internal") {
                $contact['type'] = $ctype['internal'];
                $contact['sortorder'] = 1;
            }
            if ($contact['type'] == "work") {
                $contact['type'] = $ctype['work'];
                $contact['sortorder'] = 2;
            }
            if ($contact['type'] == "other") {
                $contact['type'] = $ctype['other'];
                $contact['sortorder'] = 4;
            }
            if ($contact['type'] == "home") {
                $contact['type'] = $ctype['home'];
                $contact['sortorder'] = 5;
            }

            // Output the phone number in the correct format (E164 or normal)
            if ($use_e164 == 0 || ($use_e164 == 1 && $contact['type'] == $ctype['internal'])) {
                // Not using E164 or it is an internal extension
                echo "        <Telephone label=\"" . $contact['type'] . "\">" . $contact['number'] . "</Telephone>\n";
            } else {
                // Using E164 format
                echo "        <Telephone label=\"" . $contact['type'] . "\">" . $contact['E164'] . "</Telephone>\n";
            }
        }
        echo "    </DirectoryEntry>\n";
    }

    echo "</CompanyIPPhoneDirectory>\n";
}
?>
