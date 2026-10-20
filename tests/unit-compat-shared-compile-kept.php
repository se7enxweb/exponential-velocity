<?php

/**
 * A worker that meets a new version of a script another worker has already
 * compiled uses that compile, when the opcode cache checks timestamps on
 * every include.
 *
 * The wrapper used to drop the cache's compile of any path whose mtime had
 * moved since this worker last served it. With the cache revalidating by
 * itself that was redundant, and harmful: the compile it dropped was the one
 * another worker had just stored for the new version, so each worker compiled
 * the file again and left the discarded copy in shared memory as waste. Under
 * hundreds of workers a large generated file (a search index, 17 MB) filled
 * the cache with waste after a few changes, and from then on every include
 * compiled again.
 *
 * Two processes sharing one cache, as a pool's workers do: this one serves v1;
 * a forked "other worker" writes v2 and includes it (one compile, stored);
 * then this one includes v2 -- it must run v2, without a cache miss and
 * without adding waste. In the never-revalidating mode the wrapper still has
 * to drop the old compile itself (tests/unit-compat-include-regenerated.php).
 *
 * It re-runs itself with opcache.enable_cli=1, so the cache is involved.
 *
 *   php tests/unit-compat-shared-compile-kept.php
 */

if (getenv('QBIX_SHARED_COMPILE_CHILD') === false) {
	$cmd = escapeshellarg(PHP_BINARY) . ' -d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.revalidate_freq=0'
		. ' -d opcache.validate_timestamps=1 -d opcache.file_update_protection=0 ' . escapeshellarg(__FILE__);
	$out = array();
	exec('QBIX_SHARED_COMPILE_CHILD=1 ' . $cmd . ' 2>&1', $out, $code);
	echo implode("\n", $out), "\n";
	exit($code);
}

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q/WebServer/Compat.php';
$en = new ReflectionProperty('Q_WebServer_Compat', 'enabled');
$en->setAccessible(true);
$en->setValue(null, true);

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

$opcache = (function_exists('opcache_get_status') and ($s = @opcache_get_status(false))
	and !empty($s['opcache_enabled']));
if (!$opcache) { printf("  skip  the opcode cache is not available\n"); exit(0); }
if (!function_exists('pcntl_fork')) { printf("  skip  needs pcntl to fork a second worker\n"); exit(0); }

$dir = sys_get_temp_dir() . DS . 'qbix-shared-compile-' . getmypid();
@mkdir($dir, 0700, true);
$f = $dir . DS . 'index.php';
// Big enough that a second compile shows in the cache's wasted memory.
$data = array();
for ($i = 0; $i < 20000; ++$i) $data["term$i"] = array('doc' . ($i % 97), $i % 13);
$write = function ($version, $age) use ($f, $data) {
	stream_wrapper_restore('file');
	file_put_contents($f, '<?php return ' . var_export(array('v' => $version) + $data, true) . ';');
	touch($f, time() - $age);
	clearstatcache();
	stream_wrapper_unregister('file');
	stream_wrapper_register('file', 'Q_WebServer_CompatFileWrapper');
};
$boundary = function () { Q_WebServer_CompatFileWrapper::forgetStats(); clearstatcache(); };
$stats = function () {
	$s = opcache_get_status(false);
	return array((int) $s['opcache_statistics']['misses'], (int) $s['memory_usage']['wasted_memory']);
};

stream_wrapper_unregister('file');
stream_wrapper_register('file', 'Q_WebServer_CompatFileWrapper');
$write('v1', 120);
$boundary();
$r = include $f;
check('this worker serves v1', $r['v'], 'v1');

// The other worker: writes v2 and includes it -- one compile, stored for all.
$pid = pcntl_fork();
if ($pid === 0) {
	$write('v2', 60);
	$boundary();
	$r = include $f;
	exit($r['v'] === 'v2' ? 0 : 1);
}
pcntl_waitpid($pid, $status);
check('the other worker compiled and ran v2', pcntl_wexitstatus($status), 0);

$boundary();
list($misses, $wasted) = $stats();
$r = include $f;
list($misses2, $wasted2) = $stats();
check('this worker now runs v2', $r['v'], 'v2');
check('...from the compile the other worker stored (no cache miss)', $misses2 - $misses, 0);
check('...without throwing that compile away (no new waste)', $wasted2 - $wasted, 0);
$r = include $f;
check('...and keeps running it', $r['v'], 'v2');

stream_wrapper_restore('file');
@unlink($f); @rmdir($dir);

if ($fail) {
	printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	exit(1);
}
printf("  PASS - %d case(s)\n", $pass);
