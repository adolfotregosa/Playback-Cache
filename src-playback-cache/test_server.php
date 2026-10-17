<?PHP
/* Playback Cache: one shot media server check for the TEST button, answers as JSON.
 */
$type = isset($_POST["type"]) ? trim($_POST["type"]) : "";
$host = isset($_POST["host"]) ? trim($_POST["host"]) : "";
$port = isset($_POST["port"]) ? trim($_POST["port"]) : "";
$key  = isset($_POST["key"])  ? trim($_POST["key"])  : "";
$answer = array("ok" => false, "msg" => "something went wrong");

function ms_fetch($url, $header) {
	// body first, the last three characters are the http status code, 000 means no answer
	$cmd = "curl -s -k -m 8 ".($header != "" ? "-H ".escapeshellarg($header)." " : "")."-w '%{http_code}' ".escapeshellarg($url)." 2> /dev/null";
	$raw = (string)shell_exec($cmd);
	return array(substr($raw, 0, strlen($raw) - 3), substr($raw, -3));
}

if ($type == "" || $host == "" || $port == "" || $key == "") {
	$answer["msg"] = "type, host, port and key are all needed";
}
elseif ($type != "jelly" && $type != "emby" && $type != "plex") {
	$answer["msg"] = "unknown media server type";
}
elseif (!preg_match('/^[A-Za-z0-9._\-]+$/', $host) || !preg_match('/^[0-9]+$/', $port)) {
	$answer["msg"] = "host and port look invalid";
}
else {
	if ($type == "plex") {
		$header = "";
		$path = "/status/sessions?X-Plex-Token=".$key;
	}
	else {
		$header = 'Authorization: MediaBrowser Token="'.$key.'"';
		$path = ($type == "emby") ? "/emby/Sessions" : "/Sessions";
	}
	// same order as the mover: https first, plain http when nothing answers
	list($body, $code) = ms_fetch("https://$host:$port$path", $header);
	if ($code == "000") { list($body, $code) = ms_fetch("http://$host:$port$path", $header); }

	if ($code == "000") {
		$answer["msg"] = "no answer from $host:$port, check type, host and port";
	}
	elseif ($code == "401" || $code == "403") {
		$answer["msg"] = "the server answered but rejected the key";
	}
	elseif ($code != "200") {
		$answer["msg"] = "the server answered with HTTP $code";
	}
	elseif ($type == "plex") {
		if (strpos($body, "<MediaContainer") !== false) {
			$playing = substr_count($body, "<Video");
			$answer = array("ok" => true, "msg" => "key accepted".($playing > 0 ? ", $playing item(s) playing" : ", nothing playing"));
		}
		else {
			$answer["msg"] = "unexpected answer from Plex, is this really a Plex server?";
		}
	}
	else {
		$data = json_decode($body, true);
		$sessions = null;
		if (is_array($data)) {
			if (isset($data["Items"]) && is_array($data["Items"])) { $sessions = $data["Items"]; }
			elseif (isset($data["Sessions"]) && is_array($data["Sessions"])) { $sessions = $data["Sessions"]; }
			elseif ($data === array_values($data)) { $sessions = $data; }
		}
		if ($sessions !== null) {
			$playing = 0;
			foreach ($sessions as $session) {
				if (!empty($session["NowPlayingItem"])) { $playing++; }
			}
			$answer = array("ok" => true, "msg" => "key accepted".($playing > 0 ? ", $playing item(s) playing" : ", nothing playing"));
		}
		else {
			$answer["msg"] = "answered but not with a sessions list, is this really a $type server?";
		}
	}
}
header("Content-Type: application/json");
echo json_encode($answer);
?>
