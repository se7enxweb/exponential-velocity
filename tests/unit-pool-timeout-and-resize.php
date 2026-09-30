<?php
/**
 * Two pool settings that the documentation described and the pool ignored.
 *
 *   Q.webserver.requestTimeout  was enforced only on fork-per-request
 *       children. A pooled script caught in a loop, or waiting on a backend
 *       that never answers, held its worker and its visitor for ever. Now the
 *       visitor gets 504 once the limit passes, the worker is killed and
 *       replaced, and the request is not run again elsewhere.
 *
 *   The control panel's workers/resize  answered 501 "Pool does not support
 *       dynamic resize yet". The pool now grows at once, and shrinks by
 *       retiring idle workers.
 *
 * Against a real server with a two-worker pool, over HTTP/1.1 from this
 * machine (where the panel is allowed).
 *
 *   php tests/unit-pool-timeout-and-resize.php
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
$base = sys_get_temp_dir() . DS . 'qbix-pool-tr-' . getmypid();
$root = $base . DS . 'web';
@mkdir($root, 0700, true);
file_put_contents($root . DS . 'slow.php', '<?php
$n = (int) @file_get_contents(__DIR__ . "/slow.count"); file_put_contents(__DIR__ . "/slow.count", $n + 1);
sleep(30); echo "SLOW-DONE";');
file_put_contents($root . DS . 'fast.php', '<?php echo "FAST ", getmypid();');

function freePort()
{
	for ($i = 0; $i < 40; ++$i) {
		$p = 19100 + random_int(0, 1400);
		$probe = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
		if ($probe) { fclose($probe); return $p; }
	}
	return 0;
}

/** One HTTP/1.1 request; returns [status, body, headers-lowercased, seconds]. */
function req($port, $method, $path, $body = '', $headers = array())
{
	$t = microtime(true);
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5);
	if (!$s) return array(0, '', array(), 0);
	stream_set_timeout($s, 40);
	$h = "$method $path HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n";
	foreach ($headers as $k => $v) $h .= "$k: $v\r\n";
	if ($body !== '') $h .= "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n";
	fwrite($s, $h . "\r\n" . $body);
	$raw = '';
	while (!feof($s)) {
		$c = fread($s, 8192);
		if ($c === false or $c === '') { if (stream_get_meta_data($s)['timed_out']) break; continue; }
		$raw .= $c;
	}
	fclose($s);
	$split = strpos($raw, "\r\n\r\n");
	if ($split === false) return array(0, $raw, array(), microtime(true) - $t);
	$head = substr($raw, 0, $split);
	$status = preg_match('#^HTTP/\S+ (\d+)#', $head, $m) ? (int) $m[1] : 0;
	$hdrs = array();
	foreach (explode("\r\n", $head) as $line) {
		if (strpos($line, ':') !== false) {
			list($k, $v) = explode(':', $line, 2);
			$hdrs[strtolower(trim($k))] = trim($v);
		}
	}
	return array($status, substr($raw, $split + 4), $hdrs, microtime(true) - $t);
}

$port = freePort();
if (!$port) { check('free port', 0, 1); exit(1); }
file_put_contents($base . DS . 'config.json', json_encode(array('Q' => array(
	'webserver' => array('requestTimeout' => 2),
	'web' => array('cache' => array('enabled' => false)),
))));
$cmd = array(PHP_BINARY, $server, '--config=' . $base . DS . 'config.json',
	'--root=' . $root, '--port=' . $port, '--workers=2');
// Run from $base: the panel keeps its password in local/panel.json under the
// working directory, which must not be this repository.
$proc = proc_open($cmd, array(
	0 => array('file', '/dev/null', 'r'),
	1 => array('file', $base . DS . 'log', 'w'),
	2 => array('file', $base . DS . 'log', 'a'),
), $pipes, $base);
$up = false;
for ($i = 0; $i < 60; ++$i) {
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 1);
	if ($s) { fclose($s); $up = true; break; }
	usleep(250000);
}
check('the server started', $up, true);

if ($up) {
	// ── requestTimeout reaches pooled workers ───────────────────
	list($st, $body, , $secs) = req($port, 'GET', '/slow.php');
	check('a pooled script past requestTimeout is answered 504', $st, 504);
	check('...within a few seconds, not after the script\'s 30', $secs < 8, true);
	usleep(1500000);
	check('...and it was not run again on another worker',
		(int) @file_get_contents($root . DS . 'slow.count'), 1);
	check('...and the kill is logged with its reason',
		(bool) preg_match('/worker \d+ killed after [\d.]+s on GET \/slow\.php \(requestTimeout 2s\)/',
			(string) @file_get_contents($base . DS . 'log')), true);
	list($st, $body) = req($port, 'GET', '/fast.php');
	check('the pool still answers afterwards', array($st, strncmp($body, 'FAST ', 5) === 0), array(200, true));

	// ── the panel can resize the pool ───────────────────────────
	list($st, $body) = req($port, 'POST', '/Q/api/auth/setup', json_encode(array('password' => 'Rz8#kWq2!Lm5@Tv9Xy')));
	$token = (json_decode($body, true) ?: array())['token'] ?? '';
	check('the panel password is set up', $token !== '', true);
	$auth = array('Authorization' => 'Bearer ' . $token);

	list($st, $body) = req($port, 'POST', '/Q/api/workers/resize', json_encode(array('workers' => 4)), $auth);
	$r = json_decode($body, true) ?: array();
	check('workers/resize answers 200, not 501', $st, 200);
	check('...growing the pool to 4 at once', array($r['target'] ?? null, $r['workers'] ?? null), array(4, 4));

	list($st, $body) = req($port, 'POST', '/Q/api/workers/resize', json_encode(array('workers' => 1)), $auth);
	$r = json_decode($body, true) ?: array();
	check('...and shrinking it to 1 by retiring idle workers',
		array($st, $r['target'] ?? null, $r['workers'] ?? null), array(200, 1, 1));
	$pids = array();
	for ($i = 0; $i < 4; ++$i) {
		list($st, $body) = req($port, 'GET', '/fast.php');
		if ($st === 200) $pids[] = trim(substr($body, 5));
	}
	check('...after which one worker serves every request', count(array_unique($pids)), 1);

	list($st, $body) = req($port, 'POST', '/Q/api/workers/resize', json_encode(array('workers' => 0)), $auth);
	check('a count below 1 is refused', $st, 400);
}

$stp = @proc_get_status($proc);
if ($stp and !empty($stp['running'])) {
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
if ($fail) {
	echo "  server log (tail):\n";
	foreach (array_slice(file($base . DS . 'log') ?: array(), -15) as $l) echo '    ' . $l;
}
foreach (array('slow.php', 'fast.php', 'slow.count') as $f) @unlink($root . DS . $f);
@unlink($base . DS . 'local' . DS . 'panel.json'); @rmdir($base . DS . 'local');
@unlink($base . DS . 'config.json'); @unlink($base . DS . 'log');
@rmdir($root); @rmdir($base);

if ($fail) { printf("  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
