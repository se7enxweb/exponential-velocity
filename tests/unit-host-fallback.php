<?php

/**
 * A host the server cannot serve goes back to the previous web server
 * (Q_WebServer_HostFallback, Q.webserver.hostFallback).
 *
 * Asserted:
 *   - nothing configured: nothing changes, and 5xx answers are not counted;
 *   - a 5xx storm (errors within window) puts the host into fallback, and
 *     only that host; fewer errors, or errors older than the window, do not;
 *   - in fallback, Q_WebServer_Upstream::target() names the previous server;
 *     with no previous server, page() is a 503 that says why;
 *   - the report is written once per episode as <stateDir>/<host>.json and
 *     the report command runs with VC_FALLBACK_HOST and VC_FALLBACK_CAUSE;
 *   - a flag file <stateDir>/<host> sends the host back too, with its text as
 *     the cause, and removing it serves the host again;
 *   - the page answered in fallback is not counted as a new error;
 *   - per-host settings (Q.webserver.hosts.<host>.hostFallback) win.
 *
 *   php tests/unit-host-fallback.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Upstream.php';
require_once __DIR__ . '/../src/Q/WebServer/HostFallback.php';
$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}
$F = 'Q_WebServer_HostFallback';
$dir = sys_get_temp_dir() . '/vc-hostfallback-' . getmypid();
mkdir($dir, 0700, true);

check('not configured', $F::configured(), false);
$F::record('a.example', 500, '/');
check('not configured: nothing counted', $F::$errors, array());
check('not configured: upstream target unchanged', Q_WebServer_Upstream::target('a.example'), null);

$marker = "$dir/reported";
Q_Config::set('Q', 'webserver', 'hostFallback', array('upstream' => '127.0.0.1:7080', 'errors' => 3, 'window' => 60,
	'hold' => 300, 'stateDir' => $dir,
	'report' => 'printf "%s|%s" "$VC_FALLBACK_HOST" "$VC_FALLBACK_CAUSE" > ' . escapeshellarg($marker)));
check('configured', $F::configured(), true);
$F::record('a.example', 502, '/x');
$F::record('a.example', 404, '/y');
$F::record('a.example:443', 500, '/z');
check('two 5xx: still served', $F::cause('a.example'), null);
$F::record('b.example', 500, '/');
check('another host counts apart', $F::cause('a.example'), null);
$F::record('a.example', 503, '/w');
check('three 5xx in the window: fallback', is_string($F::cause('a.example')), true);
check('the cause names the storm', strpos((string) $F::cause('a.example'), '5xx storm') === 0, true);
check('other host still served', $F::cause('b.example'), null);
check('upstream target is the previous server', Q_WebServer_Upstream::target('a.example'),
	Q_WebServer_Upstream::address('127.0.0.1:7080'));
check('report file written', is_file("$dir/a.example.json"), true);
$rep = json_decode((string) @file_get_contents("$dir/a.example.json"), true);
check('report names the host', $rep['host'] ?? null, 'a.example');
for ($i = 0; $i < 50 and !is_file($marker); $i++) usleep(100000);
$said = (string) @file_get_contents($marker);
check('report command ran with the host', strpos($said, 'a.example|5xx storm') === 0, true);
@unlink("$dir/a.example.json");
$F::record('a.example', 503, '/page');
check('the fallback page is not a new error (no second report)', is_file("$dir/a.example.json"), false);

// Errors older than the window.
Q_Config::set('Q', 'webserver', 'hostFallback', 'window', 1);
$F::$errors['c.example'] = array(time() - 10, time() - 5);
$F::record('c.example', 500);
check('old errors fall out of the window', $F::cause('c.example'), null);

// No previous server: the page.
Q_Config::set('Q', 'webserver', 'hosts', array('d.example' => array('hostFallback' => array('upstream' => ''))));
file_put_contents("$dir/d.example", "a rule the server cannot do: RewriteMap\n");
check('flag file: in fallback with its text as cause', $F::cause('d.example'), 'a rule the server cannot do: RewriteMap');
check('per-host: no previous server, no target', $F::target('d.example'), null);
$page = $F::page('d.example');
check('page: 503', $page['status'] ?? null, 503);
check('page: says why', strpos($page['body'] ?? '', 'RewriteMap') !== false, true);
check('page: no-store', $page['headers']['Cache-Control'] ?? null, 'no-store');
unlink("$dir/d.example");
sleep(1);
check('flag removed: served again', $F::cause('d.example'), null);
check('served host: no page', $F::page('d.example'), null);

foreach ((array) glob("$dir/*") as $f) @unlink($f);
@rmdir($dir);
printf("%s: %d passed, %d failed\n", $fail ? 'FAIL' : 'PASS', $pass, $fail);
exit($fail ? 1 : 0);
