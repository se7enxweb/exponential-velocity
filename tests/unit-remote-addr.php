<?php

/**
 * A pooled request is told who is asking.
 *
 * Two ways of getting it wrong, one on each protocol:
 *
 *   HTTP/1.1  The parent works the address out -- the connection's, or a
 *             forwarded one when the connection is a trusted proxy -- and the
 *             pool then sent the worker "127.0.0.1" regardless. Every
 *             application behind the pool saw every visitor as the server.
 *             REMOTE_PORT was not set at all.
 *
 *   HTTP/2    The address was taken from the X-Real-IP header, from anyone,
 *             and fell back to 127.0.0.1. Any client could name its own
 *             address to the rate limiter, the panel's address rules, the log
 *             and the application.
 *
 * The client here connects from 127.0.0.2, which Linux answers like any other
 * loopback address, so a real answer and a hard-coded 127.0.0.1 cannot be
 * mistaken for each other. It also sends forwarding headers naming an address
 * of its own choosing, which an untrusted client must not get away with --
 * while 127.0.0.3, configured as a trusted proxy, must still be able to pass
 * the address of the visitor it is forwarding for.
 *
 * HTTP/2 needs TLS, so the test makes itself a certificate and asks through
 * curl; without curl's HTTP/2 or the openssl extension that half is skipped.
 *
 *   php tests/unit-remote-addr.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

$pass = 0;
$fail = 0;
$skipped = array();

function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what,
		var_export($got, true), var_export($want, true));
}

if (!function_exists('pcntl_fork')) { printf("  skip  needs pcntl for the worker pool\n"); exit(0); }
if (PHP_OS_FAMILY !== 'Linux') { printf("  skip  needs 127.0.0.2 to be a loopback address\n"); exit(0); }

$server = __DIR__ . '/../sbin/qbixserver.php';
$client = '127.0.0.2';
$proxy = '127.0.0.3';
$forged = '203.0.113.9';

$http2 = function_exists('curl_init') && defined('CURL_HTTP_VERSION_2TLS')
	&& (curl_version()['features'] & CURL_VERSION_HTTP2)
	&& function_exists('openssl_pkey_new');

function freePort()
{
	for ($i = 0; $i < 40; ++$i) {
		$p = 19100 + random_int(0, 1400);
		$probe = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
		if ($probe) { fclose($probe); return $p; }
	}
	return 0;
}

/**
 * HTTP/1.1 from 127.0.0.2.
 * @return array|null decoded body, plus the client's own port as 'local'
 */
function fetch1($port, $from, $extraHeaders)
{
	$ctx = stream_context_create(array('socket' => array('bindto' => "$from:0")));
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5,
		STREAM_CLIENT_CONNECT, $ctx);
	if (!$s) return null;
	$local = stream_socket_get_name($s, false);
	stream_set_timeout($s, 10);
	fwrite($s, "GET /index.php HTTP/1.1\r\nHost: 127.0.0.1:$port\r\n"
		. $extraHeaders . "Connection: close\r\n\r\n");
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
	$data = json_decode(trim($body), true);
	if (!is_array($data)) return null;
	$data['local'] = (string) substr($local, strrpos($local, ':') + 1);
	return $data;
}

/**
 * HTTP/2 over TLS from 127.0.0.2, through curl.
 * @return array|null decoded body, plus 'version' and the client's own 'local' port
 */
function fetch2($port, $from, array $headers)
{
	$ch = curl_init("https://127.0.0.1:$port/index.php");
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2TLS,
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_SSL_VERIFYHOST => 0,
		CURLOPT_INTERFACE      => $from,
		CURLOPT_HTTPHEADER     => $headers,
		CURLOPT_TIMEOUT        => 10,
	));
	$body = curl_exec($ch);
	$version = curl_getinfo($ch, CURLINFO_HTTP_VERSION);
	$local = (string) curl_getinfo($ch, CURLINFO_LOCAL_PORT);
	curl_close($ch);
	$data = json_decode(trim((string) $body), true);
	if (!is_array($data)) return null;
	$data['version'] = $version;
	$data['local'] = $local;
	return $data;
}

/** A self-signed certificate for 127.0.0.1, written to $dir. */
function makeCert($dir)
{
	$key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
	$csr = openssl_csr_new(array('commonName' => '127.0.0.1'), $key, array('digest_alg' => 'sha256'));
	$crt = openssl_csr_sign($csr, null, $key, 1, array('digest_alg' => 'sha256'));
	openssl_x509_export($crt, $crtPem);
	openssl_pkey_export($key, $keyPem);
	file_put_contents($dir . DS . 'fullchain.pem', $crtPem);
	file_put_contents($dir . DS . 'privkey.pem', $keyPem);
}

function run($server, $forkPerRequest, $http2)
{
	global $client, $proxy, $forged, $skipped;
	$mode = $forkPerRequest ? 'fresh worker per request' : 'persistent workers';
	$base = sys_get_temp_dir() . DS . 'qbix-remote-' . getmypid() . '-' . (int) $forkPerRequest;
	$root = $base . DS . 'web';
	@mkdir($root, 0700, true);
	file_put_contents($root . DS . 'index.php', '<?php echo json_encode(array(
		"addr" => isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : null,
		"port" => isset($_SERVER["REMOTE_PORT"]) ? (string) $_SERVER["REMOTE_PORT"] : null,
		"https" => isset($_SERVER["HTTPS"]) ? (string) $_SERVER["HTTPS"] : null,
		"scheme" => isset($_SERVER["REQUEST_SCHEME"]) ? (string) $_SERVER["REQUEST_SCHEME"] : null,
	));');

	$port = freePort();
	$tlsPort = freePort();
	if (!$port or !$tlsPort or $port === $tlsPort) { check("$mode: free ports", 0, 1); return; }

	$config = array('webserver' => array(
		'forkPerRequest' => $forkPerRequest,
		'proxy' => array('trusted' => array($proxy)),
	));
	if ($http2) {
		makeCert($base);
		$config['web'] = array(
			'https' => array('mode' => 'manual', 'port' => $tlsPort,
				'cert' => $base . DS . 'fullchain.pem', 'key' => $base . DS . 'privkey.pem',
				'certsDir' => $base),
			'http2' => array('enabled' => true),
		);
	}
	file_put_contents($base . DS . 'config.json', json_encode(array('Q' => $config)));

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

	if (!$up) {
		check("$mode: server listened", false, true);
		fwrite(STDERR, (string) @file_get_contents($base . '/log'));
	} else {
		$r = fetch1($port, $client, '');
		check("$mode, HTTP/1.1: REMOTE_ADDR is the client's", $r['addr'] ?? null, $client);
		check("$mode, HTTP/1.1: REMOTE_PORT is the client's", $r['port'] ?? null, $r['local'] ?? 'no answer');

		$r = fetch1($port, $client, "X-Forwarded-For: $forged\r\nX-Real-IP: $forged\r\n");
		check("$mode, HTTP/1.1: an untrusted client cannot forward an address",
			$r['addr'] ?? null, $client);

		$r = fetch1($port, $proxy, "X-Forwarded-For: $forged\r\n");
		check("$mode, HTTP/1.1: a trusted proxy forwards the visitor's address",
			$r['addr'] ?? null, $forged);

		// The protocol, the same way: a plain connection is plain, whatever an
		// untrusted client says it came in on, and a trusted proxy that ended
		// TLS for the visitor can say so.
		$r = fetch1($port, $client, '');
		check("$mode, HTTP/1.1: a plain connection is not HTTPS",
			array(!empty($r['https']) && $r['https'] !== 'off', $r['scheme'] ?? null), array(false, 'http'));

		$r = fetch1($port, $client, "X-Forwarded-Proto: https\r\n"
			. "CloudFront-Forwarded-Proto: https\r\n"
			. "CF-Visitor: {\"scheme\":\"https\"}\r\n");
		check("$mode, HTTP/1.1: an untrusted client cannot forward HTTPS",
			array(!empty($r['https']) && $r['https'] !== 'off', $r['scheme'] ?? null), array(false, 'http'));

		foreach (array(
			'X-Forwarded-Proto' => "X-Forwarded-Proto: https\r\n",
			'CloudFront-Forwarded-Proto' => "CloudFront-Forwarded-Proto: https\r\n",
			'CF-Visitor' => "CF-Visitor: {\"scheme\":\"https\"}\r\n",
		) as $name => $header) {
			$r = fetch1($port, $proxy, $header);
			check("$mode, HTTP/1.1: a trusted proxy forwards HTTPS in $name",
				array($r['https'] ?? null, $r['scheme'] ?? null), array('on', 'https'));
		}

		$r = fetch1($port, $proxy, "X-Forwarded-Proto: http\r\n");
		check("$mode, HTTP/1.1: a trusted proxy forwarding http is not HTTPS",
			array(!empty($r['https']) && $r['https'] !== 'off', $r['scheme'] ?? null), array(false, 'http'));

		if ($http2) {
			$r = fetch2($tlsPort, $client, array("X-Real-IP: $forged", "X-Forwarded-For: $forged"));
			check("$mode, HTTP/2: the request went over HTTP/2",
				$r['version'] ?? null, CURL_HTTP_VERSION_2_0);
			check("$mode, HTTP/2: REMOTE_ADDR is the client's, not X-Real-IP",
				$r['addr'] ?? null, $client);
			check("$mode, HTTP/2: REMOTE_PORT is the client's", $r['port'] ?? null, $r['local'] ?? 'no answer');
			check("$mode, HTTP/2: a TLS connection is HTTPS from any client",
				array($r['https'] ?? null, $r['scheme'] ?? null), array('on', 'https'));

			$r = fetch2($tlsPort, $proxy, array("X-Forwarded-For: $forged"));
			check("$mode, HTTP/2: a trusted proxy forwards the visitor's address",
				$r['addr'] ?? null, $forged);
		} else {
			$skipped['http2'] = 'no HTTP/2 in curl, or no openssl extension';
		}
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

	foreach (array($root . '/index.php', $base . '/config.json', $base . '/log',
		$base . '/fullchain.pem', $base . '/privkey.pem') as $f) @unlink($f);
	@rmdir($root);
	@rmdir($base);
}

run($server, false, $http2);
run($server, true, $http2);

foreach ($skipped as $what => $why) printf("  skip  %s: %s\n", $what, $why);
echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
