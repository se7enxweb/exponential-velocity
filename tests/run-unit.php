#!/usr/bin/env php
<?php

/**
 * Every self-contained test, run in one go.
 *
 * These need no server, no network, no certificate and no fixture directory:
 * each drives a class directly and asserts on what it returns. They are the
 * tests that can run in CI on a machine with nothing installed but PHP, and
 * the ones that fail fast enough to run before a commit.
 *
 * tests/run.sh covers the other half -- a real server, real sockets, real
 * requests -- and calls this first, because there is no sense starting a
 * server to find out the frame codec is broken.
 *
 *   php tests/run-unit.php
 *   php tests/run-unit.php hpack        only tests whose name contains "hpack"
 *   php tests/run-unit.php --timeout=60 a test running longer than 60s fails
 *
 * Every test has a time limit, 300 seconds unless --timeout or the
 * QBIX_UNIT_TIMEOUT environment variable says otherwise; 0 means none. A test
 * that waits for something that never comes -- a TLS connection to a server
 * that could not load its certificate, say -- used to hang the whole run with
 * it, with no word of which one it was. It is now reported as TIMEOUT with its
 * output so far, and stopped together with any server it started: each test
 * runs as the leader of its own process group, and the group is ended.
 *
 * Exits non-zero if any test fails, so it can gate a merge.
 */

$dir = __DIR__;
$filter = '';
$timeout = getenv('QBIX_UNIT_TIMEOUT') !== false ? (int) getenv('QBIX_UNIT_TIMEOUT') : 300;
foreach (array_slice($argv, 1) as $arg) {
	if (preg_match('/^--timeout=(\d+)$/', $arg, $m)) $timeout = (int) $m[1];
	else $filter = $arg;
}

// Self-contained by construction: unit-*.php, plus the http2-*.php cases that
// predate the naming and are equally standalone. http2-serve.php is excluded
// deliberately -- it is a proving ground that wants a TLS certificate and a
// client, not a test.
$files = array_merge(
	glob($dir . '/unit-*.php') ?: array(),
	array_filter(glob($dir . '/http2-*.php') ?: array(), function ($f) {
		return basename($f) !== 'http2-serve.php';
	}),
	// Named explicitly, because it does not follow the naming convention and
	// would otherwise be picked up by nothing that runs before a commit.
	//
	// It checks that sbin/qbixserver.phar was built from the sources beside it
	// (and that bin/qbixserver.phar, its former path, is the same file).
	// The archive is committed and is what a Composer install runs, so a stale
	// one ships working source and broken behaviour to anyone who runs from the
	// archive rather than from src/. That happened: a release was cut whose
	// source carried a fix and whose archive did not, and it had to be
	// withdrawn.
	//
	// The release workflow already gates on this, which is the real safety net.
	// This adds the same check to the suite a developer runs locally, so the
	// drift is visible before a commit rather than at the point of release.
	// It needs no server and no network, which is this runner's criterion.
	array_filter(array($dir . '/phar-is-current.php'), 'file_exists')
);
sort($files);

if ($filter !== '') {
	$files = array_filter($files, function ($f) use ($filter) {
		return stripos(basename($f), $filter) !== false;
	});
}

if (!$files) {
	fwrite(STDERR, "  no tests matched\n");
	exit(2);
}

$pass = 0;
$fail = 0;
$failed = array();
$started = microtime(true);

echo "\n";
foreach ($files as $file) {
	$name = basename($file, '.php');
	$began = microtime(true);

	list($status, $output, $timedOut) = runTest($file, $timeout);
	$ms = (microtime(true) - $began) * 1000;

	// Each test prints its own cases; report the count it claims so a test
	// that silently stops asserting is visible as a number that fell.
	$cases = '';
	foreach ($output as $line) {
		if (preg_match('/PASS - (\d+) case/', $line, $m)) { $cases = $m[1] . ' cases'; break; }
		if (preg_match('/PASS - (.+)$/', $line, $m)) { $cases = trim($m[1]); break; }
	}

	if ($status === 0) {
		++$pass;
		printf("  \033[32mok\033[0m    %-28s %6.0f ms  %s\n", $name, $ms, $cases);
	} else if ($timedOut) {
		++$fail;
		$output[] = sprintf('(stopped after %d seconds)', $timeout);
		$failed[$name] = $output;
		printf("  \033[31mTIMEOUT\033[0m %-26s %6.0f ms\n", $name, $ms);
	} else {
		++$fail;
		$failed[$name] = $output;
		printf("  \033[31mFAIL\033[0m  %-28s %6.0f ms\n", $name, $ms);
	}
}

if ($failed) {
	echo "\n";
	foreach ($failed as $name => $output) {
		echo "  ── $name ──\n";
		foreach (array_slice($output, -25) as $line) echo "     $line\n";
		echo "\n";
	}
}

printf("\n  %d passed, %d failed, %.1fs\n\n",
	$pass, $fail, microtime(true) - $started);

exit($fail === 0 ? 0 : 1);

/**
 * Runs one test file and returns array(exit status, output lines, timed out).
 *
 * The output goes to a file rather than a pipe, so a test that prints a lot
 * can never block on a pipe nobody is reading while this waits for it.
 */
function runTest($file, $timeout)
{
	$log = tempnam(sys_get_temp_dir(), 'qbix-unit-');
	$cmd = array(PHP_BINARY, $file);
	// Its own process group, so that ending it ends every server it started.
	// setsid only forks when the caller already leads a group, which a child
	// of proc_open() does not: the pid is the test's, and the group's.
	$group = function_exists('posix_kill') && is_executable('/usr/bin/setsid');
	if ($group) array_unshift($cmd, '/usr/bin/setsid');

	// One handle for both, so that neither writes over what the other wrote
	$out = fopen($log, 'w');
	$proc = proc_open($cmd, array(0 => array('file', '/dev/null', 'r'), 1 => $out, 2 => $out), $pipes);
	fclose($out);
	if (!is_resource($proc)) {
		@unlink($log);
		return array(1, array('could not start ' . $file), false);
	}

	$pid = proc_get_status($proc)['pid'];
	$deadline = $timeout > 0 ? microtime(true) + $timeout : INF;
	$status = null;
	while (true) {
		$info = proc_get_status($proc);
		if (!$info['running']) { $status = $info['exitcode']; break; }
		if (microtime(true) >= $deadline) break;
		usleep(50000);
	}

	$timedOut = $status === null;
	if ($timedOut) {
		stopTest($proc, $pid, $group);
	}
	proc_close($proc);

	$output = file_exists($log) ? explode("\n", rtrim((string) file_get_contents($log), "\n")) : array();
	@unlink($log);
	return array($timedOut ? 124 : (int) $status, $output, $timedOut);
}

/**
 * Ends a test that ran out of time: politely, then for certain after five
 * seconds. With a process group of its own, everything it started goes too.
 */
function stopTest($proc, $pid, $group)
{
	$signal = function ($sig) use ($proc, $pid, $group) {
		if ($group) @posix_kill(-$pid, $sig);
		else @proc_terminate($proc, $sig);
	};
	$signal(15);
	for ($i = 0; $i < 100; ++$i) {
		if (!proc_get_status($proc)['running'] and (!$group or !@posix_kill(-$pid, 0))) return;
		usleep(50000);
	}
	$signal(9);
}
