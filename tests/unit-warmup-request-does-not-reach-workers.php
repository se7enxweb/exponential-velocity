<?php

/**
 * What the parent's warm-up registered or set as a request is never part of a
 * worker's request.
 *
 * The warm-up (Q.webserver.warmup) runs in the parent before the pool forks,
 * and for an application it is a request: Exponential's renders the front page
 * through its kernel. Nothing ended that request, so the workers inherited it.
 * The shutdown functions it registered ran in each worker's first request, and
 * with a fresh worker per request in every request; the headers it set were in
 * the statics snapshot a persistent worker restores before each request; its
 * error and exception handlers, its open session, its ini and environment
 * changes and its unflushed output were all still there.
 *
 * The warm-up here does all of that. Asserted in both worker modes, over
 * several requests: none of its shutdown functions ever runs (in a worker or
 * in the parent), its header and response code are in no response, its
 * handlers are not installed, there is no $_SESSION, its ini value and its
 * environment variable are gone, its output is in no body -- and a shutdown
 * function a request registers itself still runs, once, for that request.
 *
 * Fails on the engine before the pool ended the warm-up's request.
 *
 *   php tests/unit-warmup-request-does-not-reach-workers.php
 */

require __DIR__ . '/fixtures/race-harness.php';
rh_require_pool();
list($base, $root) = rh_setup('warmup-request');
// The worker may run as another user than this test: it must reach the
// marker file and the session directory.
@chmod($base, 0755);
@chmod($root, 0755);
$marks = $base . DS . 'marks';
@mkdir($marks, 0777);
@chmod($marks, 0777);
$sessions = $base . DS . 'sessions';
@mkdir($sessions, 0777);
@chmod($sessions, 0777);
$marker = $marks . DS . 'marker.log';

$warmup = '<?php
function t435_mark($line) { file_put_contents(' . var_export($marker, true) . ', $line . "\n", FILE_APPEND | LOCK_EX); }
function t435_warm_error() { return false; }
function t435_warm_exception($e) { t435_mark("warm-up exception handler"); }
register_shutdown_function(function () { t435_mark("warm-up shutdown"); });
register_shutdown_function("t435_mark", "warm-up shutdown with argument");
set_error_handler("t435_warm_error");
set_exception_handler("t435_warm_exception");
header("X-Warmup: yes");
http_response_code(503);
ini_set("session.save_path", ' . var_export($sessions, true) . ');
putenv("T435_WARMUP=yes");
session_start();
$_SESSION["warm"] = "warm-up session data";
ob_start();
echo "WARMUP-OUTPUT";
';
file_put_contents($base . DS . 'warmup.php', $warmup);

file_put_contents($root . DS . 'page.php', '<?php
header("Content-Type: application/json");
$id = isset($_GET["id"]) ? (string) $_GET["id"] : "?";
register_shutdown_function(function () use ($id) { t435_mark("request shutdown " . $id); });
$err = set_error_handler(function () { return false; }); restore_error_handler();
$exc = set_exception_handler(function ($e) {}); restore_exception_handler();
$warmHeader = false;
foreach (headers_list() as $h) { if (stripos($h, "X-Warmup") === 0) $warmHeader = true; }
echo json_encode(array(
	"pid" => getmypid(),
	"warmHeaderListed" => $warmHeader,
	"errorHandler" => is_string($err) ? $err : (is_null($err) ? "none" : "other"),
	"exceptionHandler" => is_string($exc) ? $exc : (is_null($exc) ? "none" : "other"),
	"session" => isset($_SESSION),
	"savePath" => ini_get("session.save_path") === ' . var_export($sessions, true) . ',
	"env" => getenv("T435_WARMUP"),
));
');

$n = 4;
foreach (array('persistent' => array(), 'fork-per-request' => array('forkPerRequest' => true)) as $mode => $ws) {
	@file_put_contents($marker, '');
	@chmod($marker, 0666);
	$ws['warmup'] = $base . DS . 'warmup.php';
	$ws['zygote'] = false;
	$name = $mode === 'persistent' ? 'wp' : 'wf';
	$port = rh_start($name, array('Q' => array('webserver' => $ws)), 1);

	$state = array();
	for ($i = 1; $i <= $n; ++$i) {
		$r = rh_get($port, '/page.php?id=' . $i);
		usleep(300000);
		$j = json_decode($r['body'], true);
		$own = substr_count((string) @file_get_contents($marker), "request shutdown $i\n");
		$state[] = sprintf('status=%d header=%s listed=%s error=%s exception=%s session=%s savePath=%s env=%s output=%s own=%d',
			$r['status'], isset($r['headers']['x-warmup']) ? 'yes' : 'no',
			!empty($j['warmHeaderListed']) ? 'yes' : 'no',
			var_export($j['errorHandler'] ?? 'unreadable', true),
			var_export($j['exceptionHandler'] ?? 'unreadable', true),
			!empty($j['session']) ? 'yes' : 'no', !empty($j['savePath']) ? 'warm-up' : 'boot',
			var_export($j['env'] ?? 'unreadable', true),
			strpos($r['body'], 'WARMUP-OUTPUT') !== false ? 'yes' : 'no', $own);
	}
	$want = "status=200 header=no listed=no error='none' exception='none' session=no savePath=boot env=false output=no own=1";
	check("$mode: no request carries the warm-up's headers, handlers, session, ini, environment or output, and each runs its own shutdown function",
		implode("\n", array_unique($state)), $want);

	rh_stop($GLOBALS['rh']['servers'][$name]);
	unset($GLOBALS['rh']['servers'][$name]);
	usleep(300000);
	$log = (string) @file_get_contents($marker);
	check("$mode: none of the warm-up's shutdown functions ran, in a worker or in the parent",
		substr_count($log, 'warm-up'), 0);
}

rh_finish();
