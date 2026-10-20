<?php

/**
 * The zygote is used only where PHP passes sockets between processes intact,
 * and a pool with the zygote switched on serves every request either way.
 *
 * Before PHP 8.4, a Socket object sent with SCM_RIGHTS arrives as another socket
 * (a stream resource arrives intact). With the zygote on by default, every
 * worker it forked on such a PHP wrote into a dead end, and every request
 * after the first was a 502.
 * Asserted, on whatever PHP runs the test:
 *   - the self-test and zygoteSupported() agree: no zygote without intact passing;
 *   - a dynamic pool with the zygote setting on answers a burst that makes it
 *     fork, every request 200;
 *   - where passing is broken, the server says the zygote is off and why.
 *
 *   php tests/unit-zygote-socket-passing.php
 */

require __DIR__ . '/fixtures/race-harness.php';
if (!function_exists('pcntl_fork')) { printf("  skip  needs pcntl\n"); exit(0); }
require_once __DIR__ . '/../src/Q/WebServer/Pool.php';

$works = function_exists('socket_recvmsg') ? Q_WebServer_Pool::socketPassingWorks() : false;
printf("  note  PHP %s: sockets passed intact between processes: %s\n", PHP_VERSION, $works ? 'yes' : 'no');
check('no zygote where sockets are not passed intact', Q_WebServer_Pool::zygoteSupported() && !$works, false);
// Sent as a stream resource, the socket arrives intact on 8.0 and later: the
// Socket-object path was what PHP before 8.4 got wrong.
if (PHP_VERSION_ID >= 80000 && function_exists('socket_recvmsg')) {
	check('sockets are passed intact on PHP 8.0 and later (sent as a stream)', $works, true);
}
check('the self-test answers the same the second time', function_exists('socket_recvmsg') ? Q_WebServer_Pool::socketPassingWorks() : false, $works);

list($base, $root) = rh_setup('zygote-passing');
file_put_contents($root . DS . 'index.php', '<?php usleep((int) ($_GET["sleep"] ?? 0) * 1000); echo "ok";');
$port = rh_start('zp', array('Q' => array('webserver' => array('spareWorkers' => 1, 'idleWorkerTimeout' => 120, 'zygote' => true))), 8);

$reqs = array();
for ($i = 0; $i < 24; ++$i) $reqs[] = array('path' => "/index.php?sleep=300&i=$i");
$res = rh_many($port, $reqs, 12, 30.0);
$bad = 0;
foreach ($res as $r) if (($r['status'] ?? 0) !== 200) ++$bad;
check('a burst that makes the pool fork is answered in full, zygote setting on', $bad, 0);
$after = rh_get($port, '/index.php');
check('...and the next request too', $after['status'] ?? 0, 200);
if (!$works) {
	check('the server says the zygote is off, and why', strpos(rh_log('zp'), 'zygote off: PHP') !== false, true);
}
rh_finish();
