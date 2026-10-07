<?php
/**
 * A server that is told to stop, stops -- and nothing it started goes on
 * serving after it.
 *
 *   1. A worker killed while it holds APCu's lock leaves the lock held, and
 *      the server blocks in the kernel (futex_do_wait) at its next cache
 *      lookup. A SIGTERM that arrives while it drains such a request used to
 *      be logged and then nothing: the process never exited and kept its
 *      port. Q.webserver.shutdownTimeout now bounds the shutdown: the process
 *      is gone within it and the port is free.
 *
 *   2. The request timeout no longer leaves the lock held at all: the worker
 *      is sent SIGTERM, which it acts on outside APCu's lock (inside the
 *      callback of apcu_entry() by unwinding through it), and the server and
 *      the other workers go on using APCu.
 *
 *   3. A worker that does not act on SIGTERM is killed after
 *      Q.webserver.requestTimeoutGrace seconds.
 *
 *   4. Killed outright (SIGKILL), the server leaves no process listening on
 *      its port: neither the zygote nor a worker holds a listening socket,
 *      and each ends once the server is gone.
 *
 * Against real servers with small pools over HTTP/1.1 from this machine.
 * Needs pcntl, posix and APCu (started with -d apc.enable_cli=1); without
 * APCu only part 4 runs.
 *
 *   php tests/unit-shutdown-bounded.php
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

if (!function_exists('pcntl_fork') or !function_exists('posix_kill') or !is_dir('/proc/self')) {
	printf("  skip  needs pcntl, posix and /proc\n");
	exit(0);
}
$apcu = (bool) preg_match('/APCu Support => Enabled/',
	(string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d apc.enable_cli=1 --ri apcu 2>/dev/null'));

$server = __DIR__ . '/../sbin/qbixserver.php';
$base = sys_get_temp_dir() . DS . 'qbix-shutdown-' . getmypid();
$root = $base . DS . 'web';
@mkdir($root, 0700, true);

// Takes APCu's write lock (apcu_entry() holds it while its callback runs)
// and keeps it, its pid written first so the test can find it.
file_put_contents($root . DS . 'hold.php', '<?php
file_put_contents(__DIR__ . "/hold.pid", getmypid());
if (!empty($_GET["deaf"]) and function_exists("pcntl_sigprocmask")) pcntl_sigprocmask(SIG_BLOCK, array(SIGTERM));
apcu_entry("held-" . microtime(true), function () { sleep(60); return 1; });
echo "HOLD-DONE";');
// Reads APCu, as every cache lookup of the server does.
file_put_contents($root . DS . 'fetch.php', '<?php apcu_store("k", 1); echo "FETCH ", apcu_fetch("k"), " ", getmypid();');
file_put_contents($root . DS . 'fast.php', '<?php echo "FAST ", getmypid();');
// Never ends on SIGTERM.
file_put_contents($root . DS . 'deaf.php', '<?php
file_put_contents(__DIR__ . "/deaf.pid", getmypid());
if (function_exists("pcntl_sigprocmask")) pcntl_sigprocmask(SIG_BLOCK, array(SIGTERM, SIGINT, SIGALRM));
$end = time() + 30; while (time() < $end) { usleep(100000); }
echo "DEAF-DONE";');

function freePort()
{
	for ($i = 0; $i < 40; ++$i) {
		$p = 19100 + random_int(0, 1400);
		$probe = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
		if ($probe) { fclose($probe); return $p; }
	}
	return 0;
}

/** One HTTP/1.1 request; returns [status, body, seconds]. */
function req($port, $path, $timeout = 10)
{
	$t = microtime(true);
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 3);
	if (!$s) return array(0, '', 0);
	stream_set_timeout($s, $timeout);
	fwrite($s, "GET $path HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
	$raw = '';
	while (!feof($s)) {
		$c = fread($s, 8192);
		if ($c === false or $c === '') { if (stream_get_meta_data($s)['timed_out']) break; continue; }
		$raw .= $c;
	}
	fclose($s);
	$split = strpos($raw, "\r\n\r\n");
	$status = preg_match('#^HTTP/\S+ (\d+)#', $raw, $m) ? (int) $m[1] : 0;
	return array($status, $split === false ? '' : substr($raw, $split + 4), microtime(true) - $t);
}

/** Send a request and do not wait for the answer. */
function fire($port, $path)
{
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 3);
	if ($s) fwrite($s, "GET $path HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
	return $s;
}

/** Whether anything listens on the port: the socket table, not a connect. */
function listening($port)
{
	foreach (array('/proc/net/tcp', '/proc/net/tcp6') as $file) {
		foreach (array_slice(@file($file, FILE_IGNORE_NEW_LINES) ?: array(), 1) as $row) {
			$c = preg_split('/\s+/', trim($row));
			if (($c[3] ?? '') === '0A' and hexdec(substr($c[1], strrpos($c[1], ':') + 1)) === $port) return true;
		}
	}
	return false;
}

/** Every process descended from $pid. */
function descendants($pid)
{
	$children = array();
	foreach (glob('/proc/[0-9]*/stat') ?: array() as $f) {
		$stat = @file_get_contents($f);
		if (!$stat) continue;
		$rest = explode(' ', substr($stat, strrpos($stat, ')') + 2));
		$children[(int) ($rest[1] ?? 0)][] = (int) basename(dirname($f));
	}
	$all = array();
	$queue = array($pid);
	while ($queue) {
		$p = array_shift($queue);
		foreach ($children[$p] ?? array() as $c) { $all[] = $c; $queue[] = $c; }
	}
	return $all;
}

function alive($pid)
{
	if ($pid <= 0 or !@posix_kill($pid, 0)) return false;
	$stat = @file_get_contents("/proc/$pid/stat");
	return !$stat or !in_array(substr($stat, strrpos($stat, ')') + 2, 1), array('Z', 'X'), true);
}

function waitGone(array $pids, $seconds)
{
	$end = microtime(true) + $seconds;
	do {
		$left = array_values(array_filter($pids, 'alive'));
		if (!$left) return array();
		usleep(100000);
	} while (microtime(true) < $end);
	return $left;
}

/** Start a server; returns [pid, port, log] or null. */
function startServer($base, $root, $name, array $q, $workers = 2)
{
	$port = freePort();
	if (!$port) return null;
	$dir = $base . DS . $name;
	@mkdir($dir, 0700, true);
	file_put_contents($dir . DS . 'config.json', json_encode(array('Q' => $q)));
	$log = $dir . DS . 'log';
	$cmd = escapeshellarg(PHP_BINARY) . ' -d apc.enable_cli=1 ' . escapeshellarg($GLOBALS['server'])
		. ' ' . escapeshellarg('--config=' . $dir . DS . 'config.json')
		. ' ' . escapeshellarg('--root=' . $root) . ' --port=' . $port . ' --workers=' . (int) $workers;
	// Its own session, as a supervisor starts it; its pid is the server's.
	$pid = (int) shell_exec('cd ' . escapeshellarg($dir) . ' && exec setsid ' . $cmd
		. ' > ' . escapeshellarg($log) . ' 2>&1 < /dev/null & echo $!');
	for ($i = 0; $i < 80; ++$i) {
		if (listening($port)) break;
		usleep(250000);
	}
	// The pool is forked before the port opens; give the zygote its moment.
	usleep(500000);
	return array($pid, $port, $log);
}

function killAll(array $pids)
{
	foreach ($pids as $p) if ($p > 0) @posix_kill($p, SIGKILL);
}

function tailLog($log)
{
	foreach (array_slice(@file($log) ?: array(), -12) as $l) echo '    ' . $l;
}

$cleanup = array();

// ── 4. SIGKILL the server: nothing it started keeps the port or serves ──
$s = startServer($base, $root, 'kill', array('web' => array('cache' => array('enabled' => false))));
if ($s) {
	list($pid, $port, $log) = $s;
	list($st, $body) = req($port, '/fast.php');
	check('[kill] the server answers', $st, 200);
	$family = descendants($pid);
	$cleanup = array_merge($cleanup, array($pid), $family);
	check('[kill] it has a zygote and workers', count($family) >= 2, true);
	posix_kill($pid, SIGKILL);
	usleep(300000);
	check('[kill] no process listens on the port once the server is killed', listening($port), false);
	list($st) = req($port, '/fast.php', 2);
	check('[kill] ...and no request is answered', $st, 0);
	check('[kill] every process it started ends by itself', waitGone($family, 5), array());
	if ($fail) tailLog($log);
}

if (!$apcu) {
	printf("  skip  APCu is not available: the lock cases need it\n");
} else {
	// ── 2. requestTimeout ends a worker inside apcu_entry() and the lock is free ──
	$s = startServer($base, $root, 'timeout', array(
		'webserver' => array('requestTimeout' => 1, 'requestTimeoutGrace' => 1),
		'web' => array('cache' => array('enabled' => false)),
	), 3);
	if ($s) {
		list($pid, $port, $log) = $s;
		$cleanup = array_merge($cleanup, array($pid), descendants($pid));
		@unlink($root . DS . 'hold.pid');
		list($st, , $secs) = req($port, '/hold.php', 15);
		check('[timeout] a worker in apcu_entry() past requestTimeout is answered 504', $st, 504);
		$holder = (int) @file_get_contents($root . DS . 'hold.pid');
		check('[timeout] ...and that worker ends', waitGone(array($holder), 4), array());
		list($st, $body, $secs) = req($port, '/fetch.php', 5);
		check('[timeout] APCu is not left locked: another worker reads it', array($st, strncmp($body, 'FETCH 1', 7) === 0), array(200, true));
		check('[timeout] ...at once', $secs < 3, true);

		@unlink($root . DS . 'deaf.pid');
		list($st, , $secs) = req($port, '/deaf.php', 15);
		check('[timeout] a worker that ignores SIGTERM is answered 504 too', $st, 504);
		$deaf = (int) @file_get_contents($root . DS . 'deaf.pid');
		check('[timeout] ...and killed after requestTimeoutGrace', waitGone(array($deaf), 5), array());
		check('[timeout] ...which the log says', (bool) preg_match('/worker \d+ did not end [\d.]+s after SIGTERM; SIGKILL/',
			(string) @file_get_contents($log)), true);
		list($st) = req($port, '/fast.php', 5);
		check('[timeout] the pool still answers', $st, 200);
		$cleanup = array_merge($cleanup, descendants($pid));
		posix_kill($pid, SIGTERM);
		check('[timeout] SIGTERM stops the server', waitGone(array($pid), 15), array());
		if ($fail) tailLog($log);
	}

	// ── 1. SIGTERM while the server is blocked on an orphaned lock ──
	$limit = 4;
	$s = startServer($base, $root, 'deadline', array(
		'webserver' => array('requestTimeout' => 0, 'shutdownTimeout' => $limit),
		'web' => array('cache' => array('enabled' => true, 'dir' => $base . DS . 'deadline' . DS . 'cache',
			'apcu' => array('enabled' => true))),
	), 2);
	if ($s) {
		list($pid, $port, $log) = $s;
		$cleanup = array_merge($cleanup, array($pid), descendants($pid));
		@unlink($root . DS . 'hold.pid');
		// A worker takes the lock and keeps it; deaf to SIGTERM, so the
		// pool's shutdown has to kill it, as a timeout used to.
		$holdConn = fire($port, '/hold.php?deaf=1');
		for ($i = 0; $i < 40 and !is_file($root . DS . 'hold.pid'); ++$i) usleep(100000);
		usleep(300000);
		$holder = (int) @file_get_contents($root . DS . 'hold.pid');
		$cleanup[] = $holder;
		check('[deadline] a worker holds the lock', $holder > 0 and alive($holder), true);
		// A connection open before the stop, whose request comes during the
		// drain: the server looks it up in the cache and blocks there.
		$late = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 3);
		usleep(200000);
		$t = microtime(true);
		posix_kill($pid, SIGTERM);
		usleep(200000);
		if ($late) fwrite($late, "GET /fast.php HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
		usleep(700000);
		$wchan = trim((string) @file_get_contents("/proc/$pid/wchan"));
		check('[deadline] the server is blocked in the kernel on the lock', $wchan, 'futex_do_wait');
		$left = waitGone(array($pid), $limit + 3);
		$took = microtime(true) - $t;
		check('[deadline] it is gone within shutdownTimeout', $left, array());
		check('[deadline] ...not long after it (' . round($took, 1) . 's)', $took < $limit + 2, true);
		check('[deadline] ...and nothing listens on its port', listening($port), false);
		if ($late) @fclose($late);
		if ($holdConn) @fclose($holdConn);
		if ($fail) tailLog($log);
	}
}

killAll(array_filter($cleanup, 'alive'));
foreach (array('hold.php', 'fetch.php', 'fast.php', 'deaf.php', 'hold.pid', 'deaf.pid') as $f) @unlink($root . DS . $f);
@rmdir($root);
foreach (array('kill', 'timeout', 'deadline') as $d) {
	@unlink($base . DS . $d . DS . 'config.json'); @unlink($base . DS . $d . DS . 'log');
	foreach (glob($base . DS . $d . DS . 'cache' . DS . '*') ?: array() as $f) @unlink($f);
	@rmdir($base . DS . $d . DS . 'cache');
	@unlink($base . DS . $d . DS . 'local' . DS . 'panel.json'); @rmdir($base . DS . $d . DS . 'local');
	@rmdir($base . DS . $d);
}
@rmdir($base);

if ($fail) { printf("  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
