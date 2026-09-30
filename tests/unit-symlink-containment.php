<?php

/**
 * A link inside the document root does not make the rest of the disk part of
 * the site.
 *
 * pathEscapesRoot() judges the request string, which stops "..", a null byte
 * and their encodings before anything is opened. It cannot stop a symbolic
 * link, because nothing in the request says there is one: "/notes.txt" is an
 * ordinary path whether the name leads to a file or to somewhere else entirely.
 *
 * Without a check on the resolved path, a link inside the root served whatever
 * it pointed at, and -- far worse -- a link named *.php was executed. That
 * turns the ability to create one file into the ability to run code from
 * anywhere the server user can read. Upload directories, shared asset trees and
 * dependency installers all create links, so this needs nobody to be careless
 * in an unusual way.
 *
 * Both halves are checked here, because they are served by different code and
 * only one of them was fixed by the first attempt.
 *
 *   php tests/unit-symlink-containment.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

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

$phar = __DIR__ . '/../sbin/qbixserver.phar';
if (!is_file($phar)) { printf("  skip  no phar built\n"); exit(0); }
if (DIRECTORY_SEPARATOR !== '/') { printf("  skip  needs POSIX symlinks\n"); exit(0); }

$base = sys_get_temp_dir() . DS . 'qbix-symlink-' . getmypid();
$root = $base . DS . 'web';
$outside = $base . DS . 'private';
@mkdir($root . DS . 'sub', 0700, true);
@mkdir($outside, 0700, true);

file_put_contents($root . DS . 'index.php', '<?php echo "INDEX";');
file_put_contents($root . DS . 'sub' . DS . 'ok.txt', 'INSIDE');
file_put_contents($outside . DS . 'secret.txt', 'OUTSIDE-SECRET');
file_put_contents($outside . DS . 'evil.php', '<?php echo "EXECUTED-OUTSIDE";');

// The links. Both live inside the root; both lead out of it.
@symlink($outside . DS . 'secret.txt', $root . DS . 'leak.txt');
@symlink($outside . DS . 'evil.php', $root . DS . 'leak.php');
// And one that stays inside, which must keep working: serving through links
// is ordinary, and a fix that forbids all of them breaks real installations.
@symlink($root . DS . 'sub' . DS . 'ok.txt', $root . DS . 'inside.txt');

if (!is_link($root . DS . 'leak.txt')) {
	printf("  skip  this filesystem does not support symlinks\n");
	exit(0);
}

register_shutdown_function(function () use ($base, $root, $outside) {
	foreach (array($root . '/leak.txt', $root . '/leak.php', $root . '/inside.txt',
		$root . '/index.php', $root . '/sub/ok.txt',
		$outside . '/secret.txt', $outside . '/evil.php') as $f) @unlink($f);
	@rmdir($root . '/sub'); @rmdir($root); @rmdir($outside); @rmdir($base);
});

$port = 0;
for ($i = 0; $i < 40; ++$i) {
	$p = 19200 + random_int(0, 600);
	$probe = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
	if ($probe) { fclose($probe); $port = $p; break; }
}
if (!$port) { fwrite(STDERR, "  FAIL - no free port\n"); exit(1); }

$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phar)
	. ' --root=' . escapeshellarg($root) . ' --port=' . $port . ' --workers=2';
// No shell redirection: `cmd > log 2>&1` makes proc_open start a shell,
// so the pid it reports is the shell's and terminating it leaves the
// server running. Three processes leaked per run, and a day of runs
// left thirty-nine of them on this machine. Descriptors do the same
// redirection without a shell in between.
$descriptors = array(
	0 => array('file', '/dev/null', 'r'),
	1 => array('file', $base . '/log', 'w'),
	2 => array('file', $base . '/log', 'a'),
);
$proc = proc_open($cmd, $descriptors, $pipes);
if (!is_resource($proc)) { fwrite(STDERR, "  FAIL - could not start\n"); exit(1); }
register_shutdown_function(function () use ($proc) {
	$st = @proc_get_status($proc);
	if (!$st) { @proc_close($proc); return; }
	$pid = (int) ($st['pid'] ?? 0);
	if (!empty($st['running']) and $pid > 0) {
		// Ask, wait, then insist. The server reaps its own workers on SIGTERM,
		// so the parent going is enough -- but only if it actually goes.
		@proc_terminate($proc, 15);
		for ($i = 0; $i < 40; ++$i) {
			$now = @proc_get_status($proc);
			if (!$now or empty($now['running'])) break;
			usleep(250000);
		}
		$now = @proc_get_status($proc);
		if ($now and !empty($now['running'])) {
			@proc_terminate($proc, 9);
			if (function_exists('posix_kill')) @posix_kill($pid, 9);
		}
	}
	@proc_close($proc);
});

$up = false;
for ($i = 0; $i < 60; ++$i) {
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 1);
	if ($s) { fclose($s); $up = true; break; }
	usleep(500000);
}
if (!$up) {
	fwrite(STDERR, "  FAIL - server never listened\n");
	fwrite(STDERR, (string) @file_get_contents($base . '/log'));
	exit(1);
}

/** @return array status and body */
function fetch($path, $port)
{
	$ctx = stream_context_create(array('http' => array(
		'ignore_errors' => true, 'timeout' => 10,
	)));
	$body = @file_get_contents("http://127.0.0.1:$port" . $path, false, $ctx);
	$status = 0;
	foreach ($http_response_header ?? array() as $h) {
		if (strpos($h, 'HTTP/') === 0) {
			$bits = explode(' ', $h);
			if (isset($bits[1])) $status = (int) $bits[1];
		}
	}
	return array($status, (string) $body);
}

// ── What must still work ────────────────────────────────────────

list($st, $body) = fetch('/sub/ok.txt', $port);
check('an ordinary file is served', $st, 200);
check('...with its own contents', trim($body), 'INSIDE');

list($st, $body) = fetch('/index.php', $port);
check('an ordinary script runs', $st, 200);
check('...and produces its output', trim($body), 'INDEX');

// Serving through a link that stays inside the root is ordinary and common --
// a theme directory, a shared asset tree -- and must not be collateral damage.
list($st, $body) = fetch('/inside.txt', $port);
check('a link that stays inside the root still works', $st, 200);
check('...and serves the file it points at', trim($body), 'INSIDE');

// ── What must not ───────────────────────────────────────────────

list($st, $body) = fetch('/leak.txt', $port);
check('a link out of the root is refused', $st, 403);
check('...and its contents do not appear',
	strpos($body, 'OUTSIDE-SECRET') === false, true);

list($st, $body) = fetch('/leak.php', $port);
check('a script linked out of the root is refused', $st, 403);
check('...and is not executed',
	strpos($body, 'EXECUTED-OUTSIDE') === false, true);

// ── The escape hatch is real ────────────────────────────────────

$src = file_get_contents(__DIR__ . '/../src/Q/WebServer.php');
check('following links can be turned back on by configuration',
	(bool) preg_match("/followSymlinks/", $src), true);
check('the containment is asked on the resolved path, not the request',
	(bool) preg_match('/function insideRoot.*?realpath\(\$fsPath\)/s', $src), true);
check('and it requires a separator after the root, so a sibling is outside',
	(bool) preg_match('/strncmp\(\$real, \$root \. DIRECTORY_SEPARATOR/', $src), true);

if ($fail) {
	printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	printf("  server log:\n");
	foreach (array_slice(explode("\n",
		(string) @file_get_contents($base . '/log')), -15) as $l) printf("    %s\n", $l);
	exit(1);
}
printf("  PASS - %d case(s)\n", $pass);
