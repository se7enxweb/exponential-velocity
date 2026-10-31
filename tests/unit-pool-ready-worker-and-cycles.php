<?php

/**
 * The pool sends a request to a worker that has finished resetting itself, and
 * a worker collects cycles only when there is something to collect.
 *
 * A worker answers first and resets afterwards: statics put back, globals
 * cleared, cycles collected. The parent marked it idle as soon as the answer
 * came, so with one client sending one request after the other each request
 * went to the same worker and waited for the reset of the one before. On an
 * Exponential worker that was 1.5-8 ms, longer than a cached API answer.
 *
 * Asserted without a server, on the pool's own bookkeeping and in this process:
 *
 *   - findIdle() takes an idle worker whose reset is done (readyAt in the
 *     past) over one still resetting, whatever their order;
 *   - with every idle worker still resetting it takes the one free soonest,
 *     rather than none (which would fork another or queue the request);
 *   - a busy worker is never taken, and none at all gives null;
 *   - a worker the pool knows nothing about yet (no readyAt) counts as free;
 *   - collectCycles() collects on its first call, then not while the heap has
 *     not grown, and again once garbage has grown it past GC_GROWTH -- and
 *     what it frees then is the garbage.
 *
 *   php tests/unit-pool-ready-worker-and-cycles.php
 */

require __DIR__ . '/fixtures/race-harness.php';
rh_require_pool();
require_once __DIR__ . '/../src/Q/WebServer/Pool.php';

$pass = 0;
$fail = 0;
function rw_check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

/** A pool with these workers and nothing else: no process is forked. */
function rw_pool(array $workers)
{
	$ref = new ReflectionClass('Q_WebServer_Pool');
	$pool = $ref->newInstanceWithoutConstructor();
	$prop = $ref->getProperty('workers');
	if (PHP_VERSION_ID < 80100) $prop->setAccessible(true);
	$prop->setValue($pool, $workers);
	return $pool;
}

function rw_find($pool)
{
	$m = new ReflectionMethod('Q_WebServer_Pool', 'findIdle');
	if (PHP_VERSION_ID < 80100) $m->setAccessible(true);
	return $m->invoke($pool);
}

$now = microtime(true);
// No pid: findIdle() does not ask whether the process is alive.
$resetting = array('busy' => false, 'readyAt' => $now + 5.0);
$resettingLonger = array('busy' => false, 'readyAt' => $now + 9.0);
$ready = array('busy' => false, 'readyAt' => $now - 0.001);
$busy = array('busy' => true, 'readyAt' => $now - 1.0);

rw_check('a ready worker is taken over one still resetting before it',
	rw_find(rw_pool(array(0 => $resetting, 1 => $ready))), 1);
rw_check('the first ready worker, when several are',
	rw_find(rw_pool(array(0 => $ready, 1 => $ready))), 0);
rw_check('every idle worker resetting: the one free soonest',
	rw_find(rw_pool(array(0 => $resettingLonger, 1 => $busy, 2 => $resetting))), 2);
rw_check('a busy worker is never taken',
	rw_find(rw_pool(array(0 => $busy, 1 => $busy))), null);
rw_check('no workers: none',
	rw_find(rw_pool(array())), null);
rw_check('a worker without readyAt is free',
	rw_find(rw_pool(array(0 => $resetting, 1 => array('busy' => false)))), 1);

// ── Cycles: collected when there is something to collect ──────────

function rw_garbage($bytes)
{
	for ($made = 0; $made < $bytes; $made += 32768) {
		$x = new stdClass; $y = new stdClass;
		$x->other = $y; $y->other = $x; $x->payload = str_repeat('g', 32768);
	}
}

gc_enable();
Q_WebServer_Pool::collectCycles();   // the first call collects and sets the mark
rw_garbage(Q_WebServer_Pool::GC_GROWTH / 8);
rw_check('little garbage: not collected yet', Q_WebServer_Pool::collectCycles(), 0);
$before = memory_get_usage();
rw_garbage(2 * Q_WebServer_Pool::GC_GROWTH);
$freed = Q_WebServer_Pool::collectCycles();
rw_check('past GC_GROWTH: collected', $freed > 0, true);
rw_check('...and the heap is back down', memory_get_usage() < $before + Q_WebServer_Pool::GC_GROWTH / 2, true);

printf("%s  %d passed, %d failed\n", $fail ? 'FAIL' : 'PASS', $pass, $fail);
exit($fail ? 1 : 0);
