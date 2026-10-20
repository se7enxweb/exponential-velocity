<?php

/**
 * The user and group the workers run as (Q_WebServer_RunAs).
 *
 * Asserted:
 *   - precedence: command line > Q.webserver.user/group > <PREFIX>_RUN_USER
 *     (process environment, then the envvars file) > the document root's
 *     owner and group; a distribution's prefix comes before QBIX;
 *   - a user or group that does not exist is an error, never a silent root;
 *   - root needs allowRootWorkers; a root-owned document root keeps root
 *     workers with a warning;
 *   - as root: drop() leaves a child as the worker user with its groups and no
 *     way back, and a child that cannot drop is killed before running code;
 *   - Q_WebServer_Modes::own() hands root-created files under an ownUnder()
 *     directory to that user and leaves everything else alone.
 *
 * The root-only cases are skipped when not run as root.
 *
 *   php tests/unit-runas.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
require_once __DIR__ . '/../src/Q/WebServer/Modes.php';
require_once __DIR__ . '/../src/Q/WebServer/RunAs.php';
$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}
$R = 'Q_WebServer_RunAs';
$root = posix_geteuid() === 0;
$nobody = posix_getpwnam('nobody');
$base = sys_get_temp_dir() . '/qbix-runas-' . getmypid();
mkdir("$base/doc", 0755, true);
mkdir("$base/etc", 0755, true);
Q_Config::set('Q', 'webserver', 'startOptions', array('root' => "$base/doc"));
function reset_all()
{
	Q_WebServer_RunAs::setCommandLine(array());
	foreach (array('user', 'group', 'allowRootWorkers', 'confDirs') as $k) Q_Config::clear('Q', 'webserver', $k);
	foreach (array('VC', 'QBIX') as $p) foreach (array('USER', 'GROUP', 'ALLOW_ROOT') as $n) putenv("{$p}_RUN_$n");
	Q_WebServer_RunAs::$envPrefixes = array('QBIX');
}

if (!$root) {
	reset_all();
	$p = $R::plan(true);
	check('not root: nothing to switch', $p['switch'], false);
	Q_Config::set('Q', 'webserver', 'user', 'somebody-else');
	check('not root: a configured user is a warning, not an error', $R::plan(true)['error'], null);
	echo "  (not root: the switching cases are skipped)\n";
} elseif (!$nobody) {
	echo "  (no user nobody here: the switching cases are skipped)\n";
} else {
	$ng = posix_getgrgid($nobody['gid'])['name'];
	chown("$base/doc", $nobody['uid']); chgrp("$base/doc", $nobody['gid']);

	reset_all();
	$p = $R::plan(true);
	check('default: the document root\'s owner', array($p['switch'], $p['user'], $p['uid'], $p['gid']),
		array(true, 'nobody', (int) $nobody['uid'], (int) $nobody['gid']));

	file_put_contents("$base/etc/envvars", "# x\nexport QBIX_RUN_USER=root\nQBIX_RUN_GROUP='$ng'\n");
	Q_Config::set('Q', 'webserver', 'confDirs', array("$base/etc"));
	$p = $R::plan(true);
	check('envvars file: QBIX_RUN_USER=root without the opt-in is an error', $p['error'] !== null && strpos($p['error'], 'allowRootWorkers') !== false, true);
	putenv('QBIX_RUN_USER=nobody');
	check('process environment beats the envvars file', $R::plan(true)['user'], 'nobody');
	$R::addEnvPrefix('VC');
	putenv('VC_RUN_USER=no-such-user-runas');
	$p = $R::plan(true);
	check('a distribution prefix comes first, and a missing user is an error', $p['error'] !== null && strpos($p['error'], 'no-such-user-runas') !== false, true);
	check('...and never a switch', $p['switch'], false);
	Q_Config::set('Q', 'webserver', 'user', 'nobody');
	check('configuration beats the environment', $R::plan(true)['error'], null);
	$R::setCommandLine(array('user' => 'no-such-user-runas'));
	check('the command line beats the configuration', $R::plan(true)['error'] !== null, true);
	$R::setCommandLine(array('user' => 'nobody', 'group' => 'no-such-group-runas'));
	check('a missing group is an error', strpos((string) $R::plan(true)['error'], 'no-such-group-runas') !== false, true);
	$R::setCommandLine(array('user' => '#' . $nobody['uid'], 'group' => (string) $nobody['gid']));
	$p = $R::plan(true);
	check('numeric user and group', array($p['user'], $p['gid']), array('nobody', (int) $nobody['gid']));

	reset_all();
	$R::setCommandLine(array('user' => 'root'));
	check('user root needs allowRootWorkers', strpos((string) $R::plan(true)['error'], 'allowRootWorkers') !== false, true);
	$R::setCommandLine(array('user' => 'root', 'allow-root-workers' => true));
	$p = $R::plan(true);
	check('...and with it, root workers, said out loud', array($p['error'], $p['switch'], $p['warning'] !== null), array(null, false, true));

	reset_all();
	chown("$base/doc", 0);
	$p = $R::plan(true);
	check('root-owned document root, nothing configured: root as before, with a warning',
		array($p['error'], $p['switch'], strpos((string) $p['warning'], 'belongs to root') !== false), array(null, false, true));
	chown("$base/doc", $nobody['uid']);
	// PHP before 8.3 keeps the owner it read before the chown() in its stat cache.
	clearstatcache();

	// drop() in a child: the worker user, its groups, and no way back.
	reset_all();
	$R::plan(true);
	$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
	$pid = pcntl_fork();
	if ($pid === 0) {
		fclose($pair[0]);
		$err = $R::drop();
		fwrite($pair[1], json_encode(array($err, posix_getuid(), posix_geteuid(), posix_getgid(), @posix_setuid(0), getenv('USER'))));
		exit(0);
	}
	fclose($pair[1]);
	$got = json_decode(stream_get_contents($pair[0]), true);
	pcntl_waitpid($pid, $st);
	check('drop(): uid, euid and gid are the worker user\'s, setuid(0) fails, USER set',
		$got, array(null, (int) $nobody['uid'], (int) $nobody['uid'], (int) $nobody['gid'], false, 'nobody'));

	// A child that cannot drop never gets to run: here it is no longer root
	// when it tries (setgid fails), which is what any failure looks like.
	$R::setCommandLine(array());
	Q_Config::set('Q', 'webserver', 'user', 'nobody');
	Q_Config::set('Q', 'webserver', 'group', 'root');
	$R::plan(true);
	$marker = "$base/ran";
	$pid = pcntl_fork();
	if ($pid === 0) {
		posix_setgid($nobody['gid']); posix_setuid($nobody['uid']);
		$R::dropOrExit('worker');
		file_put_contents($marker, 'application code ran');
		exit(0);
	}
	pcntl_waitpid($pid, $st);
	check('a failed drop ends the child before any application code', array(file_exists($marker), pcntl_wifexited($st) ? pcntl_wexitstatus($st) : 'signal'),
		array(false, 'signal'));
	@unlink($marker);

	// Modes::own(): only below an ownUnder() directory, only what root made.
	mkdir("$base/cache", 0755);
	Q_WebServer_Modes::ownUnder("$base/cache", $nobody['uid'], $nobody['gid']);
	Q_WebServer_Modes::put("$base/cache/a.json", 'x', 0, null);
	Q_WebServer_Modes::dir("$base/cache/x/y", 0755);
	Q_WebServer_Modes::put("$base/other.json", 'x', 0, 0644);
	clearstatcache();
	check('a file the root master writes under the directory goes to the worker user', fileowner("$base/cache/a.json"), (int) $nobody['uid']);
	check('...and so does every level of a directory it creates there', array(fileowner("$base/cache/x"), fileowner("$base/cache/x/y")),
		array((int) $nobody['uid'], (int) $nobody['uid']));
	check('...but nothing outside it', fileowner("$base/other.json"), 0);
	Q_WebServer_Modes::$owners = array();
}

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { ($f->isDir() and !$f->isLink()) ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
rmdir($base);
echo $fail ? "  FAIL - $fail of " . ($pass + $fail) . " case(s)\n" : "  PASS - $pass case(s)\n";
exit($fail ? 1 : 0);
