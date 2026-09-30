<?php
/**
 * Shared by the tests/unit-uwebserver-*.php tests: build uwebserver from
 * native/uwebserver/ into a directory of the test's own, run it, start it on
 * a free port of 127.0.0.1 and talk HTTP to it.
 *
 * Every server a test starts listens on 127.0.0.1 and on a port the kernel
 * handed out as free (bind to port 0); uw_start() refuses anything else, so a
 * test can never take a port or an address a real server uses.
 *
 * No C compiler or no OpenSSL headers: uw_build() returns null and the test
 * reports itself skipped, as a test that needs a program the machine lacks.
 */

$uw_pass = 0;
$uw_fail = 0;
$uw_servers = array();
$uw_dir = null;

function check($what, $got, $want = true)
{
	global $uw_pass, $uw_fail;
	if ($got === $want) { ++$uw_pass; return true; }
	++$uw_fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
	return false;
}

function uw_finish()
{
	global $uw_pass, $uw_fail;
	uw_stop_all();
	uw_cleanup();
	if ($uw_fail === 0) { printf("  PASS - %d case(s)\n", $uw_pass); exit(0); }
	printf("  FAIL - %d of %d case(s)\n", $uw_fail, $uw_pass + $uw_fail);
	exit(1);
}

function uw_skip($why)
{
	uw_cleanup();
	printf("  PASS - 0 case(s), skipped: %s\n", $why);
	exit(0);
}

/** A directory of this test's own, removed at the end. */
function uw_dir()
{
	global $uw_dir;
	if ($uw_dir === null) {
		$uw_dir = sys_get_temp_dir() . '/uweb-test-' . getmypid() . '-' . bin2hex(random_bytes(3));
		mkdir($uw_dir, 0700, true);
	}
	return $uw_dir;
}

function uw_rmtree($d)
{
	if (!is_dir($d) || is_link($d)) { @unlink($d); return; }
	foreach (scandir($d) as $f) {
		if ($f === '.' || $f === '..') continue;
		uw_rmtree("$d/$f");
	}
	@rmdir($d);
}

function uw_cleanup()
{
	global $uw_dir;
	if ($uw_dir !== null) { uw_rmtree($uw_dir); $uw_dir = null; }
}

/**
 * Builds the program with the flags given (the defaults are the release
 * flags of native/uwebserver/Makefile when it exists, else -O2) and returns
 * its path, or null when it cannot be built here.
 */
function uw_build($extra = '', $name = 'uwebserver')
{
	static $built = array();
	if (isset($built[$name])) return $built[$name];
	$root = dirname(__DIR__);
	$cc = trim((string) shell_exec('command -v cc gcc 2>/dev/null | head -1'));
	if ($cc === '' || !is_file('/usr/include/openssl/ssl.h')) return $built[$name] = null;
	$out = uw_dir() . '/' . $name;
	$ver = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' describe --tags --abbrev=0 2>/dev/null')) ?: 'v0.0.0';
	$cmd = escapeshellarg($cc) . ' -O2 -Wall -Wextra ' . $extra
		. ' -DUWEB_VERSION=' . escapeshellarg('"' . $ver . '"') . ' -DUWEB_BUILD=' . escapeshellarg('"test"')
		. ' -o ' . escapeshellarg($out) . ' ' . escapeshellarg("$root/native/uwebserver/uwebserver.c")
		. ' -lssl -lcrypto 2>&1';
	exec($cmd, $o, $rc);
	if ($rc !== 0) {
		echo "  build failed:\n    " . implode("\n    ", array_slice($o, 0, 30)) . "\n";
		return $built[$name] = false;
	}
	return $built[$name] = $out;
}

/**
 * Runs the program to completion (at most $timeout seconds) and returns
 * array(exit status, stdout, stderr, seconds). A run that has not ended is
 * killed and reported with status -1.
 */
function uw_run($bin, array $args, $timeout = 5, array $env = null)
{
	$spec = array(0 => array('pipe', 'r'), 1 => array('file', uw_dir() . '/run.out', 'w'), 2 => array('file', uw_dir() . '/run.err', 'w'));
	$t = microtime(true);
	$p = proc_open(array_merge(array($bin), $args), $spec, $pipes, uw_dir(), $env);
	fclose($pipes[0]);
	$rc = null;
	while (microtime(true) - $t < $timeout) {
		$st = proc_get_status($p);
		if (!$st['running']) { $rc = $st['exitcode']; break; }
		usleep(20000);
	}
	if ($rc === null) { proc_terminate($p, 9); proc_close($p); $rc = -1; }
	else proc_close($p);
	return array($rc, (string) file_get_contents(uw_dir() . '/run.out'), (string) file_get_contents(uw_dir() . '/run.err'), microtime(true) - $t);
}

/** A port on 127.0.0.1 that was free a moment ago, never one of the server ports of this host. */
function uw_free_port()
{
	for ($i = 0; $i < 50; $i++) {
		$s = stream_socket_server('tcp://127.0.0.1:0', $e, $es);
		$name = stream_socket_get_name($s, false);
		fclose($s);
		$port = (int) substr($name, strrpos($name, ':') + 1);
		if ($port >= 10000 && !in_array($port, array(80, 443, 8070, 8080, 8088, 8089, 8443), true)) return $port;
	}
	throw new RuntimeException('no free port');
}

/** --allow-root when the test runs as root: uwebserver refuses to serve as root otherwise. */
function uw_root_args()
{
	return function_exists('posix_geteuid') && posix_geteuid() === 0 ? array('--allow-root') : array();
}

/** The address a TCP port on this host is listening on, from ss(8), or null. */
function uw_bound_address($port)
{
	$out = (string) shell_exec('ss -ltnH 2>/dev/null');
	foreach (explode("\n", $out) as $line) {
		$f = preg_split('/\s+/', trim($line));
		if (count($f) < 4) continue;
		$local = $f[3];
		$p = strrpos($local, ':');
		if ($p !== false && (int) substr($local, $p + 1) === (int) $port) return substr($local, 0, $p);
	}
	return null;
}

/** True when something accepts connections on 127.0.0.1:$port. */
function uw_listening($port, $host = '127.0.0.1')
{
	$fp = @stream_socket_client("tcp://$host:$port", $e, $es, 0.3);
	if (!$fp) return false;
	fclose($fp);
	return true;
}

/**
 * Starts the server with $args, which must bind 127.0.0.1 only: every
 * --listen / --tls-listen / --bind value is checked before it runs. Waits
 * until $port answers. Returns array('proc', 'pid', 'port', 'err') or null.
 */
function uw_start($bin, array $args, $port, $wait = 5, array $env = null)
{
	global $uw_servers;
	if ($port < 10000 || in_array((int) $port, array(8070, 8080, 8088, 8089, 8443), true)) throw new RuntimeException("refusing to start a test server on port $port");
	foreach ($args as $i => $a) {
		if (preg_match('/^--?(listen|tls-listen|bind)(=(.*))?$/', $a, $m)) {
			$v = isset($m[3]) && $m[2] !== '' ? $m[3] : ($args[$i + 1] ?? '');
			if (!preg_match('/^(127\.0\.0\.1|\[::1\])(:\d+)?$|^\d+$/', $v)) throw new RuntimeException("refusing to start a test server on $v");
		}
	}
	$err = uw_dir() . '/server-' . count($uw_servers) . '.err';
	$spec = array(0 => array('pipe', 'r'), 1 => array('file', uw_dir() . '/server.out', 'a'), 2 => array('file', $err, 'w'));
	$p = proc_open(array_merge(array($bin), $args), $spec, $pipes, uw_dir(), $env);
	fclose($pipes[0]);
	$st = proc_get_status($p);
	$h = array('proc' => $p, 'pid' => $st['pid'], 'port' => $port, 'err' => $err);
	$uw_servers[] = $h;
	$t = microtime(true);
	while (microtime(true) - $t < $wait) {
		if (uw_listening($port)) return $h;
		$st = proc_get_status($p);
		if (!$st['running']) break;
		usleep(30000);
	}
	return null;
}

/** Stops a server (SIGTERM, then SIGKILL after 5 s) and returns its exit status (-1 if killed). */
function uw_stop($h, $sig = 15)
{
	global $uw_servers;
	if (!$h) return null;
	foreach ($uw_servers as $i => $s) if ($s['pid'] === $h['pid']) unset($uw_servers[$i]);
	$st = proc_get_status($h['proc']);
	$code = $st['running'] ? null : $st['exitcode'];
	if ($st['running']) {
		posix_kill($h['pid'], $sig);
		$t = microtime(true);
		while (microtime(true) - $t < 5) {
			$st = proc_get_status($h['proc']);
			if (!$st['running']) { $code = $st['exitcode']; break; }
			usleep(20000);
		}
		if ($st['running']) { posix_kill($h['pid'], 9); $code = -1; }
	}
	@proc_close($h['proc']);
	return $code;
}

function uw_stop_all()
{
	global $uw_servers;
	foreach ($uw_servers as $h) {
		$st = @proc_get_status($h['proc']);
		if ($st && $st['running']) { posix_kill($h['pid'], 15); usleep(100000); $st = @proc_get_status($h['proc']); if ($st && $st['running']) posix_kill($h['pid'], 9); }
	}
	$uw_servers = array();
}

/**
 * Sends $raw to 127.0.0.1:$port (TLS when $tls) and returns everything the
 * server sent until it closed or $timeout seconds passed without data.
 */
function uw_raw($port, $raw, $timeout = 3, $tls = false)
{
	$ctx = stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false)));
	$fp = @stream_socket_client(($tls ? 'tls' : 'tcp') . "://127.0.0.1:$port", $e, $es, 3, STREAM_CLIENT_CONNECT, $ctx);
	if (!$fp) return 'CONNECT_FAIL';
	if ($raw !== '') fwrite($fp, $raw);
	stream_set_timeout($fp, (int) ceil($timeout));
	$r = '';
	while (!feof($fp)) {
		$c = @fread($fp, 65536);
		if ($c === false || $c === '') {
			$m = stream_get_meta_data($fp);
			if ($m['timed_out'] || $c === false) break;
			continue;
		}
		$r .= $c;
	}
	fclose($fp);
	return $r;
}

/** One request with Connection: close; returns array(status, headers (lower-case names), body). */
function uw_get($port, $path, array $headers = array(), $method = 'GET', $tls = false)
{
	$req = "$method $path HTTP/1.1\r\nHost: localhost\r\n";
	foreach ($headers as $k => $v) $req .= "$k: $v\r\n";
	$req .= "Connection: close\r\n\r\n";
	return uw_split(uw_raw($port, $req, 3, $tls));
}

function uw_split($r)
{
	$p = strpos($r, "\r\n\r\n");
	if ($p === false) return array(0, array(), $r);
	$head = explode("\r\n", substr($r, 0, $p));
	$status = preg_match('#^HTTP/1\.[01] (\d{3})#', $head[0], $m) ? (int) $m[1] : 0;
	$h = array();
	foreach (array_slice($head, 1) as $line) {
		$c = strpos($line, ':');
		if ($c !== false) $h[strtolower(substr($line, 0, $c))] = trim(substr($line, $c + 1));
	}
	return array($status, $h, substr($r, $p + 4));
}
