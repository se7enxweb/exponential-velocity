#!/usr/bin/env php
<?php
/**
 * uwebserver --root=DIR serves the files under DIR: GET and HEAD, the
 * headers a static file needs (type, length, ETag, Last-Modified), index
 * files and the redirect to a directory's slash, conditional requests, one
 * byte range, precompressed .gz files, directory listings when asked for,
 * extra headers, and nothing outside DIR, hidden or not a regular file.
 *
 *   php tests/unit-uwebserver-static.php
 */
require __DIR__ . '/uwebserver-helpers.php';

$bin = uw_build();
if ($bin === null) uw_skip('no C compiler or OpenSSL headers');
check('uwebserver builds', is_string($bin));
if (!is_string($bin)) uw_finish();

// ── A tree to serve ──────────────────────────────────────────────────────
$d = uw_dir();
$www = "$d/www";
mkdir("$www/sub", 0755, true);
mkdir("$www/nolist", 0755, true);
mkdir("$www/.git", 0755, true);
file_put_contents("$www/index.html", "hello\n");
file_put_contents("$www/sub/index.html", "sub index\n");
file_put_contents("$www/sub/home.htm", "home\n");
file_put_contents("$www/ten.txt", 'abcdefghij');
file_put_contents("$www/.env", "SECRET=1\n");
file_put_contents("$www/.git/config", "[core]\n");
file_put_contents("$www/nolist/<b>&\"x\".txt", "odd name\n");
file_put_contents("$www/nolist/plain.txt", "plain\n");
file_put_contents("$www/a b.txt", "space\n");
file_put_contents("$www/style.css", str_repeat("body { color: red; }\n", 50));
file_put_contents("$www/style.css.gz", gzencode(file_get_contents("$www/style.css")));
file_put_contents("$www/data.foo", "foo\n");
file_put_contents("$www/noext", "noext\n");
file_put_contents("$d/outside.txt", "outside\n");
symlink("$www/ten.txt", "$www/link-in");
symlink('ten.txt', "$www/rel-link-in");
symlink("$d/outside.txt", "$www/link-out");
symlink($d, "$www/dirlink-out");
if (function_exists('posix_mkfifo')) posix_mkfifo("$www/fifo", 0644);
$big = random_bytes(3 * 1024 * 1024 + 17);
file_put_contents("$www/big.bin", $big);
file_put_contents("$d/mime.types", "# extra\napplication/x-foo   foo fooo\n");

$port = uw_free_port();
$srv = uw_start($bin, array_merge(uw_root_args(), array("--root=$www", "--port=$port", '--error-log=' . "$d/error.log")), $port);
check('the server starts on 127.0.0.1', $srv !== null);
if (!$srv) { echo @file_get_contents("$d/error.log"); uw_finish(); }
check('...and listens on 127.0.0.1 only', uw_bound_address($port), '127.0.0.1');

// ── A file ──────────────────────────────────────────────────────────────
list($s, $h, $b) = uw_get($port, '/');
check('GET / is the index file', array($s, $b), array(200, "hello\n"));
check('...text/html with the charset', $h['content-type'] ?? null, 'text/html; charset=utf-8');
check('...its length', $h['content-length'] ?? null, '6');
check('...an ETag', (bool) preg_match('/^"[0-9a-f]+-6"$/', $h['etag'] ?? ''));
check('...a Last-Modified date', (bool) preg_match('/^\w{3}, \d\d \w{3} \d{4} \d\d:\d\d:\d\d GMT$/', $h['last-modified'] ?? ''));
check('...nosniff', $h['x-content-type-options'] ?? null, 'nosniff');
check('...Server: uwebserver, and no version in it', $h['server'] ?? null, 'uwebserver');
check('...Accept-Ranges: bytes', $h['accept-ranges'] ?? null, 'bytes');
$etag = $h['etag'] ?? '';
$lastmod = $h['last-modified'] ?? '';
list($s, $h, $b) = uw_get($port, '/', array(), 'HEAD');
check('HEAD /: the same head, no body', array($s, $h['content-length'] ?? null, $b), array(200, '6', ''));
list($s, $h, $b) = uw_get($port, '/ten.txt');
check('a text file', array($s, $b, $h['content-type'] ?? null), array(200, 'abcdefghij', 'text/plain; charset=utf-8'));
list($s, $h, $b) = uw_get($port, '/a%20b.txt');
check('a name with a space, percent-encoded', array($s, $b), array(200, "space\n"));
list($s, $h) = uw_get($port, '/noext');
check('no extension: the default type', $h['content-type'] ?? null, 'application/octet-stream');
list($s, $h, $b) = uw_get($port, '/big.bin');
check('a 3 MiB file arrives whole', array($s, strlen($b), md5($b)), array(200, strlen($big), md5($big)));
list($s, $h) = uw_get($port, '/missing.html');
check('a missing file: 404', $s, 404);
list($s, $h) = uw_get($port, '/ten.txt/');
check('a file named with a slash after it: 404', $s, 404);

// ── Directories ──────────────────────────────────────────────────────────
list($s, $h) = uw_get($port, '/sub');
check('a directory without its slash: 301 to it', array($s, $h['location'] ?? null), array(301, '/sub/'));
list($s, $h) = uw_get($port, '/sub?x=1&y=2');
check('...keeping the query', $h['location'] ?? null, '/sub/?x=1&y=2');
list($s, $h) = uw_get($port, '//sub');
check('...never to another host (//sub goes to /sub/)', $h['location'] ?? null, '/sub/');
list($s, $h, $b) = uw_get($port, '/sub/');
check('a directory: its index file', array($s, $b), array(200, "sub index\n"));
list($s, $h) = uw_get($port, '/nolist/');
check('a directory without an index, no listing: 403', $s, 403);

// ── Conditional requests and ranges ─────────────────────────────────────
list($s, $h, $b) = uw_get($port, '/', array('If-None-Match' => $etag));
check('If-None-Match with the ETag: 304, no body', array($s, $b), array(304, ''));
list($s) = uw_get($port, '/', array('If-None-Match' => "W/$etag"));
check('...a weak one too', $s, 304);
list($s) = uw_get($port, '/', array('If-None-Match' => '"other", ' . $etag));
check('...in a list', $s, 304);
list($s) = uw_get($port, '/', array('If-None-Match' => '"other"'));
check('another ETag: 200', $s, 200);
list($s) = uw_get($port, '/', array('If-Modified-Since' => $lastmod));
check('If-Modified-Since the file time: 304', $s, 304);
list($s) = uw_get($port, '/', array('If-Modified-Since' => 'Mon, 01 Jan 2001 00:00:00 GMT'));
check('...an older date: 200', $s, 200);
list($s) = uw_get($port, '/', array('If-Modified-Since' => 'garbage'));
check('...a date that is not one: 200', $s, 200);
$ranges = array(
	'bytes=2-4' => array(206, 'cde', 'bytes 2-4/10'),
	'bytes=7-' => array(206, 'hij', 'bytes 7-9/10'),
	'bytes=-3' => array(206, 'hij', 'bytes 7-9/10'),
	'bytes=-30' => array(206, 'abcdefghij', 'bytes 0-9/10'),
	'bytes=5-100' => array(206, 'fghij', 'bytes 5-9/10'),
	'bytes=0-0' => array(206, 'a', 'bytes 0-0/10'),
	'bytes=10-' => array(416, null, 'bytes */10'),
	'bytes=-0' => array(416, null, 'bytes */10'),
	'bytes=1-2,4-5' => array(200, 'abcdefghij', null),
	'bytes=5-2' => array(200, 'abcdefghij', null),
	'items=1-2' => array(200, 'abcdefghij', null),
	'bytes=99999999999999999999999-' => array(200, 'abcdefghij', null),
);
foreach ($ranges as $r => $want) {
	list($s, $h, $b) = uw_get($port, '/ten.txt', array('Range' => $r));
	check("Range: $r", array($s, $want[1] === null ? null : $b, $h['content-range'] ?? null), $want);
}
list($s, $h) = uw_get($port, '/ten.txt');
list($s, $h, $b) = uw_get($port, '/ten.txt', array('Range' => 'bytes=0-1', 'If-Range' => $h['etag']));
check('If-Range with the ETag: the range', array($s, $b), array(206, 'ab'));
list($s, $h, $b) = uw_get($port, '/ten.txt', array('Range' => 'bytes=0-1', 'If-Range' => '"stale"'));
check('If-Range with another ETag: the whole file', array($s, $b), array(200, 'abcdefghij'));

// ── Only what is under the root, never hidden, only regular files ──────
$refused = array(
	'/.env' => 404, '/.git/config' => 404, '/sub/../.env' => 400, '/%2e%2e/outside.txt' => 400, '/%2E%2E/outside.txt' => 400,
	'/../outside.txt' => 400, '/sub/../ten.txt' => 400, '/%2fetc/passwd' => 400, '/ten.txt%00.html' => 400,
	'/%zz' => 400, '/%4' => 400, '/.%2e/outside.txt' => 400, '/%2e/ten.txt' => 200,
	'/link-out' => 404, '/dirlink-out/outside.txt' => 404, '/sub%2f..%2foutside.txt' => 400,
);
foreach ($refused as $path => $want) {
	list($s) = uw_get($port, $path);
	check("GET $path: $want", $s, $want);
}
list($s, $h, $b) = uw_get($port, '/rel-link-in');
check('a relative link that stays inside the root is followed', array($s, $b), array(200, 'abcdefghij'));
list($s) = uw_get($port, '/link-in');
check('an absolute link is not, even to a file inside (it is resolved from /)', $s, 404);
if (function_exists('posix_mkfifo')) {
	$t = microtime(true);
	list($s) = uw_get($port, '/fifo');
	check('a FIFO: 403, at once (the server does not wait on it)', array($s, microtime(true) - $t < 2), array(403, true));
	list($s) = uw_get($port, '/ten.txt');
	check('...and it goes on serving', $s, 200);
}
$raw = uw_raw($port, "GET http://example.org/ten.txt HTTP/1.1\r\nHost: example.org\r\nConnection: close\r\n\r\n");
check('an absolute-form target: its path', uw_split($raw)[2], 'abcdefghij');

// ── Methods and bodies ──────────────────────────────────────────────────
list($s, $h) = uw_get($port, '/', array(), 'POST');
check('POST: 405 with Allow', array($s, $h['allow'] ?? null), array(405, 'GET, HEAD'));
list($s) = uw_get($port, '/', array(), 'BREW');
check('an unknown method: 501', $s, 501);
$raw = uw_raw($port, "GET /ten.txt HTTP/1.1\r\nHost: l\r\nContent-Length: 5\r\n\r\nhelloGET / HTTP/1.1\r\nHost: l\r\nConnection: close\r\n\r\n");
check('a GET with a body: the body is skipped, the next request answered', substr_count($raw, 'HTTP/1.1 200'), 2);
$raw = uw_raw($port, "GET / HTTP/1.0\r\n\r\n");
check('HTTP/1.0 without keep-alive: Connection: close, and it closes', (uw_split($raw)[1]['connection'] ?? null), 'close');
$raw = uw_raw($port, "GET / HTTP/1.0\r\nConnection: keep-alive\r\n\r\nGET /ten.txt HTTP/1.0\r\n\r\n");
check('HTTP/1.0 with keep-alive: both answered on one connection', substr_count($raw, 'HTTP/1.1 200'), 2);
uw_stop($srv);

// ── Options of the content ──────────────────────────────────────────────
$port = uw_free_port();
$srv = uw_start($bin, array_merge(uw_root_args(), array("--root=$www", "--port=$port", '--directory-listing', '--gzip-static',
	'--index=home.htm,index.html', '--header=X-Test: one', '-H', 'Access-Control-Allow-Origin: *', '--cache-control=max-age=60',
	"--mime-types=$d/mime.types", '--no-etag', '--server-name=Example', '--charset=', '--error-log=' . "$d/error.log")), $port);
check('a server with the content options starts', $srv !== null);
list($s, $h, $b) = uw_get($port, '/nolist/');
check('--directory-listing: the listing', $s, 200);
check('...names HTML-escaped', strpos($b, '&lt;b&gt;&amp;&quot;x&quot;.txt') !== false);
check('...links percent-encoded', strpos($b, 'href="./%3Cb%3E&%22x%22.txt"') !== false);
check('...no raw markup of a name', strpos($b, '<b>&') === false);
list($s, $h, $b) = uw_get($port, '/');
check('--directory-listing leaves hidden names out', strpos($b, '.env') === false && strpos($b, '.git') === false);
list($s, $h, $b) = uw_get($port, '/sub/');
check('--index: the first name that exists', $b, "home\n");
list($s, $h) = uw_get($port, '/ten.txt');
check('--header: added', array($h['x-test'] ?? null, $h['access-control-allow-origin'] ?? null), array('one', '*'));
check('--cache-control: sent', $h['cache-control'] ?? null, 'max-age=60');
check('--no-etag: none', isset($h['etag']), false);
check('--server-name', $h['server'] ?? null, 'Example');
check('--charset= (empty): no charset', $h['content-type'] ?? null, 'text/plain');
list($s, $h) = uw_get($port, '/data.foo');
check('--mime-types: the type from the file', $h['content-type'] ?? null, 'application/x-foo');
list($s, $h, $b) = uw_get($port, '/style.css', array('Accept-Encoding' => 'br, gzip'));
check('--gzip-static: the .gz file, as gzip', array($h['content-encoding'] ?? null, gzdecode($b) === file_get_contents("$www/style.css")), array('gzip', true));
check('...with the type of the file asked for', $h['content-type'] ?? null, 'text/css');
check('...and Vary', $h['vary'] ?? null, 'Accept-Encoding');
list($s, $h, $b) = uw_get($port, '/style.css');
check('...not to a client that does not accept it', array(isset($h['content-encoding']), $b === file_get_contents("$www/style.css")), array(false, true));
list($s, $h, $b) = uw_get($port, '/style.css', array('Accept-Encoding' => 'gzip;q=0'));
check('...nor with gzip;q=0', isset($h['content-encoding']), false);
list($s, $h, $b) = uw_get($port, '/style.css', array('Accept-Encoding' => 'gzip; q=0.5'));
check('...but with gzip;q=0.5', $h['content-encoding'] ?? null, 'gzip');
uw_stop($srv);

$port = uw_free_port();
$srv = uw_start($bin, array_merge(uw_root_args(), array("--root=$www", "--port=$port", '--symlinks=never', '--hidden-files', '--no-server-header')), $port);
list($s) = uw_get($port, '/rel-link-in');
check('--symlinks=never: no link is followed, even inside', $s, 404);
list($s, $h, $b) = uw_get($port, '/.env');
check('--hidden-files: a dot file is served', array($s, $b), array(200, "SECRET=1\n"));
check('--no-server-header: no Server header', isset($h['server']), false);
list($s) = uw_get($port, '/%2e%2e/outside.txt');
check('...and still never above the root', $s, 400);
uw_stop($srv);

// ── The built-in answers without --root, as before ───────────────────────
$port = uw_free_port();
$srv = uw_start($bin, array_merge(uw_root_args(), array("--port=$port")), $port);
list($s, $h, $b) = uw_get($port, '/');
check('no --root: "Hello from U!" at /', array($s, $b), array(200, 'Hello from U!'));
list($s, $h, $b) = uw_get($port, '/json');
check('...JSON at /json', array($s, json_decode($b, true)['status'] ?? null), array(200, 'ok'));
list($s, $h, $b) = uw_get($port, '/health');
$j = json_decode($b, true);
check('...counters at /health', array($s, isset($j['requests'], $j['uptime'], $j['cache_hits'], $j['merkle_trees'])), array(200, true));
list($s) = uw_get($port, '/other');
check('...404 elsewhere', $s, 404);
$r = shell_exec('php ' . escapeshellarg(__DIR__ . '/pipelining.php') . ' ' . (int) $port . ' 2>&1');
check('tests/pipelining.php passes against it', strpos((string) $r, '0 failed') !== false);
uw_stop($srv);

uw_finish();
