<?php

/**
 * A graceful GOAWAY: what a stopping server tells an HTTP/2 connection.
 *
 * A stopping server used to close the connections a browser holds for reuse
 * without a word, so whatever the browser had just sent on one failed, every
 * multiplexed request together. Now each is sent GOAWAY with NO_ERROR and its
 * last stream, the streams up to it are answered, a stream opened after it is
 * refused unprocessed (REFUSED_STREAM, safe to send again), and the connection
 * says when it has nothing left (drained()).
 *
 * Asserted on the connection itself, with no server.
 *
 *   php tests/http2-graceful-goaway.php
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

$out = fopen('php://temp', 'w+b');
$conn = new Q_WebServer_Http2_Connection($out, function () { return null; });   // answered later, like a worker
$conn->start();
$conn->feed($F::PREFACE . $F::build($F::SETTINGS, 0, 0, ''));
$hpack = new Q_WebServer_Http2_Hpack();
$request = function ($id) use ($F, $hpack) {
	return $F::build($F::HEADERS, $F::FLAG_END_HEADERS | $F::FLAG_END_STREAM, $id, $hpack->encode(array(
		array(':method', 'GET'), array(':scheme', 'https'),
		array(':authority', 'example.com'), array(':path', '/page?' . $id),
	)));
};

/** Frames written since $from: list of array(type, flags, stream, payload). */
function written($out, &$from)
{
	$F = 'Q_WebServer_Http2_Frame';
	fseek($out, 0, SEEK_END);
	$end = ftell($out);
	fseek($out, $from);
	$buf = stream_get_contents($out, $end - $from);
	$from = $end;
	$list = array();
	while (($f = $F::read($buf)) !== null) $list[] = array($f['type'], $f['flags'], $f['stream'], $f['payload']);
	return $list;
}

$pos = 0;
written($out, $pos);   // the server's SETTINGS and the like
$conn->feed($request(1));
$conn->feed($request(3));
ok('two streams in flight', array_keys($conn->streams), array(1, 3));
ok('not drained before any GOAWAY', $conn->drained(), false);

$conn->goawayGraceful();
$frames = written($out, $pos);
ok('a graceful GOAWAY is one GOAWAY frame on stream 0', array(count($frames), $frames[0][0], $frames[0][2]), array(1, $F::GOAWAY, 0));
$payload = $frames[0][3];
ok('...naming the last stream opened, with NO_ERROR', unpack('Nlast/Nerror', $payload), array('last' => 3, 'error' => 0));
ok('...the connection is not closed by it', $conn->closed, false);
ok('...and not drained while streams are in flight', $conn->drained(), false);

$conn->goawayGraceful();
ok('a second call sends nothing more', written($out, $pos), array());

// A stream opened after the GOAWAY is refused, unprocessed.
$conn->feed($request(5));
$frames = written($out, $pos);
ok('a stream opened after it: RST_STREAM REFUSED_STREAM', array(count($frames), $frames[0][0], $frames[0][2], unpack('N', $frames[0][3])[1]),
	array(1, $F::RST_STREAM, 5, 7));
ok('...and never reaches the handler (not kept)', isset($conn->streams[5]), false);

// The streams before it are answered in full.
$conn->respond(1, array('status' => 200, 'headers' => array('content-type' => 'text/plain'), 'body' => 'one'));
ok('still not drained with stream 3 open', $conn->drained(), false);
$conn->respond(3, array('status' => 200, 'headers' => array('content-type' => 'text/plain'), 'body' => 'three'));
$frames = written($out, $pos);
$ends = array();
foreach ($frames as $f) {
	if ($f[0] === $F::DATA and ($f[1] & $F::FLAG_END_STREAM)) $ends[$f[2]] = $f[3];
}
ok('streams 1 and 3 answered in full after the GOAWAY', $ends, array(1 => 'one', 3 => 'three'));
ok('...and then the connection is drained', $conn->drained(), true);

// A connection closed for cause counts as drained; one never told is not.
$out2 = fopen('php://temp', 'w+b');
$c2 = new Q_WebServer_Http2_Connection($out2, function () { return null; });
$c2->start();
ok('a connection never sent GOAWAY is not drained', $c2->drained(), false);
$c2->goaway(1);
ok('one closed for cause is', $c2->drained(), true);
$c2->goawayGraceful();
$p2 = 0;
$n = 0;
foreach (written($out2, $p2) as $f) if ($f[0] === $F::GOAWAY) ++$n;
ok('...and is not sent a second GOAWAY', $n, 1);

echo "\n";
if ($fail) {
	printf("  FAIL - %d of %d case(s)\n\n", $fail, $pass + $fail);
	exit(1);
}
printf("  PASS - %d case(s)\n\n", $pass);
exit(0);
