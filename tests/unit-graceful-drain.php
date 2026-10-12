<?php

/**
 * A server that is told to stop (SIGTERM) drains its connections gracefully.
 *
 * It used to wait for every client to go away by itself -- which a connection
 * kept open for reuse never does -- so each stop sat out the whole drain
 * timeout with the listener already closed, and then closed the HTTP/2
 * connections browsers held without a word: everything multiplexed on one
 * failed together. Asserted against a real server:
 *
 *   - an HTTP/2 connection with a request in flight is sent GOAWAY with
 *     NO_ERROR naming that stream, at once;
 *   - a stream opened after the GOAWAY is refused (REFUSED_STREAM);
 *   - the request in flight is answered in full, and only then is the
 *     connection closed;
 *   - an idle HTTP/1.1 keep-alive connection is closed at once;
 *   - the server exits without waiting out the drain timeout.
 *
 *   php tests/unit-graceful-drain.php
 */

require __DIR__ . '/fixtures/race-harness.php';
require_once __DIR__ . '/../src/Q/WebServer/Http2/Hpack.php';
require_once __DIR__ . '/../src/Q/WebServer/Http2/Frame.php';
rh_require_pool();
if (!function_exists('openssl_pkey_new')) {
	printf("  skip  needs openssl for HTTP/2\n");
	exit(0);
}
list($base, $root) = rh_setup('graceful-drain');
file_put_contents($root . DS . 'slow.php', '<?php usleep(1500000); echo "SLOW DONE";');
file_put_contents($root . DS . 'static.txt', "static\n");

$key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
$csr = openssl_csr_new(array('commonName' => '127.0.0.1'), $key, array('digest_alg' => 'sha256'));
$crt = openssl_csr_sign($csr, null, $key, 2, array('digest_alg' => 'sha256'));
openssl_x509_export($crt, $crtPem);
openssl_pkey_export($key, $keyPem);
file_put_contents("$base/cert.pem", $crtPem);
file_put_contents("$base/key.pem", $keyPem);
$tlsPort = rh_free_port();
$GLOBALS['rh_extra_args'] = array("--https-port=$tlsPort");
$port = rh_start('drain', array('Q' => array(
	'web' => array(
		'https' => array('mode' => 'manual', 'cert' => "$base/cert.pem", 'key' => "$base/key.pem"),
		'http2' => array('enabled' => true),
		'cache' => array('enabled' => false),
	),
	// The drain timeout is five seconds within this bound.
	'webserver' => array('shutdownTimeout' => 15, 'keepAlive' => array('timeout' => 60)),
)), 2);
$pid = rh_server_pid('drain');

$F = 'Q_WebServer_Http2_Frame';
$ctx = stream_context_create(array('ssl' => array(
	'verify_peer' => false, 'verify_peer_name' => false, 'alpn_protocols' => 'h2',
)));
$s = false;
for ($i = 0; $i < 40 and !$s; ++$i) {
	$s = @stream_socket_client("tls://127.0.0.1:$tlsPort", $en, $es, 5, STREAM_CLIENT_CONNECT, $ctx);
	if (!$s) usleep(250000);
}
if (!$s) { check('TLS connection', 'no connection', 'connected'); rh_finish(); }
if ((stream_get_meta_data($s)['crypto']['alpn_protocol'] ?? '') !== 'h2') {
	printf("  skip  server did not negotiate h2\n");
	rh_finish();
}
$enc = new Q_WebServer_Http2_Hpack();
$dec = new Q_WebServer_Http2_Hpack();
fwrite($s, $F::PREFACE . $F::build($F::SETTINGS, 0, 0, ''));
stream_set_blocking($s, false);
$send = function ($id, $path) use ($s, $enc, $F) {
	fwrite($s, $F::build($F::HEADERS, $F::FLAG_END_HEADERS | $F::FLAG_END_STREAM, $id, $enc->encode(array(
		array(':method', 'GET'), array(':scheme', 'https'),
		array(':authority', '127.0.0.1'), array(':path', $path),
	))));
};

// An idle HTTP/1.1 keep-alive connection: one request answered, then nothing.
$h1 = stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5);
fwrite($h1, "GET /static.txt HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: keep-alive\r\n\r\n");
stream_set_timeout($h1, 5);
$first = '';
while (strpos($first, "static\n") === false and !feof($h1)) $first .= (string) fread($h1, 8192);
check('the HTTP/1.1 connection is kept alive', stripos($first, 'Connection: keep-alive') !== false, true);
stream_set_blocking($h1, false);

// A request in flight on HTTP/2, then the stop.
$send(1, '/slow.php');
usleep(300000);
$t0 = microtime(true);
posix_kill($pid, SIGTERM);

$buf = '';
$goaway = null; $goawayAt = null; $refused = false; $body = ''; $status = null; $ended = null; $closedAt = null;
$h1ClosedAt = null; $sentLate = false;
while (microtime(true) - $t0 < 12) {
	$r = array($s); $w = $e = null;
	if ($h1ClosedAt === null) {
		$c = @fread($h1, 8192);
		if (($c === '' or $c === false) and feof($h1)) $h1ClosedAt = microtime(true) - $t0;
	}
	if (@stream_select($r, $w, $e, 0, 50000)) {
		$c = @fread($s, 65536);
		if ($c === '' or $c === false) {
			if (feof($s)) { $closedAt = microtime(true) - $t0; break; }
		} else {
			$buf .= $c;
		}
	}
	while (($f = $F::read($buf)) !== null) {
		if ($f['type'] === $F::GOAWAY) {
			$goaway = unpack('Nlast/Nerror', $f['payload']);
			$goaway['last'] &= 0x7fffffff;
			$goawayAt = microtime(true) - $t0;
			if (!$sentLate) { $send(3, '/static.txt'); $sentLate = true; }
		} elseif ($f['type'] === $F::SETTINGS and !($f['flags'] & 1)) {
			fwrite($s, $F::build($F::SETTINGS, 1, 0, ''));
		} elseif ($f['stream'] === 3 and $f['type'] === $F::RST_STREAM) {
			$refused = unpack('N', $f['payload'])[1];
		} elseif ($f['stream'] === 1 and $f['type'] === $F::HEADERS) {
			foreach ($dec->decode($f['payload']) as $h) if ($h[0] === ':status') $status = $h[1];
		} elseif ($f['stream'] === 1 and $f['type'] === $F::DATA) {
			$body .= $f['payload'];
			if ($f['flags'] & $F::FLAG_END_STREAM) $ended = microtime(true) - $t0;
		}
	}
}
$exitedAt = null;
while (microtime(true) - $t0 < 15) {
	$st = @proc_get_status($GLOBALS['rh']['servers']['drain']);   // a zombie still answers kill(0)
	if (!$st or empty($st['running'])) { $exitedAt = microtime(true) - $t0; break; }
	usleep(50000);
}
printf("  note  GOAWAY at %.2fs, stream 1 ended at %s, h2 closed at %s, h1 closed at %s, server exited at %s\n",
	(float) $goawayAt, var_export($ended === null ? null : round($ended, 2), true),
	var_export($closedAt === null ? null : round($closedAt, 2), true),
	var_export($h1ClosedAt === null ? null : round($h1ClosedAt, 2), true),
	var_export($exitedAt === null ? null : round($exitedAt, 2), true));

check('GOAWAY with NO_ERROR naming the stream in flight', $goaway, array('last' => 1, 'error' => 0));
check('...sent at once', $goawayAt !== null and $goawayAt < 0.8, true);
check('a stream opened after it is refused (REFUSED_STREAM)', $refused, 7);
check('the stream in flight is answered in full', array($status, $body), array('200', 'SLOW DONE'));
check('...before the connection closes', $ended !== null and $closedAt !== null and $ended <= $closedAt, true);
check('the idle HTTP/1.1 connection is closed at once', $h1ClosedAt !== null and $h1ClosedAt < 0.8, true);
check('the server exits without waiting out the drain timeout', $exitedAt !== null and $exitedAt < 4.5, true);

@fclose($s);
@fclose($h1);
rh_finish();
