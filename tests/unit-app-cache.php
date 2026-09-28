<?php
/**
 * Q.web.appCache: the server asks the application's own page cache before a
 * worker, with the visitor's cookies, and serves its answer as it is.
 *
 *   - not configured, a missing file or a class without fromDir(): not asked;
 *   - a hit comes back as a response array, HEAD without the body;
 *   - what the class is given: scheme, host, uri with query, cookies (from
 *     the header when the request has not parsed them), accept-encoding,
 *     if-none-match, every request header (a load balancer's
 *     X-Forwarded-Proto and X-Forwarded-Host) and the listener's port;
 *   - writes are never asked about;
 *   - a new object per request, so nothing it read outlives the request;
 *   - a class that throws is not asked for ten seconds.
 *
 *   php tests/unit-app-cache.php
 */

class Q_Config
{
	static $data = array();
	static function get()
	{
		$args = func_get_args();
		$default = array_pop($args);
		$node = self::$data;
		foreach ($args as $k) {
			if (!is_array($node) or !array_key_exists($k, $node)) return $default;
			$node = $node[$k];
		}
		return $node;
	}
}
class Q_WebServer
{
	static $port = 8787;
}
class Q
{
	static function ifset($a, $k, $d = null) { return isset($a[$k]) ? $a[$k] : $d; }
}

require __DIR__ . '/../src/Q/WebServer/AppCache.php';

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

class TestAppCache
{
	static $made = 0;
	static $last = null;
	static $throw = false;
	static function fromDir($dir)
	{
		self::$made++;
		return $dir === '/off' ? null : new self();
	}
	function serve(array $request)
	{
		if (self::$throw) throw new RuntimeException('broken');
		self::$last = $request;
		if ($request['uri'] === '/miss') return null;
		return array(200, array('Content-Type' => 'text/html', 'X-Exp-Cache' => 'HIT'), 'page');
	}
}

$A = 'Q_WebServer_AppCache';
$get = array('method' => 'GET', 'path' => '/a', 'query' => 'x=1', 'headers' => array(
	'host' => 'example.org', 'cookie' => 'eZSESSID=abc; other=1', 'accept-encoding' => 'gzip',
	'if-none-match' => '"e"', 'x-forwarded-proto' => 'https', 'x-forwarded-host' => 'www.example.org'),
	'_https' => true);

$A::init();
check('not configured: not asked', $A::get($get), null);

Q_Config::$data = array('Q' => array('web' => array('appCache' => array(
	'file' => '/nonexistent/file.php', 'class' => 'NoSuchClass', 'dir' => '/d'))));
$A::init();
check('missing file: not asked', $A::$class, null);

Q_Config::$data['Q']['web']['appCache'] = array('class' => 'TestAppCache', 'dir' => '/d');
$A::init();
check('configured class loaded', $A::$class, 'TestAppCache');

$r = $A::get($get);
check('hit: status', $r['status'] ?? null, 200);
check('hit: body', $r['body'] ?? null, 'page');
check('hit: headers kept', $r['headers']['X-Exp-Cache'] ?? null, 'HIT');
check('given: scheme', TestAppCache::$last['scheme'], 'https');
check('given: host', TestAppCache::$last['host'], 'example.org');
check('given: uri with query', TestAppCache::$last['uri'], '/a?x=1');
check('given: cookies parsed from the header', TestAppCache::$last['cookies'], array('eZSESSID' => 'abc', 'other' => '1'));
check('given: accept-encoding', TestAppCache::$last['acceptEncoding'], 'gzip');
check('given: if-none-match', TestAppCache::$last['ifNoneMatch'], '"e"');
check('given: the forwarded scheme', TestAppCache::$last['headers']['x-forwarded-proto'] ?? null, 'https');
check('given: the forwarded host', TestAppCache::$last['headers']['x-forwarded-host'] ?? null, 'www.example.org');
check('given: the listener port', TestAppCache::$last['port'] ?? null, 8787);

$made = TestAppCache::$made;
$A::get($get);
check('a new object per request', TestAppCache::$made, $made + 1);

$head = $get;
$head['method'] = 'HEAD';
$r = $A::get($head);
check('HEAD: no body', $r['body'] ?? null, '');

$post = $get;
$post['method'] = 'POST';
$made = TestAppCache::$made;
check('POST: not asked', $A::get($post), null);
check('POST: no object made', TestAppCache::$made, $made);

$miss = $get;
$miss['uri'] = '/miss';
check('miss: null', $A::get($miss), null);

Q_Config::$data['Q']['web']['appCache']['dir'] = '/off';
$A::init();
check('switched off (fromDir null): not asked', $A::get($get), null);

Q_Config::$data['Q']['web']['appCache']['dir'] = '/d';
$A::init();
TestAppCache::$throw = true;
$old = ini_set('error_log', '/dev/null');
check('throws: null', $A::get($get), null);
TestAppCache::$throw = false;
check('after a throw: not asked for a while', $A::get($get), null);
ini_set('error_log', $old);

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
