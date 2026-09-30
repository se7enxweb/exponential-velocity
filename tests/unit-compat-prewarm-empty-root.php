<?php

/**
 * A document root directly under / -- a container's /app -- must start.
 *
 * The server prewarms the directory one level above --root. For /app that is
 * /, which prewarm() trimmed to '' and handed to RecursiveDirectoryIterator,
 * which throws a ValueError: the server died before listening. Had it not,
 * it would have walked the whole filesystem. prewarm() now refuses nothing
 * and the filesystem root, and the server prewarms the document root itself
 * when there is no project directory above it.
 *
 *   php tests/unit-compat-prewarm-empty-root.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

$pass = 0;
$fail = 0;

function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	fwrite(STDERR, "  FAIL  $what\n        got  " . var_export($got, true) . "\n        want " . var_export($want, true) . "\n");
}

$scratch = sys_get_temp_dir() . DS . 'qbix-prewarm-root-' . getmypid();
@mkdir($scratch . DS . 'cache', 0700, true);

if (!class_exists('Q_Config')) {
	class Q_Config
	{
		static $dir;
		static function get()
		{
			$args = func_get_args();
			$default = array_pop($args);
			if (implode('/', $args) === 'Q/webserver/compat/dir') return self::$dir;
			return $default;
		}
	}
}
Q_Config::$dir = $scratch . DS . 'cache';

require __DIR__ . '/../src/Q/WebServer/Compat.php';

$threw = null;
try {
	$n = Q_WebServer_Compat::prewarm('');
} catch (\Throwable $e) {
	$threw = get_class($e) . ': ' . $e->getMessage();
	$n = null;
}
check('prewarm(\'\') does not throw', $threw, null);
check('...and walks nothing', $n, 0);

$threw = null;
$started = microtime(true);
try {
	$n = Q_WebServer_Compat::prewarm(DIRECTORY_SEPARATOR);
} catch (\Throwable $e) {
	$threw = get_class($e) . ': ' . $e->getMessage();
	$n = null;
}
check('prewarm(the filesystem root) does not throw', $threw, null);
check('...and does not walk the filesystem', $n, 0);
check('...and returns at once', microtime(true) - $started < 1.0, true);

// The server picks the document root itself when nothing is above it.
$src = file_get_contents(__DIR__ . '/../sbin/qbixserver.php');
check('qbixserver.php prewarms --root itself when one level up is /',
	strpos($src, "\$prewarmDir === DIRECTORY_SEPARATOR || !is_dir(\$prewarmDir)") !== false, true);

foreach (glob($scratch . DS . 'cache' . DS . '*') ?: array() as $f) @unlink($f);
@rmdir($scratch . DS . 'cache');
@rmdir($scratch);

echo $fail ? "  FAIL - $fail of " . ($pass + $fail) . " case(s)\n" : "  PASS - $pass case(s)\n";
exit($fail ? 1 : 0);
