<?php
require_once 'AstMan.php';
$astman=new AstMan;
$value = '103';
$astman->Login();
if (strlen($astman->error) > 0) {
    echo $astman->error;
    return;
} else {
    echo "Login Successful.\r\n";
}

$ext_detail = $astman->PJSIPShowEndpoint($value);
if (strlen($astman->error) > 0) { 
    echo $astman->error;
    return;
} else {
    echo "PJSIPShowEndpoint Successful.\r\n";
}

echo "Dumping \$ext_detail.\r\n";
var_dump($ext_detail);
echo "\r\n";

//foreach($ext_detail as $skey=>$svalue) {
//    echo "$skey says $svalue<br>\r\n";
//}

$astman->Logout();
if (strlen($astman->error) > 0) { 
    echo $astman->error;
    return;
} else {
    echo "Logut Successful.\r\n";
}

?>
