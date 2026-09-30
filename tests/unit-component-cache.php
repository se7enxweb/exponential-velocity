<?php
/**
 * The component cache works, in the worker pool, as docs/headers.md says.
 *
 * It did not work at all:
 *   - Q.web.cache.components.enabled was read by nothing (init() was never
 *     called), so the layer could not be switched on;
 *   - switched on, every request would have died on a getPage() the class
 *     has never had;
 *   - under the pool -- the default -- the X-Q-Cache-* headers were never
 *     looked at: they went out to the browser and no page was registered;
 *   - its page key ended in '?' for a URL with no query string, which no
 *     cache entry is filed under, so an invalidation purged nothing.
 *
 * Asserted against a real server with a two-worker pool:
 *   - a page that registers its components is cached and served from the
 *     cache, and the X-Q-Cache-* headers never reach the client;
 *   - a response carrying X-Q-Cache-Invalidate for a key the page depends on
 *     purges it, and the next request renders it again;
 *   - a page depending on another key stays cached.
 *
 *   php tests/unit-component-cache.php
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

if (!function_exists('pcntl_fork')) { printf("  skip  needs pcntl for the worker pool\n"); exit(0); }

$server = __DIR__ . '/../sbin/qbixserver.php';
$base = sys_get_temp_dir() . DS . 'qbix-components-' . getmypid();
$root = $base . DS . 'web';
@mkdir($root, 0700, true);

// A page with a feed and a sidebar, each depending on its own data key.
$page = '<?php
$name = basename(__FILE__, ".php");
$f = __DIR__ . "/$name.count"; $n = (int) @file_get_contents($f) + 1; file_put_contents($f, $n);
$dep = $name === "feed" ? "community/1/feed" : "community/1/about";
Q_Response::header("X-Q-Cache-Tree: " . json_encode(array("l" => array("main" => md5("$name $n")))));
Q_Response::header("X-Q-Cache-Deps: " . json_encode(array("main" => array($dep))));
Q_Response::header("Cache-Control: public, max-age=300");
echo strtoupper($name), " ", $n;';
file_put_contents($root . DS . 'feed.php', $page);
file_put_contents($root . DS . 'about.php', $page);
file_put_contents($root . DS . 'post.php', '<?php
Q_Response::header("X-Q-Cache-Invalidate: " . json_encode(array("community/1/feed")));
Q_Response::header("Cache-Control: no-store");
echo "POSTED";');

function freePort()
{
	for ($i = 0; $i < 40; ++$i) {
		$p = 19100 + random_int(0, 1400);
		$probe = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
		if ($probe) { fclose($probe); return $p; }
	}
	return 0;
}

function get($port, $path)
{
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5);
	if (!$s) return array(0, array(), '');
	stream_set_timeout($s, 15);
	fwrite($s, "GET $path HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
	$raw = '';
	while (!feof($s)) {
		$c = fread($s, 8192);
		if ($c === false or $c === '') { if (stream_get_meta_data($s)['timed_out']) break; continue; }
		$raw .= $c;
	}
	fclose($s);
	$split = strpos($raw, "\r\n\r\n");
	if ($split === false) return array(0, array(), $raw);
	$head = substr($raw, 0, $split);
	$status = preg_match('#^HTTP/\S+ (\d+)#', $head, $m) ? (int) $m[1] : 0;
	$h = array();
	foreach (explode("\r\n", $head) as $line) {
		if (strpos($line, ':') !== false) { list($k, $v) = explode(':', $line, 2); $h[strtolower(trim($k))] = trim($v); }
	}
	$b = substr($raw, $split + 4);
	if (stripos($h['transfer-encoding'] ?? '', 'chunked') !== false) {
		$out = '';
		while ($b !== '') {
			$nl = strpos($b, "\r\n"); if ($nl === false) break;
			$len = hexdec(substr($b, 0, $nl)); if ($len === 0) break;
			$out .= substr($b, $nl + 2, $len); $b = substr($b, $nl + 2 + $len + 2);
		}
		$b = $out;
	}
	return array($status, $h, trim($b));
}

$port = freePort();
file_put_contents($base . DS . 'config.json', json_encode(array('Q' => array('web' => array('cache' => array(
	'enabled' => true, 'dir' => $base . DS . 'cache',
	'components' => array('enabled' => true),
))))));
$proc = proc_open(array(PHP_BINARY, $server, '--config=' . $base . DS . 'config.json',
	'--root=' . $root, '--port=' . $port, '--workers=2'), array(
	0 => array('file', '/dev/null', 'r'),
	1 => array('file', $base . DS . 'log', 'w'),
	2 => array('file', $base . DS . 'log', 'a'),
), $pipes, $base);
$up = false;
for ($i = 0; $i < 60; ++$i) {
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 1);
	if ($s) { fclose($s); $up = true; break; }
	usleep(250000);
}
check('the server started with the component cache on', $up, true);

if ($up) {
	list($st, $h, $b) = get($port, '/feed.php');
	check('a page registering its components is answered', array($st, $b), array(200, 'FEED 1'));
	$leaked = array_values(array_filter(array_keys($h), function ($k) { return strpos($k, 'x-q-cache') === 0; }));
	check('...and the X-Q-Cache-* headers never reach the client', $leaked, array());
	list(, , $b) = get($port, '/feed.php');
	check('the page is then served from the cache', $b, 'FEED 1');
	list(, , $b) = get($port, '/about.php');
	get($port, '/about.php');

	list($st, , $b) = get($port, '/post.php');
	check('a response invalidating community/1/feed is answered', array($st, $b), array(200, 'POSTED'));
	list(, , $b) = get($port, '/feed.php');
	check('...the page depending on it is rendered again', $b, 'FEED 2');
	list(, , $b) = get($port, '/about.php');
	check('...while a page depending on another key stays cached', $b, 'ABOUT 1');
	check('no request failed on the way',
		(bool) preg_match('/Fatal|getPage|Uncaught/', (string) @file_get_contents($base . DS . 'log')), false);
}

$st = @proc_get_status($proc);
if ($st and !empty($st['running'])) {
	@proc_terminate($proc, 15);
	for ($i = 0; $i < 40; ++$i) { $n = @proc_get_status($proc); if (!$n or empty($n['running'])) break; usleep(250000); }
	$n = @proc_get_status($proc);
	if ($n and !empty($n['running'])) @proc_terminate($proc, 9);
}
@proc_close($proc);
if ($fail) {
	echo "  server log (tail):\n";
	foreach (array_slice(file($base . DS . 'log') ?: array(), -15) as $l) echo '    ' . $l;
}
foreach (array('feed.php', 'about.php', 'post.php', 'feed.count', 'about.count') as $f) @unlink($root . DS . $f);
if (is_dir($base . DS . 'cache')) {
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base . DS . 'cache', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($it as $e) { $e->isDir() ? @rmdir($e->getPathname()) : @unlink($e->getPathname()); }
	@rmdir($base . DS . 'cache');
}
@unlink($base . DS . 'config.json'); @unlink($base . DS . 'log'); @rmdir($root); @rmdir($base);

if ($fail) { printf("  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
