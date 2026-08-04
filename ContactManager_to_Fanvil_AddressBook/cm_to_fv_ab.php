<?php
/*
ON FREEPBX 17 (DEBIAN), USE THE PORTED VERSION INSTEAD:
https://github.com/sorvani/freepbx17-phonebooks
See https://github.com/sorvani/freepbx-helper-scripts/issues/25 -- when a phone fetches this
script on 17, /etc/freepbx.conf runs the GUI auth layer, which wants an admin session this
script never has. Current 17 builds no longer fatal on it, but only because authentication
fails open. The ported version sets $bootstrap_settings['freepbx_auth'] = false before the
bootstrap, and fixes the unreachable DB::IsError() check.

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

// Edit these variables as neeed to:
// 1. Match the name of the group in Contact Manager or pass the group name in the URL.
//    1a. The default 'Internal' group is named 'User Manager Group' is using that on the URL, use %20 in place of the spaces.
// 2. Use E164 by default
// 3. Customize the label names of the contact types
$contact_manager_group = isset($_GET['cgroup']) ? $_GET['cgroup'] : "SomeName"; // <-- Edit "SomeName" to make your own default
$use_e164 = isset($_GET['e164']) ? $_GET['e164'] : 0; // <-- Edit 0 to 1 to use the E164 formatted numbers by default
$ctype['internal'] = "Telephone"; // <-- Edit the right side to display what you want shown
$ctype['cell'] = "Mobile"; // <-- Edit the right side to display what you want shown
$ctype['work'] = "Work"; // <-- Edit the right side to display what you want shown
$ctype['home'] = "Home"; // <-- Edit the right side to display what you want shown
$ctype['other'] = "Other"; // <-- Edit the right side to display what you want shown

// Display order for a contact that has several numbers. Lower sorts first.
// This replaces the old $contact['sortorder'] field, which was assigned but
// never actually sorted by, so numbers came out in SQL order instead.
$corder['internal'] = 1;
$corder['work'] = 2;
$corder['cell'] = 3;
$corder['other'] = 4;
$corder['home'] = 5;

/**********************************************************************************************************/
/********************** End Customization. Change below at your own risk **********************************/
/**********************************************************************************************************/


header("Content-Type: text/xml");

// Load FreePBX bootstrap environment
require_once('/etc/freepbx.conf');

// Initialize a database connection
global $db;

// Escape for XML element text. Without this a contact named "Smith & Sons"
// produces a document no phone can parse.
function xml_text($value) {
    return htmlspecialchars((string) $value, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
}

// In the Fanvil format the type becomes the tag name, so it has to be a legal
// XML name. A customized label containing a space or an ampersand would
// otherwise produce a document no phone can parse.
function xml_tag($value, $fallback = 'Other') {
    $tag = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $value);
    if ($tag === '' || !preg_match('/^[A-Za-z_]/', $tag)) {
        return $fallback;
    }
    return $tag;
}

// This pulls every number in contact maanger that is part of the group specified by $contact_manager_group
// The group name is bound as a parameter so a value passed on the URL cannot alter the query.
$sql = "SELECT cen.number, cge.displayname, cen.type, cen.E164, 0 AS 'sortorder' FROM contactmanager_group_entries AS cge LEFT JOIN contactmanager_entry_numbers AS cen ON cen.entryid = cge.id WHERE cge.groupid = (SELECT cg.id FROM contactmanager_groups AS cg WHERE cg.name = ?) ORDER BY cge.displayname, cen.number;";

// Execute the SQL statement
$res = $db->prepare($sql);
$res->execute(array($contact_manager_group));
// Check that something is returned
if (DB::IsError($res)) {
    // Potentially clean this up so that it outputs pretty if not valid                
    error_log( "There was an error attempting to query contactmanager<br>($sql)<br>\n" . $res->getMessage() . "\n<br>\n");
} else {
    $contacts = $res->fetchAll(PDO::FETCH_ASSOC);

    // Group by displayname so a contact with several numbers is one entry.
    // The old code walked a flat list tracking $previousname and closed the
    // last entry unconditionally after the loop, so an empty contact group
    // emitted a </DirectoryEntry> that was never opened -- phones got
    // "mismatched tag" instead of an empty directory.
    //
    // The LEFT JOIN also yields a NULL number for a group entry that has no
    // numbers at all; skip those rather than emitting an empty element.
    $groupedContacts = array();
    foreach ($contacts as $contact) {
        if ($contact['number'] === null || $contact['number'] === '') {
            continue;
        }
        $name = (string) $contact['displayname'];
        if (!isset($groupedContacts[$name])) {
            $groupedContacts[$name] = array();
        }
        $groupedContacts[$name][] = $contact;
    }

    // output the XML header info
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    // Output the XML root. This tag must be in the format XXXIPPhoneDirectory
    // You may change the word Fanvil below, but no other part of the root tag.
    echo "<FanvilIPPhoneDirectory clearlight=\"true\">\n";

    // Loop through the results and output them correctly.
    // Spacing is setup below in case you wish to look at the result in a browser.
    foreach ($groupedContacts as $displayname => $contactList) {
        // Sort this contact's numbers into the configured display order.
        usort($contactList, function ($a, $b) use ($corder) {
            $x = isset($corder[$a['type']]) ? $corder[$a['type']] : 99;
            $y = isset($corder[$b['type']]) ? $corder[$b['type']] : 99;
            if ($x === $y) {
                return strcmp((string) $a['number'], (string) $b['number']);
            }
            return $x - $y;
        });

        // Start the entry
        echo "    <DirectoryEntry>\n";
        echo "        <Name>" . xml_text($displayname) . "</Name>\n";

        foreach ($contactList as $contact) {
            // The tag name is looked up from the raw Contact Manager type. Do
            // not overwrite $contact['type'] with it first -- the E164 test
            // below needs the raw type, and two types sharing a tag name would
            // otherwise become indistinguishable.
            $type = (string) $contact['type'];
            $tag = xml_tag(isset($ctype[$type]) ? $ctype[$type] : $type);

            // Use the E164 field when asked, except for internal extensions,
            // which are dialled as-is. Fall back to the plain number when the
            // E164 column is empty, which it commonly is.
            $number = $contact['number'];
            if ($use_e164 == 1 && $type !== 'internal' && !empty($contact['E164'])) {
                $number = $contact['E164'];
            }

            echo "        <" . $tag . ">" . xml_text($number) . "</" . $tag . ">\n";
        }
        // Close the entry.
        echo "    </DirectoryEntry>\n";
    }
    // Output the closing tag of the root. If you changed it above, make sure you change it here.
    echo "</FanvilIPPhoneDirectory>\n";
}

?>
