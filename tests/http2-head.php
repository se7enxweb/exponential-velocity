<?php

/**
 * A HEAD over HTTP/2 is answered with HEADERS carrying END_STREAM, and no DATA.
 *
 * It was answered with the whole page. The connection wrote whatever response
 * it was given, and whoever produced it -- a worker, the response cache --
 * writes what a GET gets, so a HEAD for a page went out as HEADERS plus every
 * DATA frame of the body. A strict peer treats DATA on a HEAD's stream as a
 * protocol error: curl with a current nghttp2 reported "Error in the HTTP2
 * framing layer" or "stream 1 was not closed cleanly: PROTOCOL_ERROR" for
 * every page rendered by PHP, while a static file (built without a body for
 * HEAD) was fine and older clients silently dropped the stray bytes.
 *
 * Asserted on the connection itself, with no server:
 *   - a HEAD whose handler returns a body: HEADERS with END_STREAM, no DATA,
 *     and the content-length and content-encoding the GET gets;
 *   - a GET for the same page is unchanged: HEADERS, then DATA ending the stream;
 *   - a HEAD answered later (respond() from a worker's callback) likewise;
 *   - a HEAD answered as a stream: END_STREAM on the HEADERS, pieces dropped,
 *     no RST_STREAM when the stream ends or is reset, and the stream stays open
 *     to the pool until it ends;
 *   - a HEAD with an empty body and a stated _length: that length;
 *   - a HEAD whose header block needs CONTINUATION ends with an empty DATA frame.
 *
 *   php tests/http2-head.php
 */

require __DIR__ . '/../src/Q/WebServer/Http2/Hpack.php';
require __DIR__ . '/../src/Q/WebServer/Http2/Frame.php';
require __DIR__ . '/../src/Q/WebServer/Http2/Connection.php';
require __DIR__ . '/../src/Q/WebServer/Headers.php';

$F = 'Q_WebServer_Http2_Frame';
$pass = 0;
$fail = 0;

function ok($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

$page = str_repeat("<p>a page that compresses well enough to be compressed</p>\n", 200);

/**
 * One request on a fresh connection; returns the frames written back on
 * stream 1, each array(type, flags, payload), and the decoded header fields.
 */
function exchange($method, $handler, $extra = array(), &$conn = null)
{
	$F = 'Q_WebServer_Http2_Frame';
	$out = fopen('php://temp', 'w+b');
	$conn = new Q_WebServer_Http2_Connection($out, $handler);
	$conn->start();
	$conn->feed($F::PREFACE . $F::build($F::SETTINGS, 0, 0, ''));
	$hpack = new Q_WebServer_Http2_Hpack();
	$conn->feed($F::build($F::HEADERS, $F::FLAG_END_HEADERS | $F::FLAG_END_STREAM, 1,
		$hpack->encode(array_merge(array(
			array(':method', $method), array(':scheme', 'https'),
			array(':authority', 'example.com'), array(':path', '/page'),
			array('accept-encoding', 'gzip'),
		), $extra))));
	return $out;
}

function frames($out)
{
	$F = 'Q_WebServer_Http2_Frame';
	rewind($out);
	$buf = stream_get_contents($out);
	$decoder = new Q_WebServer_Http2_Hpack();
	$list = array();
	$fields = array();
	$block = '';
	while (($frame = $F::read($buf)) !== null) {
		if ($frame['stream'] !== 1) continue;
		$list[] = array($frame['type'], $frame['flags'], $frame['payload']);
		if ($frame['type'] === $F::HEADERS or $frame['type'] === $F::CONTINUATION) {
			$block .= $frame['payload'];
			if ($frame['flags'] & $F::FLAG_END_HEADERS) {
				foreach ($decoder->decode($block) as $pair) $fields[$pair[0]] = $pair[1];
				$block = '';
			}
		}
	}
	return array($list, $fields);
}

/** [types in order, END_STREAM on HEADERS?, DATA payload bytes, ended?, RST seen?] */
function shape($list)
{
	$F = 'Q_WebServer_Http2_Frame';
	$types = array(); $esOnHeaders = false; $data = 0; $ended = false; $rst = false;
	foreach ($list as $f) {
		$types[] = $f[0];
		if ($f[0] === $F::HEADERS and ($f[1] & $F::FLAG_END_STREAM)) $esOnHeaders = true;
		if ($f[0] === $F::DATA) $data += strlen($f[2]);
		if (($f[0] === $F::HEADERS or $f[0] === $F::DATA) and ($f[1] & $F::FLAG_END_STREAM)) $ended = true;
		if ($f[0] === $F::RST_STREAM) $rst = true;
	}
	return array($types, $esOnHeaders, $data, $ended, $rst);
}

$answer = function () use ($page) {
	return array('status' => 200, 'headers' => array('content-type' => 'text/html; charset=utf-8',
		'content-length' => (string) strlen($page)), 'body' => $page);
};

// GET, as it was and must stay.
list($gl, $gh) = frames(exchange('GET', $answer));
list($types, $es, $data, $ended, $rst) = shape($gl);
ok('GET: HEADERS then DATA', array($types[0], end($types)), array($F::HEADERS, $F::DATA));
ok('GET: END_STREAM on the last DATA, not on HEADERS', array($es, $ended), array(false, true));
ok('GET: the body is compressed', $gh['content-encoding'] ?? '', 'gzip');
ok('GET: DATA carries content-length bytes', $data, (int) ($gh['content-length'] ?? -1));

// HEAD, answered at once.
list($hl, $hh) = frames(exchange('HEAD', $answer));
ok('HEAD: one HEADERS frame and nothing else', shape($hl), array(array($F::HEADERS), true, 0, true, false));
ok('HEAD: the GET\'s status', $hh[':status'] ?? '', $gh[':status'] ?? 'x');
ok('HEAD: the GET\'s content-length', $hh['content-length'] ?? 'missing', $gh['content-length'] ?? 'x');
ok('HEAD: the GET\'s content-encoding', $hh['content-encoding'] ?? 'missing', 'gzip');
ok('HEAD: the GET\'s vary', $hh['vary'] ?? 'missing', $gh['vary'] ?? 'x');

// HEAD, answered later -- how a worker's answer arrives.
$out = exchange('HEAD', function () { return null; }, array(), $conn);
$conn->respond(1, $answer());
list($ll, $lh) = frames($out);
ok('HEAD answered later: HEADERS with END_STREAM, no DATA', shape($ll), array(array($F::HEADERS), true, 0, true, false));
ok('HEAD answered later: the GET\'s content-length', $lh['content-length'] ?? 'missing', $gh['content-length'] ?? 'x');
ok('...and the stream is forgotten', isset($conn->streams[1]), false);

// HEAD with no body read, and a length stated by the writer (a large file).
list($sl, $sh) = frames(exchange('HEAD', function () {
	return array('status' => 200, 'headers' => array('content-type' => 'video/mp4'), 'body' => '', '_length' => 123456789);
}));
ok('HEAD with a stated length: HEADERS with END_STREAM', shape($sl), array(array($F::HEADERS), true, 0, true, false));
ok('...stating it', $sh['content-length'] ?? 'missing', '123456789');

// A stated _length is ignored for a GET (it is only for a HEAD).
list($xl, $xh) = frames(exchange('GET', function () {
	return array('status' => 200, 'headers' => array('content-type' => 'text/plain'), 'body' => 'abc', '_length' => 99);
}));
ok('GET ignores _length', $xh['content-length'] ?? 'missing', '3');

// A streamed answer to a HEAD.
$out = exchange('HEAD', function () { return null; }, array(), $conn);
ok('stream head for HEAD', $conn->streamHead(1, array('status' => 200,
	'headers' => array('content-type' => 'text/event-stream', 'x-accel-buffering' => 'no'))), true);
ok('...the stream is still open to the pool', $conn->streamOpen(1), true);
ok('...a piece is accepted', $conn->streamData(1, "data: one\n\n"), true);
ok('...and another', $conn->streamData(1, str_repeat('x', 100000)), true);
ok('...the end is accepted', $conn->streamEnd(1), true);
list($tl, $th) = frames($out);
ok('streamed HEAD: one HEADERS frame with END_STREAM, no DATA, no RST', shape($tl), array(array($F::HEADERS), true, 0, true, false));
ok('...status 200', $th[':status'] ?? '', '200');
ok('...and the stream is gone after its end', isset($conn->streams[1]), false);

// A streamed HEAD the pool resets (stream timeout, say): no RST on a closed stream.
$out = exchange('HEAD', function () { return null; }, array(), $conn);
$conn->streamHead(1, array('status' => 200, 'headers' => array('content-type' => 'text/event-stream')));
$conn->streamReset(1);
list($rl) = frames($out);
ok('streamed HEAD reset by the pool: no RST_STREAM', shape($rl), array(array($F::HEADERS), true, 0, true, false));

// The open-stream count does not include a HEAD stream that has ended its answer.
$out = exchange('HEAD', function () { return null; }, array(), $conn);
$conn->streamHead(1, array('status' => 200, 'headers' => array()));
ok('...counted as finished', $conn->streams[1]['finished'] ?? null, true);

// A streamed GET is unchanged: HEADERS without END_STREAM, DATA, then the end.
$out = exchange('GET', function () { return null; }, array(), $conn);
$conn->streamHead(1, array('status' => 200, 'headers' => array('content-type' => 'text/event-stream')));
$conn->streamData(1, "data: one\n\n");
$conn->streamEnd(1);
list($gsl) = frames($out);
list($types, $es, $data, $ended, $rst) = shape($gsl);
ok('streamed GET: HEADERS without END_STREAM, DATA, the end', array($types[0], $es, $data, $ended, $rst),
	array($F::HEADERS, false, 11, true, false));

// A head larger than one frame: END_STREAM cannot ride on CONTINUATION.
$big = array();
for ($i = 0; $i < 20; ++$i) $big["x-filler-" . $i] = bin2hex(random_bytes(500));
$out = exchange('HEAD', function () { return null; }, array(), $conn);
$conn->streamHead(1, array('status' => 200, 'headers' => $big));
list($cl) = frames($out);
list($types, $es, $data, $ended, $rst) = shape($cl);
ok('HEAD with CONTINUATION: HEADERS, CONTINUATION..., empty DATA with END_STREAM',
	array($types[0], in_array($F::CONTINUATION, $types, true), end($types), $data, $ended, $es),
	array($F::HEADERS, true, $F::DATA, 0, true, false));

echo "\n";
if ($fail) {
	printf("  FAIL - %d of %d case(s)\n\n", $fail, $pass + $fail);
	exit(1);
}
printf("  PASS - %d case(s)\n\n", $pass);
exit(0);
