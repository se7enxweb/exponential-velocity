<?php

/**
 * While the pause file (Q.web.cache.pauseFile) exists, the response cache
 * neither answers nor stores, from the very request after it appeared.
 *
 * An application puts the file there while it must answer every request
 * itself: a maintenance window, an installation rebuilding the database. The
 * pause used to be looked at no more than twice a second, so for up to half a
 * second after it began the cache still answered with the site; and put()
 * never looked at all, so a page a worker rendered during the pause -- for a
 * visitor the application lets through, from the half-built database -- was
 * stored and handed to everybody once the pause ended.
 *
 * A real server, a page that says public, max-age=300 and counts its renders:
 *
 *   1. no pause: the second request is a hit
 *   2. pause file created: the very next request reaches the page (no hit),
 *      and so does every one after it, at once and without waiting
 *   3. pause file removed (the cache emptied first): the first request is a
 *      miss -- nothing rendered during the pause was stored -- and the next a hit
 *
 *   php tests/unit-cache-paused-neither-answers-nor-stores.php
 */

require __DIR__ . '/fixtures/race-harness.php';
rh_require_pool();
list($base, $root) = rh_setup('cache-paused');

$counter = $base . DS . 'renders';
file_put_contents($root . DS . 'page.php', '<?php
$n = (int) @file_get_contents(' . var_export($counter, true) . ') + 1;
file_put_contents(' . var_export($counter, true) . ', (string) $n);
header("Cache-Control: public, max-age=300");
header("Content-Type: text/html");
echo "<!doctype html><title>page</title><p>render ", $n, "</p>", str_repeat("lorem ipsum ", 50);
');

$dir = $base . DS . 'cache';
$pause = $base . DS . 'maintenance.json';
@mkdir($dir, 0755, true);
$port = rh_start('paused', array('Q' => array('web' => array('cache' => array(
	'enabled' => true, 'dir' => $dir, 'defaultTtl' => 300, 'pauseFile' => $pause,
	'apcu' => array('enabled' => false))))), 2);

function hit($r) { return stripos($r['headers']['x-cache'] ?? '', 'HIT') === 0; }
function render_of($r) { return preg_match('/render (\d+)/', $r['body'] ?? '', $m) ? (int) $m[1] : 0; }

function files_in($d)
{
	if (!is_dir($d)) return 0;
	$n = 0;
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS)) as $f) {
		if ($f->isFile()) ++$n;
	}
	return $n;
}

// 1. No pause: stored, then a hit.
$a = rh_get($port, '/page.php');
usleep(200000);
$b = rh_get($port, '/page.php');
check('1. no pause: the first request is answered', $a['status'], 200);
check('1. no pause: the second request is a hit', hit($b), true);

// 2. Paused: the next request at once, and every one after it, reaches the page.
file_put_contents($pause, '{"reason":"setup"}');
$hits = 0;
for ($i = 0; $i < 6; ++$i) {
	$r = rh_get($port, '/page.php');
	if (hit($r)) ++$hits;
}
check('2. paused: no request is answered from the cache, not even the first one', $hits, 0);

// 3. Emptied as an application does when the pause starts, then resumed: what
// was rendered during the pause was not kept.
rh_rmtree($dir);
@mkdir($dir, 0755, true);
$during = rh_get($port, '/page.php');
usleep(300000);
check('3. paused: nothing stored', files_in($dir), 0);
unlink($pause);
$first = rh_get($port, '/page.php');
usleep(200000);
$second = rh_get($port, '/page.php');
check('3. resumed: the first request is a miss, not the page rendered during the pause', !hit($first) && render_of($first) > render_of($during), true);
check('3. resumed: the cache stores and answers again', hit($second), true);

rh_finish();
