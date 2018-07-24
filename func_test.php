<?php
require_once 'AstMan.php';
$astman=new AstMan;
$value="101";
$astman->Login();
if (strlen($astman->error) > 0) {
    echo $astman->error;echo "\r\n<br />";
    return;
} else {
    echo "Login Successful.\r\n";echo "\r\n<br />";
}

/*
$endpoints=$astman->GetEndpointsPJSIP();
if (strlen($astman->error) > 0) { 
    echo $astman->error;echo "\r\n<br />";
    return;
} else {
    echo "GetEndpointsPJSIP Successful.\r\n";echo "\r\n<br />";
}
echo "Dumping \$endpoints.\r\n";echo "\r\n<br />";
echo "\r\n<pre>\r\n";
var_dump($endpoints);echo "\r\n</pre>\r\n<br />";
echo "\r\n";echo "\r\n<br />";
*/

$ext_detail=$astman->PJSIPShowEndpoint($value);
if (strlen($astman->error) > 0) { 
    echo $astman->error;echo "\r\n<br />";
    return;
} else {
    echo "PJSIPShowEndpoint Successful.\r\n";echo "\r\n<br />";
}
echo "Dumping \$ext_detail.\r\n";echo "\r\n<br />";
var_dump($ext_detail);echo "\r\n<br />";
echo "\r\n";echo "\r\n<br />";

//foreach($ext_detail as $skey=>$svalue) {
//    echo "$skey says $svalue<br>\r\n";
//}

$astman->Logout();
if (strlen($astman->error) > 0) { 
    echo $astman->error;echo "\r\n<br />";
    return;
} else {
    echo "Logut Successful.\r\n";echo "\r\n<br />";
}

?>
