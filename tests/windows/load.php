<?php
/**
 * A short, fixed load for the Windows tests (docs/windows.md): N requests
 * spread over the given URLs, C in flight at a time (curl_multi, closed
 * loop), and one JSON line with what came back: counts by status, errors,
 * latency percentiles and requests per second. tests/bench-load.php is the
 * full benchmark; this only answers "did every request get a good answer
 * under concurrency".
 *
 *   php tests/windows/load.php --requests=400 --concurrency=8 http://127.0.0.1:8080/ http://127.0.0.1:8080/site/
 *
 * Exits 0 when every answer had a status below 500 and no transfer failed.
 */

$requests = 200; $concurrency = 8; $urls = array(); $cookie = null;
foreach (array_slice($argv, 1) as $a) {
	if (preg_match('/^--requests=(\d+)$/', $a, $m)) $requests = (int) $m[1];
	elseif (preg_match('/^--concurrency=(\d+)$/', $a, $m)) $concurrency = max(1, (int) $m[1]);
	elseif (preg_match('/^--cookie=(.+)$/', $a, $m)) $cookie = $m[1];
	elseif (preg_match('~^https?://~', $a)) $urls[] = $a;
}
if (!$urls or !function_exists('curl_multi_init')) {
	fwrite(STDERR, "usage: php load.php [--requests=N] [--concurrency=C] URL...  (needs the curl extension)\n");
	exit(2);
}

$mh = curl_multi_init();
$sent = 0; $done = 0; $status = array(); $errors = array(); $times = array();
$start = microtime(true);
$add = function () use (&$sent, $requests, $urls, $mh, $cookie) {
	if ($sent >= $requests) return false;
	$ch = curl_init($urls[$sent % count($urls)]);
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
		CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_FOLLOWLOCATION => false));
	if ($cookie !== null) curl_setopt($ch, CURLOPT_COOKIE, $cookie);
	curl_multi_add_handle($mh, $ch);
	++$sent;
	return true;
};
for ($i = 0; $i < $concurrency; ++$i) $add();
do {
	curl_multi_exec($mh, $running);
	while ($info = curl_multi_info_read($mh)) {
		$ch = $info['handle'];
		++$done;
		if ($info['result'] !== CURLE_OK) {
			$e = curl_strerror($info['result']);
			$errors[$e] = ($errors[$e] ?? 0) + 1;
		} else {
			$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			$status[$code] = ($status[$code] ?? 0) + 1;
			$times[] = curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
		}
		curl_multi_remove_handle($mh, $ch);
		curl_close($ch);
		$add();
	}
	if ($running) curl_multi_select($mh, 0.5);
} while ($running or $done < $sent);
curl_multi_close($mh);
$elapsed = microtime(true) - $start;

sort($times);
$pct = function ($p) use ($times) {
	if (!$times) return null;
	return round($times[min(count($times) - 1, (int) floor($p / 100 * count($times)))], 1);
};
ksort($status);
$bad = 0;
foreach ($status as $code => $n) if ($code >= 500 or $code === 0) $bad += $n;
$bad += array_sum($errors);
echo json_encode(array(
	'requests' => $done, 'concurrency' => $concurrency, 'seconds' => round($elapsed, 2),
	'rps' => $elapsed > 0 ? round($done / $elapsed, 1) : null,
	'status' => (object) $status, 'errors' => (object) $errors, 'bad' => $bad,
	'p50' => $pct(50), 'p95' => $pct(95), 'p99' => $pct(99), 'max' => $times ? round(end($times), 1) : null,
)), "\n";
exit($bad ? 1 : 0);
