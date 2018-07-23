<?php

//File to use... may work with $wrets, I can't test that.
$txt_file = 'ext_test.txt';

//Check if file exists, if not
if (file_exists($txt_file)) {
    echo "The file $txt_file exists and is readable.";
    echo "<br><br><br>";
}
else {
    echo "The file $txt_file does not exist, and is not readable.";
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

//removes the "/" character in front of each extension.
$extensions = str_replace("/", "", implode("<br>", $matches[0]));

//echos results
echo $extensions;

?>