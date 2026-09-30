<?php

/**
 * HTTP/1.1 request bodies: refused over post_max_size with a 413 the client
 * can read, accepted whole below it, however they are framed and however
 * long they take to arrive.
 *
 * What went wrong, and how each case below would fail if it came back:
 *
 *   A. A binary body just under post_max_size (8 MB) travels to the worker
 *      base64-encoded, ~11 MB of JSON, and the worker refused any frame over
 *      a fixed 10 MB: it exited without a word and the visitor got a 502 for
 *      an upload PHP allows. Checked: the body arrives whole (md5).
 *   B. Over the limit, the 413 used to be written and the socket closed at
 *      once, with the body still arriving. A client that sends everything
 *      before reading -- most HTTP libraries do -- then got a reset instead
 *      of the 413, depending on timing. Checked: such a client reads a 413
 *      with "Connection: close", every time.
 *   C. A chunked body had no size check at all (it went to a worker, and
 *      came back 502), was "complete" wherever "\r\n0\r\n" first appeared in
 *      its data, and on a kept-alive connection its bytes were left behind
 *      as if they were the next request. Checked: over the limit is 413;
 *      data containing the terminator text arrives whole; two chunked
 *      requests on one connection are answered in order; a malformed chunk
 *      size is 400.
 *   D. The read timeout ran from the connection's accept (or the keep-alive
 *      idle timer ran on) while a body was still arriving, so a slow upload
 *      was cut off part-way, on a dynamic pool whose idle workers retire
 *      every second. Checked: a body trickled in over three times the read
 *      timeout is accepted whole, on a new and on a kept-alive connection.
 *   E. A body whose text merely contains "Content-Length: 99999999" is not
 *      read as framing.
 *
 *   php tests/unit-request-body-limit.php
 */

require __DIR__ . '/fixtures/race-harness.php';
rh_require_pool();
list($base, $root) = rh_setup('body-limit');

file_put_contents($root . DS . 'index.php', '<?php
$in = file_get_contents("php://input");
echo strlen($in), " ", md5($in);
');
file_put_contents($root . DS . 'hello.txt', "hello\n");

$limit = 8388608;
$port = rh_start('body', array('Q' => array('webserver' => array(
	'postMaxSize' => '8M',
	'timeout' => array('read' => 2, 'linger' => 2, 'lingerTotal' => 10),
	'keepAlive' => array('timeout' => 2),
	'spareWorkers' => 1, 'idleWorkerTimeout' => 1,
))), 4);

/** Open, send $parts (strings; a float is a pause in seconds), then read to EOF or $max seconds. */
function raw($port, $parts, $max = 30.0)
{
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5);
	if (!$s) return array('status' => 0, 'head' => '', 'body' => '', 'raw' => '', 'error' => "connect: $es");
	stream_set_timeout($s, (int) $max);
	$error = '';
	foreach ($parts as $p) {
		if (is_float($p)) { usleep((int) ($p * 1000000)); continue; }
		for ($off = 0; $off < strlen($p); ) {
			$n = @fwrite($s, substr($p, $off, 65536));
			if (!$n) { $error = 'write failed'; break 2; }
			$off += $n;
		}
	}
	$raw = '';
	$deadline = microtime(true) + $max;
	while (!feof($s) and microtime(true) < $deadline) {
		$c = @fread($s, 65536);
		if ($c === false) { $error .= ' read failed'; break; }
		$raw .= $c;
		if ($c === '') {
			$meta = stream_get_meta_data($s);
			if ($meta['timed_out']) break;
			usleep(10000);
		}
	}
	fclose($s);
	return array('raw' => $raw, 'error' => trim($error));
}

/** Split a raw read into responses: [status, headers, body] each. */
function responses($raw)
{
	$out = array();
	while ($raw !== '' and preg_match('#^HTTP/1\.1 (\d+)#', $raw, $m)) {
		$he = strpos($raw, "\r\n\r\n");
		if ($he === false) break;
		$head = substr($raw, 0, $he);
		$len = preg_match('/\r\ncontent-length:\s*(\d+)/i', $head, $l) ? (int) $l[1] : strlen($raw) - $he - 4;
		$out[] = array((int) $m[1], $head, substr($raw, $he + 4, $len));
		$raw = (string) substr($raw, $he + 4 + $len);
	}
	return $out;
}

function post($body, $extra = '', $conn = 'close')
{
	return "POST /index.php HTTP/1.1\r\nHost: t\r\nContent-Type: application/octet-stream\r\n"
		. "Content-Length: " . strlen($body) . "\r\n{$extra}Connection: $conn\r\n\r\n" . $body;
}

function chunked($body, $size = 65536, $conn = 'close')
{
	$out = "POST /index.php HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: chunked\r\nConnection: $conn\r\n\r\n";
	for ($i = 0; $i < strlen($body); $i += $size) {
		$c = substr($body, $i, $size);
		$out .= dechex(strlen($c)) . "\r\n" . $c . "\r\n";
	}
	return $out . "0\r\n\r\n";
}

function expect($label, $r, $status, $body = null)
{
	$rs = responses($r['raw']);
	$got = $rs ? $rs[0][0] : ('none' . ($r['error'] ? " ({$r['error']})" : ''));
	check("$label: status", $got, $status);
	if ($body !== null) check("$label: body", $rs ? $rs[0][2] : null, $body);
	return $rs;
}

// ── A. binary just under the limit ──────────────────────────────
$b = random_bytes($limit - 200000);
expect('A. binary body of 8 MB minus 200 KB', raw($port, array(post($b))), 200, strlen($b) . ' ' . md5($b));
$b = random_bytes($limit);
expect('A. binary body of exactly post_max_size', raw($port, array(post($b))), 200, strlen($b) . ' ' . md5($b));

// ── B. over the limit, sent whole before reading ────────────────
foreach (array($limit + 1, 12 * 1048576, 40 * 1048576) as $size) {
	$mb = round($size / 1048576, 1);
	for ($i = 0; $i < 3; ++$i) {
		$rs = expect("B. $mb MB sent whole, try $i", raw($port, array(post(str_repeat('b', $size)))), 413);
		if ($rs) check("B. $mb MB: Connection: close", (bool) preg_match('/\r\nconnection: close/i', $rs[0][1]), true);
	}
}

// ── C. chunked ──────────────────────────────────────────────────
expect('C. chunked over the limit', raw($port, array(chunked(str_repeat('c', $limit + 70000)))), 413);
$b = str_repeat("x\r\n0\r\n\r\n", 5000) . random_bytes(300000);
expect('C. chunked data containing the terminator text', raw($port, array(chunked($b, 1000))), 200,
	strlen($b) . ' ' . md5($b));
// A script's answer closes the connection (Connection: close) -- that is
// the server's choice, and allowed. What is never allowed is a second
// answer made from the first request's leftover body.
$b1 = random_bytes(200000); $b2 = random_bytes(90000);
$rs = responses(raw($port, array(chunked($b1, 65536, 'keep-alive'), chunked($b2, 4096, 'close')))['raw']);
$got = array_map(function ($r) { return array($r[0], $r[2]); }, $rs);
$closedAfterFirst = (count($rs) === 1 and preg_match('/\r\nconnection: close/i', $rs[0][1]));
check('C. two chunked requests on one connection: the first answered with its own body',
	$got[0] ?? null, array(200, strlen($b1) . ' ' . md5($b1)));
check('C. and the second answered with its own body, or the connection closed after the first',
	$closedAfterFirst ? 'closed' : ($got[1] ?? null),
	$closedAfterFirst ? 'closed' : array(200, strlen($b2) . ' ' . md5($b2)));
expect('C. malformed chunk size', raw($port, array("POST /index.php HTTP/1.1\r\nHost: t\r\n"
	. "Transfer-Encoding: chunked\r\nConnection: close\r\n\r\nzz\r\nabc\r\n0\r\n\r\n")), 400);
expect('C. chunked exactly at the limit', raw($port, array(chunked(str_repeat('d', $limit)))), 200,
	$limit . ' ' . md5(str_repeat('d', $limit)));

// ── D. slow bodies ──────────────────────────────────────────────
$b = random_bytes(600000);
$parts = array(substr(post($b), 0, 300));
$rest = substr(post($b), 300);
foreach (str_split($rest, 50000) as $piece) { $parts[] = 0.5; $parts[] = $piece; }
expect('D. a body trickled in over ~6 s (read timeout 2 s)', raw($port, $parts, 40.0), 200,
	strlen($b) . ' ' . md5($b));
// A static file keeps the connection alive; the upload then starts 1.5 s
// into the 2 s keep-alive idle time, and takes ~6 s.
$first = "GET /hello.txt HTTP/1.1\r\nHost: t\r\nConnection: keep-alive\r\n\r\n";
$parts = array($first, 1.5, substr(post($b), 0, 300));
foreach (str_split($rest, 50000) as $piece) { $parts[] = 0.5; $parts[] = $piece; }
$rs = responses(raw($port, $parts, 40.0)['raw']);
check('D. the same on a kept-alive connection, started 1.5 s into its 2 s idle time',
	array_map(function ($r) { return array($r[0], $r[2]); }, $rs),
	array(array(200, "hello\n"), array(200, strlen($b) . ' ' . md5($b))));
$c = chunked($b, 30000);
$parts = array();
foreach (str_split($c, 50000) as $piece) { $parts[] = $piece; $parts[] = 0.5; }
expect('D. a chunked body trickled in over ~6 s', raw($port, $parts, 40.0), 200, strlen($b) . ' ' . md5($b));

// ── E. framing text inside a body ───────────────────────────────
$b = "log line\r\nContent-Length: 99999999\r\nTransfer-Encoding: chunked\r\n" . str_repeat('e', 1000);
expect('E. a body that contains header text', raw($port, array(post($b))), 200, strlen($b) . ' ' . md5($b));

// The server is still up and serving.
expect('after all of it, a plain request', raw($port, array(post('ok'))), 200, '2 ' . md5('ok'));

rh_finish();
