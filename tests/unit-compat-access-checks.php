<?php

/**
 * is_writable(), is_writeable(), is_readable() and is_executable() give the
 * operating system's answer under the file wrapper, as they do without it.
 *
 * With a user-space file:// wrapper installed PHP cannot call access(2) for
 * these: it takes the wrapper's stat() and compares the mode bits with the
 * process's uid and gid itself. Root then has no special rights, and an NFS
 * export that squashes every user to one owner (all_squash), an ACL or a
 * capability are never seen. A shared storage directory owned by another
 * user, group-writable for a group the worker is not in but writable over
 * all_squash, read as read-only: Exponential's DFS cluster handler refused
 * its mount point and every request was a 500. Asserted:
 *   - the transform sends all four (and \is_writable()) to the shims;
 *   - without the wrapper the shims are PHP's own functions;
 *   - under the wrapper they agree with PHP's own answers, given without
 *     the wrapper, for files, directories, missing paths, file:// paths and
 *     modes 0000 to 0777;
 *   - as root: a directory and a file of another user, mode 0755/0644, are
 *     writable, as access(2) says, where the mode-bit emulation says not;
 *   - the fallback without posix_access() gives the same answers and leaves
 *     the wrapper in place, also when the call throws;
 *   - another wrapper's URL is left to the native function.
 *
 *   php tests/unit-compat-access-checks.php
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

// the transform
$src = "<?php\nif (is_writeable(\$a) and is_writable(\$b) and \\is_writable(\$c)"
	. " and is_readable(\$d) and is_executable(\$e)) {}\n\$o->is_writable(\$x);\n";
$out = Q_WebServer_Compat::transformSource($src);
check('is_writable() rewritten', substr_count($out, 'Q_WebServer_Compat::_is_writable('), 3);
check('is_readable() rewritten', substr_count($out, 'Q_WebServer_Compat::_is_readable('), 1);
check('is_executable() rewritten', substr_count($out, 'Q_WebServer_Compat::_is_executable('), 1);
check('a method of the same name left alone', strpos($out, '$o->is_writable($x)') !== false, true);

$base = sys_get_temp_dir() . DS . 'qbix-access-' . getmypid();
@mkdir($base, 0700, true);
$paths = array('missing' => $base . DS . 'missing', 'base' => $base,
	'scheme' => 'file://' . $base);
foreach (array(0, 0400, 0200, 0100, 0500, 0600, 0700, 0644, 0755, 0777) as $m) {
	$f = $base . DS . sprintf('f%04o', $m);
	$d = $base . DS . sprintf('d%04o', $m);
	file_put_contents($f, 'x');
	@mkdir($d);
	chmod($f, $m);
	chmod($d, $m);
	$paths[basename($f)] = $f;
	$paths[basename($d)] = $d;
}
$isRoot = (function_exists('posix_geteuid') and posix_geteuid() === 0);
if ($isRoot) {
	// another user's, not writable by mode bits for anyone but its owner
	foreach (array('otherdir' => 0755, 'otherfile' => 0644) as $name => $m) {
		$p = $base . DS . $name;
		$name === 'otherdir' ? @mkdir($p) : file_put_contents($p, 'x');
		chmod($p, $m);
		chown($p, 65534);
		chgrp($p, 65534);
		$paths[$name] = $p;
	}
}
$fns = array('is_writable' => '_is_writable', 'is_writeable' => '_is_writable',
	'is_readable' => '_is_readable', 'is_executable' => '_is_executable');
$answers = function ($how) use ($paths, $fns) {
	$out = array();
	foreach ($paths as $k => $p) {
		foreach ($fns as $native => $shim) {
			clearstatcache();
			$out["$native $k"] = $how($native, $shim, $p);
		}
	}
	return $out;
};
$want = $answers(function ($native, $shim, $p) { return $native($p); });
check('without the wrapper the shims are PHP\'s own',
	$answers(function ($native, $shim, $p) { return Q_WebServer_Compat::$shim($p); }), $want);

stream_wrapper_unregister('file');
stream_wrapper_register('file', 'Q_WebServer_CompatFileWrapper');
Q_WebServer_CompatFileWrapper::$installed = true;
$isOurs = function () use ($base) {
	$h = @fopen($base . DS . 'f0644', 'r');
	$type = $h ? stream_get_meta_data($h)['wrapper_type'] : null;
	if ($h) fclose($h);
	return $type === 'user-space';
};
check('the wrapper answers opens', $isOurs(), true);

if ($isRoot) {
	// what the bug looked like: PHP's emulation under the wrapper
	clearstatcache();
	check('reproduced: under the wrapper PHP\'s is_writable() says no to root',
		is_writable($paths['otherdir']), false);
	check('as root, another user\'s 0755 directory is writable', $want['is_writable otherdir'], true);
	check('as root, another user\'s 0644 file is writable', $want['is_writable otherfile'], true);
} else {
	printf("  note  not root: the other-user cases are skipped\n");
}

check('under the wrapper the shims agree with PHP without it',
	$answers(function ($native, $shim, $p) { return Q_WebServer_Compat::$shim($p); }), $want);
check('the wrapper is still in place', $isOurs(), true);

$got = array();
foreach ($paths as $k => $p) {
	foreach (array('is_writable', 'is_readable', 'is_executable') as $native) {
		$got["$native $k"] = Q_WebServer_CompatFileWrapper::nativeAccess($native, $p);
	}
}
$sub = array();
foreach ($got as $k => $v) $sub[$k] = $want[$k];
check('the fallback without posix_access() agrees', $got, $sub);
check('the wrapper is back after the fallback', $isOurs(), true);
try {
	Q_WebServer_CompatFileWrapper::nativeAccess(function () { throw new RuntimeException('x'); }, $base);
} catch (RuntimeException $e) {
}
check('the wrapper is back after a fallback that threw', $isOurs(), true);

check('another wrapper\'s URL goes native', Q_WebServer_Compat::_is_writable('php://memory'),
	is_writable('php://memory'));
check('the empty path is false', Q_WebServer_Compat::_is_writable(''), false);

stream_wrapper_restore('file');
Q_WebServer_CompatFileWrapper::$installed = false;
foreach (glob($base . DS . '*') ?: array() as $p) {
	@chmod($p, 0700);
	is_dir($p) ? @rmdir($p) : @unlink($p);
}
@rmdir($base);

printf("%s: %d passed, %d failed\n", $fail ? 'FAIL' : 'PASS', $pass, $fail);
exit($fail ? 1 : 0);
