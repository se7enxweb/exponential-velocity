<?php

/**
 * Q.webserver.fallback answers a request nothing else matched.
 *
 * The setting called a method that did not exist, so every configured
 * fallback -- a string or {"file": ...} -- ended the request in a fatal error
 * instead of the page it named. Each form is checked against a running server
 * whose root has no index.php, so the front controller does not take the
 * request first:
 *
 *   "app.html"            200 with the file, for a single-page application
 *   "/app.html"           the same, with a leading slash
 *   {"file": "404.html"}  404 with the file as the page
 *   "router.php"          the script runs
 *   "missing.html"        the server's own 404
 *
 * A file that exists still wins over the fallback.
 *
 *   php tests/unit-fallback.php
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

function freePort()
{
	for ($i = 0; $i < 40; ++$i) {
		$p = 19100 + random_int(0, 1400);
		$probe = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
		if ($probe) { fclose($probe); return $p; }
	}
	return 0;
}

/** @return array|null [status, content-type, body] */
function fetch($port, $path, $method = 'GET')
{
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5);
	if (!$s) return null;
	stream_set_timeout($s, 10);
	fwrite($s, "$method $path HTTP/1.1\r\nHost: 127.0.0.1:$port\r\nConnection: close\r\n\r\n");
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
	if ($split === false) return null;
	$head = substr($raw, 0, $split);
	$body = substr($raw, $split + 4);
	if (stripos($head, "transfer-encoding: chunked") !== false) {
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
	preg_match('#^HTTP/1\.[01] (\d{3})#', $head, $m);
	preg_match('/^content-type:\s*([^;\r\n]+)/mi', $head, $t);
	return array((int) ($m[1] ?? 0), strtolower(trim($t[1] ?? '')), $body);
}

/** Start a server with $fallback, run $checks($port), stop it. */
function withServer($server, $name, $fallback, $checks)
{
	$base = sys_get_temp_dir() . DS . 'qbix-fallback-' . getmypid() . '-' . $name;
	$root = $base . DS . 'web';
	@mkdir($root, 0700, true);
	file_put_contents($root . DS . 'app.html', '<!doctype html><title>app</title>APP-SHELL');
	file_put_contents($root . DS . '404.html', '<!doctype html><title>gone</title>CUSTOM-404');
	file_put_contents($root . DS . 'style.css', 'body{color:red}');
	file_put_contents($root . DS . 'router.php', '<?php echo "ROUTER:" . $_SERVER["REQUEST_URI"];');

	$port = freePort();
	if (!$port) { check("$name: free port", 0, 1); return; }
	file_put_contents($base . DS . 'config.json', json_encode(array('Q' => array(
		'webserver' => array('fallback' => $fallback),
	))));
	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($server)
		. ' --config=' . escapeshellarg($base . DS . 'config.json')
		. ' --root=' . escapeshellarg($root) . ' --port=' . $port . ' --workers=1';
	$proc = proc_open($cmd, array(
		0 => array('file', '/dev/null', 'r'),
		1 => array('file', $base . '/log', 'w'),
		2 => array('file', $base . '/log', 'a'),
	), $pipes);
	if (!is_resource($proc)) { check("$name: server started", false, true); return; }

	$up = false;
	for ($i = 0; $i < 60; ++$i) {
		$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 1);
		if ($s) { fclose($s); $up = true; break; }
		usleep(500000);
	}
	if (!$up) {
		check("$name: server listened", false, true);
		fwrite(STDERR, (string) @file_get_contents($base . '/log'));
	} else {
		$checks($port);
		$log = (string) @file_get_contents($base . '/log');
		check("$name: no fatal error in the server log", stripos($log, 'fatal') === false
			&& stripos($log, 'undefined method') === false, true);
	}

	$status = proc_get_status($proc);
	if (!empty($status['pid'])) posix_kill($status['pid'], SIGTERM);
	for ($i = 0; $i < 50 and proc_get_status($proc)['running']; ++$i) usleep(100000);
	if (proc_get_status($proc)['running']) posix_kill($status['pid'], SIGKILL);
	proc_close($proc);
	foreach (array('app.html', '404.html', 'style.css', 'router.php') as $f) @unlink($root . DS . $f);
	@rmdir($root);
	@unlink($base . DS . 'config.json');
	@unlink($base . DS . 'log');
	@rmdir($base);
}

withServer($server, 'string', 'app.html', function ($port) {
	$r = fetch($port, '/some/client/route');
	check('string: an unmatched path gets 200', $r[0] ?? null, 200);
	check('string: ... with the named file', strpos($r[2] ?? '', 'APP-SHELL') !== false, true);
	check('string: ... as text/html', $r[1] ?? null, 'text/html');
	$r = fetch($port, '/some/client/route', 'HEAD');
	check('string: HEAD gets 200 without a body', array($r[0] ?? null, $r[2] ?? null), array(200, ''));
	$r = fetch($port, '/style.css');
	check('string: an existing file still wins', array($r[0] ?? null, $r[2] ?? null), array(200, 'body{color:red}'));
});

withServer($server, 'slash', '/app.html', function ($port) {
	$r = fetch($port, '/deep/link');
	check('leading slash: 200 with the named file',
		array($r[0] ?? null, strpos($r[2] ?? '', 'APP-SHELL') !== false), array(200, true));
});

withServer($server, 'file', array('file' => '404.html'), function ($port) {
	$r = fetch($port, '/no/such/page');
	check('{"file"}: an unmatched path gets 404', $r[0] ?? null, 404);
	check('{"file"}: ... with the named page', strpos($r[2] ?? '', 'CUSTOM-404') !== false, true);
	check('{"file"}: ... as text/html', $r[1] ?? null, 'text/html');
});

withServer($server, 'php', 'router.php', function ($port) {
	$r = fetch($port, '/pretty/url');
	check('.php: the script runs', array($r[0] ?? null, $r[2] ?? null), array(200, 'ROUTER:/pretty/url'));
});

withServer($server, 'missing', 'missing.html', function ($port) {
	$r = fetch($port, '/nothing');
	check("a fallback that does not exist: the server's own 404", $r[0] ?? null, 404);
});

if ($fail) {
	printf("  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	exit(1);
}
printf("  PASS - %d case(s)\n", $pass);
