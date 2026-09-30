#!/usr/bin/env php
<?php
/**
 * uwebserver, hardened: the release build (native/uwebserver/Makefile) is a
 * position-independent executable with full RELRO, immediate binding, a
 * stack protector, fortified libc calls and no executable stack; the fuzz
 * harness of the request parser runs clean (UWEB_FUZZ_SECONDS, default 10);
 * random and mutated requests over real connections leave the server
 * serving; responses say nothing about the machine (no version, no path,
 * no inode); no client byte can add a line to either log.
 *
 *   php tests/unit-uwebserver-hardening.php
 */
require __DIR__ . '/uwebserver-helpers.php';

$root = dirname(__DIR__);
$bin = uw_build();
if ($bin === null) uw_skip('no C compiler or OpenSSL headers');
check('uwebserver builds', is_string($bin));
if (!is_string($bin)) uw_finish();
$d = uw_dir();

// ── The release build ───────────────────────────────────────────────────
$made = "$d/release";
$libs = getenv('UWEB_TEST_LIBS') ? ' LIBS=' . escapeshellarg(getenv('UWEB_TEST_LIBS')) : '';
exec('make -s -C ' . escapeshellarg("$root/native/uwebserver") . ' OUT=' . escapeshellarg($made) . ' UWEB_VERSION=v0.0.0' . $libs . ' 2>&1', $mo, $mrc);
check('make builds the release binary', array($mrc, is_executable($made)), array(0, true));
if (is_executable($made) && trim((string) shell_exec('command -v readelf 2>/dev/null')) !== '') {
	$h = (string) shell_exec('readelf -h ' . escapeshellarg($made));
	$dyn = (string) shell_exec('readelf -d ' . escapeshellarg($made));
	$l = (string) shell_exec('readelf -lW ' . escapeshellarg($made));
	$syms = (string) shell_exec('nm -D ' . escapeshellarg($made) . ' 2>/dev/null');
	check('...a position-independent executable (PIE)', strpos($h, 'DYN (') !== false && strpos($dyn, 'PIE') !== false);
	check('...full RELRO: a RELRO segment and immediate binding', strpos($l, 'GNU_RELRO') !== false && strpos($dyn, 'BIND_NOW') !== false);
	check('...no executable stack', (bool) preg_match('/GNU_STACK\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+RW\s/', $l));
	check('...a stack protector', strpos($syms, '__stack_chk_fail') !== false);
	check('...fortified libc calls (_FORTIFY_SOURCE)', (bool) preg_match('/__\w+_chk\b/', $syms));
} else {
	echo "  skip  no readelf(1): the hardening of the binary\n";
}

// ── The fuzz harness ────────────────────────────────────────────────────
$fuzz = "$d/fuzz";
$cc = trim((string) shell_exec('command -v cc gcc 2>/dev/null | head -1'));
exec(escapeshellarg($cc) . ' -g -O1 -Wall -Wextra -Wno-unused-function ' . getenv('UWEB_TEST_CFLAGS') . ' -DUW_FUZZ_MAIN -o ' . escapeshellarg($fuzz) . ' '
	. escapeshellarg("$root/native/uwebserver/fuzz_request.c") . ' 2>&1', $fo, $frc);
check('the fuzz harness builds', $frc, 0);
if ($frc === 0) {
	$secs = (int) (getenv('UWEB_FUZZ_SECONDS') ?: 10);
	list($rc, $out, $err) = uw_run($fuzz, array((string) $secs, '1234567'), $secs + 60);
	check("the fuzz harness runs $secs s without a broken promise", array($rc, (bool) preg_match('/^fuzz: (\d+) inputs in \d+ seconds, no broken promise/', $out, $fm)), array(0, true));
	if ($rc !== 0) echo substr($err, 0, 3000);
	check('...over many inputs (at least 1000 a second)', isset($fm[1]) && (int) $fm[1] >= 1000 * $secs);
}

// ── Random requests over real connections ───────────────────────────────
mkdir("$d/www/sub", 0755, true);
file_put_contents("$d/www/index.html", "hard\n");
$port = uw_free_port();
$srv = uw_start($bin, array_merge(uw_root_args(), array("--root=$d/www", "--port=$port", '--verbose', "--error-log=$d/err.log",
	"--access-log=$d/acc.log", '--header-timeout=2', '--keepalive-timeout=1')), $port);
check('the server starts', $srv !== null);
mt_srand(424242);
$seeds = array("GET / HTTP/1.1\r\nHost: l\r\n\r\n", "GET /sub?x=1 HTTP/1.1\r\nHost: l\r\nRange: bytes=0-1\r\n\r\n",
	"HEAD /index.html HTTP/1.0\r\nUser-Agent: a\r\n\r\n", "GET /%2e%2e/x HTTP/1.1\r\nHost: l\r\nContent-Length: 3\r\n\r\nabc");
$bad_head = 0; $answers = 0; $leak = 0;
$t = microtime(true);
for ($i = 0; $i < 400 && microtime(true) - $t < 25; $i++) {
	$req = $seeds[mt_rand(0, count($seeds) - 1)];
	for ($m = mt_rand(1, 6); $m > 0; $m--) {
		$at = mt_rand(0, strlen($req));
		switch (mt_rand(0, 3)) {
		case 0: $req = substr($req, 0, $at) . chr(mt_rand(0, 255)) . substr($req, $at); break;
		case 1: $req = substr($req, 0, $at) . substr($req, $at + 1); break;
		case 2: $req = substr($req, 0, $at) . array("\r\n", "\n", "\r", '%00', '%0d%0a', '..', ' ', ':', "\0", str_repeat('A', 3000))[mt_rand(0, 9)] . substr($req, $at); break;
		default: $req .= $req; break;
		}
	}
	$raw = uw_raw($port, $req, 1);
	if ($raw === '' || $raw === 'CONNECT_FAIL') continue;
	$answers++;
	foreach (preg_split('/(?<=\r\n\r\n)(?=HTTP\/1\.1 )/', $raw) as $resp) {
		$p = strpos($resp, "\r\n\r\n");
		$head = $p === false ? $resp : substr($resp, 0, $p);
		$lines = explode("\r\n", $head);
		if (!preg_match('#^HTTP/1\.1 \d{3} [A-Za-z ]+$#', $lines[0])) $bad_head++;
		foreach (array_slice($lines, 1) as $hl) if (!preg_match('/^[A-Za-z0-9-]+: [\x20-\x7e]*$/', $hl)) $bad_head++;
		if (strpos($resp, $d) !== false || stripos($head, 'x-powered-by') !== false || preg_match('/^Server: (?!uwebserver\r?$)/m', $head)) $leak++;
	}
}
check('random and mutated requests are answered', $answers > 100);
check('...every answer a well-formed head, no byte of a request echoed into it', $bad_head, 0);
check('...none names a path of the machine, a version or X-Powered-By', $leak, 0);
check('...and the server is still serving', uw_get($port, '/')[0], 200);

// Responses: nothing about the machine.
list($s, $h, $b) = uw_get($port, '/missing/file');
check('a 404 says only 404 Not Found', array($s, $b), array(404, "404 Not Found\n"));
list($s, $h, $b) = uw_get($port, '/%2e%2e/etc/passwd');
check('a 400 says only 400 Bad Request', array($s, $b), array(400, "400 Bad Request\n"));
list($s, $h, $b) = uw_get($port, '/index.html');
check('the Server header has no version', $h['server'] ?? null, 'uwebserver');
$st = stat("$d/www/index.html");
clearstatcache();
check('the ETag is the time and the size, no inode', (bool) preg_match('/^"[0-9a-f]+-' . dechex($st['size']) . '"$/', $h['etag'] ?? ''));
check('no header names the software or the system beyond Server', array_diff(array_keys($h), array('date', 'server', 'etag', 'last-modified', 'content-type', 'content-length', 'accept-ranges', 'x-content-type-options', 'connection')), array());

// The logs: a client can never add a line.
uw_raw($port, "GET /a%0d%0a2026-01-01T00:00:00Z uwebserver[1]: error: forged HTTP/1.1\r\nHost: l\r\nUser-Agent: x\r\n\r\n", 1);
uw_raw($port, "GET /\r\n\r\nforged line\r\nmore\r\n\r\n", 1);
uw_stop($srv);
$err = file("$d/err.log", FILE_IGNORE_NEW_LINES);
$okLines = preg_grep('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ uwebserver\[\d+\]: (error|warning|notice|debug): [\x20-\x7e]*$/', $err);
check('every line of the error log is one of its own', count($okLines), count($err));
check('...none forged', count(preg_grep('/forged/', $err)), 0);
$acc = file("$d/acc.log", FILE_IGNORE_NEW_LINES);
check('every line of the access log begins with the client and has no raw control byte', count(preg_grep('/^127\.0\.0\.1 - - \[[^\]]+\] "[\x20-\x7e]*" \d{3} /', $acc)), count($acc));

uw_finish();
