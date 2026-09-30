<?php

/**
 * A pooled request is told the port it arrived on.
 *
 * The pool built the worker's SERVER_PORT from the parent's own $_SERVER,
 * which a command-line process does not fill in, so it fell back to "8080"
 * for every request whatever the server listened on. Nothing looked wrong on
 * a server that happened to run on 8080, and every other port was misreported.
 *
 * It matters where the Host header carries no port -- behind a proxy on 80 or
 * 443 -- because an application then builds absolute URLs from SERVER_PORT.
 * Exponential's eZSys::serverPort() does exactly that, and its redirects went
 * to :8080.
 *
 * Both worker modes go through the pool, so both are started: persistent
 * workers, and a fresh worker per request. The port is a random one, so a
 * hard-coded answer cannot pass by accident.
 *
 *   php tests/unit-server-port.php
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

/** A port nothing else holds. */
function freePort()
{
	for ($i = 0; $i < 40; ++$i) {
		$p = 19100 + random_int(0, 1400);
		if ($p === 8080) continue;
		$probe = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
		if ($probe) { fclose($probe); return $p; }
	}
	return 0;
}

/** @return array status and body */
function fetch($port, $host)
{
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5);
	if (!$s) return array(0, '');
	stream_set_timeout($s, 10);
	fwrite($s, "GET /index.php HTTP/1.1\r\nHost: $host\r\nConnection: close\r\n\r\n");
	$raw = '';
	while (!feof($s)) {
		$chunk = fread($s, 8192);
		if ($chunk === false or $chunk === '') {
			if (stream_get_meta_data($s)['timed_out']) break;
			continue;
		}
		$raw .= $chunk;
	}
	fclose($s);
	$split = strpos($raw, "\r\n\r\n");
	if ($split === false) return array(0, '');
	$status = (int) substr($raw, 9, 3);
	$body = substr($raw, $split + 4);
	if (stripos(substr($raw, 0, $split), "transfer-encoding: chunked") !== false) {
		$out = '';
		while ($body !== '') {
			$nl = strpos($body, "\r\n");
			if ($nl === false) break;
			$len = hexdec(substr($body, 0, $nl));
			if ($len === 0) break;
			$out .= substr($body, $nl + 2, $len);
			$body = substr($body, $nl + 2 + $len + 2);
		}
		$body = $out;
	}
	return array($status, trim($body));
}

/**
 * Start a server in one worker mode, ask it for SERVER_PORT, stop it.
 */
function run($server, $forkPerRequest)
{
	$mode = $forkPerRequest ? 'fresh worker per request' : 'persistent workers';
	$base = sys_get_temp_dir() . DS . 'qbix-port-' . getmypid() . '-' . (int) $forkPerRequest;
	$root = $base . DS . 'web';
	@mkdir($root, 0700, true);
	file_put_contents($root . DS . 'index.php',
		'<?php echo isset($_SERVER["SERVER_PORT"]) ? $_SERVER["SERVER_PORT"] : "unset";');
	file_put_contents($base . DS . 'config.json', json_encode(array('Q' => array(
		'webserver' => array('forkPerRequest' => $forkPerRequest),
	))));

	$port = freePort();
	if (!$port) { check("$mode: a free port", 0, 1); return; }

	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($server)
		. ' --config=' . escapeshellarg($base . DS . 'config.json')
		. ' --root=' . escapeshellarg($root) . ' --port=' . $port . ' --workers=2';
	// Descriptors rather than shell redirection, so the pid is the server's.
	$proc = proc_open($cmd, array(
		0 => array('file', '/dev/null', 'r'),
		1 => array('file', $base . '/log', 'w'),
		2 => array('file', $base . '/log', 'a'),
	), $pipes);
	if (!is_resource($proc)) { check("$mode: server started", false, true); return; }

	$up = false;
	for ($i = 0; $i < 60; ++$i) {
		$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 1);
		if ($s) { fclose($s); $up = true; break; }
		usleep(500000);
	}

	if ($up) {
		list($status, $body) = fetch($port, "127.0.0.1:$port");
		check("$mode: answers", $status, 200);
		check("$mode: SERVER_PORT is the port it listens on", $body, (string) $port);

		// The case that broke redirects: a Host header with no port in it.
		list($status, $body) = fetch($port, 'example.com');
		check("$mode: SERVER_PORT without a port in Host", $body, (string) $port);
	} else {
		check("$mode: server listened", false, true);
		fwrite(STDERR, (string) @file_get_contents($base . '/log'));
	}

	$st = @proc_get_status($proc);
	$pid = (int) ($st['pid'] ?? 0);
	if ($st and !empty($st['running']) and $pid > 0) {
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

	foreach (array($root . '/index.php', $base . '/config.json', $base . '/log') as $f) @unlink($f);
	@rmdir($root);
	@rmdir($base);
}

run($server, false);
run($server, true);

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
