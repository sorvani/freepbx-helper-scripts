<?php

//keep these blank
$reload = "";
$reboot = "";
$extension = "";
$extension_value = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    echo "<center>";
    echo "<br><fieldset style=\"width:270px\"><legend class='submissionfieldset'>Submission Results:</legend>";

    if (isset($_POST['reload'])) {
        echo "<p class='success'>You opted to <strong>RELOAD</strong>: $reload</p>";
        if (!empty($_POST["extension"])) {
            $extension = $_POST['extension'];
            echo "<center>\n<table border=\"1\" cellspacing=\"3\" cellpadding=\"3\">\n<thead>\n<tr>\n<th><center><h4>Extension</h4></th>\n</tr>\n</thead>\n<tbody>";
            foreach ($extension as $extension_value) {
                echo "\t\t<tr>\n\t\t\t<td><center>$extension_value</center></td>\n\t\t</tr>\n";
            }
            echo "</tbody>\n";
            echo "</table>";
            //echo "<br><p align=\"center\"><input type=\"submit\" name=\"confirm\" value=\"Confirm RELOAD\"></p>";
        } else {
            $extension = 0;
            $extension_value = 0;
            echo "<p class='error'>You did not make selection!</p>";
        }
    }

    if (isset($_POST['reboot'])) {
        echo "<p class='success'>You opted to <strong>REBOOT</strong>: $reboot</p>";
        if (!empty($_POST["extension"])) {
            $extension = $_POST['extension'];
            echo "<center>\n<table border=\"1\" cellspacing=\"3\" cellpadding=\"3\">\n<thead>\n<tr>\n<th><center><h4>Extension</h4></th>\n</tr>\n</thead>\n<tbody>";
            foreach ($extension as $extension_value) {
                echo "\t\t<tr>\n\t\t\t<td><center>$extension_value</center></td>\n\t\t</tr>\n";
            }
            echo "</tbody>\n";
            echo "</table>";
            echo "<br><p align=\"center\"><input type=\"submit\" name=\"confirm\" value=\"Confirm REBOOT\"></p>";
        } else {
            $extension = 0;
            $extension_value = 0;
            echo "<p class='error'>You did not make selection!</p>";
        }
    }

    echo '</strong></p>';

}
