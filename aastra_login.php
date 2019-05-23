
<?php
  require_once('includes/AastraCommon.php');
  require_once('includes/AastraIPPhoneInputScreen.class.php');

  // Get the info that was passed on the URL.
  $extension = $_GET["extension"];
  $password = $_GET["password"];
  $desktype = $_GET["desktype"];
  $reload = $_GET["reload"];

  file_put_contents ("user_agent.log", "begin verification: \n", FILE_APPEND);
  file_put_contents ("user_agent.log", "Extension: ".$extension." Password: ".$password." Desktype: ".$desktype." Bad Password: ".$badpass." Reload: ".$reload."\n", FILE_APPEND);
  file_put_contents ("user_agent.log", "end verification \n", FILE_APPEND);

  // this is a reload
  switch ($reload) {
    case "submit":
      TestFields();
      break;
    case "badpass":
      ShowLoginScreen();
      break;
    case "baddesk":
      ShowLoginScreen();
      break;
    case "badext":
      ShowLoginScreen();
      break;
    default:
      ShowLoginScreen();
  }

  function TestFields() {
    if ($password != '5555') {
      //the password is wrong
      ShowBadPassword($extension,$desktype);
    } elseif (!(($desktype == '1') OR ($desktype =='2'))) {
      // the desktype is wrong
      ShowBadDeskType();
    } elseif (!ValidateExtension($extension)) {
      // the extension does not exist
      ShowBadExtension();
    } else {
      // All checks passed! Add the extension.
      //do magic here
    }

  }
  
  function ShowLoginScreen(){
    $input = new AastraIPPhoneInputScreen();
    $input->setTitle('Extension Log On');
    $input->setDisplayMode('condensed');
    $input->setURL('http://watson.domain.com/custom/aastra/login.php?reload=submit');
    $input->setDestroyOnExit();
  
    // Ask for Extension
    $input->addField('number');
    $input->setFieldPrompt('Extension:');
    $input->setFieldParameter('extension');
    if ($extension != '') {
      $input->setFieldDefault("$extension");
    }
  
    // Ask for password
    $input->addField('number');
    $input->setFieldPassword('yes');
    $input->setFieldPrompt('Password:');
    $input->setFieldParameter('password');
  
    // Desk or Guest
    $input->addField('number');
    $input->setFieldPrompt('Desk Type?');
    $input->setFieldParameter('desktype');
    // Instruction on what to enter for Main or Guest
    $input->addField('string');
    $input->setFieldPrompt('Main=1 or');
    $input->setFieldDefault('Visitor=2');
    $input->setFieldEditable('no');
  
    // show it all
    $input->output();
  }

  function ShowBadPassword($ext,$desk) {
    file_put_contents ("user_agent.log", "begin verification: \n", FILE_APPEND);
    file_put_contents ("user_agent.log", "http://watson.domain.com/custom/aastra/login.php?extension=".$ext."&desktype=".$desk."&reload=badpass\n", FILE_APPEND);
    file_put_contents ("user_agent.log", "end verification \n", FILE_APPEND);
  
    // Display bad password message and return to login script
    $input = new AastraIPPhoneInputScreen();
    $input->setTitle('Invalid password');
    $input->setDisplayMode('condensed');
    $input->setURL('http://watson.domain.com/custom/aastra/login.php?extension='.$ext.'&desktype='.$desk.'&reload=badpass');
    $input->setDestroyOnExit();
    $input->addSoftkey('5', 'Cancel', 'SoftKey:Exit');
    $input->addSoftkey('6', 'Retry', 'SoftKey:Submit');
    $input->addField('empty');
    $input->addField('string');
    $input->setFieldPrompt('Please Try Again');
    $input->setFieldDefault('');
    $input->setFieldEditable('no');
    // show it all
    $input->output();
  }

  function ShowBadDeskType() {
    // Display bad password message and return to login script
    $input = new AastraIPPhoneInputScreen();
    $input->setTitle('Invalid Desk Type');
    $input->setDisplayMode('condensed');
    $input->setURL('http://watson.domain.com/custom/aastra/login.php?extension='.$extension.'&reload=baddesk');
    $input->setDestroyOnExit();
    $input->addSoftkey('5', 'Cancel', 'SoftKey:Exit');
    $input->addSoftkey('6', 'Retry', 'SoftKey:Submit');
    // Instruction on what to enter for Main or Guest
    $input->addField('empty');
    $input->addField('string');
    $input->setFieldPrompt('User Main Desk ');
    $input->setFieldDefault('Enter: 1');
    $input->setFieldEditable('no');
    $input->addField('string');
    $input->setFieldPrompt('Visitor Desk ');
    $input->setFieldDefault('Enter: 2');
    $input->setFieldEditable('no');
    $input->addField('string');
    $input->setFieldPrompt('Please Try Again');
    $input->setFieldDefault('');
    $input->setFieldEditable('no');
    // show it all
    $input->output();
  }

  function ShowBadExtension() {
    // Display bad password message and return to login script
    $input = new AastraIPPhoneInputScreen();
    $input->setTitle('Invalid Extension');
    $input->setDisplayMode('condensed');
    $input->setURL('http://watson.domain.com/custom/aastra/login.php?&reload=badext');
    $input->setDestroyOnExit();
    $input->addSoftkey('5', 'Cancel', 'SoftKey:Exit');
    $input->addSoftkey('6', 'Retry', 'SoftKey:Submit');
    // Instruciton on what to enter for Main or Guest
    $input->addField('empty');
    $input->addField('string');
    $input->setFieldPrompt('Please Try Again');
    $input->setFieldDefault('');
    $input->setFieldEditable('no');
    // show it all
    $input->output();
  }

?>
