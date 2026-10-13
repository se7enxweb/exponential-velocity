<?php
/**
 * The dashboard's live status follows the browser's session, and never sits
 * on a red "Connecting" because of a credential that has ended.
 *
 * What kept happening (alpha, 2026-09-26, 2026-10-01..04, 2026-10-12): the
 * page wrote the session token it was loaded with into the WebSocket URL, and
 * the server checked it only at the handshake. A dashboard left open stayed
 * green on a session that had ended (seven days after sign-in, a sign-out, a
 * password change) until the next restart closed the socket. From then on
 * every reconnect presented the dead token, was refused with a 403 the page
 * cannot see, and the header read "Connecting" in red for good -- 255
 * refusals over three days from one tab -- even after signing in again in
 * another tab, because the page kept sending its own old token.
 *
 * Asserted against a real server, a remote browser simulated through a
 * trusted proxy (X-Forwarded-For):
 *   - the page no longer carries the cookie session's token;
 *   - a plain GET of /Q/ws says whether this browser would be let in (JSON,
 *     403 with a sign-in link when not), which is how the page tells
 *     "signed out" from "server restarting";
 *   - an open dashboard socket is told and closed (4401) when its session
 *     ends, within a heartbeat or two;
 *   - a reconnect with a newer session cookie is let in, even when the URL
 *     still carries an old token (a page loaded before the new sign-in);
 *   - a socket the static token or this machine opened is not closed;
 *   - a session still waiting for its second factor opens nothing.
 *
 *   php tests/unit-dashboard-ws-session-ends.php
 */
require __DIR__ . '/fixtures/race-harness.php';
list($base, $root) = rh_setup('dash-ws-session');
file_put_contents($root . DS . 'index.php', '<?php echo "ok";');

$password = 'Tq7#Lw2!Vn9@Rc4Zm';
$static = bin2hex(random_bytes(16));
@mkdir("$base/acl", 0700, true);
@mkdir("$base/sessions", 0700, true);
file_put_contents("$base/acl/panel.json", json_encode(array(
	'passwordHash' => password_hash($password, PASSWORD_BCRYPT, array('cost' => 10)))));
chmod("$base/acl/panel.json", 0600);

$port = rh_start('dash', array('Q' => array(
	'webserver' => array('proxy' => array('trusted' => array('127.0.0.1', '::1'))),
	'panel' => array('aclDir' => "$base/acl", 'sessionsDir' => "$base/sessions", 'bcryptCost' => 10),
	'dashboard' => array('token' => $static),
)), 1);

$REMOTE = array('X-Forwarded-For' => '203.0.113.9');

/** Sign in as a remote browser; the session token. */
function signIn($port, $password)
{
	$r = rh_get($port, '/Q/api/auth/login', 15, 'POST', json_encode(array('password' => $password)),
		$GLOBALS['REMOTE'] + array('Content-Type' => 'application/json'));
	$j = json_decode($r['body'], true);
	return is_array($j) ? (string) ($j['token'] ?? '') : '';
}

/** A WebSocket handshake to $path; array(status, socket or null, bytes after the head). */
function wsOpen($port, $path, $headers = array())
{
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5);
	if (!$s) return array(0, null, '');
	$h = "GET $path HTTP/1.1\r\nHost: 127.0.0.1:$port\r\nUpgrade: websocket\r\nConnection: keep-alive, Upgrade\r\n"
		. 'Sec-WebSocket-Key: ' . base64_encode(random_bytes(16)) . "\r\nSec-WebSocket-Version: 13\r\n";
	foreach ($headers as $k => $v) $h .= "$k: $v\r\n";
	fwrite($s, $h . "\r\n");
	stream_set_timeout($s, 5);
	$head = '';
	while (strpos($head, "\r\n\r\n") === false and !feof($s)) {
		$c = fread($s, 4096);
		if ($c === false or $c === '') break;
		$head .= $c;
	}
	$status = preg_match('#^HTTP/1\.1 (\d{3})#', $head, $m) ? (int) $m[1] : 0;
	$rest = (string) substr($head, (int) strpos($head, "\r\n\r\n") + 4);
	if ($status !== 101) { fclose($s); return array($status, null, ''); }
	return array($status, $s, $rest);
}

/**
 * Read server frames for up to $secs seconds, or until the server closes.
 * array(frames => [[opcode, payload]...], eof => bool)
 */
function wsRead($s, &$buf, $secs)
{
	$out = array(); $eof = false;
	$end = microtime(true) + $secs;
	stream_set_blocking($s, false);
	while (microtime(true) < $end) {
		while (strlen($buf) >= 2) {
			$b1 = ord($buf[1]) & 0x7f; $off = 2;
			if ($b1 === 126) { if (strlen($buf) < 4) break; $len = unpack('n', substr($buf, 2, 2))[1]; $off = 4; }
			elseif ($b1 === 127) { if (strlen($buf) < 10) break; $len = unpack('J', substr($buf, 2, 8))[1]; $off = 10; }
			else $len = $b1;
			if (strlen($buf) < $off + $len) break;
			$out[] = array(ord($buf[0]) & 0x0f, substr($buf, $off, $len));
			$buf = (string) substr($buf, $off + $len);
		}
		$r = array($s); $w = $x = null;
		if (@stream_select($r, $w, $x, 0, 200000) > 0) {
			$c = fread($s, 65536);
			if ($c === '' or $c === false) { if (feof($s)) { $eof = true; break; } }
			else $buf .= $c;
		}
	}
	return array('frames' => $out, 'eof' => $eof);
}

function types($frames)
{
	$t = array();
	foreach ($frames as $f) {
		if ($f[0] === 1) { $j = json_decode($f[1], true); $t[] = $j['type'] ?? '?'; }
		elseif ($f[0] === 8) $t[] = 'close:' . (strlen($f[1]) >= 2 ? unpack('n', $f[1])[1] : '');
	}
	return $t;
}

// ── Signed out: the status check says so, with a way to sign in ─────────

$r = rh_get($port, '/Q/ws', 10, 'GET', '', $REMOTE + array('Accept' => 'application/json'));
$j = json_decode($r['body'], true);
check('no session: a plain GET of /Q/ws is a 403', $r['status'], 403);
check('...as JSON that says signed out, with the sign-in page that returns to the dashboard',
	array($j['ok'] ?? null, $j['reason'] ?? null, strpos((string) ($j['signIn'] ?? ''), '/Q/panel/(next)/') === 0),
	array(false, 'signed-out', true));
check('...and is never cached', $r['headers']['cache-control'] ?? '', 'no-store');
list($st) = wsOpen($port, '/Q/ws', $REMOTE);
check('no session: the handshake is refused', $st, 403);

// ── Signed in ───────────────────────────────────────────────────────────

$t1 = signIn($port, $password);
check('sign-in gives a session', strlen($t1), 64);
$cookie1 = array('Cookie' => 'Q_panel_token=' . $t1);

$r = rh_get($port, '/Q/ws', 10, 'GET', '', $REMOTE + $cookie1);
check('signed in: the plain GET of /Q/ws says ok', array($r['status'], json_decode($r['body'], true)), array(200, array('ok' => true)));

$r = rh_get($port, '/Q/dashboard', 15, 'GET', '', $REMOTE + $cookie1 + array('Accept' => 'text/html'));
check('signed in: the dashboard is served', $r['status'], 200);
check('...without the session token written into the page (the reconnect sends the cookie it has then)',
	strpos($r['body'], $t1), false);
check('...its WebSocket URL has no ?token=',
	strpos($r['body'], "+location.host+'/Q/ws';") !== false && strpos($r['body'], '/Q/ws?token=') === false, true);

list($st, $ws, $buf) = wsOpen($port, '/Q/ws', $REMOTE + $cookie1);
check('signed in: the handshake (Firefox\'s "Connection: keep-alive, Upgrade") is accepted', $st, 101);
$got = $ws ? wsRead($ws, $buf, 3.0) : array('frames' => array(), 'eof' => true);
check('...and heartbeats arrive', in_array('heartbeat', types($got['frames']), true), true);

// Static token and this machine: sockets that must not be closed by the session check.
list($stS, $wsS, $bufS) = wsOpen($port, '/Q/ws?token=' . $static, $REMOTE);
check('the static token opens the socket too', $stS, 101);
list($stL, $wsL, $bufL) = wsOpen($port, '/Q/ws');
check('this machine opens it with no credential', $stL, 101);

// ── The session ends while the dashboard is open ────────────────────────

$r = rh_get($port, '/Q/api/auth/logout', 10, 'POST', '{}', $REMOTE + array('Authorization' => 'Bearer ' . $t1,
	'X-Panel-Token' => $t1, 'Content-Type' => 'application/json'));
check('signing out answers 200', $r['status'], 200);
$t0 = microtime(true);
$got = $ws ? wsRead($ws, $buf, 6.0) : array('frames' => array(), 'eof' => false);
$secs = microtime(true) - $t0;
$tt = types($got['frames']);
check('the open socket is told the session ended', in_array('auth', $tt, true), true);
check('...and closed with 4401', in_array('close:4401', $tt, true) && $got['eof'], true);
check('...within a couple of heartbeats (' . round($secs, 1) . ' s)', $secs < 5.0, true);
if ($ws) fclose($ws);

list($st) = wsOpen($port, '/Q/ws?token=' . $t1, $REMOTE + $cookie1);
check('a reconnect with the ended session is refused', $st, 403);
$r = rh_get($port, '/Q/ws?token=' . $t1, 10, 'GET', '', $REMOTE + $cookie1);
check('...and the status check says signed out, so the page shows that, not "Connecting"', $r['status'], 403);

// ── Signed in again, in another tab ─────────────────────────────────────

$t2 = signIn($port, $password);
list($st, $ws2, $buf2) = wsOpen($port, '/Q/ws?token=' . $t1, $REMOTE + array('Cookie' => 'Q_panel_token=' . $t2));
check('the old page reconnects with the new session cookie, its old ?token= notwithstanding', $st, 101);

// ── Sockets with nothing to end stay open ───────────────────────────────

$s = $wsS ? wsRead($wsS, $bufS, 0.5) : array('frames' => array(), 'eof' => true);
$l = $wsL ? wsRead($wsL, $bufL, 0.5) : array('frames' => array(), 'eof' => true);
check('the static-token socket is still open', $s['eof'] || in_array('close:4401', types($s['frames']), true), false);
check('this machine\'s socket is still open', $l['eof'] || in_array('close:4401', types($l['frames']), true), false);
foreach (array($ws2, $wsS, $wsL) as $x) if ($x) fclose($x);

// ── A session waiting for its second factor opens nothing ───────────────

$t3 = signIn($port, $password);
$f = "$base/sessions/" . hash('sha256', $t3) . '.json';
$sess = json_decode((string) @file_get_contents($f), true);
check('the session file is where the store keeps it', is_array($sess), true);
$sess['twoFactorPending'] = true;
file_put_contents($f, json_encode($sess));
$c3 = array('Cookie' => 'Q_panel_token=' . $t3);
check('two-factor pending: /Q/stats is refused', rh_get($port, '/Q/stats', 10, 'GET', '', $REMOTE + $c3)['status'], 403);
list($st) = wsOpen($port, '/Q/ws', $REMOTE + $c3);
check('two-factor pending: the dashboard WebSocket is refused', $st, 403);

rh_finish();
