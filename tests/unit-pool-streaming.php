<?php

/**
 * Streaming responses from persistent pool workers.
 *
 * A pooled worker captured everything a script printed and sent it in one
 * message when the script ended, so a Server-Sent Events stream arrived all at
 * once, at the end, with a Content-Length (measured: 40 events, one every
 * 500 ms, all at 20.7 s). A client that left did not stop the worker; a
 * stream longer than requestTimeout was killed with nothing sent; and a GET
 * whose worker died was run again, however long it had run.
 *
 * Asserted against real servers with persistent workers:
 *
 *   HTTP/1.1
 *   - an event stream arrives piece by piece, chunked, with no length,
 *     X-Accel-Buffering: no, never from the cache, and ends with the last chunk;
 *   - Q_WebServer_State::setStreaming(true) opts a plain response in;
 *   - a page that does not opt in keeps its Content-Length, flush() or not;
 *   - a client that leaves ends the script at its next flush (within ~1 s),
 *     or, under ignore_user_abort(true), makes connection_aborted() true;
 *     the worker then serves the next request;
 *   - a stream runs past requestTimeout up to streamTimeout, and ends there
 *     cleanly; a request for an event stream is held to streamTimeout before
 *     its first byte too;
 *   - a GET whose worker died at once is retried; one that ran longer than
 *     retryWithin is not, nor is an event stream, nor one that has started;
 *   - past maxStreams an event stream is refused with 503 and Retry-After,
 *     while pages are still served.
 *
 *   HTTP/2 (TLS, ALPN h2)
 *   - the same stream arrives as DATA frames as it is written, ends with
 *     END_STREAM, and the connection serves other requests meanwhile;
 *   - RST_STREAM from the client ends the script within ~1 s and the
 *     connection carries on.
 *
 *   php tests/unit-pool-streaming.php
 */

require __DIR__ . '/fixtures/race-harness.php';
require_once __DIR__ . '/../src/Q/WebServer/Http2/Hpack.php';
require_once __DIR__ . '/../src/Q/WebServer/Http2/Frame.php';
rh_require_pool();
list($base, $root) = rh_setup('pool-streaming');

// An event stream: n events, ms apart. Logs each step with the time, so the
// test can see when the script stopped. Without ign it never asks whether
// the client is there, so only the server can end it (at a flush, as fpm
// does). ign=1 ignores the client leaving and watches connection_aborted()
// instead, ending itself when it turns true; die=1 kills its own worker after the
// first event; wait=ms sleeps before the first one.
file_put_contents($root . DS . 'sse.php', '<?php
$tag = preg_replace("/\\W/", "", $_GET["tag"] ?? "x");
$log = __DIR__ . "/$tag.log";
$say = function ($what) use ($log) { file_put_contents($log, sprintf("%.3f %s\n", microtime(true), $what), FILE_APPEND); };
if (!empty($_GET["ign"])) ignore_user_abort(true);
header("Content-Type: text/event-stream");
header("Cache-Control: no-store");
while (@ob_end_clean());
$n = (int) ($_GET["n"] ?? 5);
$ms = (int) ($_GET["ms"] ?? 300);
$say("start " . getmypid());
if (!empty($_GET["wait"])) usleep((int) $_GET["wait"] * 1000);
for ($i = 1; $i <= $n; $i++) {
	if (!empty($_GET["ign"]) and connection_aborted()) { $say("aborted $i"); break; }
	echo "id: $i\ndata: event $i", empty($_GET["pad"]) ? "" : " " . str_repeat("x", (int) $_GET["pad"]), "\n\n";
	$say("sent $i");
	@ob_flush(); flush();
	if ($i === 1 and !empty($_GET["die"])) posix_kill(getmypid(), 9);
	usleep($ms * 1000);
}
$say("end");
');
// Opts in through the API, with a plain content type.
file_put_contents($root . DS . 'api.php', '<?php
header("Content-Type: text/plain");
Q_WebServer_State::setStreaming(true);
for ($i = 1; $i <= 3; $i++) { echo "part $i\n"; flush(); usleep(400000); }
');
// Flushes, but never asks to stream: answered whole.
file_put_contents($root . DS . 'plain.php', '<?php
echo "one\n"; flush(); usleep(200000); echo "two\n";
');
file_put_contents($root . DS . 'fast.php', '<?php echo "FAST ", getmypid();');
file_put_contents($root . DS . 'slow.php', '<?php sleep(10); echo "SLOW";');
// Counts its runs, then kills its own worker after ms.
file_put_contents($root . DS . 'die.php', '<?php
$f = __DIR__ . "/die-" . preg_replace("/\\W/", "", $_GET["tag"] ?? "x") . ".count";
file_put_contents($f, "x", FILE_APPEND);
usleep((int) ($_GET["ms"] ?? 0) * 1000);
posix_kill(getmypid(), 9);
');

function logOf($tag) { return (string) @file_get_contents($GLOBALS['rh']['root'] . DS . $tag . '.log'); }
function logLines($tag, $prefix)
{
	$out = array();
	foreach (explode("\n", logOf($tag)) as $l) {
		$p = explode(' ', $l, 2);
		if (count($p) === 2 and strncmp($p[1], $prefix, strlen($prefix)) === 0) $out[] = (float) $p[0];
	}
	return $out;
}
function runs($tag) { return strlen((string) @file_get_contents($GLOBALS['rh']['root'] . DS . "die-$tag.count")); }

/** Open an HTTP/1.1 request and return the socket. */
function h1Open($port, $path, $headers = array())
{
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5);
	if (!$s) return null;
	$h = "GET $path HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n";
	foreach ($headers as $k => $v) $h .= "$k: $v\r\n";
	fwrite($s, $h . "\r\n");
	stream_set_blocking($s, false);
	return $s;
}

/**
 * Read a response, noting when each "data: " line and each marker arrived.
 * Stops at EOF, after $stopAfter events (closing the socket), or at $timeout.
 */
function h1Read($s, $stopAfter = 0, $timeout = 20.0, $markers = array('data: '))
{
	$t0 = microtime(true);
	$raw = ''; $seen = array(); $closedAt = null;
	while (microtime(true) - $t0 < $timeout) {
		$r = array($s); $w = $e = null;
		if (!@stream_select($r, $w, $e, 0, 100000)) continue;
		$c = @fread($s, 65536);
		if ($c === '' or $c === false) { if (feof($s)) break; continue; }
		$raw .= $c;
		$count = substr_count($raw, $markers[0]);
		while (count($seen) < $count) $seen[] = microtime(true) - $t0;
		if ($stopAfter and count($seen) >= $stopAfter) { fclose($s); $closedAt = microtime(true); break; }
	}
	if (!$closedAt and is_resource($s)) fclose($s);
	$split = strpos($raw, "\r\n\r\n");
	$head = $split === false ? $raw : substr($raw, 0, $split);
	$hdrs = array();
	foreach (explode("\r\n", $head) as $line) {
		if (strpos($line, ':') !== false) {
			list($k, $v) = explode(':', $line, 2);
			$hdrs[strtolower(trim($k))] = trim($v);
		}
	}
	return array(
		'status' => preg_match('#^HTTP/\S+ (\d+)#', $head, $m) ? (int) $m[1] : 0,
		'headers' => $hdrs, 'body' => $split === false ? '' : substr($raw, $split + 4),
		'times' => $seen, 'closedAt' => $closedAt, 'elapsed' => microtime(true) - $t0,
	);
}

$port = rh_start('h1', array('Q' => array(
	'webserver' => array('requestTimeout' => 3, 'streamTimeout' => 6, 'maxStreams' => 2,
		'retryWithin' => 1, 'requestTimeoutGrace' => 1),
	'web' => array('cache' => array('enabled' => true)),
)), 4);

// ── An event stream arrives as it is written ─────────────────────
$r = h1Read(h1Open($port, '/sse.php?n=6&ms=400&tag=a'));
check('an event stream is answered 200', $r['status'], 200);
check('...with all six events', count($r['times']), 6);
check('...the first within a second', ($r['times'][0] ?? 99) < 1.0, true);
check('...and the last at least 1.6 s after the first (each as it is written)',
	(($r['times'][5] ?? 0) - ($r['times'][0] ?? 0)) >= 1.6, true);
check('...chunked', strtolower($r['headers']['transfer-encoding'] ?? ''), 'chunked');
check('...with no Content-Length', isset($r['headers']['content-length']), false);
check('...X-Accel-Buffering: no for a proxy in front', $r['headers']['x-accel-buffering'] ?? '', 'no');
check('...ending with the last chunk', substr($r['body'], -5), "0\r\n\r\n");
h1Read(h1Open($port, '/sse.php?n=2&ms=10&tag=a2'));
$r2 = h1Read(h1Open($port, '/sse.php?n=2&ms=10&tag=a2'));
check('...and never stored in the response cache (the same URL runs again)',
	array(count(logLines('a2', 'start')), $r2['headers']['x-cache'] ?? 'none'), array(2, 'none'));

$r = h1Read(h1Open($port, '/api.php'), 0, 10.0, array('part '));
check('Q_WebServer_State::setStreaming(true) streams a plain response',
	array(count($r['times']), strtolower($r['headers']['transfer-encoding'] ?? ''),
		(($r['times'][2] ?? 0) - ($r['times'][0] ?? 0)) >= 0.6), array(3, 'chunked', true));

$r = rh_get($port, '/plain.php');
check('a response that does not ask to stream keeps its Content-Length, flush() or not',
	array($r['status'], $r['headers']['content-length'] ?? '', $r['body']), array(200, '8', "one\ntwo\n"));

// ── A client that leaves ends the script ────────────────────────
$r = h1Read(h1Open($port, '/sse.php?n=40&ms=250&tag=d1'), 2);
$closed = $r['closedAt'];
usleep(2500000);
$sent = logLines('d1', 'sent ');
check('a client that leaves ends the script at its next flush (within ~1 s)',
	$sent and end($sent) - $closed < 1.5, true);
check('...which does not run to the end', logLines('d1', 'end'), array());
usleep(800000);
check('...and has stopped', count(logLines('d1', 'sent ')), count($sent));
check('the server says why the stream ended',
	(bool) preg_match('#stream on GET /sse\.php\?n=40&ms=250&tag=d1 ended after .*client closed the connection#', rh_log('h1')), true);

$r = h1Read(h1Open($port, '/sse.php?n=40&ms=250&tag=d2&ign=1'), 2);
$closed = $r['closedAt'];
usleep(2500000);
$ab = logLines('d2', 'aborted ');
check('under ignore_user_abort(true) connection_aborted() turns true within ~1 s',
	$ab and $ab[0] - $closed < 1.5, true);

$pids = array();
for ($i = 0; $i < 4; ++$i) {
	$f = rh_get($port, '/fast.php');
	if ($f['status'] === 200) $pids[] = $f['body'];
}
check('the workers serve pages again afterwards', count($pids), 4);

// ── A client that does not read ─────────────────────────────────
// 64 KB events, as fast as the worker can write them, to a client that never
// reads: what the socket will not take waits in the server, and must hold up
// nobody else -- then, past the stream's write buffer, the client is dropped.
$lazy = h1Open($port, '/sse.php?n=400&ms=5&pad=65536&tag=s1');
usleep(1500000);
$t = microtime(true);
$f = rh_get($port, '/fast.php');
check('a client that does not read holds up no other request',
	array($f['status'], microtime(true) - $t < 2.0), array(200, true));
for ($i = 0; $i < 40 and !preg_match('#tag=s1 ended after#', rh_log('h1')); ++$i) usleep(250000);
check('...and is dropped once the stream\'s write buffer is full',
	(bool) preg_match('#stream on GET /sse\.php\?n=400&ms=5&pad=65536&tag=s1 ended after .*client gone#', rh_log('h1')), true);
check('...which ends the script too', logLines('s1', 'end'), array());
if (is_resource($lazy)) fclose($lazy);

// ── streamTimeout, not requestTimeout ───────────────────────────
$r = h1Read(h1Open($port, '/sse.php?n=60&ms=250&tag=t1'), 0, 15.0);
$last = end($r['times']) ?: 0;
check('a stream runs past requestTimeout (3 s)', $last > 3.5, true);
check('...and ends at streamTimeout (6 s)', $r['elapsed'] > 5.5 and $r['elapsed'] < 9.0, true);
check('...cleanly, with the last chunk', substr($r['body'], -5), "0\r\n\r\n");
usleep(500000);   // the client sees the end a moment before the server logs it
check('...and the kill is logged against streamTimeout',
	(bool) preg_match('#worker \d+ killed after [\d.]+s on GET /sse\.php\?n=60&ms=250&tag=t1 \(streamTimeout 6s\)#', rh_log('h1')), true);

$r = h1Read(h1Open($port, '/sse.php?n=2&ms=10&wait=4000&tag=t2', array('Accept' => 'text/event-stream')), 0, 12.0);
check('a request for an event stream is not killed at requestTimeout before its first event',
	array($r['status'], count($r['times'])), array(200, 2));
$r = rh_get($port, '/slow.php');
check('a page is still held to requestTimeout', $r['status'], 504);

// ── Retries after a worker death ────────────────────────────────
usleep(1500000);
$r = rh_get($port, '/die.php?tag=q');
check('a GET whose worker died at once is run again (once)', runs('q'), 2);
$r = rh_get($port, '/die.php?tag=l&ms=1600');
check('a GET whose worker died after retryWithin is not run again', array($r['status'], runs('l')), array(502, 1));
$r = rh_get($port, '/die.php?tag=e', 15.0, 'GET', '', array('Accept' => 'text/event-stream'));
check('nor is a request for an event stream', runs('e'), 1);
$r = h1Read(h1Open($port, '/sse.php?n=5&ms=100&die=1&tag=k'), 0, 10.0);
check('a stream whose worker died part-way is cut short, not run again',
	array(count(logLines('k', 'start')), count($r['times']), substr($r['body'], -5) === "0\r\n\r\n"),
	array(1, 1, false));

// ── The stream cap ──────────────────────────────────────────────
usleep(1500000);
$held = array();
foreach (array('c1', 'c2') as $t) {
	$s = h1Open($port, "/sse.php?n=40&ms=200&tag=$t", array('Accept' => 'text/event-stream'));
	$held[] = $s;
}
usleep(1000000);
$r = rh_get($port, '/sse.php?n=2&ms=10&tag=c3', 10.0, 'GET', '', array('Accept' => 'text/event-stream'));
check('past maxStreams an event stream is refused with 503', $r['status'], 503);
check('...and Retry-After', $r['headers']['retry-after'] ?? '', '5');
$f = rh_get($port, '/fast.php');
check('...while pages are still served', $f['status'], 200);
foreach ($held as $s) if (is_resource($s)) fclose($s);

// ── HTTP/2 ──────────────────────────────────────────────────────
if (!function_exists('openssl_pkey_new')) {
	printf("  skip  HTTP/2 part: needs openssl\n");
	rh_finish();
}
$key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
$csr = openssl_csr_new(array('commonName' => '127.0.0.1'), $key, array('digest_alg' => 'sha256'));
$crt = openssl_csr_sign($csr, null, $key, 2, array('digest_alg' => 'sha256'));
openssl_x509_export($crt, $crtPem);
openssl_pkey_export($key, $keyPem);
file_put_contents("$base/cert.pem", $crtPem);
file_put_contents("$base/key.pem", $keyPem);
$tlsPort = rh_free_port();
$GLOBALS['rh_extra_args'] = array("--https-port=$tlsPort");
rh_start('h2', array('Q' => array(
	'web' => array(
		'https' => array('mode' => 'manual', 'cert' => "$base/cert.pem", 'key' => "$base/key.pem"),
		'http2' => array('enabled' => true),
		'cache' => array('enabled' => false),
	),
	'webserver' => array('requestTimeout' => 3, 'streamTimeout' => 20),
)), 3);

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
$alpn = stream_get_meta_data($s)['crypto']['alpn_protocol'] ?? '';
if ($alpn !== 'h2') { printf("  skip  server did not negotiate h2\n"); rh_finish(); }
$enc = new Q_WebServer_Http2_Hpack();
$dec = new Q_WebServer_Http2_Hpack();
fwrite($s, $F::PREFACE . $F::build($F::SETTINGS, 0, 0, ''));
stream_set_blocking($s, false);

function h2Get($s, $enc, $id, $path, $eventStream = false)
{
	$F = 'Q_WebServer_Http2_Frame';
	$fields = array(
		array(':method', 'GET'), array(':scheme', 'https'),
		array(':authority', '127.0.0.1'), array(':path', $path),
	);
	if ($eventStream) $fields[] = array('accept', 'text/event-stream');
	fwrite($s, $F::build($F::HEADERS, $F::FLAG_END_HEADERS | $F::FLAG_END_STREAM, $id, $enc->encode($fields)));
}

/**
 * Read frames for $seconds or until the streams in $until have ended.
 * Returns stream => array(status, body, data times, ended, reset).
 */
function h2Pump($s, $dec, $seconds, $until = array(), &$state = null)
{
	$F = 'Q_WebServer_Http2_Frame';
	static $buf = '';
	if ($state === null) $state = array();
	$t0 = microtime(true);
	while (microtime(true) - $t0 < $seconds) {
		$done = 0;
		foreach ($until as $id) if (!empty($state[$id]['ended']) or !empty($state[$id]['reset'])) ++$done;
		if ($until and $done === count($until)) break;
		$r = array($s); $w = $e = null;
		if (!@stream_select($r, $w, $e, 0, 50000)) continue;
		$c = @fread($s, 65536);
		if ($c === '' or $c === false) { if (feof($s)) break; continue; }
		$buf .= $c;
		while (($f = $F::read($buf)) !== null) {
			$id = $f['stream'];
			if ($f['type'] === $F::SETTINGS and !($f['flags'] & 0x1)) {
				fwrite($s, $F::build($F::SETTINGS, 0x1, 0, ''));
			} elseif ($f['type'] === $F::HEADERS) {
				foreach ($dec->decode($f['payload']) as $h) {
					if ($h[0] === ':status') $state[$id]['status'] = (int) $h[1];
				}
			} elseif ($f['type'] === $F::DATA) {
				$state[$id]['body'] = ($state[$id]['body'] ?? '') . $f['payload'];
				if ($f['payload'] !== '') $state[$id]['times'][] = microtime(true);
				// Give the window back, as a browser does.
				if (strlen($f['payload']) > 0) {
					fwrite($s, $F::build($F::WINDOW_UPDATE, 0, 0, $F::uint32(strlen($f['payload']))));
					fwrite($s, $F::build($F::WINDOW_UPDATE, 0, $id, $F::uint32(strlen($f['payload']))));
				}
			} elseif ($f['type'] === $F::RST_STREAM) {
				$state[$id]['reset'] = true;
			}
			if (in_array($f['type'], array($F::HEADERS, $F::DATA), true) and ($f['flags'] & $F::FLAG_END_STREAM)) {
				$state[$id]['ended'] = true;
			}
		}
	}
	return $state;
}

$st = null;
$t0 = microtime(true);
h2Get($s, $enc, 1, '/sse.php?n=6&ms=400&tag=h2a', true);
h2Pump($s, $dec, 1.2, array(), $st);
h2Get($s, $enc, 3, '/fast.php');
h2Pump($s, $dec, 15.0, array(1, 3), $st);
$times = $st[1]['times'] ?? array();
check('over HTTP/2 the event stream is answered 200', $st[1]['status'] ?? 0, 200);
check('...its first event within a second', $times and $times[0] - $t0 < 1.0, true);
check('...the rest as they are written', $times and end($times) - $times[0] >= 1.6, true);
check('...all six, ending with END_STREAM',
	array(substr_count($st[1]['body'] ?? '', 'data: '), !empty($st[1]['ended'])), array(6, true));
check('...while the same connection answered a page meanwhile',
	array($st[3]['status'] ?? 0, strncmp($st[3]['body'] ?? '', 'FAST ', 5) === 0), array(200, true));

h2Get($s, $enc, 5, '/sse.php?n=40&ms=250&tag=h2r', true);
$st = null;
h2Pump($s, $dec, 0.9, array(), $st);
fwrite($s, $F::build($F::RST_STREAM, 0, 5, $F::uint32(8)));   // CANCEL
$reset = microtime(true);
h2Pump($s, $dec, 2.5, array(), $st);
$sent = logLines('h2r', 'sent ');
check('RST_STREAM from the client ends the script within ~1 s',
	$sent and end($sent) - $reset < 1.6, true);
check('...which does not run to the end', logLines('h2r', 'end'), array());
h2Get($s, $enc, 7, '/fast.php');
h2Pump($s, $dec, 10.0, array(7), $st);
check('...and the connection carries on', $st[7]['status'] ?? 0, 200);

fclose($s);
rh_finish();
