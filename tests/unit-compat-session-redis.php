<?php

/**
 * The compat session layer honours session.save_handler=redis, with the same
 * keys and encoding as phpredis's own session handler.
 *
 * A site served by Velocity on one node and PHP-FPM on another lost every
 * signed-in session when the load balancer switched nodes: FPM kept sessions
 * in Redis/Valkey, Velocity always in local files, whatever the ini said.
 *
 * Without a server: the save path parser. With VELOCITY_TEST_REDIS set to a
 * save path (e.g. "tcp://127.0.0.1:6379?database=15&prefix=velocitytest:"):
 * a session written by the compat layer is read by phpredis's own handler,
 * one written by phpredis is read by the compat layer, regenerate and destroy
 * move and remove the key, and an unreachable store gives an empty session
 * without an exception. Keys are removed again.
 *
 *   php tests/unit-compat-session-redis.php
 *   VELOCITY_TEST_REDIS='tcp://127.0.0.1:6379?database=15&prefix=velocitytest:' php tests/unit-compat-session-redis.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require_once __DIR__ . '/../src/Q/WebServer/Compat.php';
// Outside the server: no configuration overrides, cookies are only recorded.
if (!class_exists('Q_Config', false)) { class Q_Config { static function get(...$a) { return end($a); } } }
if (!class_exists('Q_Response', false)) { class Q_Response { static $cookies = array(); static function setCookie($n, $v) { self::$cookies[$n] = $v; } } }

$pass = 0;
$fail = 0;
function sr_check($what, $ok, $detail = '')
{
	global $pass, $fail;
	if ($ok) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s%s\n", $what, $detail !== '' ? "\n        $detail" : '');
}

// -- parser --------------------------------------------------------------------------------------------------------
$c = Q_WebServer_Compat::parseRedisSavePath('tcp://10.0.0.1:6380?database=5&prefix=alphasess:&timeout=2');
sr_check('host', $c['host'] === '10.0.0.1');
sr_check('port', $c['port'] === 6380);
sr_check('database', $c['database'] === 5);
sr_check('prefix', $c['prefix'] === 'alphasess:');
sr_check('timeout', $c['timeout'] === 2.0);
sr_check('no auth', $c['auth'] === null);
$c = Q_WebServer_Compat::parseRedisSavePath('127.0.0.1:6379');
sr_check('defaults: phpredis prefix', $c['prefix'] === 'PHPREDIS_SESSION:' && $c['database'] === 0 && $c['port'] === 6379);
$c = Q_WebServer_Compat::parseRedisSavePath('tcp://a:1?auth=s3cret, tcp://b:2');
sr_check('first of a list', $c['host'] === 'a' && $c['port'] === 1 && $c['auth'] === 's3cret');
$c = Q_WebServer_Compat::parseRedisSavePath('tcp://a:1?auth[]=u&auth[]=p');
sr_check('ACL user', $c['auth'] === array('u', 'p'));
$c = Q_WebServer_Compat::parseRedisSavePath('unix:///run/valkey.sock?database=2');
sr_check('unix socket', $c['host'] === '/run/valkey.sock' && $c['port'] === 0 && $c['database'] === 2);

// -- live round trip -----------------------------------------------------------------------------------------------
$path = getenv('VELOCITY_TEST_REDIS');
if ($path && class_exists('Redis', false)) {
	$cfg = Q_WebServer_Compat::parseRedisSavePath($path);
	$raw = new Redis();
	$raw->connect($cfg['host'], $cfg['port'], 2);
	if ($cfg['auth'] !== null) $raw->auth($cfg['auth']);
	$raw->select($cfg['database']);

	ini_set('session.save_handler', 'redis');
	ini_set('session.save_path', $path);
	ini_set('session.gc_maxlifetime', '120');
	ini_set('session.use_cookies', '0');
	ini_set('session.use_strict_mode', '0');

	// compat writes, phpredis reads
	$id = bin2hex(random_bytes(16));
	$_COOKIE['PHPSESSID'] = $id;
	Q_WebServer_Compat::_session_start();
	$_SESSION['eZUserLoggedInID'] = 61863;
	$_SESSION['list'] = array('a|b' => 0.1, 'x' => "line\nbreak");
	Q_WebServer_Compat::_session_write_close();
	$ttl = $raw->ttl($cfg['prefix'] . $id);
	sr_check('compat key carries gc_maxlifetime', $ttl > 100 && $ttl <= 120, "ttl $ttl");
	session_id($id);
	session_start();
	sr_check('phpredis reads what compat wrote', ($_SESSION['eZUserLoggedInID'] ?? null) === 61863
		&& ($_SESSION['list']['a|b'] ?? null) === 0.1, json_encode($_SESSION));
	$_SESSION['fromFpm'] = 'yes';
	session_write_close();

	// phpredis wrote, compat reads
	$_SESSION = array();
	Q_WebServer_Compat::_session_id($id);
	Q_WebServer_Compat::_session_start();
	sr_check('compat reads what phpredis wrote', ($_SESSION['fromFpm'] ?? null) === 'yes'
		&& ($_SESSION['eZUserLoggedInID'] ?? null) === 61863);

	// regenerate with delete: old key gone, new key has the data at once
	Q_WebServer_Compat::_session_regenerate_id(true);
	$newId = Q_WebServer_Compat::_session_id();
	sr_check('regenerate gives a new id', $newId !== $id && $newId !== '');
	sr_check('regenerate sends the new cookie', (Q_Response::$cookies['PHPSESSID'] ?? '') === $newId);
	sr_check('regenerate(true) removes the old key', !$raw->exists($cfg['prefix'] . $id));
	sr_check('regenerate writes the new key', (bool) $raw->exists($cfg['prefix'] . $newId));
	Q_WebServer_Compat::_session_destroy();
	sr_check('destroy removes the key', !$raw->exists($cfg['prefix'] . $newId));

	// unreachable store: empty session, no exception, page goes on
	ini_set('session.save_path', 'tcp://127.0.0.1:1?timeout=0.3');
	Q_WebServer_Compat::_session_id($id);
	$threw = false;
	try {
		$_SESSION = array('stale' => 1);
		Q_WebServer_Compat::_session_start();
		$_SESSION['x'] = 1;
		Q_WebServer_Compat::_session_write_close();
	} catch (Throwable $e) {
		$threw = $e->getMessage();
	}
	sr_check('a store that is down throws nothing', $threw === false, (string) $threw);
	sr_check('a store that is down gives an empty session', !isset($_SESSION['stale']));
	$raw->del($cfg['prefix'] . $id, $cfg['prefix'] . $newId);
} else {
	echo "  (live round trip skipped: set VELOCITY_TEST_REDIS to a save path)\n";
}

printf("%s: %d passed, %d failed\n", $fail ? 'FAIL' : 'PASS', $pass, $fail);
exit($fail ? 1 : 0);
