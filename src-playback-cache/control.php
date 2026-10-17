<?PHP
/* Playback Cache: status, run now and cancel endpoint for the webui.
 */
$pc_dir = "/usr/local/emhttp/plugins/playback-cache";
$pc_log = "/var/log/playback-cache.log";

function pc_state($file, $job) {
	// one line: pid|run|epoch|text, the script rewrites it atomically
	$out = array("running" => false, "since" => 0, "text" => "", "end" => false);
	if (!file_exists($file)) { return $out; }
	$parts = explode("|", trim((string)file_get_contents($file)), 4);
	if (count($parts) < 4) { return $out; }
	// a killed script can leave its pid behind and the pid can be reused later,
	// the command line is the proof the job is really the one running
	$cmdline = (string)@file_get_contents("/proc/" . $parts[0] . "/cmdline");
	$out["running"] = ($parts[1] == "run") && strpos($cmdline, "playback_cache_$job") !== false;
	$out["since"] = (int)$parts[2];
	$out["text"] = $parts[3];
	$out["end"] = ($parts[1] == "end");
	return $out;
}

function pc_answer($ok, $msg) {
	header("Content-Type: application/json");
	echo json_encode(array("ok" => $ok, "msg" => $msg));
	exit;
}

$copy = pc_state("/var/run/playback-cache-copy.state", "copy");
$clean = pc_state("/var/run/playback-cache-clean.state", "clean");
// command line testing: php control.php action=status job=copy
if (php_sapi_name() == "cli" && isset($_SERVER["argv"])) {
	for ($i = 1; $i < count($_SERVER["argv"]); $i++) {
		$kv = explode("=", $_SERVER["argv"][$i], 2);
		if (count($kv) == 2) { $_REQUEST[$kv[0]] = $kv[1]; }
	}
}
$action = isset($_REQUEST["action"]) ? $_REQUEST["action"] : "status";

if ($action == "status") {
	header("Content-Type: application/json");
	echo json_encode(array("copy" => $copy, "clean" => $clean));
}
elseif ($action == "logs") {
	// the scripts write "copy: ..." and "clean: ..." lines to one log file, split them here
	$log = (string)@file_get_contents($pc_log);
	$copy_lines = array();
	$clean_lines = array();
	foreach (explode("\n", $log) as $line) {
		if (strpos($line, "copy: ") === 0) { $copy_lines[] = substr($line, 6); }
		elseif (strpos($line, "clean: ") === 0) { $clean_lines[] = substr($line, 7); }
	}
	header("Content-Type: application/json");
	echo json_encode(array("copy" => implode("\n", array_slice($copy_lines, -999)) . "\n",
		"clean" => implode("\n", array_slice($clean_lines, -999)) . "\n"));
}
elseif ($action == "clearlogs") {
	$method = isset($_SERVER["REQUEST_METHOD"]) ? $_SERVER["REQUEST_METHOD"] : "GET";
	if ($method != "POST" && php_sapi_name() != "cli") { pc_answer(false, "POST needed"); }
	@file_put_contents($pc_log, "");
	pc_answer(true, "logs cleared");
}
elseif ($action == "list") {
	// the folders currently cached, one implementation shared with the Cached tab
	require_once($pc_dir . "/list_details.php");
	header("Content-Type: application/json");
	echo json_encode(array("folders" => pc_list_details("/boot/config/plugins/playback-cache/playback-cache.cfg")), JSON_UNESCAPED_SLASHES);
}
elseif ($action == "run" || $action == "cancel") {
	// unRAID rejects POSTs without the csrf token, GET must not start or stop anything
	$method = isset($_SERVER["REQUEST_METHOD"]) ? $_SERVER["REQUEST_METHOD"] : "GET";
	if ($method != "POST" && php_sapi_name() != "cli") { pc_answer(false, "POST needed"); }
	$job = isset($_REQUEST["job"]) ? $_REQUEST["job"] : "";
	if ($job != "copy" && $job != "clean") { pc_answer(false, "unknown job"); }
	if ($action == "run") {
		if (($job == "copy" && $copy["running"]) || ($job == "clean" && $clean["running"])) {
			pc_answer(false, "a $job run is already going");
		}
		exec("nohup $pc_dir/scripts/playback_cache_$job now >> $pc_log 2>&1 &");
		pc_answer(true, "$job started");
	}
	// cancel
	if (($job == "copy" && !$copy["running"]) || ($job == "clean" && !$clean["running"])) {
		pc_answer(false, "no $job run is going on");
	}
	// every job has its own flag, a cancel for copy must never stop the cleaner, the
	// scripts notice it between files, the current file always stays complete
	touch("/var/run/playback-cache-$job.cancel");
	pc_answer(true, "will stop after the current file");
}
else {
	pc_answer(false, "unknown action");
}
