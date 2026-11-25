<?php

/**
 * Running without root, and static files from a cluster's shared directory.
 *
 * Asserted:
 *   - Q.web.static.roots: a static path the document root lacks is found in
 *     a listed directory; never a script, never a path outside the static
 *     allow-list, never a file outside the listed directory (a symbolic link
 *     out of it), and an entry's "match" limits it to its paths;
 *   - insideRoot() accepts such a file and still refuses a script there;
 *   - as root with the effective user switched (the warm-up's asUser()),
 *     is_writable() answers for the effective user, not for the real one:
 *     a root-owned 0755 directory is not writable to nobody, though
 *     access(2) for the real user root would say it is;
 *   - Q.web.mimeTypes (a mime.types file, Apache's TypesConfig) and
 *     Q.web.defaultCharset (AddDefaultCharset) decide a static Content-Type;
 *   - --drop-master: dropMasterWanted() from the command line, the
 *     configuration and <PREFIX>_RUN_DROP_MASTER; dropMaster() in a child
 *     leaves real and effective ids at the worker user, hands it the pid
 *     file, never a shallow system directory, and refuses Q.webserver.pools.
 *
 * The root-only cases are skipped when not run as root.
 *
 *   php tests/unit-nonroot-and-static-roots.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
require_once __DIR__ . '/../src/Q/WebServer/Modes.php';
require_once __DIR__ . '/../src/Q/WebServer/RunAs.php';
require_once __DIR__ . '/../src/Q/WebServer.php';
require_once __DIR__ . '/../src/Q/WebServer/Compat.php';
$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

$base = sys_get_temp_dir() . '/vc-nonroot-' . getmypid();
@mkdir("$base/doc/var/site/cache/public/stylesheets", 0755, true);
@mkdir("$base/dfs/var/site/cache/public/stylesheets", 0755, true);
@mkdir("$base/dfs/var/site/cache/public/javascript", 0755, true);
@mkdir("$base/other", 0755, true);
file_put_contents("$base/dfs/var/site/cache/public/stylesheets/a_all.css", 'body{}');
file_put_contents("$base/dfs/var/site/cache/public/stylesheets/x.php", '<?php echo 1;');
file_put_contents("$base/dfs/var/site/cache/public/javascript/b.js", 'var b;');
file_put_contents("$base/other/secret.css", 'secret');
@symlink("$base/other/secret.css", "$base/dfs/var/site/cache/public/stylesheets/link.css");
file_put_contents("$base/dfs/notstatic.css", 'x');

$root = realpath("$base/doc");
$dfs = realpath("$base/dfs");
Q_WebServer::$rootDir = $root . DS;
Q_Config::set('Q', 'web', 'static', 'paths', array('^/var/([^/]+/)?cache/(texttoimage|public)/'));
Q_Config::set('Q', 'web', 'static', 'roots', array($dfs));

$W = 'Q_WebServer';
check('a static path missing locally is found in Q.web.static.roots',
	$W::alternateStatic('/var/site/cache/public/stylesheets/a_all.css'), "$dfs/var/site/cache/public/stylesheets/a_all.css");
check('a script there is never served', $W::alternateStatic('/var/site/cache/public/stylesheets/x.php'), null);
check('a path outside the static allow-list is not looked for', $W::alternateStatic('/notstatic.css'), null);
check('a link out of the listed directory is refused', $W::alternateStatic('/var/site/cache/public/stylesheets/link.css'), null);
check('climbing out is refused', $W::alternateStatic('/var/site/cache/public/../../../../other/secret.css'), null);
check('a missing file is null', $W::alternateStatic('/var/site/cache/public/stylesheets/none.css'), null);
check('insideRoot() accepts a file in a static root', $W::insideRoot("$dfs/var/site/cache/public/javascript/b.js"), true);
check('insideRoot() refuses a script in a static root', $W::insideRoot("$dfs/var/site/cache/public/stylesheets/x.php"), false);

Q_Config::set('Q', 'web', 'static', 'roots', array(array('dir' => $dfs, 'match' => '^/var/[^/]+/cache/public/stylesheets/')));
check('"match" lets its paths through', $W::alternateStatic('/var/site/cache/public/stylesheets/a_all.css') !== null, true);
check('"match" keeps other paths out', $W::alternateStatic('/var/site/cache/public/javascript/b.js'), null);
Q_Config::set('Q', 'web', 'static', 'roots', array());
check('no roots configured: nothing', $W::alternateStatic('/var/site/cache/public/javascript/b.js'), null);

// Q.web.mimeTypes and Q.web.defaultCharset: Apache's TypesConfig and AddDefaultCharset.
file_put_contents("$base/mime.types", "# test\ntext/html html htm\ntext/javascript js mjs\ntext/plain txt\n");
check('no types file: the server\'s own js type', $W::mimeType('js'), 'application/javascript; charset=utf-8');
Q_Config::set('Q', 'web', 'mimeTypes', "$base/mime.types");
check('types file: its js type', $W::mimeType('js'), 'text/javascript');
check('types file: html without a charset, as Apache by default', $W::mimeType('html'), 'text/html');
check('types file: an extension it lacks keeps the server\'s type', $W::mimeType('png'), 'image/png');
Q_Config::set('Q', 'web', 'defaultCharset', 'UTF-8');
check('defaultCharset: text/plain gets it', $W::mimeType('txt'), 'text/plain; charset=UTF-8');
check('defaultCharset: not other types', $W::mimeType('js'), 'text/javascript');
Q_Config::clear('Q', 'web', 'mimeTypes');
Q_Config::clear('Q', 'web', 'defaultCharset');

// --drop-master is asked for.
$R = 'Q_WebServer_RunAs';
$R::setCommandLine(array());
putenv('QBIX_RUN_DROP_MASTER');
check('not asked: no drop', $R::dropMasterWanted(), false);
$R::setCommandLine(array('drop-master' => true));
check('--drop-master', $R::dropMasterWanted(), true);
$R::setCommandLine(array());
Q_Config::set('Q', 'webserver', 'dropMaster', true);
check('Q.webserver.dropMaster', $R::dropMasterWanted(), true);
Q_Config::clear('Q', 'webserver', 'dropMaster');
putenv('QBIX_RUN_DROP_MASTER=1');
check('QBIX_RUN_DROP_MASTER=1', $R::dropMasterWanted(), true);
putenv('QBIX_RUN_DROP_MASTER');

$nobody = function_exists('posix_getpwnam') ? posix_getpwnam('nobody') : false;
if (!function_exists('posix_geteuid') or posix_geteuid() !== 0 or !$nobody or !function_exists('pcntl_fork')) {
	echo "  (not root, or no nobody or pcntl: the switching cases are skipped)\n";
} else {
	// Effective-user access: a root-owned 0755 directory.
	@mkdir("$base/rootonly", 0755);
	chown("$base/rootonly", 0); chmod("$base/rootonly", 0755);
	chmod($base, 0755);
	$wrapper = 'Q_WebServer_CompatFileWrapper';
	posix_setegid($nobody['gid']); posix_seteuid($nobody['uid']);
	$asNobody = Q_WebServer_Compat::accessCheck("$base/rootonly", 2, 'is_writable');
	$asNobodyRead = Q_WebServer_Compat::accessCheck("$base/rootonly", 4, 'is_readable');
	$probeLeft = count(glob("$base/rootonly/.vc-access-*"));
	posix_seteuid(0); posix_setegid(0);
	check('effective user nobody: a root-owned 0755 directory is not writable', $asNobody, false);
	check('effective user nobody: it is readable', $asNobodyRead, true);
	check('no probe file left behind', $probeLeft, 0);
	@mkdir("$base/nobodys", 0755);
	chown("$base/nobodys", $nobody['uid']);
	posix_setegid($nobody['gid']); posix_seteuid($nobody['uid']);
	$own = Q_WebServer_Compat::accessCheck("$base/nobodys", 2, 'is_writable');
	$probeLeft = count(glob("$base/nobodys/.vc-access-*"));
	posix_seteuid(0); posix_setegid(0);
	check('effective user nobody: its own directory is writable', $own, true);
	check('no probe file left in it', $probeLeft, 0);

	// dropMaster() in a child.
	@mkdir("$base/run/vc", 0755, true);
	file_put_contents("$base/run/vc/server.pid", '1');
	@mkdir("$base/out", 0777); chmod("$base/out", 0777);
	$tmp = "$base/out/result";
	$R::setCommandLine(array('user' => 'nobody', 'drop-master' => true));
	Q_Config::set('Q', 'webserver', 'startOptions', array('root' => $root));
	$pid = pcntl_fork();
	if ($pid === 0) {
		$err = $R::dropMaster(array("$base/run/vc/server.pid", '/run', '/tmp'));
		@file_put_contents($tmp, json_encode(array($err, posix_getuid(), posix_geteuid(), @posix_setuid(0))));
		posix_kill(getmypid(), SIGKILL);
	}
	pcntl_waitpid($pid, $st);
	$r = json_decode((string) @file_get_contents($tmp), true);
	check('dropMaster(): no error', is_array($r) && array_key_exists(0, $r) ? $r[0] : 'no result', null);
	check('dropMaster(): real uid is the worker user', $r[1] ?? null, (int) $nobody['uid']);
	check('dropMaster(): effective uid is the worker user', $r[2] ?? null, (int) $nobody['uid']);
	check('dropMaster(): root cannot be had back', $r[3] ?? null, false);
	check('dropMaster(): the pid file is handed over', fileowner("$base/run/vc/server.pid"), (int) $nobody['uid']);
	check('dropMaster(): /run is never touched', fileowner('/run'), 0);
	check('dropMaster(): /tmp is never touched', fileowner('/tmp'), 0);

	Q_Config::set('Q', 'webserver', 'pools', array('shop' => array('user' => 'nobody')));
	check('dropMaster() refuses pools of other users', is_string($R::dropMaster(array())), true);
	Q_Config::clear('Q', 'webserver', 'pools');
	$R::setCommandLine(array());
}

// Clean up.
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
@rmdir($base);

printf("%s: %d passed, %d failed\n", $fail ? 'FAIL' : 'PASS', $pass, $fail);
exit($fail ? 1 : 0);
