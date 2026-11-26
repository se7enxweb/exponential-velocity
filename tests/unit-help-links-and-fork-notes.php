<?php

/**
 * --help and the plan name what exists.
 *
 *   - --help, the quick start and the welcome page pointed at
 *     github.com/Qbix/webserver, a repository name that is not the engine's
 *     home page. They now ask Q_WebServer_About::homepage(): the selected
 *     distribution's home page, else the upstream engine's.
 *   - ext:plan said Windows gets its isolation from qbix_fork.dll, and a
 *     Pool.php comment that the DLL is never shipped. Neither is so: the DLL
 *     is attached to a release only when it builds, and the pool does not use
 *     it -- Windows workers are the portable pool.
 *
 *   php tests/unit-help-links-and-fork-notes.php
 */

require_once __DIR__ . '/../src/Q/WebServer/About.php';

$pass = 0;
$fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

$root = dirname(__DIR__);
putenv('QBIX_DISTRIBUTION');

// Without a distribution: the upstream home page.
$bare = sys_get_temp_dir() . '/qbix-homepage-' . getmypid();
@mkdir($bare, 0700, true);
check('no distribution: the upstream home page', Q_WebServer_About::homepage($bare), Q_WebServer_About::HOMEPAGE);
@rmdir($bare);

// With the distribution this tree names, its own.
if (is_file("$root/DISTRIBUTION")) {
	$name = ucfirst(strtolower(trim(file_get_contents("$root/DISTRIBUTION"))));
	require_once "$root/src/Q/WebServer/Distribution/$name.php";
	$about = call_user_func(array("Q_WebServer_Distribution_$name", 'about'));
	check('a distribution: its own home page', Q_WebServer_About::homepage($root), $about['homepage']);
}

$server = file_get_contents("$root/sbin/qbixserver.php");
check('the server names no stale repository', strpos($server, 'github.com/Qbix/webserver'), false);
check('--help asks About::homepage()', substr_count($server, 'Q_WebServer_About::homepage(dirname(__DIR__))'), 3);
check('the welcome page names no stale repository',
	strpos(file_get_contents("$root/src/Q/welcome.php"), 'github.com/Qbix/webserver'), false);

$plan = json_decode(file_get_contents("$root/build/extensions.json"), true);
$note = $plan['extensions']['pcntl']['unavailable']['windows-x64'] ?? ($plan['pcntl']['unavailable']['windows-x64'] ?? null);
check('the plan has a Windows note for pcntl', is_string($note), true);
check('...that does not promise qbix_fork.dll', is_string($note) && stripos($note, 'qbix_fork') === false, true);
check('...and names the portable pool', is_string($note) && stripos($note, 'portable pool') !== false, true);
check('Pool.php does not say the DLL is never shipped',
	strpos(file_get_contents("$root/src/Q/WebServer/Pool.php"), 'qbix_fork.dll that is not shipped'), false);

echo ($fail ? 'FAIL' : 'PASS') . "  $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
