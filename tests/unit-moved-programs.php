#!/usr/bin/env php
<?php

/**
 * The programs live in sbin/ and bin/, and every former path still works.
 *
 * The daemon and its administration commands are in sbin/ (qbixserver.php,
 * qbixctl.php, qbixconsole.php, the prebuilt qbixserver.phar, the uwebserver
 * binary), the user commands in bin/ (qshell.php, qbix-appinfo.php), the C
 * sources of uwebserver in native/uwebserver/ (docs/layout.md, "Programs").
 * The former paths are forwarders (the .php files run the new file in the
 * same process; bin/uwebserver execs the binary) or, for the phar, the same
 * file. Systemd units, scripts, Composer's vendor/bin link, the Exponential
 * installation's vendor copy and the start records of running servers name
 * them, so each must behave exactly like the new path.
 *
 * Asserted:
 *   - every former path and its new path answer --help, --version, -V,
 *     --about and a usage error with the same standard output, the same
 *     standard error and the same exit status (the build time in a version
 *     line, which ticks between two runs, aside);
 *   - standard input reaches the program through a forwarder, and its exit
 *     status comes back (qshell --exec, qshell -c 'false');
 *   - a forwarder writes its one-line note on standard error only at a
 *     terminal, and QBIX_MOVED_QUIET=1 silences it there too;
 *   - a server started by its former path serves, is found by qbixctl
 *     (which starts sbin/qbixserver.php itself) and is restarted with exactly
 *     the command line it was started with, former path included; a server
 *     started by qbixctl is restarted by the former qbixctl.php path; the
 *     pkill pattern "qbixserver.php.*--port=N" matches both;
 *   - both phar paths serve; the phar carries the new layout and the
 *     forwarders, its stub runs sbin/qbixserver.php, and
 *     phar://.../qbixserver.php (the former inside path) still runs the server;
 *   - Q_WebServer_Shell_Entry and Q_WebServer_Ctl find the programs from the
 *     engine's directory, from its sbin/, and in a tree of the former layout.
 *
 *   php tests/unit-moved-programs.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
$root = realpath(dirname(__DIR__));
require $root . '/src/Q.php';
require_once $root . '/src/Q/Console.php';
require_once $root . '/src/Q/WebServer/Layout.php';
require_once $root . '/src/Q/WebServer/Ctl.php';
require_once $root . '/src/Q/WebServer/StartRecord.php';
require_once $root . '/src/Q/WebServer/Shell/Entry.php';

$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

/** Run a command: array(exit status, stdout, stderr). */
function run(array $cmd, $stdin = '', array $env = null)
{
	$p = proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, $env);
	if (!is_resource($p)) return array(-1, '', '');
	fwrite($pipes[0], $stdin);
	fclose($pipes[0]);
	$out = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	fclose($pipes[1]); fclose($pipes[2]);
	return array(proc_close($p), $out, $err);
}

/** The last element of an array, or null (for a list that may be null). */
function last($list)
{
	return is_array($list) && $list ? $list[count($list) - 1] : null;
}

/** The build time a source run stamps with the current second, taken out. */
function steady($text)
{
	return preg_replace('/\d{4}-\d\d-\d\d \d\d:\d\d:\d\d UTC/', 'DATE', $text);
}

$php = PHP_BINARY;
$env = getenv();
unset($env['QBIX_MOVED_QUIET']);

// ── 1. The same answers from both paths ─────────────────────────────────
$pairs = array(
	'qbixserver.php' => 'sbin/qbixserver.php',
	'qbixctl.php' => 'sbin/qbixctl.php',
	'qbixconsole.php' => 'sbin/qbixconsole.php',
	'qshell.php' => 'bin/qshell.php',
	'bin/qbixserver.phar' => 'sbin/qbixserver.phar',
);
foreach ($pairs as $old => $new) {
	check("$new exists, $old too", array(is_file("$root/$new"), is_file("$root/$old")), array(true, true));
	$calls = array(array('--help'), array('--version'), array('-V'), array('--about'));
	if ($old === 'qbixctl.php') $calls[] = array();                            // usage, exit 1
	if ($old === 'qbixctl.php') $calls[] = array('no-such-command');
	if ($old === 'qbixconsole.php') $calls[] = array('list');
	if ($old === 'qbixconsole.php') $calls[] = array('help', 'server:start');
	if ($old === 'qshell.php') $calls[] = array('-c', 'echo moved; false');   // exit 1
	foreach ($calls as $args) {
		$a = run(array_merge(array($php, "$root/$old"), $args), '', $env);
		$b = run(array_merge(array($php, "$root/$new"), $args), '', $env);
		$label = "$old " . implode(' ', $args);
		// The phar's --about says which file it runs from; that line names
		// the path it was started by, and is the one difference allowed.
		if (substr($old, -5) === '.phar') $a[1] = str_replace("$root/$old", "$root/$new", $a[1]);
		check("$label: the same exit status as $new", $a[0], $b[0]);
		check("$label: the same standard output", steady($a[1]), steady($b[1]));
		check("$label: the same standard error (no note when it is not a terminal)", steady($a[2]), steady($b[2]));
	}
}
check('qbixctl.php with no command exits 1, as before', run(array($php, "$root/qbixctl.php"), '', $env)[0], 1);
check('qshell.php -c passes the exit status of its last command back', run(array($php, "$root/qshell.php", '-c', 'false'), '', $env)[0], 1);

// Standard input goes through the forwarder untouched.
$req = json_encode(array('line' => 'echo through-stdin', 'ctx' => new stdClass())) . "\n";
$a = run(array($php, "$root/qshell.php", '--exec'), $req, $env);
$b = run(array($php, "$root/bin/qshell.php", '--exec'), $req, $env);
check('qshell.php --exec reads its request from standard input', array($a[0], strpos($a[1], 'through-stdin') !== false), array(0, true));
check('...and answers exactly as bin/qshell.php does', $a, $b);
$bad = run(array($php, "$root/qshell.php", '--exec'), "not json\n", $env);
check('qshell.php --exec with a bad request: its own exit status (2)', $bad[0], 2);

// uwebserver: the binary in sbin/, a forwarder at bin/.
if (is_file("$root/sbin/uwebserver") && is_executable("$root/sbin/uwebserver")) {
	// A binary built before 0.0.4.42 started serving (on 0.0.0.0:8080) on any
	// argument but its about flags, --help and usage errors included, so those
	// are passed only to a binary that has a --help (its usage text is in it).
	$uwCalls = array(array('--version'), array('-V'), array('--about'));
	if (strpos((string) file_get_contents("$root/sbin/uwebserver"), 'Usage: %s [OPTION]...') !== false) {
		$uwCalls[] = array('--help');
		$uwCalls[] = array('--no-such-option');
	}
	foreach ($uwCalls as $args) {
		$a = run(array_merge(array("$root/bin/uwebserver"), $args), '', $env);
		$b = run(array_merge(array("$root/sbin/uwebserver"), $args), '', $env);
		check('bin/uwebserver ' . implode(' ', $args) . ' answers as sbin/uwebserver does', $a, $b);
	}
}
check('the uwebserver sources are in native/uwebserver/', is_file("$root/native/uwebserver/uwebserver.c") && is_file("$root/native/uwebserver/Makefile"), true);
check('...and no longer in bin/', is_file("$root/bin/uwebserver.c"), false);

// ── 2. The note at a terminal, and QBIX_MOVED_QUIET ─────────────────────
$script = is_executable('/usr/bin/script') ? '/usr/bin/script' : null;
if ($script !== null && PHP_OS_FAMILY === 'Linux') {
	// script(1) gives the command a terminal; its output is what the terminal showed.
	$tty = function ($cmd, $quiet) use ($script, $env) {
		$e = $env;
		if ($quiet) $e['QBIX_MOVED_QUIET'] = '1';
		return run(array($script, '-qec', $cmd, '/dev/null'), '', $e);
	};
	$v = escapeshellarg($php) . ' ' . escapeshellarg("$root/qbixctl.php") . ' --version';
	$n = escapeshellarg($php) . ' ' . escapeshellarg("$root/sbin/qbixctl.php") . ' --version';
	check('at a terminal the former path says where the program moved', strpos($tty($v, false)[1], 'qbixctl.php: moved to sbin/qbixctl.php') !== false, true);
	check('...and still runs it', strpos($tty($v, false)[1], 'qbixctl (') !== false, true);
	check('QBIX_MOVED_QUIET=1 silences the note', strpos($tty($v, true)[1], 'moved to') === false, true);
	check('the new path never writes the note', strpos($tty($n, false)[1], 'moved to') === false, true);
	$u = escapeshellarg("$root/bin/uwebserver") . ' --version';
	if (is_executable("$root/sbin/uwebserver")) {
		check('bin/uwebserver says so too at a terminal', strpos($tty($u, false)[1], 'uwebserver: moved to sbin/uwebserver') !== false, true);
		check('...and not with QBIX_MOVED_QUIET=1', strpos($tty($u, true)[1], 'moved to') === false, true);
	}
} else {
	echo "  skip  no script(1) for a terminal\n";
}

// ── 3. The phar: its layout, its stub, both paths ───────────────────────
if (class_exists('Phar')) {
	$p = new Phar("$root/sbin/qbixserver.phar");
	foreach (array('sbin/qbixserver.php', 'sbin/qbixctl.php', 'sbin/qbixconsole.php', 'bin/qshell.php',
		'qbixserver.php', 'qbixctl.php', 'qbixconsole.php', 'qshell.php', 'src/Q.php', 'qbix-build.php') as $f) {
		check("the phar carries $f", isset($p[$f]), true);
	}
	check('the phar\'s stub runs sbin/qbixserver.php', strpos($p->getStub(), "require 'phar://qbixserver.phar/sbin/qbixserver.php';") !== false, true);
	unset($p);
	check('bin/qbixserver.phar is the same file as sbin/qbixserver.phar', sha1_file("$root/bin/qbixserver.phar"), sha1_file("$root/sbin/qbixserver.phar"));
}

// ── 4. Servers, by the former and the new paths ─────────────────────────
if (!function_exists('pcntl_fork') || !is_dir('/proc/self')) {
	echo "  skip  the servers need pcntl and /proc\n";
} else {
	$base = sys_get_temp_dir() . '/qbix-moved-programs-' . getmypid();
	@mkdir("$base/web", 0755, true);
	@mkdir("$base/run", 0755, true);
	@mkdir("$base/log", 0755, true);
	file_put_contents("$base/web/index.php", '<?php header("Content-Type: text/plain"); echo "moved-ok";');
	$free = function () {
		$s = stream_socket_server('tcp://127.0.0.1:0');
		$p = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
		fclose($s);
		return $p;
	};
	$get = function ($port) {
		return @file_get_contents("http://127.0.0.1:$port/", false, stream_context_create(array('http' => array('timeout' => 5))));
	};
	$wait = function ($port) use ($get) {
		for ($i = 0; $i < 80; ++$i) { if ($get($port) === 'moved-ok') return true; usleep(250000); }
		return false;
	};
	$pidOf = function ($file) { return is_file($file) ? (int) trim((string) file_get_contents($file)) : 0; };
	$cmdline = function ($pid) { return explode("\0", rtrim((string) @file_get_contents("/proc/$pid/cmdline"), "\0")); };
	$bg = function (array $cmd, $log) {
		exec('(' . implode(' ', array_map('escapeshellarg', $cmd)) . ' > ' . escapeshellarg($log) . ' 2>&1 < /dev/null &)');
	};
	$common = array('--root=' . "$base/web", '--host=127.0.0.1', '--workers=1', '--distribution=none', '--conf-dir=none');
	$ctlNew = array($php, "$root/sbin/qbixctl.php");
	$ctlOld = array($php, "$root/qbixctl.php");
	Q_WebServer_Ctl::$sourceDir = $root;

	// a. Started by hand from the former path, with a pid file.
	$port = $free();
	$pidFile = "$base/run/old.pid";
	$bg(array_merge(array($php, "$root/qbixserver.php"), $common, array("--port=$port", "--pid=$pidFile")), "$base/log/old.log");
	check('a server started by the former path, qbixserver.php, serves', $wait($port), true);
	$pid1 = $pidOf($pidFile);
	$cl1 = $cmdline($pid1);
	check('...its process names the former path, as pkill patterns expect', $cl1[1] ?? null, "$root/qbixserver.php");
	check('...the pattern "qbixserver.php.*--port=N" matches it', (bool) preg_match("/qbixserver\\.php.*--port=$port/", implode(' ', $cl1)), true);
	$rec = Q_WebServer_StartRecord::read($pidFile, $pid1);
	check('...its start record names the former path', $rec['command'] ?? null, array($php, "$root/qbixserver.php"));
	$found = Q_WebServer_Ctl::discoverServer(array('port' => (string) $port));
	check('qbixctl finds it from the process table, though it starts sbin/qbixserver.php itself', $found['pid'] ?? null, $pid1);
	$r = run(array_merge($ctlNew, array('restart', "--pid=$pidFile")), '', $env);
	$pid2 = $pidOf($pidFile);
	check('sbin/qbixctl.php restart brings it back', array($r[0], $pid2 > 0 && $pid2 !== $pid1), array(0, true));
	if ($r[0]) echo '        ', trim($r[1] . $r[2]), "\n";
	check('...with exactly the command line it was started with, former path included', $cmdline($pid2), $cl1);
	check('...serving', $wait($port), true);
	$r = run(array_merge($ctlOld, array('status', "--pid=$pidFile")), '', $env);
	check('qbixctl.php status (the former path) sees it running', array($r[0], strpos($r[1], (string) $pid2) !== false), array(0, true));
	$r = run(array_merge($ctlNew, array('stop', "--pid=$pidFile")), '', $env);
	check('...and sbin/qbixctl.php stops it', array($r[0], is_file($pidFile)), array(0, false));

	// b. Started by qbixctl (sbin/qbixserver.php), restarted by the former qbixctl.php path.
	$port = $free();
	$pidFile = "$base/run/new.pid";
	$r = run(array_merge($ctlNew, array('start', "--pid=$pidFile", "--port=$port", "--log=$base/log/new.log"), $common), '', $env);
	check('sbin/qbixctl.php start starts a server', $r[0], 0);
	if ($r[0]) echo '        ', trim($r[1] . $r[2]), "\n";
	$pid3 = $pidOf($pidFile);
	$cl3 = $cmdline($pid3);
	check('...by sbin/qbixserver.php', $cl3[1] ?? null, "$root/sbin/qbixserver.php");
	check('...which the pkill pattern "qbixserver.php.*--port=N" matches too', (bool) preg_match("/qbixserver\\.php.*--port=$port/", implode(' ', $cl3)), true);
	check('...serving', $wait($port), true);
	$r = run(array_merge($ctlOld, array('restart', "--pid=$pidFile")), '', $env);
	$pid4 = $pidOf($pidFile);
	check('qbixctl.php restart (the former path) brings it back', array($r[0], $pid4 > 0 && $pid4 !== $pid3), array(0, true));
	if ($r[0]) echo '        ', trim($r[1] . $r[2]), "\n";
	check('...with the same command line', $cmdline($pid4), $cl3);
	check('...serving', $wait($port), true);
	$r = run(array_merge($ctlOld, array('stop', "--pid=$pidFile")), '', $env);
	check('...and qbixctl.php stops it', $r[0], 0);

	// c. By hand from the new path without a pid file: found and restarted from the process table.
	$port = $free();
	$bg(array_merge(array($php, "$root/sbin/qbixserver.php"), $common, array("--port=$port")), "$base/log/bare.log");
	check('a server started by sbin/qbixserver.php without a pid file serves', $wait($port), true);
	$found = Q_WebServer_Ctl::discoverServer(array('port' => (string) $port));
	$pid5 = $found['pid'] ?? 0;
	check('...and is found', $pid5 > 0, true);
	$pidFile = "$base/run/bare.pid";
	$r = run(array_merge($ctlOld, array('restart', "--port=$port", "--pid=$pidFile")), '', $env);
	$pid6 = $pidOf($pidFile);
	check('qbixctl.php restart restarts it from its process table entry', array($r[0], $pid6 > 0 && $pid6 !== $pid5), array(0, true));
	if ($r[0]) echo '        ', trim($r[1] . $r[2]), "\n";
	check('...by the same script', $cmdline($pid6)[1] ?? null, "$root/sbin/qbixserver.php");
	check('...serving', $wait($port), true);
	run(array_merge($ctlNew, array('stop', "--pid=$pidFile")), '', $env);

	// d. Both phar paths serve, and phar://.../qbixserver.php still runs the server.
	if (class_exists('Phar')) {
		foreach (array('bin/qbixserver.phar', 'sbin/qbixserver.phar') as $phar) {
			$port = $free();
			$pidFile = "$base/run/" . basename(dirname($phar)) . '-phar.pid';
			$bg(array_merge(array($php, "$root/$phar"), $common, array("--port=$port", "--pid=$pidFile")), "$base/log/phar.log");
			check("$phar serves", $wait($port), true);
			run(array($php, "$root/$phar", '--stop', "--pid=$pidFile"), '', $env);
		}
		file_put_contents("$base/inside.php", '<?php require ' . var_export('phar://' . "$root/sbin/qbixserver.phar" . '/qbixserver.php', true) . ';');
		$a = run(array($php, "$base/inside.php", '--version'), '', $env);
		// --version names the program it was started as: here the script that required it.
		check('phar://.../qbixserver.php (the former path inside the phar) runs the server', array($a[0], (bool) preg_match('/^inside \(.+\) v\d/', $a[1])), array(0, true));
	}

	foreach (glob('/proc/[0-9]*/cmdline') as $f) {
		if (strpos((string) @file_get_contents($f), $base) !== false and (int) basename(dirname($f)) !== getmypid()) @posix_kill((int) basename(dirname($f)), 9);
	}
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($it as $f) { ($f->isDir() and !$f->isLink()) ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
	@rmdir($base);
}

// ── 5. Finding the programs: this layout, from sbin/, and the former one ─
$E = 'Q_WebServer_Shell_Entry';
check('Entry::dir() beside sbin/qbixserver.php is the engine\'s directory', $E::dir("$root/sbin/qbixserver.php"), $root);
check('Entry::dir() beside the former qbixserver.php is the same', $E::dir("$root/qbixserver.php"), $root);
check('Entry::shell() runs bin/qshell.php', $E::shell("$root/sbin/qbixserver.php"), array(PHP_BINARY, "$root/bin/qshell.php"));
check('Entry::console() runs sbin/qbixconsole.php', last($E::console($root)), "$root/sbin/qbixconsole.php");
check('Entry::console() given sbin/ itself runs the same', last($E::console("$root/sbin")), "$root/sbin/qbixconsole.php");
Q_WebServer_Ctl::$sourceDir = "$root/sbin";
check('Ctl given sbin/ as its source reads the engine\'s directory', Q_WebServer_Ctl::engineDir(), $root);
Q_WebServer_Ctl::$sourceDir = $root;
check('Ctl starts sbin/qbixserver.php', Q_WebServer_Ctl::serverCommand(), array(PHP_BINARY, "$root/sbin/qbixserver.php"));
check('Ctl knows both paths a server of this engine is started by', Q_WebServer_Ctl::serverScripts(), array("$root/sbin/qbixserver.php", "$root/qbixserver.php"));
check('Ctl::pharCopies() pairs sbin/ and bin/', Q_WebServer_Ctl::pharCopies("$root/sbin/qbixserver.phar"), array("$root/sbin/qbixserver.phar", "$root/bin/qbixserver.phar"));

// A tree of the former layout: qshell.php and qbixconsole.php at the top.
$old = sys_get_temp_dir() . '/qbix-moved-programs-old-' . getmypid();
@mkdir("$old/src", 0755, true);
foreach (array('src/Q.php', 'qshell.php', 'qbixconsole.php', 'qbixserver.php') as $f) file_put_contents("$old/$f", "<?php\n");
check('Entry::dir() in a tree of the former layout', $E::dir("$old/qbixserver.php"), $old);
check('Entry::shell() there runs qshell.php', $E::shell("$old/qbixserver.php"), array(PHP_BINARY, "$old/qshell.php"));
check('Entry::console() there runs qbixconsole.php', last($E::console($old)), "$old/qbixconsole.php");
Q_WebServer_Ctl::$sourceDir = $old;
check('Ctl there starts qbixserver.php', Q_WebServer_Ctl::serverScript(), "$old/qbixserver.php");
Q_WebServer_Ctl::$sourceDir = $root;
foreach (array('src/Q.php', 'qshell.php', 'qbixconsole.php', 'qbixserver.php') as $f) @unlink("$old/$f");
@rmdir("$old/src"); @rmdir($old);

if ($fail) { printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
