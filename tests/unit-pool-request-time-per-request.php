<?php

/**
 * $_SERVER['REQUEST_TIME_FLOAT'] and REQUEST_TIME are the time of this
 * request, in every request a worker serves.
 *
 * A pool worker inherited them from the process that forked it and nothing
 * set them again, so in a persistent worker every request claimed to have
 * started when the server did. An application that keys a per-request cache
 * on REQUEST_TIME_FLOAT -- Exponential does, to remember what it read once per
 * request -- then kept that cache for the worker's whole life and served one
 * request's data to the next.
 *
 * One persistent worker serves two requests a little apart: both must come
 * from the same process, each must report a time within a second of when it
 * was sent, the second later than the first, and REQUEST_TIME must be the
 * whole seconds of REQUEST_TIME_FLOAT.
 *
 *   php tests/unit-pool-request-time-per-request.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

require __DIR__ . '/fixtures/race-harness.php';
rh_require_pool();

list($base, $root) = rh_setup('request-time');
file_put_contents($root . DS . 'index.php',
	'<?php echo json_encode(array("pid" => getmypid(), "float" => $_SERVER["REQUEST_TIME_FLOAT"] ?? null,'
	. ' "int" => $_SERVER["REQUEST_TIME"] ?? null));');

$pass = 0;
$fail = 0;
function rt_check($what, $ok, $detail = '')
{
	global $pass, $fail;
	if ($ok) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s%s\n", $what, $detail !== '' ? "\n        $detail" : '');
}

// One worker, persistent (the engine's default: forkPerRequest off), and no
// response cache, so both requests reach the script.
$port = rh_start('rt', array('Q' => array('webserver' => array('forkPerRequest' => false, 'cache' => false))), 1);

$sent1 = microtime(true);
$r1 = rh_get($port, '/index.php?n=1');
usleep(300000);
$sent2 = microtime(true);
$r2 = rh_get($port, '/index.php?n=2');

$a = json_decode((string) ($r1['body'] ?? ''), true);
$b = json_decode((string) ($r2['body'] ?? ''), true);
rt_check('both requests answered', is_array($a) and is_array($b),
	rh_short(($r1['body'] ?? '') . ' | ' . ($r2['body'] ?? '')));
if (is_array($a) and is_array($b)) {
	rt_check('one persistent worker served both', $a['pid'] === $b['pid'], "pids {$a['pid']} and {$b['pid']}");
	rt_check('REQUEST_TIME_FLOAT of the first request is when it was sent',
		is_float($a['float']) and abs($a['float'] - $sent1) < 1.0, var_export($a['float'], true) . " vs $sent1");
	rt_check('REQUEST_TIME_FLOAT of the second request is when it was sent',
		is_float($b['float']) and abs($b['float'] - $sent2) < 1.0, var_export($b['float'], true) . " vs $sent2");
	rt_check('the second request started later than the first',
		is_float($a['float']) and is_float($b['float']) and $b['float'] - $a['float'] >= 0.25,
		var_export($a['float'], true) . ' then ' . var_export($b['float'], true));
	rt_check('REQUEST_TIME is the whole seconds of REQUEST_TIME_FLOAT',
		$b['int'] === (int) $b['float'], var_export($b['int'], true));
}

printf("%s: %d passed, %d failed\n", $fail ? 'FAIL' : 'PASS', $pass, $fail);
exit($fail ? 1 : 0);
