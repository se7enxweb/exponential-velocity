<?php

/**
 * Q.webserver.instances forks its servers from one process, so they share one
 * APCu segment and one opcode cache (Q_WebServer_Instances).
 *
 * Asserted:
 *   - Q_WebServer_Instances::path() names instance files the way a launcher
 *     names separately started instances (server.pid, server.1.pid ...);
 *   - a server started with Q.webserver.instances = 2 runs two servers on the
 *     one port, each with its own pid file, neither the parent of the other;
 *   - requests over many connections reach both, and an APCu counter both
 *     increment counts every request once: one segment, not one per server;
 *   - each server knows its number (QBIX_FORKED_INSTANCE, which its workers
 *     inherit), and its start record carries it, so a restart brings back
 *     that instance;
 *   - SIGHUP re-executes an instance as the same instance: the set stays at
 *     two servers, and its pid file still names it;
 *   - SIGTERM to the first ends the first only; the second still answers.
 *
 *   php tests/unit-instances-share-memory.php
 */

require __DIR__ . '/fixtures/race-harness.php';
require_once __DIR__ . '/../src/Q/WebServer/Instances.php';

$I = 'Q_WebServer_Instances';
check('instance 0 keeps the name', $I::path('/run/server.pid', 0), '/run/server.pid');
check('the number goes before the extension', $I::path('/run/server.pid', 2), '/run/server.2.pid');
check('appended without an extension', $I::path('/run/server', 1), '/run/server.1');
check('a dot file gets it appended', $I::path('/run/.pid', 1), '/run/.pid.1');
check('a name in the current directory', $I::path('server.pid', 3), 'server.3.pid');
check('the count is at least 1', $I::count('0'), 1);
check('and at most 64', $I::count(1000), 64);

if (!function_exists('pcntl_fork') or !function_exists('posix_setsid') or !function_exists('apcu_inc')
	or !ini_get('apc.enable_cli')) {
	printf("  skip  end to end: needs pcntl, posix and APCu for the command line (apc.enable_cli)\n");
	rh_finish();
}

list($base, $root) = rh_setup('instances');
file_put_contents("$root/index.php", '<?php
apcu_add("unit-instances", 0);
echo json_encode(array("n" => apcu_inc("unit-instances"), "instance" => getenv("QBIX_FORKED_INSTANCE"),
	"server" => posix_getppid()));');
putenv('QBIX_FORKED_INSTANCE');
$port = rh_start('two', array('Q' => array('webserver' => array('instances' => 2, 'forkPerRequest' => false,
	'cache' => false))), 2);

$first = rh_server_pid('two');
$pidFile = "$base/two.pid";
$secondFile = "$base/two.1.pid";
$deadline = microtime(true) + 20;
while (!is_file($secondFile) and microtime(true) < $deadline) usleep(100000);
$second = (int) @file_get_contents($secondFile);
check('the first writes the pid file it was given', (int) @file_get_contents($pidFile), $first);
check('the second writes its own', $second > 0 and $second !== $first, true);
$ppid = function ($pid) {
	$s = (string) @file_get_contents("/proc/$pid/stat");
	$rest = explode(' ', substr($s, (int) strrpos($s, ')') + 2));
	return (int) ($rest[1] ?? 0);
};
check('the second is not a child of the first', $ppid($second) !== $first, true);
$record = json_decode((string) @file_get_contents("$base/two.1.pid.json"), true);
check('its start record carries the number', $record['env']['QBIX_FORKED_INSTANCE'] ?? null, '1');

// both listen before requests go out: the second may still be preparing
$deadline = microtime(true) + 20;
// whether $pid holds a listening socket on the port (rh_tcp_held() counts connections)
$held = function ($pid) use ($port) {
	$inodes = array();
	foreach (array('/proc/net/tcp', '/proc/net/tcp6') as $f) {
		foreach (array_slice(explode("\n", (string) @file_get_contents($f)), 1) as $l) {
			$c = preg_split('/\s+/', trim($l));
			if (count($c) < 10) continue;
			$local = explode(':', $c[1]);
			if (hexdec(end($local)) === $port and $c[3] === '0A') $inodes[$c[9]] = true;
		}
	}
	foreach (glob("/proc/$pid/fd/*") ?: array() as $fd) {
		$t = @readlink($fd);
		if ($t and preg_match('/^socket:\[(\d+)\]$/', $t, $m) and isset($inodes[$m[1]])) return true;
	}
	return false;
};
while (!($held($first) and $held($second)) and microtime(true) < $deadline) usleep(200000);
check('both servers hold the port', $held($first) and $held($second), true);

$requests = array();
for ($i = 0; $i < 60; $i++) $requests[] = array('path' => '/index.php');
$seen = array();
$ns = array();
foreach (rh_many($port, $requests, 6) as $r) {
	$j = json_decode((string) $r['body'], true);
	if (!is_array($j)) continue;
	$seen[(string) $j['instance']] = true;
	$ns[] = (int) $j['n'];
}
ksort($seen);
check('every request answered', count($ns), 60);
check('the connections reached both servers', array_keys($seen), array(0, 1));
sort($ns);
check('one APCu for both: the counter counts every request once', $ns, range(1, 60));

// a reload re-executes the second as the second, and forks nothing more
posix_kill($second, SIGHUP);
usleep(500000);
$deadline = microtime(true) + 20;
while (!$held($second) and microtime(true) < $deadline) usleep(200000);
check('after a reload the second is still the second', (int) @file_get_contents($secondFile), $second);
check('and holds the port again', $held($second), true);
$servers = 0;
foreach (glob('/proc/[0-9]*/cmdline') ?: array() as $f) {
	$cmd = (string) @file_get_contents($f);
	if (strpos($cmd, "$base/two.json") !== false and strpos($cmd, 'qbixserver: zygote') === false
		and strpos($cmd, 'sbin/qbixserver.php') !== false) {
		$p = (int) basename(dirname($f));
		if ($held($p)) $servers++;
	}
}
check('still two servers on the port', $servers, 2);

// ending the first leaves the second
posix_kill($first, SIGTERM);
$deadline = microtime(true) + 20;
$running = function () { $st = @proc_get_status($GLOBALS['rh']['servers']['two']); return $st and !empty($st['running']); };
while ($running() and microtime(true) < $deadline) usleep(200000);
check('the first has ended', $running(), false);
$r = rh_get($port, '/index.php');
$j = json_decode((string) $r['body'], true);
check('the second still answers', $j['instance'] ?? null, '1');

posix_kill($second, SIGTERM);
$deadline = microtime(true) + 20;
while (@posix_kill($second, 0) and microtime(true) < $deadline) usleep(200000);
if (@posix_kill($second, 0)) posix_kill($second, SIGKILL);
check('the second ends on SIGTERM', @posix_kill($second, 0), false);

rh_finish();
