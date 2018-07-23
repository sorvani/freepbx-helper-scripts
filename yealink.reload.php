<?php
require_once 'AstMan.php';

$astman=new AstMan;


// Some initial sample code found at https://www.voip-info.org/asterisk-manager-example:-php
// amportal.conf code modified from https://raw.githubusercontent.com/sorvani/freepbx-helper-scripts/master/yl.php

    //Set the minumim and maxium extensions to display
    $f_ext = 100;
    $l_ext = 199;

    $astman->Login();
    $endpoints=$astman->GetEndpointsPJSIP();

    if (!$endpoints) {
        echo $astMan->error;
        return false;
    }

    $astman->Logout();

    // pattern and preg_match_all courtesy of https://github.com/tjgruber    
    //regex pattern to use -- matches any number after a "/"
    $pattern = '/\/([0-9]+)/';
    //strip out all the /### values
    preg_match_all($pattern,$endpoints,$matches);

    // loop to look at only the extensions defined above
    foreach($matches[1] as $item => $value) {
        if($value >= $f_ext && $value <= $l_ext) {
            echo "item: ";
            echo $item;
            echo "   value: ";
            echo $value;
            echo "<br />\r\n";
        }
    }
?>
