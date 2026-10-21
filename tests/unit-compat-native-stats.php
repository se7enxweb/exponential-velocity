<?php

/**
 * Q_WebServer_CompatFileWrapper::nativeStats() answers the size and time of
 * many files with one swap of the file wrapper for the whole list.
 *
 * An application that compares thousands of files at once (Exponential's
 * help index looks at ~9 000 pages every few seconds) paid about 30
 * microseconds a file under the wrapper -- each filemtime() and filesize() is
 * answered through libcurl, since a swap of the wrapper per call leaves a
 * resource behind for the life of the worker. Asserted:
 *   - the answers match PHP's own stat() for files, directories, a link, a
 *     missing path, a dangling link and names full of glob characters, keyed
 *     as given and in the order given, a file:// path included;
 *   - the wrapper is in place again afterwards, also when a stat in the list
 *     makes an error handler throw;
 *   - one call allocates one wrapper registration, whatever the number of
 *     paths;
 *   - nothing is remembered: a file changed after the call is seen by the
 *     next one;
 *   - without the wrapper installed it is PHP's own stat;
 *   - for many files it is several times cheaper than the shims.
 *
 *   php tests/unit-compat-native-stats.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q/WebServer/Compat.php';

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

$base = sys_get_temp_dir() . DS . 'qbix-nativestats-' . getmypid();
@mkdir($base . DS . 'dir', 0700, true);
@mkdir($base . DS . 'we[ird]*?dir', 0700, true);
file_put_contents($base . DS . 'dir' . DS . 'a.md', 'aaa');
file_put_contents($base . DS . 'we[ird]*?dir' . DS . 'b{c}.md', 'bbbbb');
touch($base . DS . 'dir' . DS . 'a.md', 1700000000);
@symlink($base . DS . 'dir' . DS . 'a.md', $base . DS . 'link.md');
@symlink($base . DS . 'nowhere', $base . DS . 'dangling');
$paths = array(
	'a' => $base . DS . 'dir' . DS . 'a.md', 'weird' => $base . DS . 'we[ird]*?dir' . DS . 'b{c}.md',
	'dir' => $base . DS . 'dir', 'link' => $base . DS . 'link.md', 'missing' => $base . DS . 'missing.md',
	'dangling' => $base . DS . 'dangling', 'scheme' => 'file://' . $base . DS . 'dir' . DS . 'a.md',
);
$native = function (array $paths) {
	$out = array();
	foreach ($paths as $k => $p) {
		clearstatcache();
		$st = @stat(Q_WebServer_CompatFileWrapper::bare($p));
		$out[$k] = $st ? array('size' => (int) $st['size'], 'mtime' => (int) $st['mtime']) : false;
	}
	return $out;
};
$want = $native($paths);
check('without the wrapper: PHP\'s own stat', Q_WebServer_CompatFileWrapper::nativeStats($paths), $want);
check('the link is followed', $want['link'], $want['a']);
check('a missing path and a dangling link are false', array($want['missing'], $want['dangling']), array(false, false));

$isOurs = function () use ($base) {
	$h = @fopen($base . DS . 'dir' . DS . 'a.md', 'r');
	$type = $h ? stream_get_meta_data($h)['wrapper_type'] : null;
	if ($h) fclose($h);
	return $type === 'user-space';
};
stream_wrapper_unregister('file');
stream_wrapper_register('file', 'Q_WebServer_CompatFileWrapper');
Q_WebServer_CompatFileWrapper::$installed = true;
check('the wrapper answers opens before', $isOurs(), true);
$got = Q_WebServer_CompatFileWrapper::nativeStats($paths);
check('through the wrapper: the same answers', $got, $want);
check('keyed and ordered as given', array_keys($got), array_keys($paths));
check('the wrapper answers opens again after', $isOurs(), true);

// an error handler that throws (Exponential installs one) still gets the wrapper back
set_error_handler(function () { throw new ErrorException('stat warning'); });
try {
	Q_WebServer_CompatFileWrapper::nativeStats(array("\0bad"));
} catch (\Throwable $e) {
}
restore_error_handler();
check('the wrapper is back after a stat that threw', $isOurs(), true);

// one registration per call, not one per path
$factories = function () {
	$n = 0;
	foreach (get_resources() as $r) if (get_resource_type($r) === 'stream factory') ++$n;
	return $n;
};
$many = array();
for ($i = 0; $i < 500; ++$i) {
	file_put_contents($base . DS . 'dir' . DS . "f$i.md", str_repeat('x', $i));
	$many[] = $base . DS . 'dir' . DS . "f$i.md";
}
$before = $factories();
for ($i = 0; $i < 3; ++$i) Q_WebServer_CompatFileWrapper::nativeStats($many);
check('three calls over 500 files: three registrations', $factories() - $before, 3);

// nothing remembered
$f = $base . DS . 'dir' . DS . 'a.md';
Q_WebServer_CompatFileWrapper::nativeStats(array($f));
file_put_contents($f, 'longer now');
touch($f, 1700000100);
check('a change after a call is seen by the next', Q_WebServer_CompatFileWrapper::nativeStats(array($f)),
	array(array('size' => 10, 'mtime' => 1700000100)));

// cheaper than the shims for many files
Q_WebServer_CompatFileWrapper::forgetStats(true);
$t = microtime(true);
foreach ($many as $p) { Q_WebServer_Compat::_filesize($p); Q_WebServer_Compat::_filemtime($p); }
$shims = microtime(true) - $t;
$t = microtime(true);
$batch = Q_WebServer_CompatFileWrapper::nativeStats($many);
$fast = microtime(true) - $t;
check('the batch agrees with the shims', $batch[499], array('size' => 499, 'mtime' => Q_WebServer_Compat::_filemtime($many[499])));
printf("  note  500 files: shims %.1f ms, nativeStats %.1f ms\n", $shims * 1000, $fast * 1000);
check('the batch is cheaper than the shims', $fast < $shims, true);

stream_wrapper_restore('file');
Q_WebServer_CompatFileWrapper::$installed = false;
foreach (glob($base . DS . 'dir' . DS . '*') ?: array() as $p) @unlink($p);
foreach (array($base . DS . 'we[ird]*?dir' . DS . 'b{c}.md', $base . DS . 'link.md', $base . DS . 'dangling') as $p) @unlink($p);
@rmdir($base . DS . 'dir');
@rmdir($base . DS . 'we[ird]*?dir');
@rmdir($base);

printf("%s: %d passed, %d failed\n", $fail ? 'FAIL' : 'PASS', $pass, $fail);
exit($fail ? 1 : 0);
