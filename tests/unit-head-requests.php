<?php

/**
 * HEAD is the GET without its body, over HTTP/1.1 and HTTP/2, for every kind
 * of answer: a page a worker renders, a page from the response cache, a
 * streamed answer and a static file.
 *
 * Over HTTP/2 a HEAD for anything PHP rendered went out with its whole body as
 * DATA frames, which a strict client (curl with a current nghttp2) rejects as
 * an error in the HTTP/2 framing layer; and a HEAD was never answered from the
 * response cache over HTTP/2, so a monitor sending HEAD rendered the page every
 * time. Asserted against a real server with persistent workers and the cache on:
 *
 *   HTTP/2 (TLS, ALPN h2)
 *   - each HEAD is one HEADERS block with END_STREAM: no DATA payload, no
 *     RST_STREAM, no GOAWAY, and the connection goes on serving;
 *   - rendered, cached, static and refused (the server's own 403 page): the
 *     GET's status, content-length and content-encoding; streamed: the GET's
 *     status and content-type, and the stream ends at its head;
 *   - a HEAD for a cached page is a cache hit and renders nothing;
 *   - the GET's own frames and body are unchanged.
 *
 *   HTTP/1.1
 *   - each HEAD has no body (the server's own 403 page included), the GET's
 *     content-length where the GET states one, and a streamed HEAD is closed
 *     at its head rather than held until the script ends.
 *
 *   php tests/unit-head-requests.php
 */

require __DIR__ . '/fixtures/race-harness.php';
require_once __DIR__ . '/../src/Q/WebServer/Http2/Hpack.php';
require_once __DIR__ . '/../src/Q/WebServer/Http2/Frame.php';
rh_require_pool();
if (!function_exists('openssl_pkey_new')) {
	printf("  skip  needs openssl for HTTP/2\n");
	exit(0);
}
list($base, $root) = rh_setup('head-requests');

$counter = $base . DS . 'renders';
file_put_contents($counter, '0');
// Rendered every time (no-store): the worker's answer.
file_put_contents($root . DS . 'rendered.php', '<?php
	header("Cache-Control: no-store");
	header("Content-Type: text/html; charset=utf-8");
	echo str_repeat("<p>a rendered page, the same every time</p>\n", 300);');
// Cacheable, counting its renders.
file_put_contents($root . DS . 'cached.php', '<?php
	$f = ' . var_export($counter, true) . ';
	file_put_contents($f, (string) ((int) file_get_contents($f) + 1));
	header("Cache-Control: public, max-age=300");
	echo str_repeat("<p>a cached page that compresses</p>\n", 300);');
// Streamed: a short event stream.
file_put_contents($root . DS . 'sse.php', '<?php
	header("Content-Type: text/event-stream");
	header("Cache-Control: no-store");
	while (@ob_end_clean());
	$n = (int) ($_GET["n"] ?? 3);
	for ($i = 1; $i <= $n; $i++) { echo "data: event $i\n\n"; @ob_flush(); flush(); usleep(150000); }');
// Streamed through the API, with a plain type.
file_put_contents($root . DS . 'api.php', '<?php
	header("Content-Type: text/plain");
	Q_WebServer_State::setStreaming(true);
	for ($i = 1; $i <= 3; $i++) { echo "part $i\n"; flush(); usleep(150000); }');
file_put_contents($root . DS . 'static.txt', str_repeat("a static file that compresses\n", 300));
file_put_contents($root . DS . 'static.png', random_bytes(5000));
// A type the server does not serve: its 403 page, written by the server itself.
file_put_contents($root . DS . 'static.bin', random_bytes(5000));

$key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
$csr = openssl_csr_new(array('commonName' => '127.0.0.1'), $key, array('digest_alg' => 'sha256'));
$crt = openssl_csr_sign($csr, null, $key, 2, array('digest_alg' => 'sha256'));
openssl_x509_export($crt, $crtPem);
openssl_pkey_export($key, $keyPem);
file_put_contents("$base/cert.pem", $crtPem);
file_put_contents("$base/key.pem", $keyPem);
$tlsPort = rh_free_port();
$GLOBALS['rh_extra_args'] = array("--https-port=$tlsPort");
$port = rh_start('head', array('Q' => array(
	'web' => array(
		'https' => array('mode' => 'manual', 'cert' => "$base/cert.pem", 'key' => "$base/key.pem"),
		'http2' => array('enabled' => true),
		'cache' => array('enabled' => true, 'dir' => $base . DS . 'cache'),
	),
)), 2);

function renders($counter) { clearstatcache(); return (int) file_get_contents($counter); }

// ── HTTP/2 client ──────────────────────────────────────────────

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
$GLOBALS['h2'] = array('s' => $s, 'enc' => $enc, 'dec' => $dec, 'buf' => '', 'next' => 1, 'goaway' => false);

/**
 * One request on the shared connection, waited for. Returns array(headers,
 * body, frames) where frames lists array(type, flags, length) for the stream.
 */
function h2($method, $path, $extra = array())
{
	$F = 'Q_WebServer_Http2_Frame';
	$c = &$GLOBALS['h2'];
	$id = $c['next'];
	$c['next'] += 2;
	$fields = array_merge(array(
		array(':method', $method), array(':scheme', 'https'),
		array(':authority', '127.0.0.1'), array(':path', $path),
		array('accept-encoding', 'gzip'),
	), $extra);
	fwrite($c['s'], $F::build($F::HEADERS, $F::FLAG_END_HEADERS | $F::FLAG_END_STREAM, $id, $c['enc']->encode($fields)));
	$h = array(); $body = ''; $frames = array(); $done = false; $block = '';
	$t0 = microtime(true);
	while (!$done and microtime(true) - $t0 < 15) {
		while (!$done and ($f = $F::read($c['buf'])) !== null) {
			if ($f['stream'] === 0) {
				if ($f['type'] === $F::SETTINGS and !($f['flags'] & 0x1)) fwrite($c['s'], $F::build($F::SETTINGS, 0x1, 0, ''));
				if ($f['type'] === $F::GOAWAY) { $c['goaway'] = true; $done = true; }
				continue;
			}
			if ($f['stream'] !== $id) continue;
			$frames[] = array($f['type'], $f['flags'], strlen($f['payload']));
			if ($f['type'] === $F::HEADERS or $f['type'] === $F::CONTINUATION) {
				$block .= $f['payload'];
				if ($f['flags'] & $F::FLAG_END_HEADERS) {
					foreach ($c['dec']->decode($block) as $p) $h[$p[0]] = $p[1];
					$block = '';
				}
			} elseif ($f['type'] === $F::DATA) {
				$body .= $f['payload'];
				if (strlen($f['payload'])) {
					fwrite($c['s'], $F::build($F::WINDOW_UPDATE, 0, 0, $F::uint32(strlen($f['payload']))));
					fwrite($c['s'], $F::build($F::WINDOW_UPDATE, 0, $id, $F::uint32(strlen($f['payload']))));
				}
			}
			if ($f['type'] === $F::RST_STREAM) $done = true;
			if (($f['type'] === $F::HEADERS or $f['type'] === $F::DATA) and ($f['flags'] & $F::FLAG_END_STREAM)) $done = true;
		}
		if ($done) break;
		$r = array($c['s']); $w = $e = null;
		if (!@stream_select($r, $w, $e, 0, 50000)) continue;
		$chunk = @fread($c['s'], 65536);
		if ($chunk === '' or $chunk === false) { if (feof($c['s'])) break; continue; }
		$c['buf'] .= $chunk;
	}
	return array($h, $body, $frames);
}

/** The shape of a HEAD's frames: one HEADERS with END_STREAM, nothing else. */
function headShape($frames)
{
	$F = 'Q_WebServer_Http2_Frame';
	$data = 0; $rst = false; $es = false; $types = array();
	foreach ($frames as $f) {
		$types[] = $f[0];
		if ($f[0] === $F::DATA) $data += $f[2];
		if ($f[0] === $F::RST_STREAM) $rst = true;
		if ($f[0] === $F::HEADERS and ($f[1] & $F::FLAG_END_STREAM)) $es = true;
	}
	return array('types' => $types, 'endStreamOnHeaders' => $es, 'dataBytes' => $data, 'reset' => $rst);
}
$oneHeaders = array('types' => array($F::HEADERS), 'endStreamOnHeaders' => true, 'dataBytes' => 0, 'reset' => false);

$event = array(array('accept', 'text/event-stream'));
$cases = array(
	// path, extra request fields, whether the GET states a length
	array('/rendered.php', array(), true),
	array('/static.txt', array(), true),
	array('/static.png', array(), true),
	array('/static.bin', array(), true),
	array('/sse.php', $event, false),
	array('/api.php', array(), false),
);
foreach ($cases as $case) {
	list($path, $extra, $hasLength) = $case;
	list($gh, $gbody, $gframes) = h2('GET', $path, $extra);
	list($hh, $hbody, $hframes) = h2('HEAD', $path, $extra);
	check("h2 HEAD $path: one HEADERS frame with END_STREAM, no DATA, no reset", headShape($hframes), $oneHeaders);
	check("h2 HEAD $path: the GET's status", $hh[':status'] ?? '', $gh[':status'] ?? 'x');
	check("h2 HEAD $path: the GET's content-type", $hh['content-type'] ?? '', $gh['content-type'] ?? 'x');
	if ($hasLength) {
		check("h2 HEAD $path: the GET's content-length", $hh['content-length'] ?? 'missing', $gh['content-length'] ?? 'x');
		check("h2 HEAD $path: the GET's content-encoding", $hh['content-encoding'] ?? '', $gh['content-encoding'] ?? '');
		check("h2 GET $path: its body has its content-length", strlen($gbody), (int) ($gh['content-length'] ?? -1));
	} else {
		check("h2 HEAD $path: no length (a stream has none)", isset($hh['content-length']), false);
		check("h2 GET $path: the stream still arrives whole", $gbody !== '' and substr_count($gbody, '3') >= 1, true);
	}
}

// The cache: GET stores, HEAD is then a hit that renders nothing.
list($c1) = h2('GET', '/cached.php');
list($c2, $c2body) = h2('GET', '/cached.php');
check('h2 GET /cached.php renders once and is then a hit', array(renders($counter), $c2['x-cache'] ?? ''), array(1, 'HIT'));
list($ch, $cbody, $cframes) = h2('HEAD', '/cached.php');
check('h2 HEAD /cached.php: one HEADERS frame with END_STREAM', headShape($cframes), $oneHeaders);
check('h2 HEAD /cached.php: a cache hit', $ch['x-cache'] ?? '', 'HIT');
check('...that renders nothing', renders($counter), 1);
check('...with the GET\'s content-length', $ch['content-length'] ?? 'missing', $c2['content-length'] ?? 'x');
check('...and content-encoding', $ch['content-encoding'] ?? '', $c2['content-encoding'] ?? '');
list($u, $ubody, $uframes) = h2('HEAD', '/cached.php?cold=1');
check('h2 HEAD for a page not cached: rendered, no DATA', array(renders($counter), headShape($uframes)), array(2, $oneHeaders));

// Thirty HEADs in a row on one connection, mixed, then a GET.
$all = true;
for ($i = 0; $i < 30; ++$i) {
	list($xh, $xb, $xf) = h2('HEAD', array('/rendered.php', '/cached.php', '/static.txt')[$i % 3]);
	if (headShape($xf) !== $oneHeaders or ($xh[':status'] ?? '') !== '200') $all = false;
}
check('h2: 30 HEADs on one connection, each one clean', $all, true);
list($last) = h2('GET', '/rendered.php');
check('...no GOAWAY, and the connection still serves', array($GLOBALS['h2']['goaway'], $last[':status'] ?? ''), array(false, '200'));

// A HEAD for a long event stream (40 events, 6 s) ends at its head and frees
// its stream worker: an event stream asked for right after is served, not
// refused for want of a stream slot (2 workers here allow one stream).
list($lh, $lb, $lf) = h2('HEAD', '/sse.php?n=40', $event);
check('h2 HEAD of a long stream: one HEADERS frame with END_STREAM', headShape($lf), $oneHeaders);
usleep(600000);
list($eh, $ebody) = h2('GET', '/sse.php?n=2', $event);
check('...and its worker is free for the next stream', array($eh[':status'] ?? '', substr_count($ebody, 'data: ')), array('200', 2));
fclose($s);

// ── HTTP/1.1 ───────────────────────────────────────────────────

/** Raw request on its own connection; returns [headers, bytes after the head]. */
function raw1($port, $method, $path, $extra = '')
{
	$s = stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5);
	fwrite($s, "$method $path HTTP/1.1\r\nHost: 127.0.0.1\r\nAccept-Encoding: gzip\r\n{$extra}Connection: close\r\n\r\n");
	stream_set_timeout($s, 10);
	$raw = '';
	while (!feof($s)) { $c = fread($s, 65536); if ($c === '' or $c === false) { if (stream_get_meta_data($s)['timed_out']) break; continue; } $raw .= $c; }
	fclose($s);
	list($head, $rest) = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
	$h = array();
	$lines = explode("\r\n", $head);
	$h[':status'] = preg_match('#^HTTP/1\.[01] (\d{3})#', $lines[0], $m) ? $m[1] : '';
	foreach (array_slice($lines, 1) as $line) {
		$kv = explode(':', $line, 2);
		if (count($kv) === 2) $h[strtolower(trim($kv[0]))] = trim($kv[1]);
	}
	return array($h, $rest);
}

$cases1 = array(
	array('/rendered.php', '', true),
	array('/cached.php', '', true),
	array('/static.txt', '', true),
	array('/static.png', '', true),
	array('/static.bin', '', true),
	array('/sse.php', "Accept: text/event-stream\r\n", false),
	array('/api.php', '', false),
);
foreach ($cases1 as $case) {
	list($path, $extra, $hasLength) = $case;
	list($gh, $gbody) = raw1($port, 'GET', $path, $extra);
	list($hh, $hbody) = raw1($port, 'HEAD', $path, $extra);
	check("h1 HEAD $path: no body", strlen($hbody), 0);
	check("h1 HEAD $path: the GET's status", $hh[':status'], $gh[':status']);
	if ($hasLength) {
		check("h1 HEAD $path: the GET's content-length", $hh['content-length'] ?? 'missing', $gh['content-length'] ?? 'x');
	}
}

// The same over HTTP/1.1: the connection closes at the head, not after 6 s.
usleep(600000);   // the previous stream's worker settles back to idle
$t = microtime(true);
list($lh1, $lb1) = raw1($port, 'HEAD', '/sse.php?n=40', "Accept: text/event-stream\r\n");
check('h1 HEAD of a long stream: the head, at once, and no body',
	array($lh1[':status'], microtime(true) - $t < 2.0, strlen($lb1)), array('200', true, 0));
usleep(600000);
list($eh1, $eb1) = raw1($port, 'GET', '/sse.php?n=2', "Accept: text/event-stream\r\n");
check('...and its worker is free for the next stream', $eh1[':status'], '200');

rh_finish();
