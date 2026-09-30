#!/usr/bin/env php
<?php

/**
 * qbixctl restart starts the server again exactly as it was started.
 *
 * It used to stop the server and then start one with only the options given
 * to restart itself -- the pid file, the configuration -- so a server started
 * with --root, --port, --https-port, --host, --workers, --keep-globals or any
 * other option did not come back: the new process had the default root and
 * ports, and the start timed out waiting for it.
 *
 * Asserted, with real servers:
 *   - a server started with `qbixctl start` and a full set of options
 *     (--root --host --port --https-port --workers --pid --config --conf-dir
 *     --keep-globals --distribution, and one after `--`) writes how it was
 *     started beside its pid file, and `qbixctl restart --pid=...` brings it
 *     back with the same command line, ports, root and log, answering on both
 *     ports;
 *   - a bare `qbixctl restart`, run from another directory with another
 *     environment, finds the server and restarts it the same way: relative
 *     paths still resolve, and the environment it was started with (the
 *     QBIX_* variables) is the one it gets again;
 *   - an option given to restart replaces the recorded one, and only that one;
 *   - a server started by hand without a pid file (so no record) is
 *     restarted from its process table entry, the interpreter's -d settings
 *     included;
 *   - the record's helpers: options(), override(), a record naming another
 *     process is ignored.
 *
 *   php tests/unit-ctl-restart-keeps-options.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/Console.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
require_once __DIR__ . '/../src/Q/WebServer/Ctl.php';
require_once __DIR__ . '/../src/Q/WebServer/StartRecord.php';
Q_WebServer_Ctl::$sourceDir = realpath(dirname(__DIR__));
$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}
if (!is_dir('/proc/self/fd')) { echo "  SKIP - needs /proc\n"; exit(0); }

// ── The record's helpers ──────────────────────────────────────────────────
$R = 'Q_WebServer_StartRecord';
check('options(): both spellings, flags, the last one wins, nothing after --',
	$R::options(array('--root=/a', '--port', '81', '--debug', '--port=82', '--', '--workers=9')),
	array('root' => '/a', 'port' => '82', 'debug' => true));
check('override(): replaces in place of the old one, adds what is new, keeps the rest and `--` last',
	$R::override(array('--root=/a', '--workers', '2', '--keep-globals=x', '--', 'tail'), array('workers' => '3', 'host' => '127.0.0.1')),
	array('--root=/a', '--keep-globals=x', '--workers=3', '--host=127.0.0.1', '--', 'tail'));
check('override(): false removes a flag', $R::override(array('--debug', '--root=/a'), array('debug' => false)), array('--root=/a'));
check('environment(): only the variables the engine reads at start-up',
	$R::environment(array('QBIX_CONF_DIR' => '/c', 'EXAMPLE_STATE_DIR' => '/s', 'PATH' => '/bin', 'HOME' => '/root', 'QBIX_X' => '1')),
	array('EXAMPLE_STATE_DIR' => '/s', 'QBIX_CONF_DIR' => '/c', 'QBIX_X' => '1'));

$base = sys_get_temp_dir() . '/qbix-ctl-restart-' . getmypid();
mkdir("$base/site/web", 0755, true);
mkdir("$base/elsewhere", 0755, true);
mkdir("$base/log", 0755, true);
$pidFile = "$base/run/server.pid";
mkdir(dirname($pidFile));
$R::write($pidFile, array('pid' => 1, 'command' => array('php'), 'args' => array()));
check('a record naming another process is not this one\'s', $R::read($pidFile, 2), null);
unlink($R::file($pidFile));

// A page that says what the server gives it.
file_put_contents("$base/site/web/index.php", '<?php header("Content-Type: text/plain"); echo "probe=", getenv("QBIX_RESTART_PROBE") ?: "-", " ini=", ini_get("precision");');
$tree = "$base/conf";
foreach (array('mods-available', 'mods-enabled', 'conf-available', 'conf-enabled', 'sites-available', 'sites-enabled') as $d) mkdir("$tree/$d", 0755, true);
file_put_contents("$tree/qbix.conf", '{}');
file_put_contents("$base/site.json", json_encode(array('Q' => array(
	'web' => array('https' => array('mode' => 'self-signed')),
	'webserver' => array('log' => array('dir' => "$base/log")),
))));
function free_port()
{
	$s = stream_socket_server('tcp://127.0.0.1:0');
	$p = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
	fclose($s);
	return $p;
}
function cmdline($pid)
{
	return explode("\0", rtrim((string) @file_get_contents("/proc/$pid/cmdline"), "\0"));
}
function pid_of($file)
{
	return is_file($file) ? (int) trim((string) file_get_contents($file)) : 0;
}
function get($port, $https = false)
{
	$ctx = stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false), 'http' => array('timeout' => 5)));
	return @file_get_contents(($https ? 'https' : 'http') . "://127.0.0.1:$port/", false, $ctx);
}
$ctl = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(realpath(__DIR__ . '/../sbin/qbixctl.php'));
$https = extension_loaded('openssl');

// ── 1. Started with qbixctl start and every kind of option ──────────────
$port = free_port(); $sport = free_port();
$opts = ' --root=' . escapeshellarg("$base/site/web") . " --host=127.0.0.1 --port=$port"
	. ($https ? " --https-port=$sport" : '') . ' --workers=2 --pid=' . escapeshellarg($pidFile)
	. ' --config=' . escapeshellarg("$base/site.json") . ' --conf-dir=' . escapeshellarg($tree)
	. ' --keep-globals=qbixRestartA,qbixRestartB --distribution=none --log=' . escapeshellarg("$base/log/out.log");
exec("QBIX_RESTART_PROBE=first $ctl start$opts -- --quiet 2>&1", $o1, $c1);
check('qbixctl start with a full set of options', array($c1, implode("\n", $o1) !== '' && strpos(implode("\n", $o1), 'started') !== false), array(0, true));
$pid1 = pid_of($pidFile);
$rec = $R::read($pidFile, $pid1);
check('the server wrote how it was started beside its pid file', is_array($rec) && in_array("--keep-globals=qbixRestartA,qbixRestartB", $rec['args'], true) && in_array('--quiet', $rec['args'], true), true);
check('...with the environment it was given and where its output goes', array($rec['env']['QBIX_RESTART_PROBE'] ?? null, $rec['log'] ?? null), array('first', "$base/log/out.log"));
$args1 = array_slice(cmdline($pid1), 2);
$ports1 = Q_WebServer_Ctl::portsOf($pid1);
$cwd1 = @readlink("/proc/$pid1/cwd");
check('it listens where it was told to', $ports1, $https ? array(min($port, $sport), max($port, $sport)) : array($port));
check('...and answers', get($port), 'probe=first ini=' . ini_get('precision'));

exec("$ctl restart --pid=" . escapeshellarg($pidFile) . ' 2>&1', $o2, $c2);
$pid2 = pid_of($pidFile);
check('qbixctl restart --pid brings it back (no time-out)', array($c2, $pid2 > 0 && $pid2 !== $pid1), array(0, true));
if ($c2) echo '        ', implode("\n        ", $o2), "\n";
check('...with exactly the command line it was started with', array_slice(cmdline($pid2), 2), $args1);
check('...listening on the same ports', Q_WebServer_Ctl::portsOf($pid2), $ports1);
check('...serving the same root, with the same environment', get($port), 'probe=first ini=' . ini_get('precision'));
if ($https) check('...and HTTPS answers on its port', get($sport, true), 'probe=first ini=' . ini_get('precision'));
check('...writing to the same log', ($R::read($pidFile, $pid2)['log'] ?? null), "$base/log/out.log");

// ── 2. A bare restart, from elsewhere, with another environment ─────────
exec('cd ' . escapeshellarg("$base/elsewhere") . " && QBIX_RESTART_PROBE=wrong QBIX_RESTART_OTHER=1 $ctl restart 2>&1", $o3, $c3);
$pid3 = pid_of($pidFile);
check('a bare qbixctl restart finds the server and restarts it', array($c3, $pid3 > 0 && $pid3 !== $pid2), array(0, true));
if ($c3) echo '        ', implode("\n        ", $o3), "\n";
check('...with the same command line', array_slice(cmdline($pid3), 2), $args1);
check('...and the environment it was started with, not the caller\'s', get($port), 'probe=first ini=' . ini_get('precision'));
check('...in the directory it was started in', @readlink("/proc/$pid3/cwd"), $cwd1);
$env3 = (string) @file_get_contents("/proc/$pid3/environ");
check('...without the caller\'s own QBIX_* variables', strpos($env3, 'QBIX_RESTART_OTHER=') === false, true);

// ── 3. An option given to restart replaces the recorded one ─────────────
exec("$ctl restart --pid=" . escapeshellarg($pidFile) . ' --workers=3 2>&1', $o4, $c4);
$pid4 = pid_of($pidFile);
$args4 = array_slice(cmdline($pid4), 2);
check('restart --workers=3 restarts it', $c4, 0);
check('...with --workers=3 instead of --workers=2 and everything else as it was',
	array(in_array('--workers=3', $args4, true), in_array('--workers=2', $args4, true), array_values(array_diff($args4, array('--workers=3'))) === array_values(array_diff($args1, array('--workers=2')))),
	array(true, false, true));
check('...still listening on the same ports', Q_WebServer_Ctl::portsOf($pid4), $ports1);
exec("$ctl stop --pid=" . escapeshellarg($pidFile) . ' 2>&1', $o5, $c5);
check('stop removes the pid file and the record with it', array($c5, is_file($pidFile), is_file($R::file($pidFile))), array(0, false, false));

// ── 4. Started by hand, without a pid file: from the process table ──────
$port2 = free_port();
$cmd = 'cd ' . escapeshellarg("$base/site") . ' && QBIX_RESTART_PROBE=byhand exec ' . escapeshellarg(PHP_BINARY) . ' -d precision=11 '
	. escapeshellarg(realpath(__DIR__ . '/../sbin/qbixserver.php')) . " --root=web --host=127.0.0.1 --port=$port2 --workers=1 --distribution=none --conf-dir=none"
	. ' --keep-globals=qbixByHand > ' . escapeshellarg("$base/log/byhand.log") . ' 2>&1 < /dev/null &';
exec("($cmd)");
for ($i = 0; $i < 80 and get($port2) === false; ++$i) usleep(250000);
check('a server started by hand, relative root, no pid file, answers', get($port2), 'probe=byhand ini=11');
$found = Q_WebServer_Ctl::discoverServer(array('port' => (string) $port2));
$pidH = $found['pid'] ?? 0;
$argsH = array_slice(cmdline($pidH), 4);
$pid5File = "$base/run/byhand.pid";
exec('cd ' . escapeshellarg("$base/elsewhere") . " && $ctl restart --port=$port2 --pid=" . escapeshellarg($pid5File) . ' 2>&1', $o6, $c6);
$pid6 = pid_of($pid5File);
check('qbixctl restart restarts it from its process table entry', array($c6, $pid6 > 0 && $pid6 !== $pidH), array(0, true));
if ($c6) echo '        ', implode("\n        ", $o6), "\n";
$cl6 = cmdline($pid6);
check('...with the interpreter\'s -d setting', array_slice($cl6, 1, 2), array('-d', 'precision=11'));
check('...its options as they were, plus the pid file it now has', array_values(array_diff(array_slice($cl6, 4), array('--pid=' . $pid5File, "--port=$port2"))),
	array_values(array_diff($argsH, array("--port=$port2"))));
check('...in its own directory, with its own environment', get($port2), 'probe=byhand ini=11');
check('...writing where it wrote before', $R::read($pid5File, $pid6)['log'] ?? null, "$base/log/byhand.log");
exec("$ctl stop --pid=" . escapeshellarg($pid5File) . ' 2>&1', $o7, $c7);
check('and stops', $c7, 0);

// Anything still running from this test goes.
foreach (glob('/proc/[0-9]*/cmdline') as $f) {
	if (strpos((string) @file_get_contents($f), $base) !== false and (int) basename(dirname($f)) !== getmypid()) @posix_kill((int) basename(dirname($f)), 9);
}
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { ($f->isDir() and !$f->isLink()) ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
@rmdir($base);

if ($fail) { printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
