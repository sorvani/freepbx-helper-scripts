<?php

// This file is orginially from George Kanicki and is reproduced with permission.
// https://community.spiceworks.com/people/george1421

/*
George1421 Dec 4, 2017 at 8:37 PM

Yes you may. Its all code fragments I found on the internet and glued together to make the script. So its a mix of my imagination and others imagination too. I don't have a reference where the original bits came from.

Thank you for asking.
*/

header("Content-Type: text/xml");

define("AMP_CONF", "/etc/amportal.conf");

$file = file(AMP_CONF);
if (is_array($file)) {
    foreach ($file as $line) {
        if (preg_match("/^\s*([a-zA-Z0-9_]+)=([a-zA-Z0-9 .&-@=_!<>\"\']+)\s*$/",$line,$matches)) {
            $amp_conf[ $matches[1] ] = $matches[2];
        }
    }
}

require_once('DB.php'); //PEAR must be installed
$db_user = $amp_conf["AMPDBUSER"];
$db_pass = $amp_conf["AMPDBPASS"];
$db_host = $amp_conf["AMPDBHOST"];
$db_name = $amp_conf["AMPDBNAME"];

$datasource = 'mysql://'.$db_user.':'.$db_pass.'@'.$db_host.'/'.$db_name;
$db = DB::connect($datasource); // attempt connection

$type="getAll";
$results = $db->$type("select extension,name,voicemail from users where voicemail <> 'novm' order by name asc", null);
# $results = $db->$type("SELECT extension,name,voicemail FROM users ORDER BY extension", null);
foreach($results as $result){
    $extensions[] = array($result[0],$result[1],$result[2]);
}

#$extensions = core_users_list();
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "    <fcsIPPhoneDirectory  clearlight=\"true\">\n";
echo "\n\t<Title>PhoneList</Title>";
echo "\n\t<Prompt>Prompt</Prompt>";
$index = 0;
if (isset($extensions)) {
    foreach ($extensions as $key=>$extension) {
        $index= $index + 1;
        echo "\n\t<DirectoryEntry>";
        echo "\n\t\t<Name>" . $extension[1] . "</Name>";
        echo "\n\t\t<Telephone>" . $extension[0] . "</Telephone>";
        echo "\n\t</DirectoryEntry>\n";
    }
}
echo "\n\t<SoftKeyItem>";
echo "\n\t\t<Name>2</Name>";
echo "\n\t\t<URL>http://192.168.1.16/directory/yl.php</URL>";
echo "\n\t</SoftKeyItem>";

echo "\n    </fcsIPPhoneDirectory>\n";
?>
