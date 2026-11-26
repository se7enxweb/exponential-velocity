<?php

/**
 * server:start and server:restart pass --pool-mode on, without `--`.
 *
 * qbixserver.php takes --pool-mode=fork|portable|auto, but qbixctl's start did
 * not know the option: it could reach the server only after `--`, and a
 * restart could not replace it. Asserted:
 *
 *   - server:start and server:restart declare --pool-mode;
 *   - Q_WebServer_Ctl::serverArgs() passes it on;
 *   - the start record reads both spellings, so a restart can replace it;
 *   - every value option qbixserver.php takes that start passes on is in
 *     the start record's list too.
 *
 *   php tests/unit-ctl-pool-mode.php
 */

require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/Console.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
require_once __DIR__ . '/../src/Q/WebServer/Ctl.php';
require_once __DIR__ . '/../src/Q/WebServer/StartRecord.php';

$pass = 0;
$fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

check('serverArgs() passes --pool-mode on',
	Q_WebServer_Ctl::serverArgs(array('pool-mode' => 'portable', 'root' => '/a', 'pid' => '/x.pid')),
	array('root' => '/a', 'pool-mode' => 'portable'));

$R = 'Q_WebServer_StartRecord';
check('the start record reads --pool-mode VALUE',
	$R::options(array('--pool-mode', 'portable', '--root=/a')), array('pool-mode' => 'portable', 'root' => '/a'));
check('a restart replaces the recorded --pool-mode',
	$R::override(array('--pool-mode', 'fork', '--root=/a'), array('pool-mode' => 'portable')),
	array('--root=/a', '--pool-mode=portable'));

$ctl = file_get_contents(__DIR__ . '/../src/Q/WebServer/Ctl.php');
check('server:start and server:restart declare --pool-mode',
	(bool) preg_match("/\\\$startOpts = array\\([^;]*'pool-mode' =>/s", $ctl), true);

foreach (Q_WebServer_Ctl::$serverValueOptions as $o) {
	check("--$o, passed on by start, is a value option of the start record", in_array($o, $R::$valued, true), true);
}

echo ($fail ? 'FAIL' : 'PASS') . "  $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
