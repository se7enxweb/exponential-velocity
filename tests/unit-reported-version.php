<?php

/**
 * The server reports the release it runs, not the base engine's number.
 *
 * SERVER_SOFTWARE, /.well-known/qbix.json, the OpenAPI document, the server's
 * OpenClaim and the panel all said "1.5.0" -- QBIX_SERVER_VERSION, the number
 * of the engine a release is built on -- so every release looked the same.
 * They now go through Q_WebServer::version(), the shipped release without its
 * leading "v". Asserted:
 *
 *   - version() answers QBIX_SHIP_VERSION without the "v";
 *   - nothing reports QBIX_SERVER_VERSION directly any more;
 *   - SERVER_SOFTWARE, qbix.json and the OpenAPI info use version().
 *
 * Run in a process of its own: it defines the version constants.
 *
 *   php tests/unit-reported-version.php
 */

$pass = 0;
$fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

$root = dirname(__DIR__);
$web = file_get_contents("$root/src/Q/WebServer.php");

// version() itself, lifted from the source so the test needs no bootstrap.
if (!preg_match('/\tstatic function version\(\)\n\t\{.*?\n\t\}\n/s', $web, $m)) {
	check('Q_WebServer::version() exists', false, true);
} else {
	define('QBIX_SERVER_VERSION', '1.5.0');
	define('QBIX_SHIP_VERSION', 'v0.0.4.69');
	eval('class Reported_Version_Probe {' . $m[0] . '}');
	check('version() is the shipped release without its "v"', Reported_Version_Probe::version(), '0.0.4.69');
}

// Nothing reports the base number directly: every reader goes through version().
$reporters = array('src/Q/WebServer.php', 'src/Q/WebServer/Pool.php', 'src/Q/WebServer/Identity.php',
	'src/Q/WebServer/Panel.php', 'src/Q/WebServer/Attestation.php', 'src/Q/WebServer/Trust.php');
foreach ($reporters as $f) {
	$src = file_get_contents("$root/$f");
	// Outside version() itself.
	$src = preg_replace('/\tstatic function version\(\)\n\t\{.*?\n\t\}\n/s', '', $src);
	$src = preg_replace('#/\*\*.*?\*/#s', '', $src);
	check("$f does not report QBIX_SERVER_VERSION itself", strpos($src, 'QBIX_SERVER_VERSION'), false);
}

check('SERVER_SOFTWARE carries version()',
	preg_match_all("/'SERVER_SOFTWARE'\]?\s*=>?\s*'QbixServer\/' \. Q_WebServer::version\(\)/", $web), 2);
check('the pool worker sets SERVER_SOFTWARE from version()',
	strpos(file_get_contents("$root/src/Q/WebServer/Pool.php"), "'QbixServer/' . Q_WebServer::version()") !== false, true);
check('qbix.json and the OpenAPI info carry version()',
	preg_match_all("/'version' => self::version\(\)/", $web), 2);

echo ($fail ? 'FAIL' : 'PASS') . "  $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
