#!/usr/bin/env php
<?php
/**
 * uwebserver as a process: worker processes that are replaced when one
 * ends, a pid file that is locked while it runs, --daemon that returns only
 * once the server serves (or with its error), dropping to --user/--group
 * after binding and --chroot, the access log in its three formats with a
 * client's bytes escaped, the error log, --quiet, and a clean stop on
 * SIGTERM.
 *
 *   php tests/unit-uwebserver-process.php
 */
require __DIR__ . '/uwebserver-helpers.php';

$bin = uw_build();
if ($bin === null) uw_skip('no C compiler or OpenSSL headers');
check('uwebserver builds', is_string($bin));
if (!is_string($bin)) uw_finish();
$d = uw_dir();
chmod($d, 0755);
mkdir("$d/www", 0755);
file_put_contents("$d/www/index.html", "proc\n");
chmod("$d/www/index.html", 0644);
$isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;

function children($pid)
{
	$f = "/proc/$pid/task/$pid/children";
	return is_file($f) ? array_values(array_filter(array_map('intval', preg_split('/\s+/', trim((string) file_get_contents($f)))))) : array();
}
function wait_for($fn, $secs = 5)
{
	$t = microtime(true);
	while (microtime(true) - $t < $secs) { if ($fn()) return true; usleep(50000); }
	return false;
}

// ── Workers ─────────────────────────────────────────────────────────────
$port = uw_free_port();
$srv = uw_start($bin, array_merge(uw_root_args(), array("--root=$d/www", "--port=$port", '--workers=3', "--error-log=$d/w.log")), $port);
check('--workers=3 serves', $srv !== null);
check('...with three worker processes', wait_for(function () use ($srv) { return count(children($srv['pid'])) === 3; }));
$kids = children($srv['pid']);
$ok = 0;
for ($i = 0; $i < 20; $i++) if (uw_get($port, '/')[0] === 200) $ok++;
check('...which answer', $ok, 20);
if ($kids) {
	posix_kill($kids[0], 9);
	check('a worker that dies is replaced', wait_for(function () use ($srv, $kids) { $k = children($srv['pid']); return count($k) === 3 && !in_array($kids[0], $k, true); }));
	check('...and the log says so', wait_for(function () use ($d) { return strpos((string) file_get_contents("$d/w.log"), 'ended (signal 9); starting another') !== false; }));
}
$code = uw_stop($srv);
check('SIGTERM stops the parent, exit 0', $code, 0);
check('...and every worker with it', array_filter($kids, function ($p) { return $p && file_exists("/proc/$p") && strpos((string) @file_get_contents("/proc/$p/cmdline"), 'uwebserver') !== false; }), array());

// ── The pid file ────────────────────────────────────────────────────────
$port = uw_free_port();
$pidf = "$d/u.pid";
$srv = uw_start($bin, array_merge(uw_root_args(), array("--port=$port", "--pid-file=$pidf")), $port);
check('--pid-file: written with the process id', trim((string) @file_get_contents($pidf)), (string) $srv['pid']);
check('...readable by all, writable by the owner only', sprintf('%o', fileperms($pidf) & 0777), '644');
$p2 = uw_free_port();
list($rc, $out, $err) = uw_run($bin, array_merge(uw_root_args(), array("--port=$p2", "--pid-file=$pidf")), 3);
check('a second server with the same pid file: exit 1', $rc, 1);
check('...naming the one that holds it', strpos($err, "held by another running uwebserver (pid {$srv['pid']})") !== false);
check('...and it did not start serving', uw_listening($p2), false);
uw_stop($srv);
check('the pid file is removed on a clean stop', file_exists($pidf), false);
symlink("$d/elsewhere", "$d/link.pid");
list($rc, $out, $err) = uw_run($bin, array_merge(uw_root_args(), array('--port=' . uw_free_port(), "--pid-file=$d/link.pid")), 3);
check('a pid file that is a symbolic link is refused', array($rc, file_exists("$d/elsewhere")), array(1, false));

// ── --daemon ────────────────────────────────────────────────────────────
$port = uw_free_port();
list($rc, $out, $err, $secs) = uw_run($bin, array_merge(uw_root_args(), array("--port=$port", '--daemon', "--pid-file=$d/d.pid", "--error-log=$d/d.log")), 5);
check('--daemon returns 0 once it serves', array($rc, uw_listening($port)), array(0, true));
$dpid = (int) @file_get_contents("$d/d.pid");
check('...its pid file names the daemon', $dpid > 1 && file_exists("/proc/$dpid"));
check('...which has left our session (no terminal)', $dpid && posix_getsid($dpid) !== false && posix_getsid($dpid) !== posix_getsid(0));
if ($dpid > 1) posix_kill($dpid, 15);
check('...and stops on SIGTERM', wait_for(function () use ($port) { return !uw_listening($port); }));
list($rc, $out, $err) = uw_run($bin, array_merge(uw_root_args(), array("--port=$port", '--daemon', "--pid-file=$d/no/such/dir/d.pid")), 5);
check('--daemon that cannot start: its exit status is 1, not 0', $rc, 1);

// ── --user, --group, --chroot ───────────────────────────────────────────
if ($isRoot && ($pw = posix_getpwnam('nobody'))) {
	$port = uw_free_port();
	$srv = uw_start($bin, array("--root=$d/www", "--port=$port", '--user=nobody', "--error-log=$d/u.log"), $port);
	check('--user=nobody: serves', $srv !== null && uw_get($port, '/')[0] === 200);
	$status = (string) @file_get_contents("/proc/{$srv['pid']}/status");
	check('...running as nobody: real, effective and saved uid', (bool) preg_match('/^Uid:\s+' . $pw['uid'] . '\s+' . $pw['uid'] . '\s+' . $pw['uid'] . '\s/m', $status));
	check('...and gid', (bool) preg_match('/^Gid:\s+' . $pw['gid'] . '\s+' . $pw['gid'] . '\s+' . $pw['gid'] . '\s/m', $status));
	check('...with no supplementary group but its own', (bool) preg_match('/^Groups:\s*' . $pw['gid'] . '\s*$/m', $status));
	uw_stop($srv);
	$jail = "$d/jail";
	mkdir("$jail/www", 0755, true);
	file_put_contents("$jail/www/index.html", "jailed\n");
	$port = uw_free_port();
	$srv = uw_start($bin, array("--root=$jail/www", "--port=$port", '--user=nobody', "--chroot=$jail"), $port);
	check('--chroot with --user: serves the root opened before it', $srv !== null && uw_get($port, '/')[2] === "jailed\n");
	check('...its root directory is the jail', @readlink("/proc/{$srv['pid']}/root"), $jail);
	uw_stop($srv);
} else {
	echo "  skip  not root, or no user nobody: --user and --chroot\n";
}
if (!$isRoot) {
	list($rc, $out, $err) = uw_run($bin, array('--port=' . uw_free_port(), '--user=nobody'), 3);
	check('--user as another user than root: exit 1', $rc, 1);
}

// ── Logs ────────────────────────────────────────────────────────────────
foreach (array('combined', 'common', 'json') as $fmt) {
	$port = uw_free_port();
	$log = "$d/access-$fmt.log";
	$srv = uw_start($bin, array_merge(uw_root_args(), array("--root=$d/www", "--port=$port", "--access-log=$log", "--access-log-format=$fmt")), $port);
	uw_get($port, '/', array('User-Agent' => "evil\"agent\\ x", 'Referer' => 'http://ref/'));
	uw_raw($port, "GET /%0d%0aFAKE HTTP/1.1\r\nHost: l\r\nUser-Agent: a\xffb\r\nConnection: close\r\n\r\n");
	uw_raw($port, "garbage\r\n\r\n");
	wait_for(function () use ($log) { return substr_count((string) @file_get_contents($log), "\n") >= 3; }, 3);
	uw_stop($srv);
	$lines = explode("\n", trim((string) @file_get_contents($log)));
	check("$fmt: one line per request, three", count($lines), 3);
	$all = implode("\n", $lines);
	check("$fmt: a client's bytes never break a line", count(preg_grep('/FAKE/', $lines)) <= 1 && strpos($all, "\x01") === false && strpos($all, "\xff") === false);
	if ($fmt === 'json') {
		$ok = true;
		foreach ($lines as $l) { $j = json_decode($l, true); if (!is_array($j) || !isset($j['status'], $j['remote'], $j['bytes'])) $ok = false; }
		check('json: every line is a JSON object', $ok);
		$j = json_decode($lines[0], true);
		check('json: the fields', array($j['method'] ?? null, $j['target'] ?? null, $j['status'] ?? null, $j['bytes'] ?? null, $j['remote'] ?? null),
			array('GET', '/', 200, 5, '127.0.0.1'));
		check('json: a quote and a backslash of the client, escaped', ($j['user_agent'] ?? null), 'evil\\x22agent\\x5c x');
		check('json: a malformed request is logged as 400', (json_decode($lines[2], true)['status'] ?? null), 400);
	} else {
		check("$fmt: the request line and status", (bool) preg_match('#^127\.0\.0\.1 - - \[\d\d/\w{3}/\d{4}:\d\d:\d\d:\d\d \+0000\] "GET / HTTP/1\.1" 200 5#', $lines[0]));
		if ($fmt === 'combined') check('combined: Referer and User-Agent, the quote and backslash escaped', strpos($lines[0], '"http://ref/" "evil\\x22agent\\x5c x"') !== false);
		check("$fmt: bytes outside printable ASCII as \\xHH", strpos($lines[1], 'a\\xffb') !== false || $fmt === 'common');
		check("$fmt: a malformed request as 400", (bool) preg_match('#"- " 400 #', $lines[2]) || (bool) preg_match('#" 400 #', $lines[2]));
	}
}
$port = uw_free_port();
$srv = uw_start($bin, array_merge(uw_root_args(), array("--port=$port", "--error-log=$d/e.log")), $port);
uw_stop($srv);
$e = (string) @file_get_contents("$d/e.log");
check('--error-log: the start and the stop', (bool) preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ uwebserver\[\d+\]: notice: serving the built-in answers on http:\/\/127\.0\.0\.1:' . $port . '$/m', $e) && strpos($e, 'notice: stopped after') !== false);
check('--error-log file is not readable by others', sprintf('%o', fileperms("$d/e.log") & 0007), '0');
$port = uw_free_port();
$srv = uw_start($bin, array_merge(uw_root_args(), array("--port=$port", '--quiet')), $port);
uw_stop($srv);
check('--quiet: no notices', (string) @file_get_contents($srv['err']), '');

uw_finish();
