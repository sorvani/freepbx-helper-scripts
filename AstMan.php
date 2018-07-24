<?php
class AstMan {
    var $socket;
    var $error;
    function AstMan() {
        $this->socket = FALSE;
        $this->error = "";
    }

    function Login() {
        // Open /etc/amportal.conf and get the Asterisk Manager connection information
        define("AMP_CONF", "/etc/amportal.conf");
        $file = file(AMP_CONF);
        if (is_array($file)) {
            foreach ($file as $line) {
                if (preg_match("/^\s*([a-zA-Z0-9_]+)=([a-zA-Z0-9 .&-@=_!<>\"\']+)\s*$/",$line,$matches)) {
                    $amp_conf[ $matches[1] ] = $matches[2];
                }
            }
        }

        require_once('DB.php'); //php-pear-db must first be installed on a new FreePBX 14 system
        $db_user = $amp_conf["AMPMGRUSER"];
        $db_pass = $amp_conf["AMPMGRPASS"];
        $db_host = $amp_conf["ASTMANAGERHOST"];
        $db_port = $amp_conf["ASTMANAGERPORT"];
        $db_timeout = $amp_conf["ASTMGRWRITETIMEOUT"];

        $this->socket = @fsockopen($db_host,$db_port, $errno, $errstr, $db_timeout);
        if (!$this->socket) {
            $this->error =  "Could not connect - $errstr ($errno)";
            return FALSE;
        } else {
            stream_set_timeout($this->socket, 1);
            $wrets = $this->Query("Action: Login\r\nUserName: $db_user\r\nSecret: $db_pass\r\nEvents: off\r\n\r\n");
            if (strpos($wrets, "Message: Authentication accepted") != FALSE) {
                return true;
            } else {
                $this->error = "Could not login - Authentication failed";
                fclose($this->socket);
                $this->socket = FALSE;
                return FALSE;
            }
        }
    }
  
    function Logout() {
	$wrets="";
        if ($this->socket) {
            fputs($this->socket, "Action: Logoff\r\n\r\n");
            while (!feof($this->socket)) {
            $wrets .= fread($this->socket, 8192);
        }
            fclose($this->socket);
            $this->socket = "FALSE";
        }
        return;
    }

    function Query($query) {
	$wrets="";
        if ($this->socket === FALSE)
            return FALSE;

        fputs($this->socket, $query);
        do {
            $line = fgets($this->socket, 4096);
            $wrets .= $line;
            $info = stream_get_meta_data($this->socket);
        } while ($line != "\r\n" && $info['timed_out'] == false );
        // This updated loop needs tested with original functions (GetDB, etc.)
        //} while (!feof($this->socket) && $info['timed_out'] == false );
        return $wrets;
    }

    function QueryFull($query) {
        $wrets="";
        if ($this->socket === FALSE)
            return FALSE;

        fputs($this->socket, $query);
        $socket = $this->socket;
        while (!feof($socket)) {
            $tmpData=fread($socket,8192);
            $wrets .= $tmpData;
        }
        return $wrets;
    }

    function GetError() {
        return $this->error;
    }

    function GetDB($family, $key) {
        $wrets = $this->Query("Action: Command\r\nCommand: database get $family $key\r\n\r\n");
        if ($wrets) {
            $value_start = strpos($wrets, "Value: ") + 7;
            $value_stop = strpos($wrets, "\n", $value_start);
            if ($value_start > 8) {
                $value = substr($wrets, $value_start, $value_stop - $value_start);
            }
        }
        return $value;
    }

    function PutDB($family, $key, $value){
        $wrets = $this->Query("Action: Command\r\nCommand: database put $family $key $value\r\n\r\n");
        if (strpos($wrets, "Updated database successfully") != FALSE){
            return TRUE;
        }
        $this->error =  "Could not updated database";
        return FALSE;
    }

    function DelDB($family, $key) {
        $wrets = $this->Query("Action: Command\r\nCommand: database del $family $key\r\n\r\n");
        if (strpos($wrets, "Database entry removed.") != FALSE) {
            return TRUE;
        }
        $this->error =  "Database entry does not exist";
        return FALSE;
    }

    function GetFamilyDB($family) {
        $wrets = $this->Query("Action: Command\r\nCommand: database show $family\r\n\r\n");
        if ($wrets) {
            $value_start = strpos($wrets, "Response: Follows\r\n") + 19;
            $value_stop = strpos($wrets, "--END COMMAND--\r\n", $value_start);
            if ($value_start > 18) {
                $wrets = substr($wrets, $value_start, $value_stop - $value_start);
            }
            $lines = explode("\n", $wrets);
            foreach($lines as $line) {
                if (strlen($line) > 4) {
                    $value_start = strpos($line, ": ") + 2;
                    $value_stop = strpos($line, " ", $value_start);
                    $key = trim(substr($line, strlen($family) + 2, strpos($line, " ") - strlen($family) + 2));			
                    $value[$key] = trim(substr($line, $value_start));
                }
            }
            return $value;
        }
        return FALSE;
    }

    function GetEndpointsPJSIP() {
        $wrets = $this->Query("Action: Command\r\nCommand: pjsip list endpoints\r\n\r\n");
        if (strpos($wrets, "Output: Objects found: ") != FALSE){
            return $wrets;
        }
        $this->error =  "Failed list PJSIP endpoints";
        return FALSE;
    }

    function RebootYealink($extension){
        $wrets = $this->Query("Action: Command\r\nCommand: pjsip send notify reboot-yealink endpoint $extension\r\n\r\n");
        if (strpos($wrets, "Output: Sending NOTIFY of type 'reboot-yealink' to '$extension'") != FALSE){
            return TRUE;
        }
        $this->error =  "Failed to send reboot-yealink command to $extension";
        return FALSE;
    }

    function ReloadYealink($extension){
        $wrets = $this->Query("Action: Command\r\nCommand: pjsip send notify reload-yealink endpoint $extension\r\n\r\n");
        if (strpos($wrets, "Output: Sending NOTIFY of type 'reload-yealink' to '$extension'") != FALSE){
            return TRUE;
        }
        $this->error =  "Failed to send reload-yealink command to $extension";
        return FALSE;
    }

    function PJSIPShowEndpoint($extension) {
        //$extension must only be a single extension
        $wrets = $this->QueryFull("Action: PJSIPShowEndpoint\r\nEndpoint: $extension\r\n\r\n");
        if (strpos($wrets,"Unable to retrieve endpoint") != FALSE) {
            $this->error = "Failed to get data for extension $extension";
            return FALSE;
        } else {
            $item = "";
            $getitem = 0;
            $lines = explode("\n", $wrets);
            foreach($lines as $line) {
                $a = explode(":", $line, 2);
	        $key=trim($a[0]);
	        $key_value=trim($a[1]);
                if (trim($a[0]) == "Event") {
                    if (trim($a[1]) == "ContactStatusDetail") {
                        $getitem = 1;
                    } else {
                        $getitem = 0;
                    }
                }
                if ($getitem == 1 && strlen(trim($a[0]))) {
                    $key = trim($a[0]);
                    $item[$key] = trim($a[1]);
                }
            }
            return $item;
        }
    }
}
?>
