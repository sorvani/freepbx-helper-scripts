<?php

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $extension = $_POST['extension'];

    if (isset($_POST['confirm'])) {

        foreach ($extension as $extension_value) {
            sleep(1);
            echo "<center>$extension_value</center>\n";
        }
    }

}

?>