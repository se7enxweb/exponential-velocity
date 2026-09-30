<?php
/**
 * Every program of the server answers --version, -version, -v, -V, --about,
 * -about, --copyright and -copyright with the same GNU-style text: program,
 * package and release on the first line, the details, the copyright, the MIT
 * licence and the warranty disclaimer, and exits 0 without doing anything
 * else. Arguments after "--" are not read.
 *
 *   php tests/unit-about.php
 */

$root = dirname(__DIR__);
require $root . '/src/Q/WebServer/About.php';

$pass = 0;
$fail = 0;
function check($what, $ok)
{
	global $pass, $fail;
	if ($ok) { ++$pass; return; }
	++$fail;
	echo "  FAIL  $what\n";
}

// The programs where they are (docs/layout.md, "Programs"), then the
// forwarders at their former paths, which must answer exactly the same.
$programs = array('sbin/qbixserver.php', 'sbin/qbixctl.php', 'sbin/qbixconsole.php', 'bin/qshell.php',
	'qbixserver.php', 'qbixctl.php', 'qbixconsole.php', 'qshell.php',
	'bin/qbix-appinfo.php', 'build-phar.php', 'build-app.php');
foreach ($programs as $program) {
	foreach (Q_WebServer_About::$flags as $flag) {
		$out = array();
		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/' . $program) . ' ' . escapeshellarg($flag) . ' 2>&1', $out, $code);
		$text = implode("\n", $out);
		$name = preg_replace('/\.(php|phar)$/', '', basename($program));
		check("$program $flag: exit 0", $code === 0);
		check("$program $flag: first line names the program and a release",
			(bool) preg_match('/^' . preg_quote($name, '/') . ' \(.+\) v\d/', $out[0] ?? ''));
		check("$program $flag: copyright", strpos($text, 'Copyright (C)') !== false);
		check("$program $flag: licence", strpos($text, 'License MIT') !== false);
		check("$program $flag: no warranty", strpos($text, 'There is NO WARRANTY') !== false);
	}
}

$text = Q_WebServer_About::text('x', 'a test', $root);
foreach (array('Version:', 'Build:', 'Running from:', 'Engine:', 'PHP:', 'System:', 'Extensions:', 'Written by') as $part) {
	check("text has $part", strpos($text, $part) !== false);
}

// After "--" the arguments are someone else's: handle() must return.
ob_start();
Q_WebServer_About::handle(array('run', '--', '--version'), 'x', 'a test', $root);
check('after -- not read', ob_get_clean() === '');

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
