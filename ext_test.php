<!doctype html>
<html lang="en">

<head>
<meta charset="utf-8">
<title>EXT TEST</title>
<!-- <link rel="stylesheet" type="text/css" href="style.css"> -->
</head>

<?php

//Set the minumim and maxium extensions to display
$f_ext = 100;
$l_ext = 199;

//including the future php file that processes the form
include "ext_test_form_processor.php";

//File to use... may work with $wrets, I can't test that.
$txt_file = 'ext_test.txt';

//Check if file exists, if not
if (file_exists($txt_file)) {
    echo "<br><br><br>";
//    echo "<center>The file <strong>$txt_file</strong> exists and is readable.</center>";
    echo "<br>";
}
else {
    echo "The file $txt_file does not exist.";
}

//grabs contents of the text file.
$contents = file_get_contents($txt_file);

//regex pattern to use -- matches any number after a "/"
$pattern = '/\/([0-9]+)/';

//opens file in read-only mode to use.
$handle = fopen($txt_file, "r");
if ($handle) {
    while (($line = fgets($handle)) !== false) {
        preg_match_all($pattern,$contents,$matches);
    }
    
    //closes open file
    fclose($handle);
} else {
    echo "Can't open file.";
}

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

    <!-- begin table -->
    <center>
    <table border="1" cellspacing="3" cellpadding="3">
        <thead>
            <tr>
                <th><center><h4>Selected</h4></center></th>
                <th><center><h4>Extension</h4></center></th>
            </tr>
        </thead>
        <tbody>
<?php } ?>

<?php

// print default table using file
if (!($_SERVER["REQUEST_METHOD"] == "POST")) {
    foreach($matches[1] as $item => $value) {
        if($value >= $f_ext && $value <= $l_ext) {
            echo "\t\t<tr>\n\t\t\t<td align=\"center\"><input type=\"checkbox\" name=\"extension\" value=\"$value\"></td>\n";
            echo "\t\t\t<td align=\"center\"><strong>$value</strong></td>\n";
        }
    }

    print "</tbody>\n";
    print "</table>\n"; // end table
    print "<br>\n";
    print "<p align=\"center\"><input type=\"submit\" name=\"submit\" value=\"Reload\">&nbsp;";
    print "<input type=\"submit\" name=\"submit\" value=\"Reboot\"></p>";
    echo "</fieldset>";

} else {
    ?>
    <center>
    <table border="1" cellspacing="3" cellpadding="3">
    <thead>
        <tr>
            <th><center><h4>Submision Results:</h4></th>
        </tr>
    </thead>
    <tbody>

<?php 

// Submission results
    print "\t\t<tr>\n\t\t\t<td>Placeholder for later...</td>\n\t\t</tr>\n";
    print "</tbody>\n";
    echo "</table>";

}

?>

</center>

</form>

</body>

</html>