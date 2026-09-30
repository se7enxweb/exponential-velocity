<?php

/**
 * A duration on the dashboard is a number someone reads, not a raw subtraction.
 *
 * microtime(true) differences carry thirteen decimal places. The average was
 * rounded on its way out and the slowest request was not, so the card read
 *
 *   Avg response  258.4ms
 *   slowest: 1541.8879985809326ms
 *
 * -- two numbers beside each other disagreeing about how precisely this server
 * measures anything, and a string long enough to break the width of the card
 * holding it. It was reported by somebody looking at the page, because nothing
 * about it fails.
 *
 * getStats() reaches into Q_WebServer, Q_WebSocket, the cache, the log and the
 * component store, so stubbing it would mostly test the stubs. This starts a
 * real server and reads /Q/health, which serves the same payload the dashboard
 * renders -- and which is the reason the fix belongs at the source rather than
 * at the point of display.
 *
 *   php tests/unit-dashboard-precision.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

$pass = 0;
$fail = 0;

function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what,
		var_export($got, true), var_export($want, true));
}

$phar = __DIR__ . '/../sbin/qbixserver.phar';
if (!is_file($phar)) { printf("  skip  no phar built\n"); exit(0); }
if (DIRECTORY_SEPARATOR !== '/') { printf("  skip  needs a POSIX shell\n"); exit(0); }

$base = sys_get_temp_dir() . DS . 'qbix-precision-' . getmypid();
$root = $base . DS . 'web';
@mkdir($root, 0700, true);
// Something for the server to spend measurable time on, so 'slowest' is a
// real measurement rather than zero.
file_put_contents($root . DS . 'slow.php',
	'<?php usleep(120000); echo "SLOW";');
file_put_contents($root . DS . 'index.php', '<?php echo "OK";');

register_shutdown_function(function () use ($base, $root) {
	foreach (array($root . '/slow.php', $root . '/index.php') as $f) @unlink($f);
	@unlink($base . '/log');
	@rmdir($root); @rmdir($base);
});

$port = 0;
for ($i = 0; $i < 40; ++$i) {
	$p = 19900 + random_int(0, 600);
	$probe = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
	if ($probe) { fclose($probe); $port = $p; break; }
}
if (!$port) { fwrite(STDERR, "  FAIL - no free port\n"); exit(1); }

$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phar)
	. ' --root=' . escapeshellarg($root) . ' --port=' . $port . ' --workers=2';
// Descriptors, not shell redirection: `cmd > log 2>&1` makes proc_open start a
// shell, and the pid it reports is the shell's.
$proc = proc_open($cmd, array(
	0 => array('file', '/dev/null', 'r'),
	1 => array('file', $base . '/log', 'w'),
	2 => array('file', $base . '/log', 'a'),
), $pipes);
if (!is_resource($proc)) { fwrite(STDERR, "  FAIL - could not start\n"); exit(1); }
register_shutdown_function(function () use ($proc) {
	$st = @proc_get_status($proc);
	if (!$st) { @proc_close($proc); return; }
	$pid = (int) ($st['pid'] ?? 0);
	if (!empty($st['running']) and $pid > 0) {
		@proc_terminate($proc, 15);
		for ($i = 0; $i < 40; ++$i) {
			$now = @proc_get_status($proc);
			if (!$now or empty($now['running'])) break;
			usleep(250000);
		}
		$now = @proc_get_status($proc);
		if ($now and !empty($now['running'])) {
			@proc_terminate($proc, 9);
			if (function_exists('posix_kill')) @posix_kill($pid, 9);
		}
	}
	@proc_close($proc);
});

$up = false;
for ($i = 0; $i < 60; ++$i) {
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 1);
	if ($s) { fclose($s); $up = true; break; }
	usleep(500000);
}
if (!$up) {
	fwrite(STDERR, "  FAIL - server never listened\n");
	fwrite(STDERR, (string) @file_get_contents($base . '/log'));
	exit(1);
}

function fetch($path, $port)
{
	$ctx = stream_context_create(array('http' => array(
		'ignore_errors' => true, 'timeout' => 15,
	)));
	return (string) @file_get_contents("http://127.0.0.1:$port" . $path, false, $ctx);
}

// Give it something slow to measure, and something fast, so the two numbers
// are genuinely different.
fetch('/slow.php', $port);
fetch('/', $port);
fetch('/', $port);

$json = fetch('/Q/health', $port);
$stats = json_decode($json, true);
check('/Q/health answers with the stats payload', is_array($stats), true);
if (!is_array($stats)) {
	printf("        got: %s\n", substr($json, 0, 200));
	printf("\n  FAIL\n");
	exit(1);
}

check('it reports a slowest request', isset($stats['slowest']), true);
check('...that actually measured something', ($stats['slowest'] ?? 0) > 0, true);

/** Every float in the payload, keyed by its path. */
function floats($value, $path = '')
{
	$out = array();
	if (is_array($value)) {
		foreach ($value as $k => $v) {
			$out += floats($v, $path === '' ? (string) $k : "$path.$k");
		}
	} elseif (is_float($value)) {
		$out[$path] = $value;
	}
	return $out;
}

// One decimal is what avgMs has always used, so it is the house style. This
// checks the whole payload rather than the one field that was wrong, because
// the next raw microtime() to be added here will look exactly as innocent.
$loud = array();
foreach (floats($stats) as $path => $v) {
	$trimmed = rtrim(sprintf('%.10F', $v), '0');
	$decimals = strlen(substr(strrchr($trimmed, '.'), 1));
	if ($decimals > 1) $loud[$path] = $v;
}

check('no number in the payload carries more than one decimal', $loud, array());
if ($loud) {
	foreach ($loud as $path => $v) printf("        %s = %s\n", $path, var_export($v, true));
	printf("        round() it where it enters the payload, not where it is shown:\n");
	printf("        /Q/health serves these to anything that asks, not just the page.\n");
}

if ($fail) {
	printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	foreach (array_slice(explode("\n",
		(string) @file_get_contents($base . '/log')), -12) as $l) printf("    %s\n", $l);
	exit(1);
}
printf("  PASS - %d case(s), slowest reported as %sms\n", $pass, $stats['slowest']);
