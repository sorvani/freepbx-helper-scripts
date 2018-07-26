<?php
require_once 'AstMan.php';
$astman=new AstMan;
$value="103";
$astman->Login();
if (strlen($astman->GetError()) > 0) {
    echo $astman->GetError();echo "\r\n<br />\r\n";
    return;
} else {
    echo "Login Successful.<br />\r\n";
}

$endpoints=$astman->GetEndpointsPJSIP();
if (strlen($astman->GetError()) > 0) { 
    echo $astman->GetError();echo "<br />\r\n";
    return;
} else {
    echo "GetEndpointsPJSIP Successful.<br />\r\n";
    echo "Dumping \$endpoints.<br />\r\n";
    echo "<pre>\r\n";
    var_dump($endpoints);
    echo "\r\n</pre><br />\r\n";
}

$ext_detail=$astman->PJSIPShowEndpoint($value);
if (strlen($astman->GetError()) > 0) { 
    echo $astman->GetError();echo "\r\n<br />";
    return;
} else {
    echo "PJSIPShowEndpoint Successful.<br />\r\n";
    echo "Dumping \$ext_detail.<br />\r\n";
    echo "<pre>\r\n";
    var_dump($ext_detail);
    echo "\r\n</pre><br />\r\n";
}

$astman->Logout();
if (strlen($astman->GetError()) > 0) { 
    echo $astman->GetError();echo "\r\n<br />\r\n";
    return;
} else {
    echo "Logut Successful.<br />\r\n";
}

?>
