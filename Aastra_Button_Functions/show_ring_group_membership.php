<?php
// http://__destination__/show_ring_group_membership.php?ext=$$SIPUSERNAME$$
/* // Example of the XML structure used by the Aastra display.
<AastraIPPhoneTextMenu
    defaultIndex = “some integer”
    destroyOnExit = “yes/no”
    style = “numbered/none/radio” * Not all models recognize. Ignored if not supported.
    Beep = “yes/no”
    Timeout = “some integer”
    LockIn = “yes/no”
    GoodbyeLockInURI = “some URI”
    allowAnswer = “yes/no” * Not all models recognize. Ignored if not supported.
    allowDrop = “yes/no” * Not all models recognize. Ignored if not supported.
    allowXfer = “yes/no” * Not all models recognize. Ignored if not supported.
    allowConf = “yes/no” * Not all models recognize. Ignored if not supported.
    cancelAction = “some URI”
    wrapList = “yes/no” * Not all models recognize. Ignored if not supported.
    scrollConstrain = “yes/no” * Not all models recognize. Ignored if not supported.
    unitScroll = “yes/no” * Not all models recognize. Ignored if not supported.
    scrollUp = “Some URI”
    scrollDown = “Some URI”
    numberLaunch = “yes/no” * Not all models recognize. Ignored if not supported.
    touchLaunch = “yes/no” * Not all models recognize. Ignored if not supported.
    fontMono = “yes/no” * Not all models recognize. Ignored if not supported.
>
  <Title>Phone Services</Title>
  <MenuItem base = “http://10.50.10.53/”>
    <Prompt>Traffic Reports</Prompt>
    <URI>rss_to_xml.pl</URI>
  </MenuItem>
  <MenuItem>
    <Prompt>Employee List</Prompt>
    <URI>employees.xml</URI>
  </MenuItem>
  <MenuItem base =””>
    <Prompt>Weather</Prompt>
    <URI>http://10.50.10.52/weather.pl</URI>
  </MenuItem>
</AastraIPPhoneTextMenu>

*/
  // Show or don't show user extension
  // 0 = do not show    1 = show
  $show_user_extension = 0;

  // Load FreePBX bootstrap environment
  //require_once('/etc/freepbx.conf');

  // Get the extension that was passed on the URL.
  $extension = $_GET["ext"];
  // $extension = $argv[1]; // for testing from CLI
  // echo var_dump($extension)."\n";

  // Initialize a database connection
  global $db;

  if ($show_user_extension == 1) {
    // Check to see if extension is valid and return the user name 
    $sql = "SELECT `id`,`description` FROM `devices` WHERE `id` = $extension;";
    // Execute the SQL statement
    $res = $db->prepare($sql);
    $res->execute();
    // Check that something is returned
    if (DB::IsError($res)) {
      // Potentially clean this up so that it outputs pretty if not valid                
      error_log( "Error attempting to get the name associated to the extension<br>($sql)<br>\n" . $res->getMessage() . "\n<br>\n");
    } else {
      $row = $res->fetchAll(PDO::FETCH_ASSOC);
      // ensure there is only one row returned. Should be impossible to do anything else.
      if ( count($row) == 1 ) {
        $personal = "<MenuItem><Prompt>".$extension." - ".$row[0]['description']."</Prompt></MenuItem>\n";
      } else {
        $personal = "<MenuItem><Prompt>".$extension." - Error Not Found</Prompt></MenuItem>\n";
      }
    }
  }

  // Ring groups are only able to be queried from the MySQL database.
  // The `grplist` coliumn contains the list of extensions that are members.
  // The WHERE clause below is so specific in order to not match on a potential dupe as a substring.
  $sql = "SELECT `grpnum`,`description`,`grplist` FROM `ringgroups` "; 
  $sql .= "WHERE `grplist` LIKE '$extension-%' OR `grplist` LIKE '%-$extension' OR `grplist` LIKE '%-$extension-%' OR `grplist` = '$extension' ";
  $sql .= "ORDER BY `description`;";

  // Execute the SQL statement
  $res = $db->prepare($sql);
  $res->execute();
  // Check that something is returned
  if (DB::IsError($res)) {
    // Potentially clean this up so that it outputs pretty if not valid                
    error_log( "Error attemptig to get the list of ring groups<br>($sql)<br>\n" . $res->getMessage() . "\n<br>\n");
  } else {
    $row = $res->fetchAll(PDO::FETCH_ASSOC);
    if ( count($row) > 0 ) {
      //echo "<pre>Found ".count($row)." rows:\n";
      //echo var_dump($row);
      //echo "\n</pre>\n";
      //echo $row[0]['description'];
      $my_ringgroups = "";
      foreach ($row as $line) {
        $my_ringgroups .= "<MenuItem><Prompt>".$line['grpnum']." - ".$line['description']."</Prompt></MenuItem>\n";
      }
    } else {
      $my_ringgroups = "<MenuItem><Prompt>No ring groups found</Prompt></MenuItem>\n";
    }
  }

  // Variable to hold non breaking space
  $nbsp = "&#160;";
  // Output
  $xmlout = "<?xml version='1.0' encoding='UTF-8' standalone='no' ?>";
  $xmlout .= "<AastraIPPhoneTextMenu style='none' destroyOnExit='yes' Beep='no' Timeout='9'>\n";
  if ($show_user_extension == 1) {
    $xmlout .= "<Title>Your Line</Title>\n";
    $xmlout .= $personal;
  }
  $xmlout .= "<MenuItem><Prompt>".$nbsp.$nbsp."Your Ring Groups</Prompt></MenuItem>\n";
  $xmlout .= $my_ringgroups;
  $xmlout .= "</AastraIPPhoneTextMenu>\n";  

  print $xmlout;


?>
