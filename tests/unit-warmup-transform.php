<?php

/**
 * Code the parent warm-up loads is compiled through the source transform.
 *
 * The warm-up (Q.webserver.warmup) loads an application in the parent so every
 * forked worker shares it copy-on-write. It first shipped reading
 * Q.webserver.preload -- a key that already meant an autoloader, required by
 * Q_WebServer::start() before the pool exists and so before the transform is
 * installed. The script ran twice, and the first run compiled everything it
 * loaded untransformed. Classes are compiled once and inherited by every
 * worker, so an application method that ends with exit ended the worker
 * instead of the request: Exponential's AJAX endpoints finish with
 * eZExecution::cleanExit() and every one answered 502 "Worker died". header()
 * from the same code went to the real function, which does nothing under the
 * CLI SAPI.
 *
 * Asserted here the way it failed: a class the warm-up loads, a method on it
 * that echoes and then exits, and a request that calls it. The request must
 * end with its output, and the worker must still be there for the next one.
 *
 *   php tests/unit-warmup-transform.php
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

$server = __DIR__ . '/../sbin/qbixserver.php';
$base = sys_get_temp_dir() . DS . 'qbix-warmup-' . getmypid();
$root = $base . DS . 'web';
$app = $base . DS . 'app';
@mkdir($root, 0700, true);
@mkdir($app, 0700, true);

// The application class. Loaded only by the warm-up -- nothing in the document
// root can autoload it -- so if a request finds it, it came from the parent.
file_put_contents($app . DS . 'probe.php', '<?php
class WarmupProbe
{
	static function finish()
	{
		echo "BYE";
		exit;
	}
}
');
file_put_contents($app . DS . 'warmup.php', '<?php require __DIR__ . "/probe.php";' . "\n");

file_put_contents($root . DS . 'index.php', '<?php
$a = isset($_GET["a"]) ? $_GET["a"] : "";
if ($a === "check") {
	echo class_exists("WarmupProbe", false) ? "PRELOADED" : "NOT-PRELOADED";
	return;
}
if ($a === "exit") {
	WarmupProbe::finish();
	echo "NOT-REACHED";
	return;
}
echo "INDEX";
');

file_put_contents($base . DS . 'config.json', json_encode(array('Q' => array(
	'webserver' => array('warmup' => $app . DS . 'warmup.php'),
	'compat' => array('skipSourceCodeTransform' => false),
))));

register_shutdown_function(function () use ($base, $root, $app) {
	foreach (array($root . '/index.php', $app . '/probe.php', $app . '/warmup.php',
		$base . '/config.json', $base . '/log') as $f) @unlink($f);
	@rmdir($root); @rmdir($app); @rmdir($base);
});

$port = 0;
for ($i = 0; $i < 40; ++$i) {
	$p = 19800 + random_int(0, 600);
	$probe = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
	if ($probe) { fclose($probe); $port = $p; break; }
}
if (!$port) { fwrite(STDERR, "  FAIL - no free port\n"); exit(1); }

$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($server)
	. ' --config=' . escapeshellarg($base . DS . 'config.json')
	. ' --root=' . escapeshellarg($root) . ' --port=' . $port . ' --workers=2';
// Descriptors rather than shell redirection, so the pid is the server's.
$descriptors = array(
	0 => array('file', '/dev/null', 'r'),
	1 => array('file', $base . '/log', 'w'),
	2 => array('file', $base . '/log', 'a'),
);
$proc = proc_open($cmd, $descriptors, $pipes);
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

/** @return array status and body */
function fetch($path, $port)
{
	$ctx = stream_context_create(array('http' => array(
		'ignore_errors' => true, 'timeout' => 10,
	)));
	$body = @file_get_contents("http://127.0.0.1:$port" . $path, false, $ctx);
	$status = 0;
	foreach ($http_response_header ?? array() as $h) {
		if (strpos($h, 'HTTP/') === 0) {
			$bits = explode(' ', $h);
			if (isset($bits[1])) $status = (int) $bits[1];
		}
	}
	return array($status, (string) $body);
}

// ── The warm-up ran, in the parent ──────────────────────────────

$log = (string) @file_get_contents($base . '/log');
check('the warm-up ran and reported what it warmed',
	(bool) preg_match('/warm-up warmup\.php warmed/', $log), true);

list($st, $body) = fetch('/index.php?a=check', $port);
check('a request finds the class the warm-up loaded', trim($body), 'PRELOADED');

// ── Its exit ends the request, not the worker ───────────────────

list($st, $body) = fetch('/index.php?a=exit', $port);
check('a warmed method that exits answers normally', $st, 200);
check('...with what it printed before exiting', trim($body), 'BYE');

// Both workers, more than once: a real exit would have taken one out each time.
for ($i = 0; $i < 4; ++$i) {
	list($st, $body) = fetch('/index.php?a=exit', $port);
	check("...and again (request " . ($i + 2) . ")", $st . ' ' . trim($body), '200 BYE');
}

list($st, $body) = fetch('/index.php', $port);
check('ordinary requests still work afterwards', $st . ' ' . trim($body), '200 INDEX');

// ── The key it is read from ─────────────────────────────────────

$pool = file_get_contents(__DIR__ . '/../src/Q/WebServer/Pool.php');
check('the pool reads its warm-up from its own key',
	(bool) preg_match("/Q_Config::get\\('Q', 'webserver', 'warmup'/", $pool), true);
check('...and not from preload, which is loaded before the transform',
	(bool) preg_match("/Q_Config::get\\('Q', 'webserver', 'preload'/", $pool), false);

if ($fail) {
	printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	printf("  server log:\n");
	foreach (array_slice(explode("\n",
		(string) @file_get_contents($base . '/log')), -20) as $l) printf("    %s\n", $l);
	exit(1);
}
printf("  PASS - %d case(s)\n", $pass);
