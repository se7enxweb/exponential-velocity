<?php

/**
 * Q.webserver.trimMemory: a persistent worker gives the memory a large request needed back to the system once the
 * request is over, instead of keeping the memory manager's chunks for the next one.
 *
 * Two real servers with one worker each, the same large request (40 MB for a moment) then a small one; the worker's
 * resident memory (VmRSS) is read from /proc. With trimMemory the worker holds at least 20 MB less than without.
 *
 *   php tests/unit-trim-memory.php
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

if (!function_exists('pcntl_fork')) { printf("  skip  needs pcntl for the worker pool\n"); exit(0); }

$serverScript = __DIR__ . '/../sbin/qbixserver.php';
$base = sys_get_temp_dir() . DS . 'qbix-trim-' . getmypid();
$root = $base . DS . 'web';
@mkdir($root, 0700, true);

file_put_contents($root . DS . 'index.php', '<?php
if (isset($_GET["big"])) {
	// a request that needs 40 MB for a moment (a large report, an image), as a real page sometimes does
	// in small pieces, as a page builds its data: they live in the memory manager\'s 2 MB chunks (one large string
	// would be mapped and unmapped on its own and never cached)
	$parts = array();
	for ($k = 0; $k < 400000; ++$k) $parts[] = str_repeat("m", 64) . $k;
	echo intdiv(memory_get_usage(), 1048576) >= 40 ? "40" : "small";
	return;
}
echo json_encode(array("pid" => getmypid()));
');

$servers = array();
register_shutdown_function(function () use (&$servers, $base, $root) {
	foreach ($servers as $proc) {
		$st = @proc_get_status($proc);
		if ($st and !empty($st['running'])) {
			@proc_terminate($proc, 15);
			for ($i = 0; $i < 40; ++$i) {
				$now = @proc_get_status($proc);
				if (!$now or empty($now['running'])) break;
				usleep(250000);
			}
			$now = @proc_get_status($proc);
			if ($now and !empty($now['running'])) @proc_terminate($proc, 9);
		}
		@proc_close($proc);
	}
	foreach (glob($base . '/*') ?: array() as $f) if (is_file($f)) @unlink($f);
	@unlink($root . '/index.php'); @rmdir($root); @rmdir($base);
});

function freePort()
{
	for ($i = 0; $i < 40; ++$i) {
		$p = 20400 + random_int(0, 600);
		$probe = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
		if ($probe) { fclose($probe); return $p; }
	}
	return 0;
}

function startServer($name, $config, &$servers)
{
	global $serverScript, $base, $root;
	$port = freePort();
	if (!$port) { fwrite(STDERR, "  FAIL - no free port\n"); exit(1); }
	file_put_contents("$base/$name.json", json_encode($config));
	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($serverScript)
		. ' --config=' . escapeshellarg("$base/$name.json")
		. ' --root=' . escapeshellarg($root) . ' --port=' . $port . ' --workers=1'
		. ' --pid=' . escapeshellarg("$base/$name.pid");
	$proc = proc_open($cmd, array(
		0 => array('file', '/dev/null', 'r'),
		1 => array('file', "$base/$name.log", 'w'),
		2 => array('file', "$base/$name.log", 'a'),
	), $pipes);
	if (!is_resource($proc)) { fwrite(STDERR, "  FAIL - could not start\n"); exit(1); }
	$servers[] = $proc;
	for ($i = 0; $i < 60; ++$i) {
		$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 1);
		if ($s) { fclose($s); return $port; }
		usleep(500000);
	}
	fwrite(STDERR, "  FAIL - $name never listened\n" . @file_get_contents("$base/$name.log"));
	exit(1);
}

function fetch($path, $port)
{
	$ctx = stream_context_create(array('http' => array('ignore_errors' => true, 'timeout' => 15)));
	$body = @file_get_contents("http://127.0.0.1:$port" . $path, false, $ctx);
	$status = 0;
	foreach ($http_response_header ?? array() as $h) {
		if (strpos($h, 'HTTP/') === 0) { $bits = explode(' ', $h); $status = (int) ($bits[1] ?? 0); }
	}
	return array($status, (string) $body);
}


function rssKb($pid)
{
	$s = @file_get_contents("/proc/$pid/status");
	return ($s !== false && preg_match('/^VmRSS:\s+(\d+)/m', $s, $m)) ? (int) $m[1] : -1;
}

if (!is_dir('/proc/self')) { printf("  skip  needs /proc\n"); exit(0); }
$rss = array();
foreach (array('keep' => false, 'trim' => true) as $name => $trim) {
	$port = startServer($name, array('Q' => array('webserver' => array('trimMemory' => $trim))), $servers);
	list(, $b) = fetch('/index.php', $port);
	$pid = (int) (json_decode($b, true)['pid'] ?? 0);
	list($st, $big) = fetch('/index.php?big=1', $port);
	check("$name: the large request is answered", array($st, $big), array(200, '40'));
	list(, $b2) = fetch('/index.php', $port);
	check("$name: the same worker answers after it", (int) (json_decode($b2, true)['pid'] ?? 0), $pid);
	usleep(300000);
	$rss[$name] = rssKb($pid);
}
printf("  keep %d KB, trim %d KB\n", $rss['keep'], $rss['trim']);
check('a worker that trims holds at least 20 MB less after a large request (keep ' . $rss['keep'] . ' KB, trim ' . $rss['trim'] . ' KB)',
	$rss['keep'] > 0 && $rss['trim'] > 0 && $rss['keep'] - $rss['trim'] >= 20480, true);

printf("%s - %d case(s)\n", $fail ? '  FAIL' : '  PASS', $pass + $fail);
exit($fail ? 1 : 0);
