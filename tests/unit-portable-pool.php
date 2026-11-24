<?php

/**
 * The portable worker pool (Q.webserver.poolMode "portable"): workers started
 * with proc_open() instead of fork(), for Windows and any PHP without pcntl.
 *
 * Run on Linux, where it is chosen by setting; nothing here proves it on
 * Windows (see docs/windows.md and tests/windows/).
 *
 * Without a server:
 *
 *   - the control-pipe protocol: READY <pid>, DONE <requests>, EXIT <code>,
 *     one ASCII line each; anything else is not the protocol;
 *   - a worker's greeting on its socket: QPW1 <32 hex token> <pid>;
 *   - the delay after boot failures (0.5 s doubling, at most 30 s) and how
 *     many workers the supervisor wants (fixed and dynamic pools);
 *   - the worker's command line (php, -c php.ini, the server's own argv; a
 *     static binary; a configured command);
 *   - Q_WebServer_Pool::mode(): auto stays fork where fork exists, portable
 *     is taken when asked for;
 *   - Q_WebServer_Process: NUL or /dev/null, absolute Windows paths,
 *     tasklist output, stop/reload request files, liveness;
 *   - the supervisor's bookkeeping: a greeting with the right token attaches
 *     the worker and serves the queue; a wrong token, a second greeting for
 *     the same worker, or a line too long are refused.
 *
 * With a real server started with --pool-mode=portable and two workers:
 *
 *   - pages are served by worker processes that are not the server, were
 *     not forked from it (their environment names the pool) and are reused;
 *   - a worker holds exactly one socket (its connection to the server), not
 *     the listener or visitors' connections;
 *   - after Q.webserver.maxRequests requests a worker is replaced;
 *   - a worker killed outright is replaced and nothing is lost;
 *   - a script that dies with a fatal error answers 500 and the pool goes on;
 *   - a stop asked for by request file (the Windows path of --stop) ends the
 *     server and every worker.
 *
 *   php tests/unit-portable-pool.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require_once __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/Evented.php';
require_once __DIR__ . '/../src/Q/WebServer/Process.php';
require_once __DIR__ . '/../src/Q/WebServer/Pool.php';
require_once __DIR__ . '/../src/Q/WebServer/PortablePool.php';
require_once __DIR__ . '/../src/Q/WebServer/Ctl.php';

$pass = 0;
$fail = 0;
function pp_check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

$P = 'Q_WebServer_PortablePool';

// ── Control-pipe protocol ───────────────────────────────────────────

pp_check('READY <pid> parses', $P::parseControlLine("READY 4242\n"), array('READY', 4242));
pp_check('DONE <requests> parses', $P::parseControlLine('DONE 17'), array('DONE', 17));
pp_check('EXIT <code> parses, CRLF too', $P::parseControlLine("EXIT 255\r\n"), array('EXIT', 255));
pp_check('a written line reads back', $P::parseControlLine($P::controlLine('DONE', 3)), array('DONE', 3));
pp_check('...and is one ASCII line', $P::controlLine('READY', 7), "READY 7\n");
pp_check('lower case is not the protocol', $P::parseControlLine('ready 1'), null);
pp_check('a word without a number is not', $P::parseControlLine('READY'), null);
pp_check('text an application printed is not', $P::parseControlLine('Hello READY 1'), null);
pp_check('two spaces are not', $P::parseControlLine('DONE  1'), null);

// ── Greeting ───────────────────────────────────────────────────────

$tok = str_repeat('ab', 16);
pp_check('a greeting parses', $P::parseHello("QPW1 $tok 99\n"), array('token' => $tok, 'pid' => 99));
pp_check('a short token is refused', $P::parseHello('QPW1 abcd 99'), null);
pp_check('upper-case hex is refused (tokens are lower case)', $P::parseHello('QPW1 ' . strtoupper($tok) . ' 99'), null);
pp_check('another protocol word is refused', $P::parseHello("QPW2 $tok 99"), null);
pp_check('trailing bytes are refused', $P::parseHello("QPW1 $tok 99 x"), null);

// ── Supervisor arithmetic ───────────────────────────────────────────

pp_check('no failures, no delay', $P::backoffDelay(0), 0.0);
pp_check('first failure: 0.5 s', $P::backoffDelay(1), 0.5);
pp_check('then doubling: 1 s', $P::backoffDelay(2), 1.0);
pp_check('...4 s', $P::backoffDelay(4), 4.0);
pp_check('...at most 30 s', $P::backoffDelay(9), 30.0);
pp_check('...even after very many', $P::backoffDelay(500), 30.0);

pp_check('fixed pool wants its size', $P::wantedSize(4, 0, 1, 0, 0), 4);
pp_check('dynamic pool wants its spare count', $P::wantedSize(8, 2, 0, 0, 0), 2);
pp_check('dynamic pool grows for waiting requests', $P::wantedSize(8, 2, 2, 3, 0), 5);
pp_check('...not for those a starting worker will take', $P::wantedSize(8, 2, 3, 1, 1), 2);
pp_check('...and never past its size', $P::wantedSize(4, 2, 4, 9, 0), 4);

// ── Worker command line ─────────────────────────────────────────────

$argv = array('/srv/vc/sbin/qbixserver.php', '--root=/srv/web', '--workers=2');
pp_check('php, its ini and the server\'s argv',
	$P::workerCommand($argv, '/usr/bin/php', 'cli', '/etc/php.ini'),
	array('/usr/bin/php', '-c', '/etc/php.ini', '/srv/vc/sbin/qbixserver.php', '--root=/srv/web', '--workers=2'));
pp_check('no ini file: no -c',
	$P::workerCommand($argv, 'C:\\php\\php.exe', 'cli', false),
	array('C:\\php\\php.exe', '/srv/vc/sbin/qbixserver.php', '--root=/srv/web', '--workers=2'));
pp_check('PHP options from Q.webserver.portable.phpArgs come before the script',
	$P::workerCommand($argv, 'php', 'cli', false, null, array('-d', 'memory_limit=512M')),
	array('php', '-d', 'memory_limit=512M', '/srv/vc/sbin/qbixserver.php', '--root=/srv/web', '--workers=2'));
pp_check('a static binary (micro) is its own script',
	$P::workerCommand(array('vc.exe', '--port=8080'), 'C:\\vc\\vc.exe', 'micro', false),
	array('C:\\vc\\vc.exe', '--port=8080'));
pp_check('Q.webserver.portable.command replaces it all',
	$P::workerCommand($argv, 'php', 'cli', '/etc/php.ini', array('C:\\php\\php.exe', 'w.php', 5)),
	array('C:\\php\\php.exe', 'w.php', '5'));

pp_check('the server\'s own interpreter line (with its -d settings) is used when known',
	$P::workerCommand($argv, '/usr/bin/php', 'cli', '/etc/php.ini', null, array(),
		array('/usr/bin/php', '-d', 'precision=11', '/srv/vc/sbin/qbixserver.php')),
	array('/usr/bin/php', '-d', 'precision=11', '/srv/vc/sbin/qbixserver.php', '--root=/srv/web', '--workers=2'));
pp_check('...with phpArgs before the script',
	$P::workerCommand($argv, '/usr/bin/php', 'cli', false, null, array('-d', 'a=1'),
		array('/usr/bin/php', '-d', 'b=2', 'sbin/qbixserver.php')),
	array('/usr/bin/php', '-d', 'b=2', '-d', 'a=1', 'sbin/qbixserver.php', '--root=/srv/web', '--workers=2'));
if (is_readable('/proc/self/cmdline')) {
	$ic = $P::interpreterCommand();
	pp_check('this process\'s interpreter line ends with its script', is_array($ic) ? basename(end($ic)) : null, basename(__FILE__));
}

// ── Pool mode ────────────────────────────────────────────────────────

Q_Config::set('Q', 'webserver', 'poolMode', 'auto');
$hasFork = function_exists('pcntl_fork');
pp_check('auto is fork where fork exists (the default stays as it was)',
	Q_WebServer_Pool::mode(), $hasFork ? 'fork' : 'portable');
pp_check('portable when asked for', Q_WebServer_Pool::mode('portable'), 'portable');
pp_check('fork when asked for and available', Q_WebServer_Pool::mode('fork'), $hasFork ? 'fork' : 'portable');
pp_check('an unknown word is auto', Q_WebServer_Pool::mode('Fork-Me'), $hasFork ? 'fork' : 'portable');
pp_check('case does not matter', Q_WebServer_Pool::mode('PORTABLE'), 'portable');

// ── Q_WebServer_Process ─────────────────────────────────────────────

Q_WebServer_Process::$forceWindows = true;
pp_check('Windows discards into NUL', Q_WebServer_Process::nullDevice(), 'NUL');
pp_check('Windows has no inherited descriptors to replace', $P::inheritedDescriptors(), array());
Q_WebServer_Process::$forceWindows = false;
pp_check('POSIX discards into /dev/null', Q_WebServer_Process::nullDevice(), '/dev/null');
Q_WebServer_Process::$forceWindows = null;

foreach (array('/srv/x' => true, 'C:\\exp\\site' => true, 'c:/exp' => true, '\\\\nas\\share\\x' => true,
	'\\x' => true, 'phar:///a/b.phar/web' => true, 'web' => false, 'C:relative' => false, '' => false,
	'./web' => false) as $path => $abs) {
	pp_check("isAbsolutePath('$path')", Q_WebServer_Process::isAbsolutePath($path), $abs);
}

$csv = "\"php.exe\",\"4242\",\"Console\",\"1\",\"35,120 K\"\r\n\"php.exe\",\"77\",\"Console\",\"1\",\"9,000 K\"\r\n";
pp_check('tasklist lists the pid', Q_WebServer_Process::tasklistHasPid($csv, 4242), true);
pp_check('...and the other one', Q_WebServer_Process::tasklistHasPid($csv, 77), true);
pp_check('...but not a pid that is part of a number', Q_WebServer_Process::tasklistHasPid($csv, 424), false);
pp_check('tasklist\'s "no tasks" message is not a pid',
	Q_WebServer_Process::tasklistHasPid("INFO: No tasks are running which match the specified criteria.\r\n", 4242), false);

pp_check('this process is alive', Q_WebServer_Process::isAlive(getmypid()), true);
pp_check('pid 0 is not', Q_WebServer_Process::isAlive(0), false);

$scratch = getenv('QBIX_TEST_TMP') ?: sys_get_temp_dir();
$pidFile = $scratch . DS . 'pp-' . getmypid() . '.pid';
pp_check('nothing requested yet', Q_WebServer_Process::takeControlRequest($pidFile), null);
pp_check('a stop request is written', Q_WebServer_Process::requestControl($pidFile, 'stop'), true);
pp_check('...beside the pid file', is_file($pidFile . '.stop'), true);
pp_check('...taken once', Q_WebServer_Process::takeControlRequest($pidFile), 'stop');
pp_check('...and gone', is_file($pidFile . '.stop'), false);
Q_WebServer_Process::requestControl($pidFile, 'reload');
Q_WebServer_Process::requestControl($pidFile, 'stop');
pp_check('stop wins over reload', Q_WebServer_Process::takeControlRequest($pidFile), 'stop');
pp_check('...and both are taken', file_exists($pidFile . '.reload') || file_exists($pidFile . '.stop'), false);
pp_check('anything else is not a request', Q_WebServer_Process::requestControl($pidFile, 'rm -rf'), false);

// ── Supervisor bookkeeping (no processes) ─────────────────────────

/** A portable pool with these workers and processes, built without its constructor. */
function pp_pool(array $workers, array $procs)
{
	$ref = new ReflectionClass('Q_WebServer_PortablePool');
	$pool = $ref->newInstanceWithoutConstructor();
	$tokens = array();
	foreach ($procs as $i => $p) $tokens[$p['token']] = $i;
	foreach (array('workers' => $workers, 'procs' => $procs, 'tokens' => $tokens, 'nextIndex' => count($workers)) as $k => $v) {
		$prop = $ref->getProperty($k);
		if (PHP_VERSION_ID < 80100) $prop->setAccessible(true);
		$prop->setValue($pool, $v);
	}
	return $pool;
}
function pp_get($pool, $prop)
{
	$p = new ReflectionProperty(get_class($pool), $prop);
	if (PHP_VERSION_ID < 80100) $p->setAccessible(true);
	return $p->getValue($pool);
}
function pp_set($pool, $prop, $value)
{
	$p = new ReflectionProperty(get_class($pool), $prop);
	if (PHP_VERSION_ID < 80100) $p->setAccessible(true);
	$p->setValue($pool, $value);
}
/** Put one end of a socket pair in as a connection that has not greeted yet; return the other. */
function pp_connect($pool)
{
	$pair = stream_socket_pair(DIRECTORY_SEPARATOR === '\\' ? STREAM_PF_INET : STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
	stream_set_blocking($pair[0], false);
	$g = pp_get($pool, 'greetings');
	$g[(int) $pair[0]] = array('sock' => $pair[0], 'buf' => '', 'since' => microtime(true), 'watcher' => null);
	pp_set($pool, 'greetings', $g);
	return array((int) $pair[0], $pair[1]);
}

$tokA = bin2hex(random_bytes(16));
$tokB = bin2hex(random_bytes(16));
$starting = array('pid' => 0, 'socket' => null, 'busy' => true, 'starting' => true, 'idleSince' => 0.0, 'zygote' => false);
$proc = function ($tok) { return array('proc' => null, 'stdin' => null, 'ctl' => null, 'token' => $tok,
	'started' => microtime(true), 'line' => '', 'readyPid' => 0, 'exitCode' => null, 'pid' => 0); };
$pool = pp_pool(array(0 => $starting, 1 => $starting), array(0 => $proc($tokA), 1 => $proc($tokB)));

list($id, $peer) = pp_connect($pool);
fwrite($peer, "QPW1 " . str_repeat('0', 32) . " 5\n");
$pool->readGreeting($id);
pp_check('a wrong token is refused', isset(pp_get($pool, 'greetings')[$id]), false);
pp_check('...and the worker is still starting', !empty(pp_get($pool, 'workers')[0]['starting']), true);

list($id, $peer) = pp_connect($pool);
fwrite($peer, str_repeat('x', 600));
$pool->readGreeting($id);
pp_check('a line too long is refused', isset(pp_get($pool, 'greetings')[$id]), false);

list($id, $peer) = pp_connect($pool);
fwrite($peer, "QPW1 $tokA 4321");   // not finished yet
$pool->readGreeting($id);
pp_check('half a greeting waits for the rest', isset(pp_get($pool, 'greetings')[$id]), true);
fwrite($peer, "\n");
$pool->readGreeting($id);
$w = pp_get($pool, 'workers');
pp_check('the right token attaches the worker', empty($w[0]['starting']) && is_resource($w[0]['socket']), true);
pp_check('...idle', $w[0]['busy'], false);
pp_check('...under the pid it reported', $w[0]['pid'], 4321);
pp_check('...and its token is used up', isset(pp_get($pool, 'tokens')[$tokA]), false);
pp_check('the other worker is still starting', !empty($w[1]['starting']), true);

list($id, $peer) = pp_connect($pool);
fwrite($peer, "QPW1 $tokA 4321\n");
$pool->readGreeting($id);
pp_check('the same token a second time is refused', isset(pp_get($pool, 'greetings')[$id]), false);

// A request was waiting: the worker that connects takes it at once.
pp_set($pool, 'pending', array(array(null, array('method' => 'GET', 'uri' => '/q', 'path' => '/q', 'query' => '',
	'headers' => array(), 'body' => ''), '/dev/null/q.php', function () {})));
list($id, $peer) = pp_connect($pool);
fwrite($peer, "QPW1 $tokB 555\n");
$pool->readGreeting($id);
$w = pp_get($pool, 'workers');
pp_check('a worker that connects serves the waiting request', $w[1]['busy'], true);
pp_check('...and the queue is empty', count(pp_get($pool, 'pending')), 0);
stream_set_blocking($peer, true);
stream_set_timeout($peer, 2);
$hdr = fread($peer, 4);
$frame = $hdr !== false && strlen($hdr) === 4 ? json_decode(fread($peer, unpack('N', $hdr)[1]), true) : null;
pp_check('...sent to it as a request frame', is_array($frame) ? $frame['uri'] : null, '/q');

// Control lines from a worker: DONE counts its requests; other output is passed on, not parsed.
$p = $proc($tokA);
$ref = new ReflectionMethod($P, 'onControlLine');
if (PHP_VERSION_ID < 80100) $ref->setAccessible(true);
$args = array(&$p, 0, 'DONE 12');
$ref->invokeArgs($pool, $args);
pp_check('DONE n is the worker\'s request count', pp_get($pool, 'workers')[0]['requests'] ?? null, 12);
$args = array(&$p, 0, 'EXIT 3');
$ref->invokeArgs($pool, $args);
pp_check('EXIT n is remembered for the log', $p['exitCode'], 3);

// Boot failures hold the next start back.
$bf = new ReflectionMethod($P, 'bootFailed');
if (PHP_VERSION_ID < 80100) $bf->setAccessible(true);
ob_start();
$t = microtime(true);
$bf->invoke($pool, 1, 'test failure 1');
$bf->invoke($pool, 1, 'test failure 2');
ob_end_clean();
pp_check('two boot failures in a row', pp_get($pool, 'bootFailures'), 2);
$wait = pp_get($pool, 'nextSpawnAt') - $t;
pp_check('...hold the next start back about 1 s', $wait > 0.9 && $wait < 1.5, true);

// ── A real server ───────────────────────────────────────────────────

if (getenv('QBIX_PORTABLE_SKIP_SERVER')) {
	printf("%s  %d passed, %d failed (server part skipped)\n", $fail ? 'FAIL' : 'PASS', $pass, $fail);
	exit($fail ? 1 : 0);
}
if (!is_dir('/proc/self')) {
	printf("  skip  the server part reads /proc\n");
	printf("%s  %d passed, %d failed\n", $fail ? 'FAIL' : 'PASS', $pass, $fail);
	exit($fail ? 1 : 0);
}

require __DIR__ . '/fixtures/race-harness.php';
$GLOBALS['rh_extra_args'] = array('--pool-mode=portable');
list($base, $root) = rh_setup('portable');
file_put_contents("$root/pid.php", "<?php echo getmypid();\n");
file_put_contents("$root/ini.php", "<?php echo ini_get('precision');\n");
file_put_contents("$root/fatal.php", "<?php echo 'x'; undefined_function_portable_pool();\n");

$port = rh_start('portable', array('Q' => array(
	'web' => array('cache' => array('enabled' => false)),
	'webserver' => array('maxRequests' => 5),
)), 2);
$server = rh_server_pid('portable');

function pp_pid($port)
{
	$r = rh_get($port, '/pid.php');
	return $r['status'] === 200 ? (int) $r['body'] : -$r['status'];
}

$seen = array();
for ($i = 0; $i < 4; ++$i) $seen[pp_pid($port)] = true;
$pids = array_keys($seen);
pp_check('pages are served', min($pids) > 0, true);
pp_check('...not by the server', isset($seen[$server]), false);
$wpid = (int) $pids[0];
$env = (string) @file_get_contents("/proc/$wpid/environ");
pp_check('...by a process started for the pool (QBIX_POOL_WORKER set, a Unix socket here)', strpos($env, 'QBIX_POOL_WORKER=unix://') !== false, true);
pp_check('...and the server listens on no TCP port of the pool\'s own', Q_WebServer_Ctl::portsOf($server), array($port));
pp_check('...which is not a fork of the server (it ran qbixserver.php itself)',
	strpos((string) @file_get_contents("/proc/$wpid/cmdline"), 'qbixserver.php') !== false, true);
pp_check('...and workers are reused (at most 2 pids for 4 requests)', count($pids) <= 2, true);

$sockets = 0;
foreach (glob("/proc/$wpid/fd/*") ?: array() as $fd) {
	if (strpos((string) @readlink($fd), 'socket:') === 0) ++$sockets;
}
pp_check('a worker holds one socket: its connection to the server', $sockets, 1);
pp_check('...and no visitor\'s connection to the server\'s port', rh_tcp_held($wpid, $port), 0);

// maxRequests 5: a worker that has served five leaves and is replaced.
$all = array();
for ($i = 0; $i < 24; ++$i) $all[] = pp_pid($port);
pp_check('every request is answered across recycling', min($all) > 0, true);
pp_check('...by more workers than the pool holds (recycled after 5)', count(array_unique($all)) > 2, true);

// A worker killed outright.
$victim = pp_pid($port);
posix_kill($victim, SIGKILL);
usleep(300000);
$after = array();
for ($i = 0; $i < 6; ++$i) $after[] = pp_pid($port);
pp_check('after a worker is killed, requests are still answered', min($after) > 0, true);
pp_check('...never by the dead one', in_array($victim, $after, true), false);
$deadline = microtime(true) + 10;
do {
	$live = 0;
	foreach (rh_children($server) as $cpid => $state) if ($state !== 'Z') ++$live;
	if ($live >= 2) break;
	usleep(200000);
} while (microtime(true) < $deadline);
pp_check('...and the pool is back to two workers', $live >= 2, true);

$r = rh_get($port, '/ini.php');
pp_check('a worker runs with the server\'s own php.ini settings', $r['body'] ?? null, (string) ini_get('precision'));

$r = rh_get($port, '/fatal.php');
pp_check('a fatal error answers 500', $r['status'], 500);
pp_check('...and the pool goes on', pp_pid($port) > 0, true);

$log = rh_log('portable');
pp_check('the banner names the portable pool', strpos($log, 'Pool: portable') !== false, true);

// Stop by request file -- what --stop does where there are no signals.
$workersBefore = array_keys(rh_children($server));
Q_WebServer_Process::requestControl("$base/portable.pid", 'stop');
$gone = false;
$deadline = microtime(true) + 15;
while (microtime(true) < $deadline) {
	$st = proc_get_status($GLOBALS['rh']['servers']['portable']);
	if (empty($st['running'])) { $gone = true; break; }
	usleep(100000);
}
pp_check('a stop asked for by request file ends the server', $gone, true);
usleep(300000);
$left = 0;
foreach ($workersBefore as $wp) if (Q_WebServer_Process::isAlive($wp)) ++$left;
pp_check('...and every worker', $left, 0);
pp_check('...gracefully', strpos(rh_log('portable'), 'Graceful shutdown (stop requested)') !== false, true);


// The control channel as a file -- what Windows uses -- on this system.
$port2 = rh_start('portable-file', array('Q' => array(
	'web' => array('cache' => array('enabled' => false)),
	// What Windows uses: a control file per worker, and TCP on 127.0.0.1.
	'webserver' => array('portable' => array('controlChannel' => 'file', 'transport' => 'tcp')),
)), 2);
$srv2 = rh_server_pid('portable-file');
$ok = 0; $p2 = array();
for ($i = 0; $i < 5; ++$i) { $v = pp_pid($port2); if ($v > 0) { ++$ok; $p2[$v] = true; } }
pp_check('with a control file per worker, requests are answered', $ok, 5);
pp_check('...by workers, not the server', isset($p2[$srv2]), false);
$files = glob(sys_get_temp_dir() . DS . 'qbix-pool-' . $srv2 . '-*.ctl') ?: array();
pp_check('...each worker has its control file', count($files) >= 2, true);
$lines = '';
foreach ($files as $f) $lines .= (string) @file_get_contents($f);
pp_check('...which holds READY and DONE lines', strpos($lines, 'READY ') !== false && strpos($lines, 'DONE ') !== false, true);
Q_WebServer_Process::requestControl("$base/portable-file.pid", 'stop');
$deadline = microtime(true) + 15;
while (microtime(true) < $deadline) {
	$st = proc_get_status($GLOBALS['rh']['servers']['portable-file']);
	if (empty($st['running'])) break;
	usleep(100000);
}
usleep(500000);
pp_check('...and the control files are removed when the pool stops',
	count(glob(sys_get_temp_dir() . DS . 'qbix-pool-' . $srv2 . '-*.ctl') ?: array()), 0);

printf("%s  %d passed, %d failed\n", $fail ? 'FAIL' : 'PASS', $pass, $fail);
exit($fail ? 1 : 0);
