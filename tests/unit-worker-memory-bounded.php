<?php

/**
 * A persistent worker's memory does not grow with the requests it serves, and
 * if it ever does, the worker is replaced rather than left to grow.
 *
 * Every request used to leave an output buffer behind -- non-removable, with
 * that response in it -- so a worker grew by roughly one page per request
 * without limit: 1.1 GB after 600 requests on an Exponential install. Nothing
 * in the responses showed it. See Q_WebServer_Capture.
 *
 * Asserted against a real server with one worker, so every request lands on
 * the same process:
 *
 *   - after hundreds of requests, including ones that leave buffers open, the
 *     worker has one output buffer and has not grown;
 *   - a buffer a script leaves open is part of its response, not the next one;
 *   - with a memory ceiling the worker cannot stay under, each request is
 *     still answered and the worker is replaced after it, with a logged reason.
 *
 *   php tests/unit-worker-memory-bounded.php
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
$base = sys_get_temp_dir() . DS . 'qbix-membound-' . getmypid();
$root = $base . DS . 'web';
@mkdir($root, 0700, true);

file_put_contents($root . DS . 'index.php', '<?php
$a = isset($_GET["a"]) ? $_GET["a"] : "";
if ($a === "probe") {
	echo json_encode(array("pid" => getmypid(), "level" => ob_get_level(),
		"mem" => memory_get_usage()));
	return;
}
if ($a === "hold") {
	// Keep 2 MB for the life of the worker, so it is over a 1 MB ceiling
	// whatever the server itself weighs: a fresh worker is under 1 MB on
	// a lean build, and more than that with the compat layer loaded.
	if (!class_exists("Held", false)) { class Held { static $d; } }
	Held::$d = str_repeat("h", 2097152);
	echo json_encode(array("pid" => getmypid(), "mem" => memory_get_usage()));
	return;
}
if ($a === "open") { ob_start(); echo "OPEN"; return; }
if ($a === "handler") {
	// What Exponential does: install a method of a per-request object that
	// holds the request\'s log, as the error handler, and never restore it.
	if (!class_exists("RequestLog", false)) {
		class RequestLog { public $d; function __construct() { $this->d = str_repeat("x", 1048576); }
			function onError() { return false; } }
	}
	set_error_handler(array(new RequestLog(), "onError"));
	set_exception_handler(array(new RequestLog(), "onError"));
	echo "H";
	return;
}
if ($a === "retire") {
	Q_WebServer_Pool::retireAfterResponse("declares request constants");
	echo "RETIRING";
	return;
}
if ($a === "cycles") {
	// Garbage the way real applications make it: objects that point at each
	// other, unreachable once the request is over, carrying some weight.
	for ($k = 0; $k < 20; ++$k) {
		$x = new stdClass; $y = new stdClass;
		$x->other = $y; $y->other = $x; $x->payload = str_repeat("c", 32768);
	}
	echo "C";
	return;
}
if ($a === "endall") { echo "lost"; while (@ob_end_clean()); echo "kept"; return; }
echo str_repeat("<p>content</p>", 5000);
if (isset($_GET["i"]) and $_GET["i"] % 3 == 0) { ob_start(); echo "tail"; }
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

// ── Hundreds of requests on one worker ──────────────────────────

$port = startServer('steady', array('Q' => array('compat' => array('skipSourceCodeTransform' => false))), $servers);

for ($i = 1; $i <= 50; ++$i) fetch("/index.php?i=$i", $port);
list(, $b) = fetch('/index.php?a=probe', $port);
$early = json_decode($b, true);

$bad = 0;
for ($i = 51; $i <= 400; ++$i) {
	list($st, $body) = fetch("/index.php?i=$i", $port);
	$want = str_repeat('<p>content</p>', 5000) . ($i % 3 == 0 ? 'tail' : '');
	if ($st !== 200 or $body !== $want) ++$bad;
}
check('every response is exactly its own page', $bad, 0);

list(, $b) = fetch('/index.php?a=probe', $port);
$late = json_decode($b, true);
check('the same worker served them all', $late['pid'] ?? null, $early['pid'] ?? 'x');
check('it has one output buffer after 400 requests', $late['level'] ?? null, 1);
$grew = (($late['mem'] ?? 0) - ($early['mem'] ?? 0)) / 1048576;
check(sprintf('its heap did not grow with requests (%.2f MB over 350)', $grew),
	$grew < 2.0, true);

// ── A buffer left open belongs to its own request ───────────────

list($st, $body) = fetch('/index.php?a=open', $port);
check('a buffer a script leaves open is in its response', $st . ' ' . $body, '200 OPEN');
list(, $b) = fetch('/index.php?a=probe', $port);
$after = json_decode($b, true);
check('...and not in the next one', ($after['level'] ?? null), 1);
list($st, $body) = fetch('/index.php?a=endall', $port);
check('an end-every-buffer loop cannot take the capture buffer with it', $st, 200);
check('...and output after it still arrives', substr($body, -4), 'kept');

// ── Handlers a request installs do not outlive it ───────────────

list(, $b) = fetch('/index.php?a=probe', $port);
$before = json_decode($b, true);
$bad = 0;
for ($i = 0; $i < 120; ++$i) {
	list($st, $body) = fetch('/index.php?a=handler', $port);
	if ($st !== 200 or $body !== 'H') ++$bad;
}
check('requests installing handlers are answered', $bad, 0);
list(, $b) = fetch('/index.php?a=probe', $port);
$afterH = json_decode($b, true);
check('...by the same worker', $afterH['pid'] ?? null, $before['pid'] ?? 'x');
$grewH = (($afterH['mem'] ?? 0) - ($before['mem'] ?? 0)) / 1048576;
check(sprintf('handlers holding 2 MB each were released (%.1f MB over 120 requests)', $grewH),
	$grewH < 5.0, true);

// ── Cyclic garbage is collected between requests ────────────────

list(, $b) = fetch('/index.php?a=probe', $port);
$beforeC = json_decode($b, true);
for ($i = 0; $i < 150; ++$i) fetch('/index.php?a=cycles', $port);
list(, $b) = fetch('/index.php?a=probe', $port);
$afterC = json_decode($b, true);
$grewC = (($afterC['mem'] ?? 0) - ($beforeC['mem'] ?? 0)) / 1048576;
check(sprintf('cycles left by 150 requests are collected (%.1f MB grown; ~96 MB of garbage made)', $grewC),
	$grewC < 5.0, true);

// ── The application can ask for its worker to be replaced ───────

list(, $b) = fetch('/index.php?a=probe', $port);
$p1 = json_decode($b, true);
list($st, $body) = fetch('/index.php?a=retire', $port);
check('a request that asks to retire its worker is answered', $st . ' ' . $body, '200 RETIRING');
usleep(400000);
list(, $b) = fetch('/index.php?a=probe', $port);
$p2 = json_decode($b, true);
check('...and the next request is served by a fresh worker', ($p2['pid'] ?? 0) !== ($p1['pid'] ?? 0)
	and !empty($p2['pid']), true);
list(, $b) = fetch('/index.php?a=probe', $port);
$p3 = json_decode($b, true);
check('...which is not itself retired', $p3['pid'] ?? null, $p2['pid'] ?? 'x');
check('...and the reason is logged',
	(bool) preg_match('/replaced after \d+ requests: asked by the application: declares request constants/',
		(string) @file_get_contents("$base/steady.log")), true);

// ── A worker past its ceiling is replaced ───────────────────────

$cport = startServer('ceiling', array('Q' => array(
	'compat' => array('skipSourceCodeTransform' => false),
	'webserver' => array('workerMemoryCeiling' => 1),
)), $servers);

$pids = array();
$codes = array();
for ($i = 0; $i < 4; ++$i) {
	list($st, $b) = fetch('/index.php?a=hold', $cport);
	$codes[] = $st;
	$j = json_decode($b, true);
	if ($j) $pids[] = $j['pid'];
	usleep(300000);   // let the parent fork the replacement
}
check('every request over the ceiling is still answered', $codes, array(200, 200, 200, 200));
check('...each by a fresh worker', count(array_unique($pids)), 4);
// Workers inherit the parent's shutdown functions. A replaced worker used to
// run the pid-file cleanup as it exited, deleting the running server's pid
// file -- after which status and stop could not find it.
clearstatcache();
check('the server\'s pid file survives its workers being replaced',
	is_file("$base/ceiling.pid"), true);
$ownerPid = (int) @file_get_contents("$base/ceiling.pid");
check('...and still names the parent, not a worker',
	$ownerPid > 0 and !in_array($ownerPid, $pids, true) and posix_kill($ownerPid, 0), true);
$log = (string) @file_get_contents("$base/ceiling.log");
check('...and the replacement is logged with its reason',
	(bool) preg_match('/worker \d+ replaced after \d+ requests: heap .* MB over the 1 MB ceiling/', $log), true);

if ($fail) {
	printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	foreach (array('steady', 'ceiling') as $n) {
		printf("  %s log:\n", $n);
		foreach (array_slice(explode("\n", (string) @file_get_contents("$base/$n.log")), -12) as $l)
			printf("    %s\n", $l);
	}
	exit(1);
}
printf("  PASS - %d case(s)\n", $pass);
