#!/usr/bin/env php
<?php
/**
 * uwebserver reads a request head strictly (RFC 9112), so that it never
 * disagrees with another parser about where a request ends, and never lets a
 * malformed one through: CRLF only, no obsolete folding, no space before the
 * colon, no control character, one Host in HTTP/1.1, Content-Length digits
 * that agree with themselves, never with Transfer-Encoding, limits on the
 * head and the target with their own status. A head that fills the buffer is
 * answered 431 and the connection closed: its bytes are never read as the
 * start of another request.
 *
 *   php tests/unit-uwebserver-parser.php
 */
require __DIR__ . '/uwebserver-helpers.php';

$bin = uw_build();
if ($bin === null) uw_skip('no C compiler or OpenSSL headers');
check('uwebserver builds', is_string($bin));
if (!is_string($bin)) uw_finish();
$d = uw_dir();
mkdir("$d/www/.well-known/acme-challenge", 0755, true);
mkdir("$d/www/dir", 0755, true);
file_put_contents("$d/www/index.html", "ok\n");
file_put_contents("$d/www/.well-known/acme-challenge/token", "challenge\n");
file_put_contents("$d/www/.well-known/.hidden", "no\n");

$port = uw_free_port();
$srv = uw_start($bin, array_merge(uw_root_args(), array("--root=$d/www", "--port=$port", '--max-header-size=2k', '--max-uri-length=1k', '--access-log=' . "$d/a.log", '--access-log-format=json')), $port);
check('the server starts', $srv !== null);
if (!$srv) uw_finish();

function status_of($raw) { return preg_match('#^HTTP/1\.1 (\d{3})#', $raw, $m) ? (int) $m[1] : 0; }

$cases = array(
	'a good request' => array("GET / HTTP/1.1\r\nHost: l\r\nConnection: close\r\n\r\n", 200),
	'empty lines before it are ignored' => array("\r\n\r\nGET / HTTP/1.1\r\nHost: l\r\nConnection: close\r\n\r\n", 200),
	'a bare LF ending a line' => array("GET / HTTP/1.1\nHost: l\r\n\r\n", 400),
	'a bare LF ending a header' => array("GET / HTTP/1.1\r\nHost: l\n\r\n", 400),
	'a bare CR inside a header' => array("GET / HTTP/1.1\r\nHost: l\rX: y\r\n\r\n", 400),
	'obsolete line folding' => array("GET / HTTP/1.1\r\nHost: l\r\nX-A: 1\r\n  more\r\n\r\n", 400),
	'a space before the colon' => array("GET / HTTP/1.1\r\nHost : l\r\n\r\n", 400),
	'a header without a colon' => array("GET / HTTP/1.1\r\nHost: l\r\nNoColon\r\n\r\n", 400),
	'an empty header name' => array("GET / HTTP/1.1\r\nHost: l\r\n: v\r\n\r\n", 400),
	'NUL in a header value' => array("GET / HTTP/1.1\r\nHost: l\r\nX: a\0b\r\n\r\n", 400),
	'a control character in a value' => array("GET / HTTP/1.1\r\nHost: l\r\nX: a\x01b\r\n\r\n", 400),
	'DEL in a value' => array("GET / HTTP/1.1\r\nHost: l\r\nX: a\x7fb\r\n\r\n", 400),
	'HTTP/1.1 without Host' => array("GET / HTTP/1.1\r\n\r\n", 400),
	'two Host headers' => array("GET / HTTP/1.1\r\nHost: a\r\nHost: b\r\n\r\n", 400),
	'HTTP/1.0 without Host is fine' => array("GET / HTTP/1.0\r\n\r\n", 200),
	'Content-Length and Transfer-Encoding' => array("GET / HTTP/1.1\r\nHost: l\r\nContent-Length: 3\r\nTransfer-Encoding: chunked\r\n\r\nabc", 400),
	'Transfer-Encoding alone: not read' => array("GET / HTTP/1.1\r\nHost: l\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n", 501),
	'Transfer-Encoding with an empty value' => array("GET / HTTP/1.1\r\nHost: l\r\nTransfer-Encoding:\r\n\r\n", 501),
	'two Content-Lengths that differ' => array("GET / HTTP/1.1\r\nHost: l\r\nContent-Length: 1\r\nContent-Length: 2\r\n\r\nab", 400),
	'two Content-Lengths that agree' => array("GET / HTTP/1.1\r\nHost: l\r\nContent-Length: 2\r\nContent-Length: 2\r\nConnection: close\r\n\r\nab", 200),
	'Content-Length with a sign' => array("GET / HTTP/1.1\r\nHost: l\r\nContent-Length: +2\r\n\r\nab", 400),
	'Content-Length negative' => array("GET / HTTP/1.1\r\nHost: l\r\nContent-Length: -1\r\n\r\n", 400),
	'Content-Length as a list' => array("GET / HTTP/1.1\r\nHost: l\r\nContent-Length: 2, 2\r\n\r\nab", 400),
	'Content-Length of 19 digits' => array("GET / HTTP/1.1\r\nHost: l\r\nContent-Length: 9999999999999999999\r\n\r\n", 400),
	'a body over --max-body-size' => array("GET / HTTP/1.1\r\nHost: l\r\nContent-Length: 999999999999\r\n\r\n", 413),
	'HTTP/2.0' => array("GET / HTTP/2.0\r\nHost: l\r\n\r\n", 505),
	'HTTP/0.9 style' => array("GET /\r\n\r\n", 400),
	'HTTP/1.2 is answered as 1.1' => array("GET / HTTP/1.2\r\nHost: l\r\nConnection: close\r\n\r\n", 200),
	'a lower-case protocol' => array("GET / http/1.1\r\nHost: l\r\n\r\n", 400),
	'two spaces after the method' => array("GET  / HTTP/1.1\r\nHost: l\r\n\r\n", 400),
	'a TAB in the target' => array("GET /a\tb HTTP/1.1\r\nHost: l\r\n\r\n", 400),
	'a fragment in the target' => array("GET /index.html#x HTTP/1.1\r\nHost: l\r\n\r\n", 400),
	'a target not starting with /' => array("GET index.html HTTP/1.1\r\nHost: l\r\n\r\n", 400),
	'* for GET' => array("GET * HTTP/1.1\r\nHost: l\r\n\r\n", 400),
	'a method of 40 letters' => array(str_repeat('A', 40) . " / HTTP/1.1\r\nHost: l\r\n\r\n", 501),
	'a lower-case method' => array("get / HTTP/1.1\r\nHost: l\r\n\r\n", 501),
	'a target of the longest allowed' => array('GET /' . str_repeat('a', 1023) . " HTTP/1.1\r\nHost: l\r\nConnection: close\r\n\r\n", 404),
	'a target one byte too long' => array('GET /' . str_repeat('a', 1024) . " HTTP/1.1\r\nHost: l\r\n\r\n", 414),
	'a head over --max-header-size' => array("GET / HTTP/1.1\r\nHost: l\r\nX: " . str_repeat('b', 2100) . "\r\n\r\n", 431),
	'connection: CLOSE, any case' => array("GET / HTTP/1.1\r\nHost: l\r\nconnection: CLOSE\r\n\r\n", 200),
	'/.well-known/ is served' => array("GET /.well-known/acme-challenge/token HTTP/1.1\r\nHost: l\r\nConnection: close\r\n\r\n", 200),
	'...but a dot name inside it is not' => array("GET /.well-known/.hidden HTTP/1.1\r\nHost: l\r\n\r\n", 404),
	'...nor .well-known deeper down' => array("GET /dir/.well-known/x HTTP/1.1\r\nHost: l\r\n\r\n", 404),
);
foreach ($cases as $what => $c) {
	$raw = uw_raw($port, $c[0], 3);
	check("$what: {$c[1]}", status_of($raw), $c[1]);
}

// After a malformed request the connection is closed: nothing behind it is read.
$raw = uw_raw($port, "GET / HTTP/1.1\nHost: l\r\n\r\nGET / HTTP/1.1\r\nHost: l\r\n\r\n", 3);
check('after a 400 nothing more is answered on that connection', substr_count($raw, 'HTTP/1.1 '), 1);
check('...and it says Connection: close', uw_split($raw)[1]['connection'] ?? null, 'close');
// A head that fills the buffer: 431 and closed, its tail never taken for a request.
$raw = uw_raw($port, "GET / HTTP/1.1\r\nHost: l\r\nX: " . str_repeat('b', 3000) . "\r\n\r\nGET /index.html HTTP/1.1\r\nHost: l\r\n\r\n", 3);
check('a head that fills the buffer: one 431, and the bytes after it are not a request', array(status_of($raw), substr_count($raw, 'HTTP/1.1 ')), array(431, 1));
// Connection: close inside another header's value closes nothing.
$raw = uw_raw($port, "GET / HTTP/1.1\r\nHost: l\r\nX-Note: Connection: close\r\n\r\nGET / HTTP/1.1\r\nHost: l\r\nConnection: close\r\n\r\n", 3);
check('"Connection: close" inside another header keeps the connection', substr_count($raw, 'HTTP/1.1 200'), 2);
// A request that arrives a byte at a time, a CR on its own at the end of a write included.
$fp = stream_socket_client("tcp://127.0.0.1:$port", $e, $es, 2);
foreach (str_split("\r\nGET / HTTP/1.1\r\nHost: l\r\nConnection: close\r\n\r\n") as $ch) { fwrite($fp, $ch); usleep(2000); }
stream_set_timeout($fp, 3);
check('a request a byte at a time, its first CR alone', status_of((string) stream_get_contents($fp)), 200);
fclose($fp);

// The redirect of a directory encodes what is not printable ASCII in the query.
$raw = uw_raw($port, "GET /dir?q=\xc3\xa9&x=1 HTTP/1.1\r\nHost: l\r\nConnection: close\r\n\r\n", 3);
check('a directory redirect keeps the query, bytes above 0x7e encoded', uw_split($raw)[1]['location'] ?? null, '/dir/?q=%C3%A9&x=1');

// The JSON access log stays JSON with every field at its longest.
$ua = str_repeat("\"\\", 600);
uw_raw($port, 'GET /' . str_repeat('%22', 330) . " HTTP/1.1\r\nHost: l\r\nUser-Agent: $ua\r\nReferer: $ua\r\nConnection: close\r\n\r\n", 3);
uw_stop($srv);
$bad = 0; $n = 0;
foreach (file("$d/a.log") as $l) { $n++; if (!is_array(json_decode($l, true))) $bad++; }
check('every JSON log line parses, the longest too', array($n > 40, $bad), array(true, 0));

// Started with standard input closed, the first connection gets descriptor 0:
// it is served and closed like any other (0 once marked a free slot).
$port = uw_free_port();
$spec = array(1 => array('file', "$d/fd0.out", 'w'), 2 => array('file', "$d/fd0.err", 'w'));
$cmd = 'exec 0<&- ; exec ' . implode(' ', array_map('escapeshellarg', array_merge(array($bin), uw_root_args(), array("--root=$d/www", "--port=$port")))) ;
$p = proc_open(array('sh', '-c', $cmd), $spec, $pipes);
$up = false;
for ($i = 0; $i < 100 && !$up; $i++) { usleep(30000); $up = uw_listening($port); }
$answers = 0;
for ($i = 0; $i < 5; $i++) if (uw_get($port, '/')[0] === 200) $answers++;
check('with standard input closed, connection after connection is served', $answers, 5);
$st = proc_get_status($p);
posix_kill($st['pid'], 15);
proc_close($p);

// A NUL byte in the configuration file is found, not cut at.
file_put_contents("$d/nul.conf", "port = 12345\0\n");
list($rc, $out, $err) = uw_run($bin, array("--config=$d/nul.conf", '--check'));
check('a NUL byte in the configuration file: exit 2, file and line', array($rc, strpos($err, "$d/nul.conf:1: a NUL byte") !== false), array(2, true));

uw_finish();
