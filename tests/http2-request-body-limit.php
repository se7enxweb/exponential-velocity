<?php

/**
 * An HTTP/2 request body over the application's limit (post_max_size) is
 * answered 413 on its own stream, and only that stream ends.
 *
 * HTTP/1.1 has refused such a body with 413 for a long time; HTTP/2 never
 * looked. It held the body whole and handed it to a worker, whose request
 * frame was then too large for it to read -- the worker exited and the
 * visitor got a 502, every time, for anything from 8 MB upwards.
 *
 * Checked here, against the connection class:
 *   1. a content-length over the limit: 413 at once, before any DATA, then
 *      RST_STREAM(NO_ERROR) after the answer, and the handler never called;
 *   2. DATA the client sends afterwards is discarded, credited back to the
 *      connection's window, and the connection keeps serving other streams;
 *   3. a body with no content-length is refused on the DATA frame that takes
 *      it over the limit;
 *   4. a body exactly at the limit, and one without a limit set, reach the
 *      handler whole.
 *
 *   php tests/http2-request-body-limit.php
 */

require __DIR__ . '/../src/Q/WebServer/Http2/Hpack.php';
require __DIR__ . '/../src/Q/WebServer/Http2/Frame.php';
require __DIR__ . '/../src/Q/WebServer/Http2/ErrorPage.php';
require __DIR__ . '/../src/Q/WebServer/Http2/Connection.php';

$F = 'Q_WebServer_Http2_Frame';
$pass = 0;
$fail = 0;

function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; printf("  PASS  %s\n", $what); return; }
	++$fail;
	printf("  FAIL  %s\n        got %s, want %s\n", $what,
		var_export($got, true), var_export($want, true));
}

$handled = array();

function connection(&$out, $limit)
{
	$out = fopen('php://temp', 'w+b');
	$conn = new Q_WebServer_Http2_Connection($out, function ($request) {
		$GLOBALS['handled'][] = array($request['stream'], strlen($request['body']));
		return array('status' => 200, 'headers' => array(), 'body' => 'ok ' . strlen($request['body']));
	});
	$conn->maxRequestBody = $limit;
	$conn->start();
	$conn->feed(Q_WebServer_Http2_Frame::PREFACE
		. Q_WebServer_Http2_Frame::build(Q_WebServer_Http2_Frame::SETTINGS, 0, 0, ''));
	return $conn;
}

function headers($method, $length = null)
{
	$hpack = new Q_WebServer_Http2_Hpack();
	$list = array(
		array(':method', $method), array(':scheme', 'https'),
		array(':authority', 'example.com'), array(':path', '/upload'),
	);
	if ($length !== null) $list[] = array('content-length', (string) $length);
	return $hpack->encode($list);
}

/** Every frame written so far: [type, flags, stream, payload]; the stream is emptied. */
function written($out)
{
	rewind($out);
	$buf = stream_get_contents($out);
	ftruncate($out, 0);
	rewind($out);
	$frames = array();
	while (($f = Q_WebServer_Http2_Frame::read($buf)) !== null) {
		$frames[] = $f;
	}
	return $frames;
}

/** The :status of the first HEADERS frame for $stream, or null. */
function statusOf($frames, $stream)
{
	$hpack = new Q_WebServer_Http2_Hpack();
	foreach ($frames as $f) {
		if ($f['type'] !== Q_WebServer_Http2_Frame::HEADERS) continue;
		$list = $hpack->decode($f['payload']);
		if ($f['stream'] !== $stream) continue;
		foreach ($list as $pair) if ($pair[0] === ':status') return (int) $pair[1];
	}
	return null;
}

function framesOf($frames, $type, $stream)
{
	return array_values(array_filter($frames, function ($f) use ($type, $stream) {
		return $f['type'] === $type and $f['stream'] === $stream;
	}));
}

function connectionCredit($frames)
{
	$n = 0;
	foreach ($frames as $f) {
		if ($f['type'] === Q_WebServer_Http2_Frame::WINDOW_UPDATE and $f['stream'] === 0) {
			$n += unpack('N', $f['payload'])[1] & 0x7fffffff;
		}
	}
	return $n;
}

echo "\n";
$limit = 100000;

// ── 1. content-length over the limit ─────────────────────────────────────
$conn = connection($out, $limit);
written($out);
$handled = array();
$alive = $conn->feed($F::build($F::HEADERS, $F::FLAG_END_HEADERS, 1, headers('POST', $limit + 1)));
$frames = written($out);
check('1. the connection stays up', $alive, true);
check('1. answered 413 before any body arrived', statusOf($frames, 1), 413);
$rst = framesOf($frames, $F::RST_STREAM, 1);
check('1. then RST_STREAM on that stream', count($rst), 1);
check('1. with NO_ERROR', $rst ? unpack('N', $rst[0]['payload'])[1] : null, 0);
check('1. the handler was never asked', $handled, array());

// ── 2. the rest of the body is discarded, the connection carries on ──────
$chunk = str_repeat('x', 16000);
$sent = 0;
for ($i = 0; $i < 8; ++$i) {
	$alive = $conn->feed($F::build($F::DATA, 0, 1, $chunk)) and $alive;
	$sent += strlen($chunk);
}
$frames = written($out);
check('2. DATA after the refusal does not end the connection', $alive, true);
check('2. every discarded byte is credited to the connection window', connectionCredit($frames), $sent);
check('2. nothing further is written on the refused stream',
	count(framesOf($frames, $F::HEADERS, 1)) + count(framesOf($frames, $F::DATA, 1)), 0);
$conn->feed($F::build($F::HEADERS, $F::FLAG_END_HEADERS | $F::FLAG_END_STREAM, 3, headers('GET')));
$frames = written($out);
check('2. another stream on the same connection is served', statusOf($frames, 3), 200);

// ── 3. no content-length: refused on the frame that passes the limit ─────
$conn = connection($out, $limit);
written($out);
$handled = array();
$conn->feed($F::build($F::HEADERS, $F::FLAG_END_HEADERS, 1, headers('POST')));
$first = null;
for ($i = 1; $i <= 10; ++$i) {
	$conn->feed($F::build($F::DATA, 0, 1, str_repeat('y', 16384)));
	$frames = written($out);
	if (statusOf($frames, 1) !== null) { $first = $i; $status = statusOf($frames, 1); break; }
}
check('3. a body without content-length is refused with 413', $status ?? null, 413);
check('3. on the DATA frame that took it over the limit', $first, (int) ceil(($limit + 1) / 16384));
check('3. and the handler never saw it', $handled, array());

// ── 4. at the limit, and with no limit ───────────────────────────────────
foreach (array(array($limit, 'at the limit'), array(0, 'with no limit set')) as $case) {
	list($max, $label) = $case;
	$conn = connection($out, $max);
	written($out);
	$handled = array();
	$conn->feed($F::build($F::HEADERS, $F::FLAG_END_HEADERS, 1, headers('POST', $limit)));
	$left = $limit;
	while ($left > 0) {
		$n = min(16384, $left);
		$left -= $n;
		$conn->feed($F::build($F::DATA, $left === 0 ? $F::FLAG_END_STREAM : 0, 1, str_repeat('z', $n)));
	}
	$frames = written($out);
	check("4. a body $label is answered by the handler", statusOf($frames, 1), 200);
	check("4. and reaches it whole ($label)", $handled, array(array(1, $limit)));
}

echo "\n  $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
