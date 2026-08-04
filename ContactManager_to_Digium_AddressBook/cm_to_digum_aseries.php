<?php
/*
ON FREEPBX 17 (DEBIAN), USE THE PORTED VERSION INSTEAD:
https://github.com/sorvani/freepbx17-phonebooks
See https://github.com/sorvani/freepbx-helper-scripts/issues/25 -- when a phone fetches this
script on 17, /etc/freepbx.conf runs the GUI auth layer, which wants an admin session this
script never has. Current 17 builds no longer fatal on it, but only because authentication
fails open. The ported version also sets $bootstrap_settings['freepbx_auth'] = false before
the bootstrap and fixes the unreachable DB::IsError() check.

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

// Display order for a contact that has several numbers. Lower sorts first.
// Keyed by the Contact Manager type, not the Digium tag, so it still works if
// you point two of the three above at different types.
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
// produces a document no phone can parse. $ringtype comes off the URL and goes
// straight into the output, so it needs the same treatment.
function xml_text($value) {
    return htmlspecialchars((string) $value, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
}

// Map the Contact Manager types chosen above to the three Digium tags. Built in
// this order so that if two of them name the same Contact Manager type, the
// earlier one wins -- the same precedence the old if/elseif chain had. Any type
// not listed is left out of the output, which is the existing behaviour for
// 'internal' and 'home' under the defaults.
$typemap = array();
if (!isset($typemap[$telephone])) { $typemap[$telephone] = 'Telephone'; }
if (!isset($typemap[$mobile]))    { $typemap[$mobile]    = 'Mobile'; }
if (!isset($typemap[$other]))     { $typemap[$other]     = 'Other'; }

// This pulls every number in contact maanger that is part of the group specified by $contact_manager_group
// The group name is bound as a parameter so a value passed on the URL cannot alter the query.
$sql = "SELECT cen.number, cge.displayname, cen.type, cen.E164 FROM contactmanager_group_entries AS cge LEFT JOIN contactmanager_entry_numbers AS cen ON cen.entryid = cge.id WHERE cge.groupid = (SELECT cg.id FROM contactmanager_groups AS cg WHERE cg.name = ?) ORDER BY cge.displayname, cen.number;";

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
    // emitted a <Ring> and a </DirectoryEntry> outside any entry -- phones got
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
    // You may change the word Asterisk below, but no other part of the root tag.
    echo "<AsteriskIPPhoneDirectory clearlight=\"true\">\n";

    // Loop through the results and output them correctly.
    // Spacing is setup below in case you wish to look at the result in a browser.
    foreach ($groupedContacts as $displayname => $contactList) {
        // Only the mapped types are output, so a contact whose numbers are all
        // of unmapped types would otherwise produce an entry with a name, no
        // numbers, and a stray <Ring>.
        $usable = array();
        foreach ($contactList as $contact) {
            if (isset($typemap[$contact['type']])) {
                $usable[] = $contact;
            }
        }
        if (!$usable) {
            continue;
        }

        // Sort this contact's numbers into the configured display order.
        usort($usable, function ($a, $b) use ($corder) {
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

        // Output the numbers as mapped above
        foreach ($usable as $contact) {
            $type = (string) $contact['type'];
            $tag = $typemap[$type];

            // Use the E164 field when asked, except for internal extensions,
            // which are dialled as-is. Fall back to the plain number when the
            // E164 column is empty, which it commonly is.
            //
            // The old code tested $ctype['internal'] here, a variable this
            // script never defines -- the Yealink and Fanvil versions do, but
            // this one dropped labels entirely. It only ever evaluated when
            // e164=1, so the default path hid it, and on PHP 8 the undefined
            // index was a warning that FreePBX's handler promoted to a fatal.
            $number = $contact['number'];
            if ($use_e164 == 1 && $type !== 'internal' && !empty($contact['E164'])) {
                $number = $contact['E164'];
            }

            echo "        <" . $tag . ">" . xml_text($number) . "</" . $tag . ">\n";
        }

        echo "        <Ring>" . xml_text($ringtype) . "</Ring>\n";
        echo "    </DirectoryEntry>\n";
    }
    // Output the closing tag of the root. If you changed it above, make sure you change it here.
    echo "</AsteriskIPPhoneDirectory>\n";
}

?>
