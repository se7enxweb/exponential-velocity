<?php

/**
 * A worker still busy after its answer is not handed the next request.
 *
 * A request can go on working after its answer has gone out: shutdown
 * callbacks write a session, send mail or start work meant for later, and a
 * persistent worker runs them during its reset, after writeMsg(). The server
 * marked the worker free at the answer and predicted the end of its reset from
 * the reset before, so once that short prediction had passed it handed the
 * worker the next request -- which then sat unread in its socket for as long
 * as the after-answer work took, while another worker was idle. On a page of
 * hundreds of images whose requests each started work after their answer, an
 * image that takes 30 ms took seconds. The worker now says when it is free
 * (writeReady()) and the server waits for that (workerReady()).
 *
 * Asserted on the pool's bookkeeping, without a server:
 *
 *   - findIdle() does not take a worker that has answered and is past its
 *     predicted reset without having said it is free (awaitReady);
 *   - it still takes one whose predicted reset has not run out yet, and one
 *     that has said it is free;
 *
 * and on a real server with a pool of two workers:
 *
 *   - after a request whose shutdown callback works for 1.5 s after the
 *     answer, the next requests are answered at once by the other worker;
 *   - with both workers busy after their answers, a request waits in the
 *     queue and is answered when the first of them is free, not lost;
 *   - afterwards both workers serve again (each said it was free).
 *
 *   php tests/unit-pool-busy-after-answer.php
 */

require __DIR__ . '/fixtures/race-harness.php';
rh_require_pool();
require_once __DIR__ . '/../src/Q/WebServer/Pool.php';

$pass = 0;
$fail = 0;
function ba_check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

/** A pool with these workers and nothing else: no process is forked. */
function ba_pool(array $workers)
{
	$ref = new ReflectionClass('Q_WebServer_Pool');
	$pool = $ref->newInstanceWithoutConstructor();
	$prop = $ref->getProperty('workers');
	if (PHP_VERSION_ID < 80100) $prop->setAccessible(true);
	$prop->setValue($pool, $workers);
	return $pool;
}

function ba_find($pool)
{
	$m = new ReflectionMethod('Q_WebServer_Pool', 'findIdle');
	if (PHP_VERSION_ID < 80100) $m->setAccessible(true);
	return $m->invoke($pool);
}

// ── Bookkeeping ────────────────────────────────────────────────────

$now = microtime(true);
$overrun = array('busy' => false, 'readyAt' => $now - 0.5, 'awaitReady' => true);
$predicted = array('busy' => false, 'readyAt' => $now + 0.005, 'awaitReady' => true);
$free = array('busy' => false, 'readyAt' => $now - 0.5, 'awaitReady' => false);
$busy = array('busy' => true, 'readyAt' => $now - 1.0);

ba_check('a worker past its reset that has not said it is free is not taken',
	ba_find(ba_pool(array(0 => $overrun))), null);
ba_check('...another worker is taken instead, whatever the order',
	ba_find(ba_pool(array(0 => $overrun, 1 => $free))), 1);
ba_check('...a worker whose reset is predicted to end in a moment still is',
	ba_find(ba_pool(array(0 => $overrun, 1 => $busy, 2 => $predicted))), 2);
ba_check('a worker that said it is free is taken',
	ba_find(ba_pool(array(0 => $free, 1 => $overrun))), 0);

// ── A real server ──────────────────────────────────────────────────

list($base, $root) = rh_setup('busyafter');
// Answers at once, then works for 1.5 s after the answer.
file_put_contents("$root/after.php", "<?php register_shutdown_function(function () { usleep(1500000); });\necho 'after ', getmypid();\n");
file_put_contents("$root/fast.php", "<?php echo 'fast ', getmypid();\n");

$port = rh_start('busyafter', array('Q' => array('web' => array('cache' => array('enabled' => false)))), 2);
// Both workers up and through a first request each.
rh_many($port, array(array('path' => '/fast.php'), array('path' => '/fast.php')), 2);
usleep(300000);

function ba_get($port, $path)
{
	$t = microtime(true);
	$r = rh_get($port, $path);
	return array($r['status'], (string) $r['body'], microtime(true) - $t);
}

list($s, $body) = ba_get($port, '/after.php');
ba_check('the request that works after its answer is answered', $s, 200);
$afterPid = (int) substr($body, 6);
usleep(100000);   // its usual reset is long over; its shutdown callback is not
$slowest = 0.0;
$pids = array();
for ($i = 0; $i < 4; ++$i) {
	list($s, $body, $took) = ba_get($port, '/fast.php');
	$slowest = max($slowest, $took);
	$pids[(int) substr($body, 5)] = true;
}
ba_check('the next requests are answered at once (slowest under 0.7 s)', $slowest < 0.7, true);
if ($slowest >= 0.7) printf("        slowest %.2f s\n", $slowest);
ba_check('...by the other worker, not the one still working', isset($pids[$afterPid]), false);

// Both workers busy after their answers: the next request waits for the first free one.
sleep(2);
rh_many($port, array(array('path' => '/after.php'), array('path' => '/after.php')), 2);
usleep(100000);
list($s, $body, $took) = ba_get($port, '/fast.php');
ba_check('with every worker busy after its answer, a request is queued and answered', $s, 200);
ba_check('...once a worker is free (after about 1.4 s, within 5 s)', $took > 1.0 && $took < 5.0, true);
if (!($took > 1.0 && $took < 5.0)) printf("        took %.2f s\n", $took);

// Each worker said it was free: two requests at once are served by both.
sleep(2);
$both = rh_many($port, array(array('path' => '/fast.php'), array('path' => '/fast.php'),
	array('path' => '/fast.php'), array('path' => '/fast.php')), 4);
$ok = 0;
foreach ($both as $r) if ($r['status'] === 200) ++$ok;
ba_check('both workers serve again afterwards', $ok, 4);

$log = rh_log('busyafter');
ba_check('nothing was lost or retried', strpos($log, 'died before answering') === false, true);

printf("%s  %d passed, %d failed\n", $fail ? 'FAIL' : 'PASS', $pass, $fail);
exit($fail ? 1 : 0);
