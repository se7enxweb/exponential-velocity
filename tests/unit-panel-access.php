<?php
/**
 * The control panel answers on both protocols, by one rule, and nothing the
 * application says can take its place.
 *
 * Found on a live server: /Q/panel showed the application's 404. Over HTTP/2
 * -- every browser -- nothing in the server answered the panel, so it was
 * handed to the application; the response cache then stored that 404 under
 * /Q/panel and served it over HTTP/1.1 as well.
 *
 * Asserted, over HTTP/1.1 and HTTP/2, from 127.0.0.1 (this machine) and from
 * 127.0.0.2 (standing in for a remote address; Linux answers it on loopback):
 *
 *   - from this machine the panel is served on both protocols;
 *   - from elsewhere with no password set, it is refused with the server's
 *     403 page, which says how to set one;
 *   - where the panel is reachable remotely without a password
 *     (Q.dashboard.remote), a remote visitor cannot set the first password;
 *     from this machine the setup works;
 *   - once a password exists -- set through the page, or with
 *     `qbixctl panel:password` -- a remote visitor gets the login form, and
 *     that password signs in;
 *   - a script under /Q/ that says it may be cached is never answered from
 *     the response cache, while an ordinary page is.
 *
 *   php tests/unit-panel-access.php
 */
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

$pass = 0;
$fail = 0;

function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what,
		var_export($got, true), var_export($want, true));
}

if (!function_exists('pcntl_fork')) { printf("  skip  needs pcntl for the worker pool\n"); exit(0); }
if (PHP_OS_FAMILY !== 'Linux') { printf("  skip  needs 127.0.0.2 to be a loopback address\n"); exit(0); }

$http2 = function_exists('curl_init') && defined('CURL_HTTP_VERSION_2TLS')
	&& (curl_version()['features'] & CURL_VERSION_HTTP2)
	&& function_exists('openssl_pkey_new');

$server = __DIR__ . '/../sbin/qbixserver.php';
$ctl = __DIR__ . '/../sbin/qbixctl.php';
$local = '127.0.0.1';
$remote = '127.0.0.2';

function freePort()
{
	for ($i = 0; $i < 40; ++$i) {
		$p = 19100 + random_int(0, 1400);
		$probe = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
		if ($probe) { fclose($probe); return $p; }
	}
	return 0;
}

/** HTTP/1.1 from $from. Returns [status, headers (lowercase names), body]. */
function h1($port, $from, $method, $path, $body = '', $headers = array())
{
	$ctx = stream_context_create(array('socket' => array('bindto' => "$from:0")));
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5, STREAM_CLIENT_CONNECT, $ctx);
	if (!$s) return array(0, array(), '');
	stream_set_timeout($s, 15);
	$h = "$method $path HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n";
	foreach ($headers as $k => $v) $h .= "$k: $v\r\n";
	if ($body !== '') $h .= "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n";
	fwrite($s, $h . "\r\n" . $body);
	$raw = '';
	while (!feof($s)) {
		$c = fread($s, 8192);
		if ($c === false or $c === '') { if (stream_get_meta_data($s)['timed_out']) break; continue; }
		$raw .= $c;
	}
	fclose($s);
	$split = strpos($raw, "\r\n\r\n");
	if ($split === false) return array(0, array(), $raw);
	$head = substr($raw, 0, $split);
	$status = preg_match('#^HTTP/\S+ (\d+)#', $head, $m) ? (int) $m[1] : 0;
	$hdrs = array();
	foreach (explode("\r\n", $head) as $line) {
		if (strpos($line, ':') !== false) { list($k, $v) = explode(':', $line, 2); $hdrs[strtolower(trim($k))] = trim($v); }
	}
	$b = substr($raw, $split + 4);
	if (stripos($hdrs['transfer-encoding'] ?? '', 'chunked') !== false) {
		$out = '';
		while ($b !== '') {
			$nl = strpos($b, "\r\n"); if ($nl === false) break;
			$len = hexdec(substr($b, 0, $nl)); if ($len === 0) break;
			$out .= substr($b, $nl + 2, $len); $b = substr($b, $nl + 2 + $len + 2);
		}
		$b = $out;
	}
	return array($status, $hdrs, $b);
}

/** HTTP/2 over TLS from $from, through curl. Same return as h1(), plus the version in headers['_version']. */
function h2($port, $from, $method, $path, $body = '', $headers = array())
{
	$ch = curl_init("https://127.0.0.1:$port$path");
	$hl = array();
	foreach ($headers as $k => $v) $hl[] = "$k: $v";
	if ($body !== '') $hl[] = 'Content-Type: application/json';
	$opts = array(
		CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,
		CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
		CURLOPT_INTERFACE => $from, CURLOPT_HTTPHEADER => $hl, CURLOPT_TIMEOUT => 15,
		CURLOPT_CUSTOMREQUEST => $method,
	);
	if ($body !== '') $opts[CURLOPT_POSTFIELDS] = $body;
	curl_setopt_array($ch, $opts);
	$raw = (string) curl_exec($ch);
	$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	$version = curl_getinfo($ch, CURLINFO_HTTP_VERSION);
	curl_close($ch);
	$hdrs = array('_version' => $version);
	foreach (explode("\r\n", substr($raw, 0, $hsize)) as $line) {
		if (strpos($line, ':') !== false) { list($k, $v) = explode(':', $line, 2); $hdrs[strtolower(trim($k))] = trim($v); }
	}
	return array($status, $hdrs, substr($raw, $hsize));
}

function makeCert($dir)
{
	$key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
	$csr = openssl_csr_new(array('commonName' => '127.0.0.1'), $key, array('digest_alg' => 'sha256'));
	$crt = openssl_csr_sign($csr, null, $key, 1, array('digest_alg' => 'sha256'));
	openssl_x509_export($crt, $crtPem);
	openssl_pkey_export($key, $keyPem);
	file_put_contents($dir . DS . 'fullchain.pem', $crtPem);
	file_put_contents($dir . DS . 'privkey.pem', $keyPem);
}

/** Start a server in $base with extra Q config; returns [proc, port, tlsPort] or null. */
function startServer($base, array $q)
{
	global $server, $http2;
	$root = $base . DS . 'web';
	$port = freePort(); $tls = freePort();
	if (!$port or !$tls or $port === $tls) return null;
	$q['web']['cache'] = $q['web']['cache'] ?? array('enabled' => true, 'dir' => $base . DS . 'cache');
	if ($http2) {
		makeCert($base);
		$q['web']['https'] = array('mode' => 'manual', 'port' => $tls,
			'cert' => $base . DS . 'fullchain.pem', 'key' => $base . DS . 'privkey.pem', 'certsDir' => $base);
		$q['web']['http2'] = array('enabled' => true);
	}
	file_put_contents($base . DS . 'config.json', json_encode(array('Q' => $q)));
	$proc = proc_open(array(PHP_BINARY, $server, '--config=' . $base . DS . 'config.json',
		'--root=' . $root, '--port=' . $port, '--workers=2'), array(
		0 => array('file', '/dev/null', 'r'),
		1 => array('file', $base . DS . 'log', 'w'),
		2 => array('file', $base . DS . 'log', 'a'),
	), $pipes, $base);
	for ($i = 0; $i < 60; ++$i) {
		$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 1);
		if ($s) { fclose($s); return array($proc, $port, $tls); }
		usleep(250000);
	}
	return array($proc, 0, 0);
}

function stopServer($proc)
{
	$st = @proc_get_status($proc);
	if ($st and !empty($st['running'])) {
		@proc_terminate($proc, 15);
		for ($i = 0; $i < 40; ++$i) { $n = @proc_get_status($proc); if (!$n or empty($n['running'])) break; usleep(250000); }
		$n = @proc_get_status($proc);
		if ($n and !empty($n['running'])) @proc_terminate($proc, 9);
	}
	@proc_close($proc);
}

function setupSite($name)
{
	$base = sys_get_temp_dir() . DS . 'qbix-panel-' . $name . '-' . getmypid();
	@mkdir($base . DS . 'web' . DS . 'Q', 0700, true);
	file_put_contents($base . DS . 'web' . DS . 'Q' . DS . 'probe.php', '<?php
$f = __DIR__ . "/../probe.count"; $n = (int) @file_get_contents($f) + 1; file_put_contents($f, $n);
Q_Response::header("Cache-Control: public, max-age=300"); echo "PROBE ", $n;');
	file_put_contents($base . DS . 'web' . DS . 'page.php', '<?php
$f = __DIR__ . "/page.count"; $n = (int) @file_get_contents($f) + 1; file_put_contents($f, $n);
Q_Response::header("Cache-Control: public, max-age=300"); echo "PAGE ", $n;');
	return $base;
}

$protocols = array('HTTP/1.1' => 'h1');
if ($http2) $protocols['HTTP/2'] = 'h2';
else printf("  skip  HTTP/2 half: no HTTP/2 in curl, or no openssl extension\n");
$portFor = function ($proto, $port, $tls) { return $proto === 'h2' ? $tls : $port; };
$logs = array();

// ── Server A: defaults (no password, panel not remote) ──────────
$baseA = setupSite('a');
$srv = startServer($baseA, array('panel' => array('defaultPassword' => null)));
check('server A started', $srv !== null && $srv[1] > 0, true);
if ($srv and $srv[1]) {
	list($procA, $port, $tls) = $srv;
	foreach ($protocols as $label => $fn) {
		$p = $portFor($fn, $port, $tls);
		list($st, $h, $b) = $fn($p, $local, 'GET', '/Q/panel');
		check("A, $label, this machine: /Q/panel is the panel", array($st, strpos($b, 'Control Panel') !== false), array(200, true));
		if ($fn === 'h2') check("A, $label: the request really went over HTTP/2", $h['_version'] ?? null, CURL_HTTP_VERSION_2_0);

		list($st, $h, $b) = $fn($p, $remote, 'GET', '/Q/panel');
		check("A, $label, remote, no password: refused with 403", $st, 403);
		check("A, $label, ...as the server's HTML page", strpos($h['content-type'] ?? '', 'text/html') === 0, true);
		check("A, $label, ...which says how to set a password", strpos($b, 'qbixctl panel:password') !== false, true);

		list($st, $h, $b) = $fn($p, $remote, 'POST', '/Q/api/auth/setup', json_encode(array('password' => 'In7#Tr9!Dx3@Ub5Kq')));
		check("A, $label, remote: the API refuses too", $st, 403);
	}
	check('A: no password was stored by a remote visitor', is_file($baseA . DS . 'local' . DS . 'panel.json'), false);

	// The response cache never answers for /Q/.
	foreach ($protocols as $label => $fn) {
		$p = $portFor($fn, $port, $tls);
		@unlink($baseA . DS . 'web' . DS . 'probe.count');
		list(, $h1r, $b1) = $fn($p, $local, 'GET', '/Q/probe.php');
		list(, $h2r, $b2) = $fn($p, $local, 'GET', '/Q/probe.php');
		check("A, $label: a cacheable script under /Q/ runs every time", array(trim($b1), trim($b2)), array('PROBE 1', 'PROBE 2'));
		check("A, $label: ...and is never marked a cache hit", isset($h2r['x-cache']) && stripos($h2r['x-cache'], 'HIT') !== false, false);
	}
	@unlink($baseA . DS . 'web' . DS . 'page.count');
	h1($port, $local, 'GET', '/page.php');
	list(, $hc, $bc) = h1($port, $local, 'GET', '/page.php');
	check('A: an ordinary cacheable page is answered from the cache (the cache is on)', trim($bc), 'PAGE 1');

	// The CLI sets a password; the running server honours it on the next request.
	$out = array();
	exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ctl) . ' panel:password --root=' . escapeshellarg($baseA . DS . 'web')
		. ' --password=Cl1#Rk7!Wm9@Pz4Xq 2>&1', $out, $rc);
	check('qbixctl panel:password exits 0', $rc, 0);
	check('...and says where it wrote', strpos(implode("\n", $out), $baseA . DS . 'local' . DS . 'panel.json') !== false, true);
	foreach ($protocols as $label => $fn) {
		$p = $portFor($fn, $port, $tls);
		list($st, , $b) = $fn($p, $remote, 'GET', '/Q/panel');
		check("A, $label, remote, password set by the CLI: the page is served (login form)", array($st, strpos($b, 'Control Panel') !== false), array(200, true));
		list($st, , $b) = $fn($p, $remote, 'POST', '/Q/api/auth/login', json_encode(array('password' => 'Cl1#Rk7!Wm9@Pz4Xq')));
		$token = (json_decode($b, true) ?: array())['token'] ?? '';
		check("A, $label, remote: the CLI's password signs in", array($st, $token !== ''), array(200, true));
		list($st) = $fn($p, $remote, 'GET', '/Q/api/system', '', array('X-Panel-Token' => $token));
		check("A, $label, remote: ...and the session reaches the API", $st, 200);
		list($st) = $fn($p, $remote, 'GET', '/Q/api/system');
		check("A, $label, remote: the API still needs a session", $st, 401);

		// One session for every /Q/ view: signed in on the panel, the cookie
		// alone opens the dashboard and phpinfo, and the shell takes it too.
		// (On a live server the dashboard answered 403 to a signed-in visitor.)
		list($st, $h) = $fn($p, $remote, 'POST', '/Q/api/auth/login', json_encode(array('password' => 'Cl1#Rk7!Wm9@Pz4Xq')));
		$sc = (string) ($h['set-cookie'] ?? '');
		preg_match('/Q_panel_token=([^;]+)/', $sc, $cm);
		$ck = $cm[1] ?? '';
		check("A, $label, remote: sign-in sets the session cookie for every /Q/ path",
			array($ck !== '', stripos($sc, 'Path=/') !== false, stripos($sc, 'SameSite=Lax') !== false), array(true, true, true));
		list($st) = $fn($p, $remote, 'GET', '/Q/dashboard', '', array('Cookie' => "Q_panel_token=$ck"));
		check("A, $label, remote, signed in on the panel: /Q/dashboard answers 200", $st, 200);
		list($st) = $fn($p, $remote, 'GET', '/Q/phpinfo', '', array('Cookie' => "Q_panel_token=$ck"));
		check("A, $label, remote, signed in: /Q/phpinfo answers 200", $st, 200);
		list($st) = $fn($p, $remote, 'GET', '/Q/dashboard', '', array('Cookie' => "Q_panel_token=stale0000; Q_panel_token=$ck"));
		check("A, $label, remote: a stale cookie beside the live one does not hide it", $st, 200);
		list($st, $h) = $fn($p, $remote, 'GET', '/Q/dashboard', '', array('Cookie' => "Q_panel_token=$ck"));
		check("A, $label: the dashboard is never stored by a cache (no-store)", stripos((string) ($h['cache-control'] ?? ''), 'no-store') !== false, true);
		list($st) = $fn($p, $remote, 'GET', '/Q/api/shell/session?session=t', '', array('X-Panel-Token' => $ck));
		check("A, $label, remote: the shell takes the same session", $st, 200);
		list($st) = $fn($p, $remote, 'GET', '/Q/api/shell/poll?session=t&since=0');
		check("A, $label, remote: shell polling refuses without a session", $st, 401);
		list($st, $h) = $fn($p, $remote, 'POST', '/Q/api/auth/logout', '{}', array('X-Panel-Token' => $ck, 'Cookie' => "Q_panel_token=$ck"));
		check("A, $label: sign-out clears the cookie", stripos((string) ($h['set-cookie'] ?? ''), 'Max-Age=0') !== false, true);
		list($st) = $fn($p, $remote, 'GET', '/Q/dashboard', '', array('Cookie' => "Q_panel_token=$ck"));
		check("A, $label, remote: after sign-out the dashboard refuses again", in_array($st, array(302, 403), true), true);
	}
	stopServer($procA);
	$logs['A'] = $baseA . DS . 'log';
}

// ── Server B: reachable remotely without a password ─────────────
$baseB = setupSite('b');
$srv = startServer($baseB, array('dashboard' => array('remote' => true), 'panel' => array('defaultPassword' => null)));
check('server B started', $srv !== null && $srv[1] > 0, true);
if ($srv and $srv[1]) {
	list($procB, $port, $tls) = $srv;
	foreach ($protocols as $label => $fn) {
		$p = $portFor($fn, $port, $tls);
		list($st, , $b) = $fn($p, $remote, 'GET', '/Q/panel');
		check("B, $label, remote: the page is served", $st, 200);
		list($st, , $b) = $fn($p, $remote, 'POST', '/Q/api/auth/login', '{}');
		$d = json_decode($b, true) ?: array();
		check("B, $label, remote: it is told a password is needed and may not be set from here",
			array($d['needsSetup'] ?? null, $d['setupAllowed'] ?? null), array(true, false));
		list($st, , $b) = $fn($p, $remote, 'POST', '/Q/api/auth/setup', json_encode(array('password' => 'In7#Tr9!Dx3@Ub5Kq')));
		check("B, $label, remote: first-time setup is refused", $st, 403);
	}
	check('B: nothing was stored by the remote attempts', is_file($baseB . DS . 'local' . DS . 'panel.json'), false);
	$fn = $http2 ? 'h2' : 'h1';
	list($st, , $b) = $fn($portFor($fn, $port, $tls), $local, 'POST', '/Q/api/auth/setup', json_encode(array('password' => 'Lo5#Vt8!Qn2@Hy6Wd')));
	check('B: setup from this machine works', array($st, isset((json_decode($b, true) ?: array())['token'])), array(200, true));
	list($st, , $b) = h1($port, $remote, 'POST', '/Q/api/auth/login', json_encode(array('password' => 'Lo5#Vt8!Qn2@Hy6Wd')));
	check('B, remote: that password signs in', isset((json_decode($b, true) ?: array())['token']), true);
	stopServer($procB);
	$logs['B'] = $baseB . DS . 'log';
}

// ── Server C: the default key ("panel") in force ────────────────
// Each protocol starts from no password file. The remote addresses differ
// per step so the lockout (5 failures per address) never gets in the way.
$baseC = setupSite('c');
$srv = startServer($baseC, array());
check('server C started', $srv !== null && $srv[1] > 0, true);
$strong = 'Ch4#Nq8!Wz2@Kv6Pt';
if ($srv and $srv[1]) {
	list($procC, $port, $tls) = $srv;
	$fileC = $baseC . DS . 'local' . DS . 'panel.json';
	foreach ($protocols as $label => $fn) {
		$p = $portFor($fn, $port, $tls);
		$ip = $fn === 'h2' ? '127.0.0.4' : '127.0.0.3';
		@unlink($fileC);
		list($st, , $b) = $fn($p, $ip, 'GET', '/Q/panel');
		check("C, $label, remote: with the default in force the panel page is served", $st, 200);
		list(, , $b) = $fn($p, $ip, 'POST', '/Q/api/auth/login', '{}');
		$d = json_decode($b, true) ?: array();
		check("C, $label: the login form is told the default is \"panel\" and must be changed",
			array($d['defaultInForce'] ?? null, strpos($d['hint'] ?? '', '"panel"') !== false), array(true, true));
		list($st) = $fn($p, $ip, 'POST', '/Q/api/auth/login', json_encode(array('password' => 'not-it')));
		$c = json_decode((string) @file_get_contents($fileC), true) ?: array();
		check("C, $label: a failed sign-in is refused and marks nothing", array($st, isset($c['mustChange'])), array(401, false));
		list($st, , $b) = $fn($p, $ip, 'POST', '/Q/api/auth/login', json_encode(array('password' => 'panel')));
		$d = json_decode($b, true) ?: array();
		$token = $d['token'] ?? '';
		check("C, $label, remote: the default signs in, and must be changed", array($st, $token !== '', $d['mustChange'] ?? null), array(200, true, true));
		$tok = array('X-Panel-Token' => $token);
		list($st, , $b) = $fn($p, $ip, 'GET', '/Q/api/system', '', $tok);
		check("C, $label: until then the API is vetoed", array($st, (json_decode($b, true) ?: array())['mustChange'] ?? null), array(403, true));
		list($st) = $fn($p, $ip, 'GET', '/Q/dashboard?token=' . $token);
		check("C, $label: ...and the session is no admin credential for the dashboard", $st !== 200, true);
		list($st, , $b) = $fn($p, $ip, 'POST', '/Q/api/auth/password', json_encode(array('password' => 'panel2024!Short')), $tok);
		$d = json_decode($b, true) ?: array();
		check("C, $label: a weak new password is refused, with the rules it broke", array($st, !empty($d['failed'])), array(400, true));
		list($st, , $b) = $fn($p, $ip, 'POST', '/Q/api/auth/password', json_encode(array('password' => $strong)), $tok);
		check("C, $label: a strong one is accepted", $st, 200);
		list($st) = $fn($p, $ip, 'GET', '/Q/api/system', '', $tok);
		check("C, $label: ...after which the API works", $st, 200);
		$c = json_decode((string) @file_get_contents($fileC), true) ?: array();
		check("C, $label: ...the stored credential is bcrypt and not the default, with no must-change left",
			array(strpos($c['passwordHash'] ?? '', '$2y$'), $c['default'] ?? null, isset($c['mustChange'])), array(0, false, false));
		list($st) = $fn($p, $ip, 'POST', '/Q/api/auth/login', json_encode(array('password' => 'panel')));
		check("C, $label: the default no longer signs in", $st, 401);

		// The CLI sets a real password directly and clears the default's marks.
		@unlink($fileC);
		list(, , $b) = $fn($p, '127.0.0.5', 'POST', '/Q/api/auth/login', json_encode(array('password' => 'panel')));
		$token = (json_decode($b, true) ?: array())['token'] ?? '';
		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ctl) . ' panel:password --root=' . escapeshellarg($baseC . DS . 'web')
			. ' --password=' . escapeshellarg('Cl9#Mx4!Rb7@Tq2Vz') . ' 2>&1', $out, $rc);
		$c = json_decode((string) @file_get_contents($fileC), true) ?: array();
		check("C, $label: qbixctl panel:password replaces the default (default false, no must-change)",
			array($rc, $c['default'] ?? null, isset($c['mustChange'])), array(0, false, false));
		list($st) = $fn($p, '127.0.0.5', 'GET', '/Q/api/system', '', array('X-Panel-Token' => $token));
		check("C, $label: ...and ends the default's session", $st, 401);
		list($st, , $b) = $fn($p, '127.0.0.5', 'POST', '/Q/api/auth/login', json_encode(array('password' => 'Cl9#Mx4!Rb7@Tq2Vz')));
		check("C, $label: ...and its password signs in with nothing to change",
			array($st, (json_decode($b, true) ?: array())['mustChange'] ?? null), array(200, false));
	}
	stopServer($procC);
	$logs['C'] = $baseC . DS . 'log';
	check('C: default-password sign-ins are logged as warnings with the address',
		(bool) preg_match('/WARNING: signed in with the DEFAULT panel password from 127\.0\.0\.3/', (string) @file_get_contents($logs['C'])), true);
}

// ── Server D: the default accepted only from this machine ───────
$baseD = setupSite('d');
$srv = startServer($baseD, array('panel' => array('defaultLocalOnly' => true)));
check('server D started', $srv !== null && $srv[1] > 0, true);
if ($srv and $srv[1]) {
	list($procD, $port, $tls) = $srv;
	foreach ($protocols as $label => $fn) {
		$p = $portFor($fn, $port, $tls);
		list($st) = $fn($p, $remote, 'GET', '/Q/panel');
		check("D, $label, remote: with defaultLocalOnly the default gives no remote access", $st, 403);
		list($st) = $fn($p, $remote, 'POST', '/Q/api/auth/login', json_encode(array('password' => 'panel')));
		check("D, $label, remote: ...and the default does not sign in", $st, 403);
		list($st, , $b) = $fn($p, $local, 'POST', '/Q/api/auth/login', json_encode(array('password' => 'panel')));
		check("D, $label, this machine: the default signs in, and must be changed",
			array($st, (json_decode($b, true) ?: array())['mustChange'] ?? null), array(200, true));
	}
	stopServer($procD);
	$logs['D'] = $baseD . DS . 'log';
}

if ($fail) {
	foreach ($logs as $n => $l) {
		echo "  server $n log (tail):\n";
		foreach (array_slice(file($l) ?: array(), -12) as $line) echo '    ' . $line;
	}
}
foreach (array($baseA, $baseB, $baseC, $baseD) as $base) {
	foreach (array('web/Q/probe.php', 'web/probe.count', 'web/page.php', 'web/page.count', 'local/panel.json',
		'config.json', 'log', 'fullchain.pem', 'privkey.pem') as $f) @unlink($base . DS . $f);
	@rmdir($base . DS . 'web' . DS . 'Q'); @rmdir($base . DS . 'web'); @rmdir($base . DS . 'local');
	if (is_dir($base . DS . 'cache')) {
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base . DS . 'cache', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($it as $e) { $e->isDir() ? @rmdir($e->getPathname()) : @unlink($e->getPathname()); }
		@rmdir($base . DS . 'cache');
	}
	@rmdir($base);
}

if ($fail) { printf("  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
