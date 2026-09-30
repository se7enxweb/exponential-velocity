#!/usr/bin/env php
<?php

/**
 * Where qbixctl start sends the server's output when --log does not say.
 *
 * It used to be <temporary directory>/qbixserver.log: one file for every
 * server on the machine, overwritten by each start, in a directory that is
 * cleaned out and readable by others. It is now the server's own log
 * directory, never the temporary directory.
 *
 * Asserted:
 *   - Q.webserver.log.dir wins;
 *   - then var/log, files/log or logs of the document root, then of the
 *     directory above it;
 *   - then the configuration tree's log directory (Q_WebServer_Layout::logDir:
 *     /var/log/qbix for the base tree, an overlay's own, QBIX_LOG_DIR);
 *   - else var/log beside the document root, made for it;
 *   - a real `qbixctl start` with no --log writes there, and nothing named
 *     qbixserver.log appears in the temporary directory.
 *
 *   php tests/unit-ctl-default-log.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/Console.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
require_once __DIR__ . '/../src/Q/WebServer/Ctl.php';
Q_WebServer_Ctl::$sourceDir = realpath(dirname(__DIR__));
$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

// ── The configuration tree's log directory ───────────────────────────────
putenv('QBIX_LOG_DIR');
putenv('QBIX_CONF_DIR');
check('/etc/qbix logs to /var/log/qbix', Q_WebServer_Layout::logDir('/etc/qbix'), '/var/log/qbix');
Q_WebServer_Layout::addOverlay('/etc/example-overlay', 'EXAMPLE_OVERLAY_CONF_DIR', '/var/lib/example-overlay', '/var/log/example-overlay');
check('an overlay logs to its own directory', Q_WebServer_Layout::logDir('/etc/example-overlay'), '/var/log/example-overlay');
check('a directory that is neither has none', Q_WebServer_Layout::logDir('/srv/elsewhere'), null);
putenv('QBIX_LOG_DIR=/srv/logs/');
check('QBIX_LOG_DIR moves it', Q_WebServer_Layout::logDir('/etc/qbix'), '/srv/logs');
putenv('QBIX_LOG_DIR');

// ── The order ─────────────────────────────────────────────────────────────
$base = sys_get_temp_dir() . '/qbix-ctl-default-log-' . getmypid();
$tmp = rtrim(realpath(sys_get_temp_dir()), '/');
mkdir("$base/a/site/web", 0755, true);
$rootA = realpath("$base/a/site/web");
$tree = "$base/tree";
mkdir($tree, 0755, true);
Q_Config::set('Q', 'webserver', 'confDir', $tree);

Q_Config::set('Q', 'webserver', 'log', 'dir', "$base/configured");
check('Q.webserver.log.dir wins (and is made)', array(Q_WebServer_Ctl::defaultLog(array('root' => $rootA)), is_dir("$base/configured")),
	array("$base/configured/qbixserver.log", true));
Q_Config::clear('Q', 'webserver', 'log');

mkdir("$rootA/var/log", 0755, true);
check('var/log of the document root (an installation that is its own root)', Q_WebServer_Ctl::defaultLog(array('root' => $rootA)), "$rootA/var/log/qbixserver.log");
rmdir("$rootA/var/log"); rmdir("$rootA/var");
mkdir("$rootA/files/log", 0755, true);
check('files/log of the document root', Q_WebServer_Ctl::defaultLog(array('root' => $rootA)), "$rootA/files/log/qbixserver.log");
rmdir("$rootA/files/log"); rmdir("$rootA/files");
mkdir(dirname($rootA) . '/var/log', 0755, true);
check('var/log of the directory above it', Q_WebServer_Ctl::defaultLog(array('root' => $rootA)), dirname($rootA) . '/var/log/qbixserver.log');
rmdir(dirname($rootA) . '/var/log'); rmdir(dirname($rootA) . '/var');
mkdir(dirname($rootA) . '/logs', 0755, true);
check('logs/ above it, where the server keeps its own logs by default', Q_WebServer_Ctl::defaultLog(array('root' => $rootA)), dirname($rootA) . '/logs/qbixserver.log');
rmdir(dirname($rootA) . '/logs');
check('--app DIR: its web/ is the root', Q_WebServer_Ctl::defaultLog(array('app' => dirname($rootA))) !== null
	&& is_dir(dirname($rootA) . '/var/log'), true);
rmdir(dirname($rootA) . '/var/log'); rmdir(dirname($rootA) . '/var');

Q_WebServer_Layout::addOverlay($tree, null, null, "$base/treelog");
check('then the configuration tree\'s log directory', Q_WebServer_Ctl::defaultLog(array('root' => $rootA)), "$base/treelog/qbixserver.log");
Q_Config::set('Q', 'webserver', 'confDir', null);
check('else var/log beside the document root, made for it', array(Q_WebServer_Ctl::defaultLog(array('root' => $rootA)), is_dir(dirname($rootA) . '/var/log')),
	array(dirname($rootA) . '/var/log/qbixserver.log', true));
rmdir(dirname($rootA) . '/var/log'); rmdir(dirname($rootA) . '/var');
$none = Q_WebServer_Ctl::defaultLog(array('root' => "$base/missing/web"));
check('never the temporary directory itself', dirname($none) !== $tmp && strpos($none, '/qbixserver.log') !== false, true);

// ── A real start with no --log ───────────────────────────────────────────
mkdir("$base/b/web", 0755, true);
mkdir("$base/b/var/log", 0755, true);
file_put_contents("$base/b/web/index.php", '<?php echo "log ok";');
$s = stream_socket_server('tcp://127.0.0.1:0'); $port = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1); fclose($s);
$ctl = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(realpath(__DIR__ . '/../sbin/qbixctl.php'));
$srv = ' --distribution=none --conf-dir=none --root=' . escapeshellarg("$base/b/web") . " --host=127.0.0.1 --port=$port --workers=1 --pid=" . escapeshellarg("$base/b/server.pid");
$before = is_file("$tmp/qbixserver.log") ? filemtime("$tmp/qbixserver.log") . ':' . filesize("$tmp/qbixserver.log") : null;
exec("$ctl start$srv 2>&1", $o1, $c1);
check('qbixctl start without --log starts', $c1, 0);
check('...and its output goes to the installation\'s var/log', is_file(realpath("$base/b") . '/var/log/qbixserver.log'), true);
$after = is_file("$tmp/qbixserver.log") ? filemtime("$tmp/qbixserver.log") . ':' . filesize("$tmp/qbixserver.log") : null;
check('...not to the temporary directory', $after, $before);
exec("$ctl stop$srv 2>&1", $o2, $c2);
check('and stops', $c2, 0);

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { ($f->isDir() and !$f->isLink()) ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
@rmdir($base);

if ($fail) { printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
