#!/usr/bin/env php
<?php
/**
 * uwebserver under load it did not ask for: every way a client can hold a
 * connection is bounded in time (a head sent slowly, an idle connection, a
 * body that never comes, a response never read, a TLS handshake never
 * finished); the number of connections, requests per connection, the memory
 * a connection may hold and the CPU a slow client costs are bounded; running
 * out of descriptors pauses accepting instead of spinning; workers that keep
 * dying stop the server instead of forking without end; a file that shrinks
 * while it is sent, a file over 4 GiB and a very large directory are handled.
 *
 *   php tests/unit-uwebserver-limits.php
 */
require __DIR__ . '/uwebserver-helpers.php';

$bin = uw_build();
if ($bin === null) uw_skip('no C compiler or OpenSSL headers');
check('uwebserver builds', is_string($bin));
if (!is_string($bin)) uw_finish();
$d = uw_dir();
mkdir("$d/www/big-dir", 0755, true);
file_put_contents("$d/www/index.html", "limits\n");
$fh = fopen("$d/www/big.bin", 'w');
for ($i = 0; $i < 48; $i++) fwrite($fh, random_bytes(1024 * 1024));
fclose($fh);

/** Seconds until the server closes a connection on which $send was sent (and then nothing), at most $max. */
function closed_after($port, $send, $max, $tls = false, $slow = null)
{
	$ctx = stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false)));
	$fp = @stream_socket_client("tcp://127.0.0.1:$port", $e, $es, 2, STREAM_CLIENT_CONNECT, $ctx);
	if (!$fp) return -1;
	$t = microtime(true);
	if ($send !== '') fwrite($fp, $send);
	stream_set_blocking($fp, false);
	$next = $t;
	while (microtime(true) - $t < $max) {
		if ($slow !== null && microtime(true) >= $next) { @fwrite($fp, $slow); $next = microtime(true) + 0.4; }
		$r = array($fp); $w = null; $x = null;
		if (@stream_select($r, $w, $x, 0, 100000) > 0) {
			$c = @fread($fp, 65536);
			if ($c === '' || $c === false) { if (feof($fp)) { fclose($fp); return microtime(true) - $t; } }
		}
	}
	fclose($fp);
	return INF;
}

function cpu_seconds($pid)
{
	$f = @file_get_contents("/proc/$pid/stat");
	if ($f === false) return null;
	$parts = explode(' ', substr($f, strrpos($f, ')') + 2));
	return ((int) $parts[11] + (int) $parts[12]) / 100.0;   // utime + stime, in clock ticks of 1/100 s
}

function rss_kb($pid)
{
	return preg_match('/^VmRSS:\s+(\d+) kB/m', (string) @file_get_contents("/proc/$pid/status"), $m) ? (int) $m[1] : null;
}

// ── Time limits ─────────────────────────────────────────────────────────
$port = uw_free_port();
$srv = uw_start($bin, array_merge(uw_root_args(), array("--root=$d/www", "--port=$port", '--header-timeout=2', '--keepalive-timeout=1',
	'--read-timeout=1', '--write-timeout=2', '--max-connections=6', '--max-requests=3')), $port);
check('the server starts', $srv !== null);
$t = closed_after($port, '', 6);
check('a connection that sends nothing: closed at --header-timeout (2 s)', $t >= 1.5 && $t < 4, true);
$t = closed_after($port, "GET / HTTP/1.1\r\n", 8, false, "X-Slow: 1\r\n");
check('a head sent slowly, a line every 0.4 s: closed at --header-timeout, however it trickles', $t >= 1.5 && $t < 4.5, true);
$t = closed_after($port, "GET / HTTP/1.1\r\nHost: l\r\n\r\n", 6);
check('an idle connection after a request: closed at --keepalive-timeout (1 s)', $t >= 0.5 && $t < 3, true);
$t = closed_after($port, "GET / HTTP/1.1\r\nHost: l\r\nContent-Length: 100\r\n\r\n0123456789", 6);
check('a body that stops coming: closed at --read-timeout (1 s)', $t >= 0.5 && $t < 3, true);

// A response that is never read: the connection goes after --write-timeout without progress.
$fp = stream_socket_client("tcp://127.0.0.1:$port", $e, $es, 2);
fwrite($fp, "GET /big.bin HTTP/1.1\r\nHost: l\r\n\r\n");
sleep(4);
stream_set_timeout($fp, 5);
$got = 0;
while (!feof($fp)) { $c = fread($fp, 1 << 20); if ($c === '' || $c === false) break; $got += strlen($c); }
fclose($fp);
check('a response never read: dropped after --write-timeout, not held', $got > 0 && $got < 48 * 1024 * 1024);

// --max-requests
$raw = uw_raw($port, str_repeat("GET / HTTP/1.1\r\nHost: l\r\n\r\n", 5), 4);
check('--max-requests=3: three answers on one connection', substr_count($raw, 'HTTP/1.1 200'), 3);
check('...the third says Connection: close', substr_count($raw, 'Connection: close'), 1);

// --max-connections
$held = array();
for ($i = 0; $i < 6; $i++) { $h = stream_socket_client("tcp://127.0.0.1:$port", $e, $es, 2); fwrite($h, "GET / HTTP/1.1\r\nHost: l\r\n\r\n"); $held[] = $h; }
usleep(300000);
$t = closed_after($port, '', 3);
check('--max-connections=6: a seventh connection is closed at once', $t < 1, true);
foreach ($held as $h) fclose($h);
usleep(300000);
check('...and once they are gone, it serves again', uw_get($port, '/')[0], 200);

// A client pipelining requests without reading: its memory stays bounded.
$rss0 = rss_kb($srv['pid']);
$fp = stream_socket_client("tcp://127.0.0.1:$port", $e, $es, 2);
stream_set_blocking($fp, false);
$sent = 0;
$flood = str_repeat("GET /index.html HTTP/1.1\r\nHost: l\r\n\r\n", 1000);
$t = microtime(true);
while (microtime(true) - $t < 2) { $w = @fwrite($fp, $flood); if ($w > 0) $sent += $w; else usleep(10000); }
$rss1 = rss_kb($srv['pid']);
fclose($fp);
check('pipelined requests never read: the server reads no further than it answers (memory grew < 4 MiB)', $rss0 !== null && $rss1 - $rss0 < 4096);
check('...the client could not push more than the socket buffers hold', $sent < 32 * 1024 * 1024);
uw_stop($srv);

// A TLS handshake that never finishes.
if (function_exists('openssl_pkey_new')) {
	$key = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
	$crt = openssl_csr_sign(openssl_csr_new(array('commonName' => 'localhost'), $key), null, $key, 1);
	openssl_x509_export($crt, $pem); openssl_pkey_export($key, $kpem);
	file_put_contents("$d/c.crt", $pem); file_put_contents("$d/c.key", $kpem); chmod("$d/c.key", 0600);
	$tp = uw_free_port();
	$srv = uw_start($bin, array_merge(uw_root_args(), array("--tls-port=$tp", "--cert=$d/c.crt", "--key=$d/c.key", '--listen=127.0.0.1:' . uw_free_port(), '--tls-handshake-timeout=1')), $tp);
	$t = closed_after($tp, "\x16\x03\x01\x00\x05", 5);
	check('half a TLS ClientHello: closed at --tls-handshake-timeout (1 s)', $t >= 0.5 && $t < 3, true);
	uw_stop($srv);
}

// ── CPU: a head sent a byte at a time costs nothing like its square ─────
$port = uw_free_port();
$srv = uw_start($bin, array_merge(uw_root_args(), array("--root=$d/www", "--port=$port", '--max-header-size=64k', '--max-uri-length=4k')), $port);
$cpu0 = cpu_seconds($srv['pid']);
$ctx = stream_context_create(array('socket' => array('tcp_nodelay' => true)));
$clients = array();
for ($i = 0; $i < 4; $i++) $clients[] = stream_socket_client("tcp://127.0.0.1:$port", $e, $es, 2, STREAM_CLIENT_CONNECT, $ctx);
$head = "GET / HTTP/1.1\r\nHost: l\r\n" . str_repeat("X-Pad: 0123456789abcdefghijklmnopqrstuvwxyz\r\n", 1300);
foreach (str_split($head, 8) as $chunk) foreach ($clients as $c) fwrite($c, $chunk);
foreach ($clients as $c) fwrite($c, "Connection: close\r\n\r\n");
$ok = 0;
foreach ($clients as $c) { stream_set_timeout($c, 5); if (strpos((string) stream_get_contents($c), 'HTTP/1.1 200') === 0) $ok++; fclose($c); }
$cpu = cpu_seconds($srv['pid']) - $cpu0;
check('four 57 KiB heads sent 8 bytes at a time are answered', $ok, 4);
check('...for little CPU (under 0.5 s; parsing every read again costs seconds)', $cpu < 0.5);
uw_stop($srv);

// ── Out of descriptors: a pause, not a spin ─────────────────────────────
$port = uw_free_port();
$args = array_merge(array($bin), uw_root_args(), array("--root=$d/www", "--port=$port", '--max-connections=10000', "--error-log=$d/fd.log"));
$p = proc_open(array('sh', '-c', 'ulimit -n 40 && exec ' . implode(' ', array_map('escapeshellarg', $args))), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
for ($i = 0; $i < 100 && !uw_listening($port); $i++) usleep(30000);
$spid = (int) trim((string) shell_exec('pgrep -P ' . proc_get_status($p)['pid'] . ' 2>/dev/null')) ?: proc_get_status($p)['pid'];
$held = array();
for ($i = 0; $i < 60; $i++) { $h = @stream_socket_client("tcp://127.0.0.1:$port", $e, $es, 1); if ($h) $held[] = $h; }
$cpu0 = cpu_seconds($spid);
sleep(2);
$cpu = cpu_seconds($spid) - $cpu0;
check('with its descriptors used up, it pauses accepting (under 0.3 s CPU in 2 s)', $cpu !== null && $cpu < 0.3);
check('...and says so in its log', strpos((string) @file_get_contents("$d/fd.log"), 'pausing for a second') !== false);
foreach ($held as $h) fclose($h);
sleep(2);
check('...and serves again once descriptors are free', uw_get($port, '/')[0], 200);
posix_kill($spid, 15);
proc_close($p);

// ── Workers that keep dying stop the server, not a fork loop ────────────
$port = uw_free_port();
$srv = uw_start($bin, array_merge(uw_root_args(), array("--port=$port", '--workers=2', "--error-log=$d/wk.log")), $port);
$killed = 0;
$t = microtime(true);
while (microtime(true) - $t < 6 && proc_get_status($srv['proc'])['running']) {
	foreach (array_filter(array_map('intval', preg_split('/\s+/', trim((string) @file_get_contents("/proc/{$srv['pid']}/task/{$srv['pid']}/children"))))) as $k) { posix_kill($k, 9); $killed++; }
	usleep(50000);
}
$st = proc_get_status($srv['proc']);
check('workers killed again and again: the parent stops', $st['running'], false);
check('...with exit status 1', $st['exitcode'], 1);
check('...and says why', strpos((string) @file_get_contents("$d/wk.log"), 'workers keep ending; stopping') !== false);
check('...after a bounded number of new workers (the limit is 2N+5 = 9 deaths in ten seconds)', $killed <= 20, true);
uw_stop($srv);

// ── Files: one that shrinks while it is sent, one over 4 GiB ────────────
$port = uw_free_port();
$srv = uw_start($bin, array_merge(uw_root_args(), array("--root=$d/www", "--port=$port", '--write-timeout=3', '--directory-listing')), $port);
\copy("$d/www/big.bin", "$d/www/shrinks.bin");
$fp = stream_socket_client("tcp://127.0.0.1:$port", $e, $es, 2);
fwrite($fp, "GET /shrinks.bin HTTP/1.1\r\nHost: l\r\n\r\n");
$first = fread($fp, 65536);
$h = fopen("$d/www/shrinks.bin", 'r+'); ftruncate($h, 1024 * 1024); fclose($h);
stream_set_timeout($fp, 6);
$got = strlen((string) $first); $t = microtime(true);
while (!feof($fp) && microtime(true) - $t < 8) { $c = fread($fp, 1 << 20); if ($c === '' || $c === false) break; $got += strlen($c); }
$ended = feof($fp);
fclose($fp);
check('a file cut short while it is sent: the connection is closed (its length can no longer be kept)', array($ended, $got < 48 * 1024 * 1024), array(true, true));
check('...and the server goes on serving', uw_get($port, '/')[0], 200);
$h = fopen("$d/www/huge.bin", 'w'); ftruncate($h, 5 * 1024 * 1024 * 1024); fclose($h);   // sparse: no disk used
list($s, $hd) = uw_get($port, '/huge.bin', array(), 'HEAD');
check('a file of 5 GiB: its length', array($s, $hd['content-length'] ?? null), array(200, '5368709120'));
list($s, $hd, $b) = uw_get($port, '/huge.bin', array('Range' => 'bytes=5000000000-5000000009'));
check('...a range past 4 GiB', array($s, $hd['content-range'] ?? null, $b), array(206, 'bytes 5000000000-5000000009/5368709120', str_repeat("\0", 10)));
for ($i = 0; $i < 20050; $i++) touch(sprintf('%s/www/big-dir/f%05d', $d, $i));
list($s, $hd, $b) = uw_get($port, '/big-dir/');
check('a directory of 20 050 names: listed, at most 20 000 of them (and ../)', array($s, substr_count($b, '<li>')), array(200, 20001));
$body = str_repeat('x', 1024 * 1024);
$raw = uw_raw($port, "GET / HTTP/1.1\r\nHost: l\r\nContent-Length: " . strlen($body) . "\r\n\r\n$body" . "GET /index.html HTTP/1.1\r\nHost: l\r\nConnection: close\r\n\r\n", 5);
check('a body of --max-body-size is skipped and the next request answered', substr_count($raw, 'HTTP/1.1 200'), 2);
$raw = uw_raw($port, "GET / HTTP/1.1\r\nHost: l\r\nContent-Length: " . (1024 * 1024 + 1) . "\r\n\r\n" . substr($body, 0, 1000), 5);
check('one byte more: 413, and closed', array((int) substr($raw, 9, 3), uw_split($raw)[1]['connection'] ?? null), array(413, 'close'));
uw_stop($srv);

uw_finish();
