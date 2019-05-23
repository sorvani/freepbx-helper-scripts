
<?php
  require_once('includes/AastraCommon.php');
  require_once('includes/AastraIPPhoneInputScreen.class.php');
  require_once('includes/AastraIPPhoneExecute.class.php');

  // Get the info that was passed on the URL.
  $extension = $_GET["extension"];
  $password = $_GET["password"];
  $desktype = $_GET["desktype"];
  $reload = $_GET["reload"];

  file_put_contents ("user_agent.log", "begin verification: \n", FILE_APPEND);
  file_put_contents ("user_agent.log", "Extension: ".$extension." Password: ".$password." Desktype: ".$desktype." Password: ".$badpass." Reload: ".$reload."\n", FILE_APPEND);
  file_put_contents ("user_agent.log", "end verification \n", FILE_APPEND);

  // this is a reload
  switch ($reload) {
    case "submit":
      if ($password != '5555') {
        //the password is wrong
        ShowBadPassword($extension,$desktype);
      } elseif (!(($desktype == '1') OR ($desktype =='2'))) {
        // the desktype is wrong
        ShowBadDeskType($extension);
      } elseif (!ValidateExtension($extension)) {
        // the extension does not exist
        ShowBadExtension();
      } else {
        // All checks passed! Add the extension.
        CreateEPMEntry(Aastra_decode_HTTP_header(),$extension,$desktype);
        // Tell the phone to reset
        ErasePhone();
      }
      break;
    case "badpass":
      ShowLoginScreen($extension,$desktype,'badpass');
      break;
    case "baddesk":
      ShowLoginScreen($extension,'','baddesk');
      break;
    case "badext":
      ShowLoginScreen('','','');
      break;
    default:
      ShowLoginScreen('','','');
  }

  function ValidateExtension($ext) {
    // Load FreePBX bootstrap environment
    // require_once('/etc/freepbx.conf');

    return TRUE;
  }


  function ShowLoginScreen($ext,$desk,$focus){
    $input = new AastraIPPhoneInputScreen();
    $input->setTitle('Extension Log On');
    $input->setDisplayMode('condensed');
    $input->setURL('http://watson.domain.com/custom/aastra/login.php?reload=submit');
    $input->setDestroyOnExit();
    switch ($focus) {
      case "badpass":
        $input->setDefaultIndex(2);
        break;
      case "baddesk":
        $input->setDefaultIndex(2);
        break;
      default:
        $input->setDefaultIndex(1);
    }
  
    // Ask for Extension
    $input->addField('number');
    $input->setFieldPrompt('Extension:');
    $input->setFieldParameter('extension');
    if ($ext != '') {
      $input->setFieldDefault("$ext");
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
    if ($desk != '') {
      $input->setFieldDefault("$desk");
    }
    // Instruction on what to enter for Main or Guest
    $input->addField('string');
    $input->setFieldPrompt('Main=1 or');
    $input->setFieldDefault('Visitor=2');
    $input->setFieldEditable('no');
  
    // show it all
    $input->output();
  }

  function ShowBadPassword($ext,$desk) {
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

  function ShowBadDeskType($ext) {
    // Display bad desktype message and return to login script
    $input = new AastraIPPhoneInputScreen();
    $input->setTitle('Invalid Desk Type');
    $input->setDisplayMode('condensed');
    $input->setURL('http://watson.domain.com/custom/aastra/login.php?extension='.$ext.'&reload=baddesk');
    $input->setDestroyOnExit();
    $input->addSoftkey('5', 'Cancel', 'SoftKey:Exit');
    $input->addSoftkey('6', 'Retry', 'SoftKey:Submit');
    // Instruction on what to enter for Main or Guest
    $input->addField('string');
    $input->setFieldPrompt('Try again');
    $input->setFieldDefault('using');
    $input->setFieldEditable('no');
    $input->addField('empty');
    $input->addField('string');
    $input->setFieldPrompt('Main Desk:');
    $input->setFieldDefault('1');
    $input->setFieldEditable('no');
    $input->addField('string');
    $input->setFieldPrompt('Visitor Desk:');
    $input->setFieldDefault('2');
    $input->setFieldEditable('no');
    // show it all
    $input->output();
  }

  function ShowBadExtension() {
    // Display bad extension message and return to login script
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

  function ErasePhone() {
    $execute = new AastraIPPhoneExecute();
    $execute->addEntry('Command: Reset');
    $execute->output();
  }

  function CreateEPMEntry($header,$ext,$desk) {
    // Create the configuration file on the PBX.
    // Pull information from the HTTP header to create the command.
    $model = substr($header['model'],6);
    $mac = strtolower($header['mac']);
    $phoneip = $header['ip'];
    // set template based on IP of phone.
    // need to add this logic testing with chicago
    $template = "chicago";

    // Build the fwconsole command into a string
    // fwconsole epm addmapping 6115-2 --brand=aastra --mac=00085d2723de --template=chicago --model=57i --account=account1
    $fwconsole = "fwconsole endpoint addmapping ";
    $fwconsole .= $ext."-".$desk." ";
    $fwconsole .= "--brand=aastra ";
    $fwconsole .= "--mac=".$mac." ";
    $fwconsole .= "--template=".$template." ";
    $fwconsole .= "--model=".$model." ";
    $fwconsole .= "--account=account1";

    // begin manual verification of shit during development
    file_put_contents ("user_agent.log", "begin verification: \n", FILE_APPEND);
    file_put_contents ("user_agent.log", "Command: ".$fwconsole." \n", FILE_APPEND);
    file_put_contents ("user_agent.log", "end verification \n", FILE_APPEND);
    // end manual verificatoin of shit during development

    // execute the command and log the result
    $result = shell_exec($fwconsole);
    syslog(LOG_INFO,"Configuration file for ".str_replace("\n", "", $result));

    // build the fwconsole command to to rebuild the configuration file
    $fwconsole = "fwconsole endpoint rebuild ";
    $fwconsole .= $ext."-".$desk;

    // execute the command an log the result
    $result = shell_exec($fwconsole);
    syslog(LOG_INFO,"Configuration file for ".str_replace("\n", "", $result));
  }
?>
