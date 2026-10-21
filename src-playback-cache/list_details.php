<?PHP
/* Playback Cache: the cached folder list with size and release time.
 * Shared by control.php and the Cached tab so both show exactly what the cleaner
 * will do: the cleaner ages from the stamps file and treats a "keep these folders
 * longer" name as name followed by a slash anywhere in the path, both rules live here.
 */
/* the cleaner only releases when its cron runs, so the time a folder really leaves the
 * cache is the first clean run at or after the moment it becomes due, these two find it */
function pc_cron_set($field, $min, $max, $names = array()) {
	$set = array();
	foreach (explode(',', strtolower(trim($field))) as $part) {
		if ($part === '') { return null; }
		$step = 1;
		if (strpos($part, '/') !== false) {
			list($part, $step) = explode('/', $part, 2);
			if (!ctype_digit($step) || (int)$step < 1) { return null; }
			$step = (int)$step;
		}
		if ($part === '*') { $lo = $min; $hi = $max; }
		else {
			$ends = explode('-', $part, 2);
			foreach ($ends as $i => $e) {
				if (isset($names[$e])) { $ends[$i] = $names[$e]; }
				elseif (ctype_digit($e)) { $ends[$i] = (int)$e; }
				else { return null; }
			}
			$lo = $ends[0];
			$hi = count($ends) == 2 ? $ends[1] : ($step > 1 ? $max : $lo);
		}
		if ($lo < $min || $hi > $max || $lo > $hi) { return null; }
		for ($v = $lo; $v <= $hi; $v += $step) { $set[$v] = true; }
	}
	ksort($set);
	return $set;
}
function pc_cron_next($cron, $after) { // first run at or after $after, null when cron is unusable
	$f = preg_split('/\s+/', trim($cron));
	if (count($f) != 5) { return null; }
	$mon_names = array('jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12);
	$dow_names = array('sun'=>0,'mon'=>1,'tue'=>2,'wed'=>3,'thu'=>4,'fri'=>5,'sat'=>6);
	$mi = pc_cron_set($f[0], 0, 59); $ho = pc_cron_set($f[1], 0, 23);
	$dom = pc_cron_set($f[2], 1, 31); $mo = pc_cron_set($f[3], 1, 12, $mon_names);
	$dow = pc_cron_set($f[4], 0, 7, $dow_names);
	if ($mi === null || $ho === null || $dom === null || $mo === null || $dow === null) { return null; }
	if (isset($dow[7])) { $dow[0] = true; }
	// cron rule: with both day fields restricted, either one matching is enough
	$dom_any = (trim($f[2]) === '*'); $dow_any = (trim($f[4]) === '*');
	$start = (int)(ceil($after / 60) * 60);
	$day = strtotime(date('Y-m-d 00:00:00', $start));
	for ($d = 0; $d < 400; $d++, $day = strtotime('+1 day', $day)) {
		if (!isset($mo[(int)date('n', $day)])) { continue; }
		$dm = isset($dom[(int)date('j', $day)]); $dw = isset($dow[(int)date('w', $day)]);
		if ($dom_any && $dow_any) { $ok = true; }
		elseif ($dom_any) { $ok = $dw; }
		elseif ($dow_any) { $ok = $dm; }
		else { $ok = $dm || $dw; }
		if (!$ok) { continue; }
		foreach (array_keys($ho) as $h) {
			foreach (array_keys($mi) as $m) {
				$t = mktime($h, $m, 0, (int)date('n', $day), (int)date('j', $day), (int)date('Y', $day));
				if ($t >= $start) { return $t; }
			}
		}
	}
	return null;
}
function pc_list_details($cfgfile) {
	$out = array();
	$settings = @parse_ini_file($cfgfile, true);
	if (!is_array($settings)) { return $out; }
	$list_file = isset($settings["exclusion_list"]) ? $settings["exclusion_list"] : "";
	if ($list_file == "" || !is_file($list_file)) { return $out; }
	$keep = isset($settings["keep_hours"]) ? (int)$settings["keep_hours"] : 24;
	$keep_sub = isset($settings["keep_hours_long"]) ? (int)$settings["keep_hours_long"] : 720;
	$clean_cron = isset($settings["clean_cron"]) ? trim($settings["clean_cron"]) : "";
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
	// runs, so measured sizes are reused for a minute, the release time is always fresh
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
		$stamp = array_key_exists($list_line, $stamps) ? (int)$stamps[$list_line] : null;
		$hours = ($keep_regex != "" && preg_match($keep_regex, $list_line)) ? $keep_sub : $keep;
		// the cleaner releases once the whole hours since the stamp are more than the keep
		// hours, and only when it runs: the first clean run at or after that moment
		$due = null; $release = null;
		if (!is_null($stamp)) {
			$due_ts = $stamp + ($hours + 1) * 3600;
			$due = ($due_ts <= $now);
			if ($clean_cron != "") {
				$run = pc_cron_next($clean_cron, max($due_ts, $now));
				if (!is_null($run)) { $release = (int)ceil(($run - $now) / 60); }
			}
		}
		$out[] = array("path" => $list_line, "size" => (int)$cache_new[$list_line]["size"],
			"due" => $due, "release" => $release, "cleaner" => ($clean_cron != ""));
	}
	// best effort, /var/run is tmpfs, a failed write only costs the cache
	if (is_dir(dirname($cache_file))) { @file_put_contents($cache_file, json_encode($cache_new), LOCK_EX); }
	return $out;
}
