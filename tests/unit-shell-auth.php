#!/usr/bin/env php
<?php
/**
 * The shell's doors all ask for the same control panel session, and a
 * session that ends mid-way is refused at once.
 *
 * A real server with four workers, reached from 127.0.0.2 (remote). For
 * every shell route (session, exec, poll, jobs, complete, history, audit):
 *
 *   - no credential: 401;
 *   - only the cookie: 403 (writes need the token in a header, so another
 *     site cannot drive them from a visitor's browser);
 *   - X-Panel-Token or Authorization: Bearer: answered;
 *   - an unknown or expired token: 401;
 *   - a session signed in with the default key: refused until it changes it.
 *
 * Also: twenty commands run back to back through exec and poll all arrive,
 * each whole and in order, however the requests land on the workers; and
 * after sign-out the same token is refused by poll and exec.
 *
 *   php tests/unit-shell-auth.php
 */
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}
if (!function_exists('pcntl_fork')) { echo "  skip  needs pcntl\n"; exit(0); }
$server = __DIR__ . '/../sbin/qbixserver.php';
$ctl = __DIR__ . '/../sbin/qbixctl.php';
$remote = '127.0.0.2';

function freePort() { for ($i = 0; $i < 40; ++$i) { $p = 20600 + random_int(0, 1300); $s = @stream_socket_server("tcp://127.0.0.1:$p"); if ($s) { fclose($s); return $p; } } return 0; }
function req($port, $method, $path, $body = '', $headers = array())
{
	global $remote;
	$ctx = stream_context_create(array('socket' => array('bindto' => "$remote:0")));
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5, STREAM_CLIENT_CONNECT, $ctx);
	if (!$s) return array(0, array(), '');
	stream_set_timeout($s, 15);
	$h = "$method $path HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n";
	foreach ($headers as $k => $v) $h .= "$k: $v\r\n";
	if ($body !== '') $h .= "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n";
	fwrite($s, $h . "\r\n" . $body);
	$raw = stream_get_contents($s); fclose($s);
	$split = strpos($raw, "\r\n\r\n");
	if ($split === false) return array(0, array(), $raw);
	$status = preg_match('#^HTTP/\S+ (\d+)#', $raw, $m) ? (int) $m[1] : 0;
	$b = substr($raw, $split + 4);
	if (stripos(substr($raw, 0, $split), 'chunked') !== false) {
		$out = ''; while ($b !== '') { $nl = strpos($b, "\r\n"); if ($nl === false) break; $len = hexdec(substr($b, 0, $nl)); if (!$len) break; $out .= substr($b, $nl + 2, $len); $b = substr($b, $nl + 2 + $len + 2); } $b = $out;
	}
	return array($status, array(), $b);
}
function start($base, array $q)
{
	global $server;
	@mkdir($base . DS . 'web', 0700, true);
	file_put_contents($base . DS . 'web' . DS . 'index.php', '<?php echo "APP";');
	$port = freePort();
	file_put_contents($base . DS . 'config.json', json_encode(array('Q' => $q)));
	$proc = proc_open(array(PHP_BINARY, $server, '--config=' . $base . DS . 'config.json', '--root=' . $base . DS . 'web',
		'--port=' . $port, '--workers=4'), array(0 => array('file', '/dev/null', 'r'),
		1 => array('file', $base . DS . 'log', 'w'), 2 => array('file', $base . DS . 'log', 'a')), $pipes, $base);
	for ($i = 0; $i < 60; ++$i) { $s = @stream_socket_client("tcp://127.0.0.1:$port", $e, $es, 1); if ($s) { fclose($s); return array($proc, $port); } usleep(250000); }
	return array($proc, 0);
}
function stop($proc) { @proc_terminate($proc, 15); for ($i = 0; $i < 40; ++$i) { $s = @proc_get_status($proc); if (!$s || empty($s['running'])) break; usleep(250000); } @proc_terminate($proc, 9); @proc_close($proc); }
function login($port, $pw) { list($st, , $b) = req($port, 'POST', '/Q/api/auth/login', json_encode(array('password' => $pw))); return (json_decode($b, true) ?: array()) + array('_status' => $st); }
/** Run a command through exec and collect its output by polling. */
function runCmd($port, $hdr, $session, $cmd, &$since)
{
	list($st, , $b) = req($port, 'POST', '/Q/api/shell/exec', json_encode(array('command' => $cmd, 'session' => $session)), $hdr);
	$job = (json_decode($b, true) ?: array())['job'] ?? null;
	if ($st !== 202 || !$job) return array($st, null);
	$out = ''; $done = false;
	for ($i = 0; $i < 100 && !$done; ++$i) {
		list(, , $pb) = req($port, 'GET', '/Q/api/shell/poll?session=' . $session . '&since=' . $since, '', $hdr);
		$j = json_decode($pb, true) ?: array();
		foreach ($j['messages'] ?? array() as $m) {
			if (($m['id'] ?? '') !== $job) continue;
			if ($m['t'] === 'out') $out .= $m['d'];
			if ($m['t'] === 'exit') $done = true;
		}
		if (isset($j['next'])) $since = $j['next'];
		if (!$done) usleep(100000);
	}
	return array($st, $done ? $out : null);
}

$pw = 'Sh3#Lk9!Vq2@Mz7Xp';
$base = sys_get_temp_dir() . DS . 'qbix-shell-auth-' . getmypid();
@mkdir($base . DS . 'web', 0700, true);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ctl) . ' panel:password --root=' . escapeshellarg($base . DS . 'web') . ' --password=' . escapeshellarg($pw) . ' 2>&1', $o, $rc);
check('qbixctl panel:password', $rc, 0);
list($proc, $port) = start($base, array('panel' => array('defaultPassword' => null)));
check('server started', $port > 0, true);
if ($port) {
	$s = login($port, $pw); $tok = (string) ($s['token'] ?? '');
	check('signed in', $tok !== '', true);
	$routes = array(
		array('GET', '/Q/api/shell/session?session=a', ''),
		array('POST', '/Q/api/shell/exec', json_encode(array('command' => 'echo hi', 'session' => 'a'))),
		array('GET', '/Q/api/shell/poll?session=a&since=0', ''),
		array('GET', '/Q/api/shell/jobs', ''),
		array('GET', '/Q/api/shell/complete?session=a&line=ec&pos=2', ''),
		array('GET', '/Q/api/shell/history', ''),
		array('GET', '/Q/api/shell/audit?limit=5', ''),
	);
	foreach ($routes as $r) {
		list($m, $path, $body) = $r;
		$name = "$m " . strtok($path, '?');
		list($st) = req($port, $m, $path, $body);
		check("$name: no credential is 401", $st, 401);
		list($st) = req($port, $m, $path, $body, array('Cookie' => "Q_panel_token=$tok"));
		check("$name: the cookie alone is 403 (header required)", $st, 403);
		list($st) = req($port, $m, $path, $body, array('X-Panel-Token' => $tok));
		check("$name: X-Panel-Token is answered", in_array($st, array(200, 202), true), true);
		list($st) = req($port, $m, $path, $body, array('Authorization' => "Bearer $tok"));
		check("$name: Bearer is answered", in_array($st, array(200, 202), true), true);
		list($st) = req($port, $m, $path, $body, array('X-Panel-Token' => str_repeat('0', 64)));
		check("$name: an unknown or expired token is 401", $st, 401);
	}

	// Twenty commands, each through exec and poll, across four workers.
	$hdr = array('X-Panel-Token' => $tok); $since = 0; $okAll = true; $bad = '';
	for ($i = 1; $i <= 20; ++$i) {
		list($st, $out) = runCmd($port, $hdr, 'multi', "echo line-$i", $since);
		if ($st !== 202 || trim((string) $out) !== "line-$i") { $okAll = false; $bad = "#$i: $st " . var_export($out, true); break; }
	}
	check('twenty exec+poll round trips all complete, whole and in order' . ($bad ? " ($bad)" : ''), $okAll, true);

	// Signed out mid-session: the same token is refused at once.
	req($port, 'POST', '/Q/api/auth/logout', '{}', $hdr);
	list($st) = req($port, 'GET', '/Q/api/shell/poll?session=multi&since=' . $since, '', $hdr);
	check('after sign-out, poll refuses the token', $st, 401);
	list($st) = req($port, 'POST', '/Q/api/shell/exec', json_encode(array('command' => 'echo x', 'session' => 'multi')), $hdr);
	check('after sign-out, exec refuses the token', $st, 401);
	stop($proc);
}

// A session signed in with the default key must change it first.
$base2 = sys_get_temp_dir() . DS . 'qbix-shell-auth-d-' . getmypid();
list($proc2, $port2) = start($base2, array());
if ($port2) {
	$s = login($port2, 'panel'); $tok2 = (string) ($s['token'] ?? '');
	check('the default key signs in, marked must-change', array($tok2 !== '', !empty($s['mustChange'])), array(true, true));
	list($st) = req($port2, 'POST', '/Q/api/shell/exec', json_encode(array('command' => 'echo hi')), array('X-Panel-Token' => $tok2));
	check('a must-change session cannot run shell commands', $st, 403);
	list($st) = req($port2, 'GET', '/Q/api/shell/poll?session=a&since=0', '', array('X-Panel-Token' => $tok2));
	check('...nor poll', $st, 403);
	stop($proc2);
}
echo $fail ? "  FAIL - $fail of " . ($pass + $fail) . " case(s)\n" : "  PASS - $pass case(s)\n";
exit($fail ? 1 : 0);
