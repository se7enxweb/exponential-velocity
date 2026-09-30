<?php

/**
 * A length-prefixed frame has to go out whole, or not at all.
 *
 * fwrite() on a non-blocking stream writes what fits in the socket buffer and
 * returns how much that was. For a stream of self-describing records -- a
 * WebSocket frame, or the pack('N', len) . json packets on the worker pipes --
 * a short write is not partial delivery, it is corruption. The peer reads the
 * length we declared, consumes that many bytes, and lands mid-payload. Every
 * record after it is misparsed, and nothing downstream can resynchronise.
 *
 * Every one of those writes discarded its return value. The pipe writer looked
 * like it checked, but it tested for false or zero and counted a partial write
 * as success, which is the case that actually breaks the stream.
 *
 * Q_WebServer::writeAll() solves this by switching to blocking, which suits a
 * worker that owns one connection. It is the wrong answer here: broadcast()
 * walks every client in one process, so blocking on a single unresponsive peer
 * would hold up everybody else. writeFully() waits for writability instead,
 * with a bounded timeout.
 *
 *   php tests/unit-websocket-write-framing.php
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

// These classes pull in the event loop and the worker machinery, so the method
// under test is exercised through a copy of its source taken at run time.
$src = file_get_contents(__DIR__ . '/../src/Q/WebSocket.php');
$wsSrc = file_get_contents(__DIR__ . '/../src/Q/WebServer.php');
$poolSrc = file_get_contents(__DIR__ . '/../src/Q/WebServer/Pool.php');

// The implementation lives on Q_WebServer, beside writeAll(), because three
// classes need it and a copy in each is how a fix reaches two of them.
if (!preg_match('/\n\tstatic function writeFully\(.*?\n\t\}\n/s', $wsSrc, $m)) {
	fwrite(STDERR, "  FAIL - writeFully() not found in src/Q/WebServer.php\n");
	exit(1);
}
eval('class T { ' . $m[0] . ' }');

function pair()
{
	$p = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
	if (!$p) {
		fwrite(STDERR, "  FAIL - stream_socket_pair unavailable\n");
		exit(1);
	}
	return $p;
}

// ── Contract ────────────────────────────────────────────────────

check('an empty payload is a no-op that succeeds',
	T::writeFully(null, ''), true);

check('a closed or absent socket fails rather than warning',
	T::writeFully(null, 'x'), false);

list($a, $b) = pair();
stream_set_blocking($a, false);
check('a small payload reports success',
	T::writeFully($a, 'hello'), true);
fclose($a);
check('a small payload arrives byte for byte', stream_get_contents($b), 'hello');
fclose($b);

list($a, $b) = pair();
fclose($a);
check('a socket closed under us fails', T::writeFully($a, 'x'), false);
fclose($b);

// ── The bug this exists to prevent ──────────────────────────────

// 8 MB is far larger than any default socket buffer, so with nobody reading,
// one fwrite() cannot possibly place it all. This is the measurement that
// shows the old code was losing data rather than theorising that it might.
$payload = str_repeat('0123456789abcdef', 8 * 65536); // 8 MiB, deterministic
$size = strlen($payload);

list($a, $b) = pair();
stream_set_blocking($a, false);
$oneShot = @fwrite($a, $payload);
check('a single fwrite() cannot place a large payload',
	($oneShot !== false && $oneShot < $size), true);
check('...and what it did write was silently short',
	$oneShot > 0, true);
fclose($a);
fclose($b);

// ── The fix, against a real reader ──────────────────────────────

// A child process drains the socket while the parent writes, which is the
// only way to produce genuine repeated partial writes: the buffer fills, the
// reader empties it, and writeFully() has to come back for the rest.
//
// pcntl is not everywhere -- the official PHP images ship without it, and
// Windows has none at all -- and this file is run on every platform the phar
// reaches. Skipping the concurrent half is honest; calling pcntl_fork() where
// it does not exist is a fatal that reads like a failure of the code under
// test rather than of the fixture.
$skipped = 0;
if (!function_exists('pcntl_fork')) {
	printf("  skip  the live-reader case needs pcntl, which this PHP lacks\n");
	$skipped = 3;
	goto structural;
}

list($a, $b) = pair();
list($resA, $resB) = pair();

$pid = pcntl_fork();
if ($pid === -1) {
	fwrite(STDERR, "  FAIL - could not fork\n");
	exit(1);
}

if ($pid === 0) {
	// Child: read to EOF, report what arrived.
	fclose($a);
	fclose($resB);
	$got = '';
	while (!feof($b)) {
		$chunk = fread($b, 8192);
		if ($chunk === false || $chunk === '') break;
		$got .= $chunk;
	}
	fclose($b);
	fwrite($resA, strlen($got) . ' ' . md5($got));
	fclose($resA);
	exit(0);
}

// Parent: write the whole thing, then close so the child sees EOF.
fclose($b);
fclose($resA);
stream_set_blocking($a, false);
$ok = T::writeFully($a, $payload, 20.0);
fclose($a);

$report = stream_get_contents($resB);
fclose($resB);
pcntl_waitpid($pid, $status);

check('writeFully reports success against a live reader', $ok, true);

$parts = explode(' ', trim($report));
check('the reader received every byte',
	isset($parts[0]) ? (int) $parts[0] : -1, $size);
check('the bytes arrived unaltered and in order',
	isset($parts[1]) ? $parts[1] : '', md5($payload));

// ── The bounded-timeout half ────────────────────────────────────

// With nobody reading, the buffer fills and stays full. writeFully must give
// up rather than stall the process that is also serving every other client.
list($a, $b) = pair();
stream_set_blocking($a, false);
$started = microtime(true);
$result = T::writeFully($a, $payload, 0.4);
$elapsed = microtime(true) - $started;
fclose($a);
fclose($b);

check('a peer that never reads makes the write fail', $result, false);
check('...and it gives up near the timeout, not later', $elapsed < 3.0, true);
check('...having actually waited rather than returned at once',
	$elapsed >= 0.3, true);

structural:

// ── The call sites ──────────────────────────────────────────────

// The helper is only worth anything where it is used, and every one of these
// writes a length-prefixed record.
check('encodeAndSend writes frames through writeFully',
	(bool) preg_match('/static function encodeAndSend\(.*?self::writeFully\(\$socket, \$frame\)/s', $src),
	true);

check('a frame that could not be completed closes the connection',
	(bool) preg_match('/static function encodeAndSend\(.*?self::disconnect\(\$sk\)/s', $src),
	true);

check('the handshake goes out through writeFully',
	(bool) preg_match('/self::writeFully\(\$socket, \$resp\)/', $src), true);

$rawPipeWrites = preg_match_all('/@fwrite\([^)]*\[\x27pipe\x27\]/', $src);
check('no worker pipe is still written with a bare fwrite', $rawPipeWrites, 0);

$pipeWrites = preg_match_all('/self::writeFully\([^;]*\[\x27pipe\x27\]/', $src);
check('every worker pipe write goes through writeFully', $pipeWrites >= 4, true);

check('the WebSocket helper delegates rather than keeping a second copy',
	(bool) preg_match('/static function writeFully\([^)]*\)\s*\{\s*return Q_WebServer::writeFully\(/s', $src),
	true);

// The worker pool sends each request as pack('N', len) . msg over a socket
// whose parent end is non-blocking, so it has exactly the same exposure. Its
// old check caught a dead worker but treated a short write as a success, which
// is the case that desynchronises the pipe for every later request.
check('the pool dispatches requests through writeFully',
	// A deadline may follow as a third argument (it grows with the frame).
	(bool) preg_match('/Q_WebServer::writeFully\(\$this->workers\[\$index\]\[\x27socket\x27\], \$packet\s*[,)]/', $poolSrc),
	true);

check('the pool no longer writes a request with a bare fwrite',
	preg_match_all('/@fwrite\(\$this->workers\[\$index\]\[\x27socket\x27\]/', $poolSrc), 0);

check('the pool no longer treats a short write as success',
	preg_match_all('/\$written === false \|\| \$written === 0/', $poolSrc), 0);

// The chunked-streaming path in the web server has the same shape: it states a
// length in hex and then supplies that many bytes.
check('chunked streaming writes its length prefix through writeAll',
	(bool) preg_match('/Q_WebServer::writeAll\(\$_streamingClient,\s*\n?\s*dechex\(strlen\(\$chunk\)\)/', $wsSrc),
	true);

check('the chunked terminator goes through writeAll',
	(bool) preg_match('/Q_WebServer::writeAll\(\$_streamingClient, "0\\\\r\\\\n\\\\r\\\\n"\)/', $wsSrc),
	true);

check('no bare fwrite remains on the streaming client',
	preg_match_all('/@fwrite\(\$_streamingClient/', $wsSrc), 0);

check('the CGI request body is written in full',
	(bool) preg_match('/self::writeAll\(\$pipes\[0\], \$parsed\[\x27body\x27\]\)/', $wsSrc),
	true);

if ($fail) {
	printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	exit(1);
}
if ($skipped) {
	printf("  PASS - %d case(s), %d skipped for want of pcntl\n", $pass, $skipped);
	exit(0);
}
printf("  PASS - %d case(s)\n", $pass);
