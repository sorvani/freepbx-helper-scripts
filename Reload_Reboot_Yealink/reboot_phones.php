<!doctype html>
<html lang="en">

<head>
<meta charset="utf-8">
<title>Reboot &amp; Reload Phones</title>
<!-- <link rel="stylesheet" type="text/css" href="style.css"> -->
<style>
table {
    border-collapse: collapse;
    width: 100%;
}

th, td {
    text-align: left;
    padding: 8px;
    white-space:nowrap; 
}

tr:nth-child(even){background-color: #f2f2f2}

th {
    background-color: #4CAF50;
    color: white;
}
</style>

</head>

<?php
require_once 'AstMan.php';

$astman=new AstMan;

//including the php files that process the form
include "reboot_process.php";
include "reboot_confirm.php";

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

    // pattern and preg_match_all courtesy of https://github.com/tjgruber    
    //regex pattern to use -- matches any number after a "/"
    $pattern = '/\/([0-9]+)/';
    //strip out all the /### values
    preg_match_all($pattern,$endpoints,$matches);

?>

<body>
    <!-- Start of form, uses built-in htmlspecialchars security function as added precaution:
    http://php.net/manual/en/function.htmlspecialchars.php -->
    <form enctype="multipart/form-data" method="post" action=<?php print htmlspecialchars($_SERVER["PHP_SELF"]); ?>>

<?php 
if (!($_SERVER["REQUEST_METHOD"] == "POST")) {
    
?>

    <center>
    <fieldset style="width:270px"><legend class="legendtitle">Select extension(s) to reboot or reload:</legend>

<!-- Start of form, uses built-in htmlspecialchars security function as added precaution:
    http://php.net/manual/en/function.htmlspecialchars.php -->
    <form enctype="multipart/form-data" method="post" action=<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>>

    <!-- begin table -->
    <table border="1" cellspacing="3" cellpadding="3">
        <thead>
            <tr>
                <th><center><h4>Selected</h4></center></th>
                <th><center><h4>Extension</h4></center></th>
                <th><center><h4>Brand</h4></center></th>
                <th><center><h4>Model</h4></center></th>
                <th><center><h4>Firmware</h4></center></th>
            </tr>
        </thead>
        <tbody>
<?php } ?>

<?php

// print default table using file
if (!($_SERVER["REQUEST_METHOD"] == "POST")) {
    foreach($matches[1] as $item => $value) {
        if($value >= $f_ext && $value <= $l_ext) {
            echo "\t\t<tr>\n\t\t\t<td><input type=\"checkbox\" name=\"extension[]\" value=\"$value\"></td>\n";
            echo "\t\t\t<td><strong>$value</strong></td>\n";
            $useragent = "";
            $useragent = $astman->PJSIPShowEndpoint($value);
            $ext_detail = explode(" ", $useragent['UserAgent'],3);
            echo "\t\t\t<td>$ext_detail[0]</td>\n";
            echo "\t\t\t<td>$ext_detail[1]</td>\n";
            echo "\t\t\t<td>$ext_detail[2]</td>\n";
            echo "\t\t</tr>\n";
        }
    }
    echo "</tbody>\n";
    echo "</table>\n"; // end table
    echo "<br>\n";
    echo "<p align=\"center\"><input type=\"submit\" name=\"reload\" value=\"Reload\">";
    echo "<input type=\"submit\" name=\"reboot\" value=\"Reboot\"></p>";
    echo "\n</form>";
    echo "\n</fieldset>";
    echo "\n</center>";

    $astman->Logout();
} else {
    ?>

<?php 

}

?>

</body>

</html>