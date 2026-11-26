<?php

/**
 * Q.webserver.workers sizes a forked pool too, not only a portable one.
 *
 * The server read the setting only in the branch for a PHP without fork, so
 * on Linux a configured count was replaced by the automatic one without a
 * word. Asserted:
 *
 *   - Q_WebServer_Pool::configuredWorkers() turns the configured value into a
 *     count, and anything that is not one into 0 ("choose automatically");
 *   - sbin/qbixserver.php reads the setting before, and outside, the fork and
 *     the portable branches, and the automatic sizing only runs when neither
 *     --workers nor the setting gave a count.
 *
 *   php tests/unit-pool-configured-workers.php
 */

require_once __DIR__ . '/../src/Q/WebServer/Pool.php';

$pass = 0;
$fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

foreach (array(
	array(12, 12), array('12', 12), array(' 6 ', 6), array(0, 0), array(-3, 0),
	array(null, 0), array('', 0), array('auto', 0), array(true, 0), array(array(4), 0),
	array(8.0, 8), array(0.5, 0),
) as $case) {
	check('configuredWorkers(' . var_export($case[0], true) . ')',
		Q_WebServer_Pool::configuredWorkers($case[0]), $case[1]);
}

$src = file_get_contents(__DIR__ . '/../sbin/qbixserver.php');
$read = strpos($src, "Q_WebServer_Pool::configuredWorkers(Q_Config::get('Q', 'webserver', 'workers'");
$auto = strpos($src, "if (!\$opts['workers'] && function_exists('pcntl_fork')) {");
$portable = strpos($src, "if (!\$opts['workers'] && !function_exists('pcntl_fork') && function_exists('proc_open')) {");
check('the server reads Q.webserver.workers through configuredWorkers()', $read !== false, true);
check('...before the automatic sizing for a forked pool', $read !== false && $auto !== false && $read < $auto, true);
check('...and before the portable pool\'s default', $read !== false && $portable !== false && $read < $portable, true);
check('the setting is read in one place only',
	preg_match_all("/Q_Config::get\\('Q', 'webserver', 'workers'/", $src), 1);

echo ($fail ? 'FAIL' : 'PASS') . "  $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
