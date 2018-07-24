<?php

//keep these blank
$reload = "";
$reboot = "";
$extension = "";
$extension_value = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    echo "<center>\n";
    echo "<br>\n<fieldset style=\"width:270px\"><legend class='submissionfieldset'>Submission Results:</legend>\n";

    if (isset($_POST['reload'])) {
        echo "<p class='success'>You opted to <strong>RELOAD</strong>:</p>\n";
        if (!empty($_POST["extension"])) {
            $extension = $_POST['extension'];
            echo "\n<table border=\"1\" cellspacing=\"3\" cellpadding=\"3\">\n<thead>\n<tr>\n<th><center><h4>Extension</h4></center></th>\n</tr>\n</thead>\n<tbody>";
            foreach ($extension as $extension_value) {
                echo "\t\t<tr>\n\t\t\t<td><center>$extension_value</center></td>\n\t\t</tr>\n";
            }
            echo "</tbody>\n";
            echo "</table>\n\n";
            ?>
<form enctype="multipart/form-data" method="post" action=<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>>
            <?php 
            foreach ($extension as $extension_value) {
                echo "\n<input type=\"hidden\" name=\"extension[]\" value=\"$extension_value\">";
            }
            echo "\n<br>\n<p align=\"center\"><input type=\"submit\" name=\"confirm\" value=\"Confirm RELOAD\"></p>";
            echo "\n</form>";
            echo "\n</fieldset>";
            echo "\n</center>";
        } else {
            $extension = 0;
            $extension_value = 0;
            echo "<p class='error'>however,</p>\n";
            echo "<p class='error'>you did not make selection!</p>";
            echo "\n</fieldset>";
            echo "\n</center>";
        }
    }

    if (isset($_POST['reboot'])) {
        echo "<p class='success'>You opted to <strong>REBOOT</strong>:</p>\n";
        if (!empty($_POST["extension"])) {
            $extension = $_POST['extension'];
            echo "\n<table border=\"1\" cellspacing=\"3\" cellpadding=\"3\">\n<thead>\n<tr>\n<th><center><h4>Extension</h4></center></th>\n</tr>\n</thead>\n<tbody>";
            foreach ($extension as $extension_value) {
                echo "\t\t<tr>\n\t\t\t<td><center>$extension_value</center></td>\n\t\t</tr>\n";
            }
            echo "</tbody>\n";
            echo "</table>\n\n";
            ?>
<form enctype="multipart/form-data" method="post" action=<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>>
            <?php 
            echo "\n<br>\n<p align=\"center\"><input type=\"submit\" name=\"confirm\" value=\"Confirm REBOOT\"></p>";
            echo "\n</form>";
            echo "\n</fieldset>";
            echo "\n</center>";
        } else {
            $extension = 0;
            $extension_value = 0;
            echo "<p class='error'>however,</p>\n";
            echo "<p class='error'>you did not make selection!</p>";
            echo "\n</fieldset>";
            echo "\n</center>";
        }
    }

}
?>
