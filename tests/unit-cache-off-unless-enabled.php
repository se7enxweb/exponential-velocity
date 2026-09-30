<?php

/**
 * The response cache is off unless a setting turns it on.
 *
 * The server's built-in defaults said Q.web.cache.enabled = true, although
 * docs/cache.md, the README and Q_WebServer_Cache::init() all say false. An
 * installation that configured nothing therefore cached every public page,
 * into <app>/files/cache/reverse; and one that enabled the cache through its
 * cache mod and later disabled the mod (mod:disable, qbixctl dismod) kept
 * caching after a restart, because the mod was the only thing that said
 * anything about the cache. Each case starts a real server on a page that
 * sends Cache-Control: public, max-age=300 and asks for it several times:
 *
 *   1. no cache setting anywhere             -> no hit, nothing stored
 *   2. the cache mod enabled (enabled: true) -> hits, stored in its dir
 *   3. the same mod disabled, restarted      -> no hit, nothing stored
 *   4. Q.web.cache.enabled: true, no mod     -> hits
 *   5. the mod enabled, the site file false  -> no hit (the site file wins)
 *
 *   php tests/unit-cache-off-unless-enabled.php
 */

require __DIR__ . '/fixtures/race-harness.php';
require_once __DIR__ . '/../src/Q/WebServer/Ctl.php';
rh_require_pool();
list($base, $root) = rh_setup('cache-off');

file_put_contents($root . DS . 'page.php', '<?php
header("Cache-Control: public, max-age=300");
header("Content-Type: text/html");
echo "<!doctype html><title>page</title><p>", str_repeat("lorem ipsum ", 200), "</p>";
');

// The engine's fallback directory when nothing names one: APP_DIR is the
// document root's parent.
$fallback = dirname($root) . DS . 'files' . DS . 'cache' . DS . 'reverse';
$conf = "$base/conf";
$dir = "$base/cache";
@mkdir("$conf/mods-available", 0755, true);
@mkdir("$conf/mods-enabled", 0755, true);
@mkdir($dir, 0755, true);
file_put_contents("$conf/mods-available/cache.conf", json_encode(array('Q' => array('web' => array(
	'cache' => array('enabled' => true, 'dir' => $dir, 'defaultTtl' => 30))))));

$GLOBALS['rh_extra_args'] = array('--conf-dir=' . $conf, '--distribution=none');

function files_in($d)
{
	if (!is_dir($d)) return 0;
	$n = 0;
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS)) as $f) {
		if ($f->isFile()) ++$n;
	}
	return $n;
}

function empty_dir($d)
{
	if (!is_dir($d)) return;
	foreach (scandir($d) as $e) {
		if ($e === '.' or $e === '..') continue;
		is_dir("$d/$e") && !is_link("$d/$e") ? rh_rmtree("$d/$e") : @unlink("$d/$e");
	}
}

/** Start, ask five times, stop; how many answers were hits and what was stored. */
function run_case($name, array $cache)
{
	global $dir, $fallback;
	empty_dir($dir);
	empty_dir($fallback);
	// The harness turns the cache off when a config says nothing about it;
	// an empty Q.web.cache states nothing and so leaves the defaults alone.
	$port = rh_start($name, array('Q' => array('web' => array('cache' => $cache))), 2);
	$hits = 0; $ok = 0;
	for ($i = 0; $i < 5; ++$i) {
		$r = rh_get($port, '/page.php');
		if ($r['status'] === 200) ++$ok;
		if (stripos($r['headers']['x-cache'] ?? '', 'HIT') === 0) ++$hits;
	}
	usleep(300000);
	$stored = files_in($dir);
	$fell = files_in($fallback);
	rh_stop($GLOBALS['rh']['servers'][$name]);
	return array('ok' => $ok, 'hits' => $hits, 'stored' => $stored, 'fallback' => $fell);
}

// 1. Nothing configured.
$r = run_case('none', array());
check('1. no cache setting: every request answered', $r['ok'], 5);
check('1. no cache setting: no answer is a hit', $r['hits'], 0);
check('1. no cache setting: nothing in files/cache/reverse', $r['fallback'], 0);

// 2. The cache mod enabled with the engine's own tool.
$t = Q_WebServer_Ctl::toggle($conf, 'mod', 'cache', true);
check('2. mod:enable cache', $t[0], true);
$r = run_case('mod-enabled', array());
check('2. cache mod enabled: later requests are hits', $r['hits'] >= 3, true);
check('2. cache mod enabled: stored in its dir', $r['stored'] >= 1, true);
check('2. cache mod enabled: nothing in files/cache/reverse', $r['fallback'], 0);

// 3. Disabled with the engine's own tool, then restarted.
$t = Q_WebServer_Ctl::toggle($conf, 'mod', 'cache', false);
check('3. mod:disable cache', $t[0], true);
$r = run_case('mod-disabled', array());
check('3. cache mod disabled: no answer is a hit', $r['hits'], 0);
check('3. cache mod disabled: nothing stored in its dir', $r['stored'], 0);
check('3. cache mod disabled: nothing in files/cache/reverse', $r['fallback'], 0);

// 4. The setting itself, no mod.
$r = run_case('site-true', array('enabled' => true, 'dir' => $dir));
check('4. Q.web.cache.enabled true: later requests are hits', $r['hits'] >= 3, true);
check('4. Q.web.cache.enabled true: stored in its dir', $r['stored'] >= 1, true);

// 5. The mod says true, the site file says false: the site file wins.
Q_WebServer_Ctl::toggle($conf, 'mod', 'cache', true);
$r = run_case('site-false', array('enabled' => false));
check('5. mod enabled, Q.web.cache.enabled false: no answer is a hit', $r['hits'], 0);
check('5. mod enabled, Q.web.cache.enabled false: nothing stored', $r['stored'] + $r['fallback'], 0);

rh_finish();
