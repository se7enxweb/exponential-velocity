<?php

/**
 * A request's own Cache-Control: no-cache, honoured when asked for.
 *
 * Every request the cache answers never reaches the application, so what the
 * application does per request -- logging, intrusion detection -- does not see
 * it. With Q.web.cache.honorRequestNoCache a client that asks for an answer
 * from the origin gets one, and the answer it gets is stored again. Off, the
 * old behaviour holds exactly: only the refresh header reads past the cache.
 *
 *   php tests/unit-cache-request-no-cache.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q/WebServer/Cache.php';

$C = 'Q_WebServer_Cache';
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

$root = sys_get_temp_dir() . DS . 'qcache-nocache-' . getmypid();
@mkdir($root, 0755, true);
register_shutdown_function(function () use ($root) {
	foreach (new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST) as $f) {
		$f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
	}
	@rmdir($root);
});

$C::$enabled = true;
$C::$dir = $root;
$C::$apcuEnabled = false;
$C::$staleWhileRevalidate = 0;
$C::$defaultTtl = 30;

function req($path, $headers = array())
{
	return array('method' => 'GET', 'path' => $path, 'query' => '',
		'headers' => $headers + array('host' => 'example.test'));
}

function resp($body)
{
	// What Exponential sends on every page: stored only through defaultTtl.
	return array('status' => 200,
		'headers' => array('Cache-Control' => 'no-cache, must-revalidate'),
		'body' => $body);
}

function body($r) { return is_array($r) ? ($r['body'] ?? null) : null; }

// ── The test itself ──────────────────────────────────────────────────────
check('Cache-Control: no-cache', $C::isNoCacheRequest(array('cache-control' => 'no-cache')), true);
check('among other directives, any case', $C::isNoCacheRequest(array('cache-control' => 'max-stale=5, No-Cache')), true);
check('Pragma: no-cache without Cache-Control', $C::isNoCacheRequest(array('pragma' => 'no-cache')), true);
check('Pragma is ignored next to a Cache-Control', $C::isNoCacheRequest(array('cache-control' => 'max-age=0', 'pragma' => 'no-cache')), false);
check('max-age=0 (an ordinary reload) is not no-cache', $C::isNoCacheRequest(array('cache-control' => 'max-age=0')), false);
check('no-store alone is not no-cache', $C::isNoCacheRequest(array('cache-control' => 'no-store')), false);
check('no-cache-like token is not no-cache', $C::isNoCacheRequest(array('cache-control' => 'no-cachex')), false);
check('no headers', $C::isNoCacheRequest(array()), false);

// ── Off by default: the old behaviour exactly ────────────────────────────
$C::$honorRequestNoCache = false;
$C::put(req('/page'), resp('one'));
check('a plain GET is answered from the cache', body($C::get(req('/page'))), 'one');
check('off: no-cache is answered from the cache too',
	body($C::get(req('/page', array('cache-control' => 'no-cache')))), 'one');
check('off: so is Pragma: no-cache',
	body($C::get(req('/page', array('pragma' => 'no-cache')))), 'one');

// ── On ───────────────────────────────────────────────────────────────────
$C::$honorRequestNoCache = true;
check('on: no-cache reaches the application',
	$C::get(req('/page', array('cache-control' => 'no-cache'))), null);
check('on: Pragma: no-cache reaches the application',
	$C::get(req('/page', array('pragma' => 'no-cache'))), null);
check('on: a plain GET is still a hit', body($C::get(req('/page'))), 'one');
check('on: an ordinary reload (max-age=0) is still a hit',
	body($C::get(req('/page', array('cache-control' => 'max-age=0')))), 'one');
$hit = $C::get(req('/page'));
check('on: a hit says so', $hit['headers']['X-Cache'] ?? null, 'HIT');

// The answer the application gave is stored again, as a refresh would be.
$C::put(req('/page', array('cache-control' => 'no-cache')), resp('two'));
check('on: the revalidated answer replaces the stored copy', body($C::get(req('/page'))), 'two');

// The application's own cache is not asked either: it is not the origin.
if (!class_exists('Q_WebServer_AppCache', false)) {
	class Q_WebServer_AppCache
	{
		static $asked = 0;
		static function get($parsed) { self::$asked++; return array('status' => 200, 'headers' => array(), 'body' => 'app'); }
	}
}
Q_WebServer_AppCache::$asked = 0;
$C::get(req('/elsewhere', array('cache-control' => 'no-cache')));
check('on: the application cache is not asked for a no-cache request', Q_WebServer_AppCache::$asked, 0);
$C::get(req('/elsewhere'));
check('on: it is asked for a plain one', Q_WebServer_AppCache::$asked, 1);

$C::$honorRequestNoCache = false;

printf("%s  unit-cache-request-no-cache: %d passed, %d failed\n",
	$fail ? 'FAIL' : 'PASS', $pass, $fail);
exit($fail ? 1 : 0);
