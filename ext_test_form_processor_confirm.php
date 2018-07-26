<?php

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $extension = $_POST['extension'];

    if (isset($_POST['confirm_reload'])) {
        $astman->Login();
        foreach ($extension as $extension_value) {
            if ($astman->ReloadYealink($extension_value)) {
                echo "<center>NOTIFY sent to $extension_value</center<br />\r\n";
            } else {
                echo "<center>NOTIFY failed to $extension_value</center<br />\r\n";
            }
        }
        $astman->Logout();
        echo "Reload done.";
        echo "\n</fieldset>";
        echo "\n</center>";
    } 
    if (isset($_POST['confirm_reboot'])) {
        $astman->Login();
        foreach ($extension as $extension_value) {
            if ($astman->RebootYealink($extension_value)) {
                echo "<center>NOTIFY sent to $extension_value</center<br />\r\n";
            } else {
                echo "<center>NOTIFY failed to $extension_value</center<br />\r\n";
            }
        }
        $astman->Logout();
        echo "Reboot done.";
        echo "\n</fieldset>";
        echo "\n</center>";
    }

}

?>