<?php
/**
 * The dashboard's header status tells the truth, and comes back by itself.
 *
 * The page's own script, rendered as the server renders it, is run under
 * node with a scripted WebSocket and fetch and a virtual clock
 * (tests/fixtures/dashboard-live-status.js), so each scenario takes
 * milliseconds. Each one is a way the status went wrong on alpha before:
 *
 *   - ws:// from an https page (blocked as mixed content): "Connecting" that
 *     never resolved (T018, 2026-09-23);
 *   - a restart: back to green by itself within seconds;
 *   - a refused handshake (the session had ended): it used to retry the same
 *     dead token every 2 s for days as a red "Connecting"; now "Signed out"
 *     with a sign-in link, and green again once a sign-in elsewhere gives the
 *     browser a live session;
 *   - the server closing a socket whose session ended (4401): "Signed out" at once;
 *   - an unreachable server: amber, then "Offline", retried no faster than
 *     every 10 s, and green when it answers again;
 *   - a connection that dies without a close (sleep, a changed network):
 *     replaced after 10 s without a heartbeat;
 *   - a tab shown again while not live: connects at once, not after its
 *     throttled background timer.
 *
 * Skipped when node is not installed.
 *
 *   php tests/unit-dashboard-live-status-client.php
 */
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Design.php';

$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

$node = trim((string) @shell_exec('command -v node 2>/dev/null'));
if ($node === '') { echo "  skip  node is not installed\n  PASS - 0 case(s)\n"; exit(0); }

// The script exactly as the page carries it.
$sample = json_decode(file_get_contents(__DIR__ . '/fixtures/design-dashboard-sample.json'), true);
$sample['tokenParam'] = '';
// Real stats, as getStats() gives them, so the page's own redraw runs too.
$sample['stats'] = json_encode(Q_WebServer_Dashboard::getStats());
$sample['recent'] = '[]';
$html = Q_WebServer_Design::render('dashboard', $sample);
$scripts = array();
preg_match_all('#<script>(.*?)</script>#s', $html, $m);
foreach ($m[1] as $s) if (strpos($s, 'function tickUp') !== false) $scripts[] = $s;
check('the page carries the dashboard script', count($scripts), 1);
$tmp = sys_get_temp_dir() . DS . 'qbix-dash-client-' . getmypid() . '.js';
file_put_contents($tmp, $scripts[0] ?? '');
register_shutdown_function(function () use ($tmp) { @unlink($tmp); });

function play($scenario, $proto = 'https:')
{
	global $node, $tmp;
	$out = shell_exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/fixtures/dashboard-live-status.js')
		. ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg($scenario) . ' ' . escapeshellarg($proto) . ' 2>&1');
	$j = json_decode((string) $out, true);
	if (!is_array($j)) { printf("  note  %s: %s\n", $scenario, substr((string) $out, 0, 600)); return array(); }
	$by = array();
	foreach ($j as $row) $by[$row['label']] = $row;
	return $by;
}
function at($by, $label, $key) { return $by[$label][$key] ?? null; }

// ── Connecting, then live; the scheme follows the page ──────────────────
$r = play('restart');
check('an https page connects with wss:// (ws:// is blocked as mixed content)', strpos((string) at($r, 'loaded', 'url'), 'wss://example.test:8080/Q/ws') === 0, true);
check('...and is Live, green, once open', array(at($r, 'live', 'state'), at($r, 'live', 'color'), at($r, 'live', 'text')), array('live', 'green', 'Live'));
$h = play('restart', 'http:');
check('an http page connects with ws://', strpos((string) at($h, 'loaded', 'url'), 'ws://example.test:8080/Q/ws') === 0, true);

// ── A restart ───────────────────────────────────────────────────────────
check('restart: amber "Reconnecting" while the server is away, never red',
	array(at($r, 'restarting', 'state'), at($r, 'restarting', 'color'), at($r, 'still restarting', 'color')),
	array('reconnecting', 'amber', 'amber'));
check('restart: green again by itself once it answers', array(at($r, 'back', 'state'), at($r, 'back', 'color')), array('live', 'green'));

// ── Refused: the session has ended ──────────────────────────────────────
$r = play('signedout');
check('refused: "Signed out", red, with a sign-in link -- not "Connecting"',
	array(at($r, 'refused', 'state'), at($r, 'refused', 'color'), at($r, 'refused', 'text'), at($r, 'refused', 'signIn')),
	array('signedout', 'red', 'Signed out', true));
check('...and it stays that way while refused', at($r, 'still refused', 'state'), 'signedout');
check('...retrying slowly (no more than one try in 5 s)', at($r, 'still refused', 'sockets') <= 1 + 1 + (30 / 5) + 1, true);
check('...and green by itself once a sign-in elsewhere makes the cookie live', at($r, 'signed in elsewhere', 'state'), 'live');

// ── The server says the session ended ───────────────────────────────────
$r = play('ended');
check('4401 from the server: "Signed out" at once', array(at($r, 'told', 'state'), at($r, 'told', 'signIn')), array('signedout', true));

// ── Unreachable ─────────────────────────────────────────────────────────
$r = play('down');
check('unreachable for a minute: "Offline", red', array(at($r, 'down a minute', 'state'), at($r, 'down a minute', 'color')), array('offline', 'red'));
$n = $r['attempts in the second minute']['n'] ?? 99;
check('...retried at most every 10 s (' . $n . ' tries in the second minute)', $n >= 5 && $n <= 7, true);
check('...and green within 10 s of the server answering', at($r, 'back', 'state'), 'live');

// ── Dead without a close ────────────────────────────────────────────────
$r = play('silent');
check('no heartbeat for 10 s: the socket is replaced', at($r, 'silent', 'sockets') > at($r, 'live', 'sockets'), true);
check('...and the status is live again on the new one', at($r, 'after', 'state'), 'live');

// ── Shown again ─────────────────────────────────────────────────────────
$r = play('visible');
check('a tab shown again while not live connects at once', ($r['a connect at once when shown']['n'] ?? 0) >= 1, true);
check('...and is live', at($r, 'shown', 'state'), 'live');

if ($fail) { printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
