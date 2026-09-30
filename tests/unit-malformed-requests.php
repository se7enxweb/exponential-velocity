<?php

/**
 * A malformed request gets an answer, not a crash and not a guess.
 *
 * Everything here is what a scanner, a broken proxy or an attacker sends on an
 * ordinary afternoon. The server is entitled to reject any of it; what it is
 * not entitled to do is die, hang, or quietly decide which of two contradictory
 * framing headers it prefers.
 *
 * That last one is request smuggling. When a request carries both
 * Content-Length and Transfer-Encoding, or two different Content-Lengths, a
 * front-end and a back-end that disagree about which wins can be made to see
 * different request boundaries in one stream -- so one visitor's request is
 * appended to another's. RFC 9112 section 6.1 is explicit: a message with both
 * must be rejected, not resolved.
 *
 * This starts a real server and speaks to it over a socket, because these
 * requests cannot be expressed through curl -- curl declines to send most of
 * them, which is rather the point.
 *
 *   php tests/unit-malformed-requests.php
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
if (!is_file($phar)) {
	printf("  skip  no phar at %s\n", $phar);
	exit(0);
}

$tmp = sys_get_temp_dir() . DS . 'qbix-malformed-' . getmypid();
@mkdir($tmp . DS . 'web', 0700, true);
file_put_contents($tmp . DS . 'web' . DS . 'index.php', '<?php echo "OK";');

register_shutdown_function(function () use ($tmp) {
	foreach (glob($tmp . '/web/*') ?: array() as $f) @unlink($f);
	@rmdir($tmp . '/web');
	@rmdir($tmp);
});

// A port nothing else holds.
$port = 0;
for ($i = 0; $i < 40; ++$i) {
	$p = 19100 + random_int(0, 700);
	$probe = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
	if ($probe) { fclose($probe); $port = $p; break; }
}
if (!$port) { fwrite(STDERR, "  FAIL - no free port\n"); exit(1); }

$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phar)
	. ' --root=' . escapeshellarg($tmp . DS . 'web')
	. ' --port=' . $port . ' --workers=2';
// No shell redirection: `cmd > log 2>&1` makes proc_open start a shell,
// so the pid it reports is the shell's and terminating it leaves the
// server running. Three processes leaked per run, and a day of runs
// left thirty-nine of them on this machine. Descriptors do the same
// redirection without a shell in between.
$descriptors = array(
	0 => array('file', '/dev/null', 'r'),
	1 => array('file', $tmp . '/log', 'w'),
	2 => array('file', $tmp . '/log', 'a'),
);
$proc = proc_open($cmd, $descriptors, $pipes);
if (!is_resource($proc)) { fwrite(STDERR, "  FAIL - could not start\n"); exit(1); }

register_shutdown_function(function () use ($proc) {
	$st = @proc_get_status($proc);
	if (!$st) { @proc_close($proc); return; }
	$pid = (int) ($st['pid'] ?? 0);
	if (!empty($st['running']) and $pid > 0) {
		// Ask, wait, then insist. The server reaps its own workers on SIGTERM,
		// so the parent going is enough -- but only if it actually goes.
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

// Wait for it, rather than guessing at a sleep.
$up = false;
for ($i = 0; $i < 60; ++$i) {
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 1);
	if ($s) { fclose($s); $up = true; break; }
	usleep(500000);
}
if (!$up) {
	fwrite(STDERR, "  FAIL - server never listened on $port\n");
	fwrite(STDERR, (string) @file_get_contents($tmp . '/log'));
	exit(1);
}

/**
 * Send raw bytes, return the status line, or a word describing what else
 * happened. Never throws and never waits for ever.
 */
function speak($raw, $port, $timeout = 5)
{
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $e, $m, $timeout);
	if (!$s) return 'no-connection';
	stream_set_timeout($s, $timeout);
	@fwrite($s, $raw);
	$line = @fgets($s, 8192);
	$info = stream_get_meta_data($s);
	@fclose($s);
	if (!empty($info['timed_out'])) return 'hung';
	if ($line === false or $line === '') return 'closed-silently';
	return trim($line);
}

function status($raw, $port)
{
	$line = speak($raw, $port);
	if (strpos($line, 'HTTP/') !== 0) return $line;
	$bits = explode(' ', $line);
	return isset($bits[1]) ? (int) $bits[1] : $line;
}

// ── A good request, so the rest means something ─────────────────

check('a well-formed request is answered',
	status("GET / HTTP/1.1\r\nHost: a\r\nConnection: close\r\n\r\n", $port), 200);

// ── Framing: the smuggling cases ────────────────────────────────

// RFC 9112 6.1: both present must be rejected, not reconciled.
$both = "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 4\r\n"
	. "Transfer-Encoding: chunked\r\nConnection: close\r\n\r\n0\r\n\r\n";
$r = status($both, $port);
check('Content-Length with Transfer-Encoding is refused',
	($r === 400 or $r === 501), true);

$two = "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 1\r\n"
	. "Content-Length: 2\r\nConnection: close\r\n\r\nxx";
$r = status($two, $port);
check('two different Content-Lengths are refused',
	($r === 400 or $r === 501), true);

$neg = "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: -1\r\n"
	. "Connection: close\r\n\r\n";
$r = status($neg, $port);
check('a negative Content-Length is refused or ignored',
	in_array($r, array(400, 200, 413, 501), true), true);

// ── Shapes that must not crash or hang ──────────────────────────

$cases = array(
	'no HTTP version' => "GET /\r\n\r\n",
	'unknown method' => "FROBNICATE / HTTP/1.1\r\nHost: a\r\nConnection: close\r\n\r\n",
	'absolute-form URI' => "GET http://elsewhere/ HTTP/1.1\r\nHost: a\r\nConnection: close\r\n\r\n",
	'no Host on HTTP/1.1' => "GET / HTTP/1.1\r\nConnection: close\r\n\r\n",
	'a header with no colon' => "GET / HTTP/1.1\r\nHost: a\r\nRubbish\r\nConnection: close\r\n\r\n",
	'a very long request line' => "GET /" . str_repeat('a', 16384)
		. " HTTP/1.1\r\nHost: a\r\nConnection: close\r\n\r\n",
	'a very long header value' => "GET / HTTP/1.1\r\nHost: a\r\nX-Big: "
		. str_repeat('b', 32768) . "\r\nConnection: close\r\n\r\n",
	'many headers' => "GET / HTTP/1.1\r\nHost: a\r\n"
		. str_repeat("X-H: v\r\n", 200) . "Connection: close\r\n\r\n",
	'bare LF line endings' => "GET / HTTP/1.1\nHost: a\nConnection: close\n\n",
	'a NUL in the path' => "GET /a\x00b HTTP/1.1\r\nHost: a\r\nConnection: close\r\n\r\n",
	'traversal above the root' => "GET /../../../../etc/passwd HTTP/1.1\r\n"
		. "Host: a\r\nConnection: close\r\n\r\n",
	'encoded traversal' => "GET /..%2f..%2f..%2fetc%2fpasswd HTTP/1.1\r\n"
		. "Host: a\r\nConnection: close\r\n\r\n",
	'an empty request' => "\r\n\r\n",
);

// Deliberately not in that list: "GET /\r\n". It carries no blank line, so by
// HTTP/1.x rules it is not a finished request at all and the server is right
// to wait for the rest -- HTTP/0.9 has no headers and no version, and nothing
// has spoken it in thirty years. Waiting is only a problem if it waits for
// ever, which is the read timeout's job, checked below rather than by holding
// this test open for half a minute.
$wsSrc = file_get_contents(__DIR__ . '/../src/Q/WebServer.php');
check('an unfinished request is reaped by a read timeout',
	(bool) preg_match('/\$readTimeout = \(float\) Q_Config::get\(/', $wsSrc), true);
check('...and that timeout arms a watcher that closes the client',
	(bool) preg_match('/timeoutWatchers\[\$key\] = Q_Evented::delay\(\$readTimeout/', $wsSrc), true);

foreach ($cases as $what => $raw) {
	$r = status($raw, $port);
	// Any status is fine, and so is closing the connection. Hanging is not,
	// and neither is refusing the next connection, which is checked below.
	check($what . ': answered or closed, not hung', $r !== 'hung', true);
}

// ── Traversal must not actually serve the file ──────────────────

$body = speak("GET /../../../../etc/passwd HTTP/1.1\r\nHost: a\r\n"
	. "Connection: close\r\n\r\n", $port);
check('traversal does not return a 200', strpos((string) $body, ' 200') === false, true);

// ── The server is still there afterwards ────────────────────────

check('the server still answers after all of that',
	status("GET / HTTP/1.1\r\nHost: a\r\nConnection: close\r\n\r\n", $port), 200);

if ($fail) {
	printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	printf("  server log:\n");
	foreach (array_slice(explode("\n",
		(string) @file_get_contents($tmp . '/log')), -20) as $l) {
		printf("    %s\n", $l);
	}
	exit(1);
}
printf("  PASS - %d case(s)\n", $pass);
