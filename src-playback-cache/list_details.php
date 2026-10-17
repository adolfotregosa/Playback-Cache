<?PHP
/* Playback Cache: the cached folder list with size, keep age and release hour.
 * Shared by control.php and the Cached tab so both show exactly what the cleaner
 * will do: the cleaner ages from the stamps file and treats a "keep these folders
 * longer" name as name followed by a slash anywhere in the path, both rules live here.
 */
function pc_list_details($cfgfile) {
	$out = array();
	$settings = @parse_ini_file($cfgfile, true);
	if (!is_array($settings)) { return $out; }
	$list_file = isset($settings["exclusion_list"]) ? $settings["exclusion_list"] : "";
	if ($list_file == "" || !is_file($list_file)) { return $out; }
	$keep = isset($settings["keep_hours"]) ? (int)$settings["keep_hours"] : 24;
	$keep_sub = isset($settings["keep_hours_long"]) ? (int)$settings["keep_hours_long"] : 720;
	$keep_names = isset($settings["keep_long_folders"]) ? trim($settings["keep_long_folders"]) : "";
	$keep_regex = "";
	if ($keep_names != "") {
		$parts = array();
		foreach (preg_split('/\s*,\s*/', $keep_names) as $name) {
			$name = trim($name);
			if ($name != "") { $parts[] = preg_quote($name, '#'); }
		}
		if ($parts) { $keep_regex = '#(' . implode('|', $parts) . ')/#'; }
	}
	$stamps = array();
	if (is_file($list_file . ".stamps")) {
		foreach ((array)file($list_file . ".stamps") as $stamp_line) {
			$stamp_f = explode("\t", rtrim($stamp_line));
			if (count($stamp_f) == 2 && $stamp_f[1] != "") { $stamps[$stamp_f[1]] = (int)$stamp_f[0]; }
		}
	}
	// du over a big library folder is not free and the Cached tab polls while a job
	// runs, so measured sizes are reused for a minute, ages are always computed fresh
	$cache_file = "/var/run/playback-cache/du.cache";
	$cache = array();
	if (is_file($cache_file)) {
		$cache = json_decode((string)file_get_contents($cache_file), true);
		if (!is_array($cache)) { $cache = array(); }
	}
	$cache_new = array();
	$now = time();
	foreach (preg_split("/\r\n|\r|\n/", (string)file_get_contents($list_file)) as $list_line) {
		if (trim($list_line) == "") { continue; }
		if (isset($cache[$list_line]["ts"]) && is_array($cache[$list_line]) && $now - (int)$cache[$list_line]["ts"] < 60) {
			$cache_new[$list_line] = $cache[$list_line];
		}
		else {
			$size = 0;
			if (is_dir($list_line)) {
				$du = trim((string)@shell_exec("du -s -B1 " . escapeshellarg($list_line) . " 2> /dev/null"));
				$size = (int)$du;
			}
			$cache_new[$list_line] = array("size" => $size, "ts" => $now);
		}
		$age = array_key_exists($list_line, $stamps) ? intval(($now - $stamps[$list_line]) / 3600) : null;
		$hours = ($keep_regex != "" && preg_match($keep_regex, $list_line)) ? $keep_sub : $keep;
		$out[] = array("path" => $list_line, "size" => (int)$cache_new[$list_line]["size"], "age" => $age,
			"release" => is_null($age) ? null : $hours - $age);
	}
	// best effort, /var/run is tmpfs, a failed write only costs the cache
	if (is_dir(dirname($cache_file))) { @file_put_contents($cache_file, json_encode($cache_new), LOCK_EX); }
	return $out;
}
