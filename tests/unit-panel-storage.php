#!/usr/bin/env php
<?php
/**
 * The control panel's credential store (Q_WebServer_Panel_Store): where it
 * is, the trust rule it must pass (fail closed), the move from the old
 * single panel.json, and sessions as one file each.
 *
 * Asserted:
 *   - with a configuration tree, acl/ is <confDir>/acl and sessions/ is the
 *     tree's state directory + /sessions (an overlay names its own; the base
 *     tree's is /var/lib/qbix); without one, APP_DIR/local as before;
 *   - a store below a directory another user owns (the planted-directory
 *     attack, as the real user `nobody`) is refused: sign-in answers 503, the
 *     default key is not in force, a session file planted there is not live;
 *   - a store planted by another user (their acl/ with a known hash) is refused;
 *   - symbolic links (the directory, the file, an ancestor) are refused;
 *   - a group- or world-writable ancestor is refused, a sticky one is not;
 *     a laxer mode on our own directory or file is tightened, not trusted;
 *   - the old panel.json is moved once (its sessions become session files,
 *     a 0600 copy stays in acl/, the old file is renamed, not deleted); a
 *     second start changes nothing; an old file another user owns is refused
 *     and locks the panel;
 *   - sessions survive a restart of the process;
 *   - 20 concurrent sign-ins against a 4-worker server all get live,
 *     distinct sessions, one file each;
 *   - `qbixctl panel:check` reports trusted and locked stores.
 *
 *   php tests/unit-panel-storage.php   (as root: the ownership cases need it)
 */
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel/Store.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel/Auth.php';

$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}
if (!function_exists('posix_geteuid') or posix_geteuid() !== 0) { echo "  skip  needs root for the ownership cases\n"; exit(0); }
$nobody = posix_getpwnam('nobody');
if (!$nobody) { echo "  skip  no user 'nobody'\n"; exit(0); }

$S = 'Q_WebServer_Panel_Store';
$A = 'Q_WebServer_Panel_Auth';
$base = sys_get_temp_dir() . DS . 'qbix-panel-storage-' . getmypid();
@mkdir($base, 0755, true);
chmod($base, 0755);

/** Point the store at $acl and $sessions (or clear), and forget what it knew. */
function useDirs($acl, $sessions)
{
	Q_Config::set('Q', 'panel', 'aclDir', $acl);
	Q_Config::set('Q', 'panel', 'sessionsDir', $sessions);
	Q_WebServer_Panel_Store::reset();
}
/** As `nobody`, in a child: run $fn. */
function asNobody(callable $fn)
{
	global $nobody;
	$pid = pcntl_fork();
	if ($pid === 0) { posix_setgid($nobody['gid']); posix_setuid($nobody['uid']); $fn(); exit(0); }
	pcntl_waitpid($pid, $st);
}
$good = 'Vx7#qLm2!Rt9@Kw4-Zb';

// ── Where: the layout ───────────────────────────────────────────────
Q_WebServer_Layout::addOverlay($base . '/etc/example', 'EXAMPLE_TEST_CONF', $base . '/var/lib/example');
mkdir($base . '/etc/example', 0755, true);
check('the overlay\'s state directory', Q_WebServer_Layout::stateDir($base . '/etc/example'), $base . '/var/lib/example');
check('the base tree\'s state directory', Q_WebServer_Layout::stateDir('/etc/qbix'), '/var/lib/qbix');
check('no state directory for a tree that is neither', Q_WebServer_Layout::stateDir($base . '/elsewhere'), null);
Q_Config::set('Q', 'panel', 'aclDir', null); Q_Config::set('Q', 'panel', 'sessionsDir', null);
Q_Config::set('Q', 'webserver', 'confDir', $base . '/etc/example');
$d = $S::dirs();
check('with a tree: acl/ under it', $d['acl'], $base . '/etc/example/acl');
check('with a tree: sessions/ under its state directory', $d['sessions'], $base . '/var/lib/example/sessions');
check('...and says so', $d['source'], 'layout');
Q_Config::set('Q', 'webserver', 'confDir', null);
$S::setAppDir($base . '/app');
$d = $S::dirs();
check('without a tree: beside the application, as before', $d['acl'], $base . '/app/local');
check('...with its sessions in local/sessions', $d['sessions'], $base . '/app/local/sessions');
$S::setAppDir(null);

// ── A sound store ───────────────────────────────────────────────────
$ok = $base . '/ok';
useDirs("$ok/acl", "$ok/sessions");
check('a sound store is trusted (created 0700)', $S::problem(), null);
check('acl/ is 0700', substr(sprintf('%o', fileperms("$ok/acl")), -4), '0700');
check('sessions/ is 0700', substr(sprintf('%o', fileperms("$ok/sessions")), -4), '0700');
check('no password yet: the default key is in force', $A::defaultInForce(), true);
$r = $A::storePassword($good, null, true, null, array('default' => 'panel', 'brand' => 'Qbix Server', 'host' => '', 'currentHash' => ''));
check('a password can be stored', $r['ok'], true);
check('...in acl/panel.json, 0600', substr(sprintf('%o', fileperms("$ok/acl/panel.json")), -4), '0600');
check('...and no session is kept in it', array_key_exists('sessions', json_decode(file_get_contents("$ok/acl/panel.json"), true)), false);
list($st, $body) = $A::login(array('clientIp' => '127.0.0.1', 'headers' => array()), array('password' => $good));
check('sign-in works', $st, 200);
$token = $body['token'] ?? '';
check('the session is one file, named by the token\'s hash', is_file("$ok/sessions/" . hash('sha256', $token) . '.json'), true);
check('...0600', substr(sprintf('%o', fileperms("$ok/sessions/" . hash('sha256', $token) . '.json')), -4), '0600');
check('...and live', $A::sessionLive($token), true);
check('the token itself is written nowhere in the directory', strpos(implode(' ', scandir("$ok/sessions")), $token), false);
$S::reset();
check('sessions survive a restart', $A::sessionLive($token), true);
$A::logout(array('headers' => array('x-panel-token' => $token)));
check('sign-out ends it', $A::sessionLive($token), false);

// ── A laxer mode on our own directory or file is tightened ──────────
chmod("$ok/acl", 0755); chmod("$ok/acl/panel.json", 0644);
$S::reset();
check('our own directory at 0755 is tightened, then trusted', $S::problem(), null);
check('...to 0700', substr(sprintf('%o', fileperms("$ok/acl")), -4), '0700');
check('...and the file to 0600', substr(sprintf('%o', fileperms("$ok/acl/panel.json")), -4), '0600');

// ── The planted-directory attack: an ancestor another user owns ─────
$att = $base . '/attack';
mkdir("$att/doc", 0755, true);
chown("$att/doc", $nobody['uid']);          // the site's user, in real life
useDirs("$att/doc/acl", "$att/doc/sessions");
$p = $S::problem();
check('a store below a directory another user owns is refused', $p !== null, true);
check('...naming it', strpos((string) $p, "$att/doc belongs to uid " . $nobody['uid']) !== false, true);
check('...and the default key is NOT in force (no way in)', $A::defaultInForce(), false);
list($st) = $A::login(array('clientIp' => '127.0.0.1', 'headers' => array()), array('password' => 'panel'));
check('...sign-in with the default key answers 503 (locked)', $st, 503);
check('...and storing a password is refused', $A::storePassword($good)['ok'], false);

// That user plants their own acl/ with a hash they know, and a session.
asNobody(function () use ($att, $good) {
	@mkdir("$att/doc/acl", 0700); @mkdir("$att/doc/sessions", 0700);
	file_put_contents("$att/doc/acl/panel.json", json_encode(array('passwordHash' => password_hash('planted-Pass-9#xyzQ', PASSWORD_BCRYPT))));
	file_put_contents("$att/doc/sessions/" . hash('sha256', 'plantedtoken') . '.json', json_encode(array('expires' => time() + 3600, 'mustChange' => false)));
});
check('the planted files exist, owned by nobody', fileowner("$att/doc/acl/panel.json"), $nobody['uid']);
$S::reset();
check('a planted store is refused', $S::problem() !== null, true);
list($st) = $A::login(array('clientIp' => '127.0.0.1', 'headers' => array()), array('password' => 'planted-Pass-9#xyzQ'));
check('...its known password does not sign in (503)', $st, 503);
check('...and its planted session is not live', $A::sessionLive('plantedtoken'), false);

// Even under a sound parent: an acl/ another user made is refused.
$pl = $base . '/planted';
mkdir($pl, 0755);
mkdir("$pl/acl", 0700); chown("$pl/acl", $nobody['uid']);
useDirs("$pl/acl", "$pl/sessions");
check('an acl/ owned by another user is refused', strpos((string) $S::problem(), "$pl/acl belongs to uid") !== false, true);
// A file of theirs in our directory.
$f2 = $base . '/theirfile';
mkdir("$f2/acl", 0700, true); mkdir("$f2/sessions", 0700);
file_put_contents("$f2/acl/panel.json", '{"passwordHash":"x"}'); chown("$f2/acl/panel.json", $nobody['uid']);
useDirs("$f2/acl", "$f2/sessions");
check('a panel.json owned by another user is refused', $S::problem() !== null, true);

// ── Symbolic links ──────────────────────────────────────────────────
$ln = $base . '/links';
mkdir("$ln/real", 0700, true); mkdir("$ln/sessions", 0700);
symlink("$ln/real", "$ln/acl");
useDirs("$ln/acl", "$ln/sessions");
check('acl/ as a symbolic link is refused', strpos((string) $S::problem(), 'symbolic link') !== false, true);
$ln2 = $base . '/links2';
mkdir("$ln2/acl", 0700, true); mkdir("$ln2/sessions", 0700);
file_put_contents("$ln2/real.json", '{}'); chmod("$ln2/real.json", 0600);
symlink("$ln2/real.json", "$ln2/acl/panel.json");
useDirs("$ln2/acl", "$ln2/sessions");
check('panel.json as a symbolic link is refused', strpos((string) $S::problem(), 'symbolic link') !== false, true);
$ln3 = $base . '/links3';
mkdir("$ln3/realparent/acl", 0700, true); mkdir("$ln3/realparent/sessions", 0700);
symlink("$ln3/realparent", "$ln3/parent");
useDirs("$ln3/parent/acl", "$ln3/parent/sessions");
check('a symbolic link on the way up is refused', strpos((string) $S::problem(), 'symbolic link') !== false, true);

// ── Writable ancestors ──────────────────────────────────────────────
$gw = $base . '/groupw';
mkdir("$gw/x", 0755, true); chmod("$gw/x", 0775);
useDirs("$gw/x/acl", "$gw/x/sessions");
check('a group-writable ancestor is refused', strpos((string) $S::problem(), 'writable by group or others') !== false, true);
$ww = $base . '/worldw';
mkdir("$ww/x", 0755, true); chmod("$ww/x", 0777);
useDirs("$ww/x/acl", "$ww/x/sessions");
check('a world-writable ancestor is refused', strpos((string) $S::problem(), 'writable by group or others') !== false, true);
$sk = $base . '/sticky';
mkdir("$sk/x", 0755, true); chmod("$sk/x", 01777);
useDirs("$sk/x/acl", "$sk/x/sessions");
check('a sticky, root-owned, world-writable ancestor is allowed (like /tmp)', $S::problem(), null);

// ── Migration from the old single file ──────────────────────────────
$mg = $base . '/migrate';
mkdir("$mg/app/local", 0700, true); chmod("$mg/app", 0755);
$live = bin2hex(random_bytes(32));
file_put_contents("$mg/app/local/panel.json", json_encode(array('passwordHash' => password_hash($good, PASSWORD_BCRYPT),
	'default' => false, 'appsDir' => '/srv/apps', 'sessions' => array($live => time() + 3600, 'dead' => time() - 10),
	'mustChange' => array($live => true))));
chmod("$mg/app/local/panel.json", 0600);
$S::setAppDir("$mg/app");
Q_Config::set('Q', 'webserver', 'confDir', null);
useDirs("$mg/etc/acl", "$mg/var/sessions");
check('migration: the store is trusted after moving the old file', $S::problem(), null);
check('...acl/panel.json holds the credentials and settings', json_decode(file_get_contents("$mg/etc/acl/panel.json"), true)['appsDir'] ?? null, '/srv/apps');
check('...without the sessions', array_key_exists('sessions', json_decode(file_get_contents("$mg/etc/acl/panel.json"), true)), false);
check('...the live session became a session file', $A::sessionLive($live), true);
check('...keeping its must-change mark', $A::mustChange($live), true);
check('...an expired one was dropped', count($S::sessionFiles()), 1);
$bk = glob("$mg/etc/acl/panel.json.pre-migration-*");
check('...a copy stays in acl/', count($bk), 1);
check('...0600', $bk ? substr(sprintf('%o', fileperms($bk[0])), -4) : null, '0600');
check('...the old file is renamed, not deleted', count(glob("$mg/app/local/panel.json.migrated-*")), 1);
check('...and no longer read from its old name', is_file("$mg/app/local/panel.json"), false);
list($st) = $A::login(array('clientIp' => '127.0.0.1', 'headers' => array()), array('password' => $good));
check('...the moved password signs in', $st, 200);
$S::reset();
$S::problem();
check('a second start changes nothing', $S::$lastMigration['action'] ?? null, 'none');

// An old file another user owns is refused, and locks the panel.
$mb = $base . '/migrate-bad';
mkdir("$mb/app/local", 0755, true);
file_put_contents("$mb/app/local/panel.json", json_encode(array('passwordHash' => password_hash('planted-Pass-9#xyzQ', PASSWORD_BCRYPT))));
chown("$mb/app/local/panel.json", $nobody['uid']);
$S::setAppDir("$mb/app");
useDirs("$mb/etc/acl", "$mb/var/sessions");
check('an old file another user owns is not moved: the panel is locked', strpos((string) $S::problem(), 'does not belong to the server') !== false, true);
check('...and the default key is not the way in', $A::defaultInForce(), false);
check('...and it stays where it was', is_file("$mb/app/local/panel.json"), true);
$S::setAppDir(null);

// ── Concurrent sign-ins across a 4-worker server ────────────────────
$srv = $base . '/server';
mkdir("$srv/web", 0755, true);
file_put_contents("$srv/web/index.php", '<?php echo "APP";');
useDirs("$srv/etc/acl", "$srv/var/sessions");
$A::storePassword($good, null, true, null, array('default' => 'panel', 'brand' => 'Qbix Server', 'host' => '', 'currentHash' => ''));
$port = 0;
for ($i = 0; $i < 40 and !$port; ++$i) { $pp = 21900 + random_int(0, 1500); $s = @stream_socket_server("tcp://127.0.0.1:$pp"); if ($s) { fclose($s); $port = $pp; } }
file_put_contents("$srv/config.json", json_encode(array('Q' => array('panel' => array('aclDir' => "$srv/etc/acl", 'sessionsDir' => "$srv/var/sessions"),
	'web' => array('cache' => array('enabled' => false))))));
$proc = proc_open(array(PHP_BINARY, __DIR__ . '/../sbin/qbixserver.php', '--config=' . "$srv/config.json", '--root=' . "$srv/web",
	'--port=' . $port, '--workers=4'), array(0 => array('file', '/dev/null', 'r'), 1 => array('file', "$srv/log", 'w'), 2 => array('file', "$srv/log", 'a')), $pipes);
for ($i = 0; $i < 60; ++$i) { $s = @stream_socket_client("tcp://127.0.0.1:$port", $e, $es, 1); if ($s) { fclose($s); break; } usleep(250000); }
check('the server says where the panel keeps its store', strpos((string) @file_get_contents("$srv/log"), "panel: credentials in $srv/etc/acl, sessions in $srv/var/sessions") !== false, true);
$kids = array();
for ($k = 0; $k < 20; ++$k) {
	$pid = pcntl_fork();
	if ($pid === 0) {
		$s = @stream_socket_client("tcp://127.0.0.1:$port", $e, $es, 10);
		$body = json_encode(array('password' => $good));
		fwrite($s, "POST /Q/api/auth/login HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n$body");
		stream_set_timeout($s, 20);
		$raw = stream_get_contents($s);
		$j = json_decode(substr($raw, strpos($raw, "\r\n\r\n") + 4), true);
		file_put_contents("$srv/token.$k", (string) ($j['token'] ?? ''));
		exit(0);
	}
	$kids[] = $pid;
}
foreach ($kids as $pid) pcntl_waitpid($pid, $st);
$tokens = array();
for ($k = 0; $k < 20; ++$k) $tokens[] = (string) @file_get_contents("$srv/token.$k");
$S::reset();
$liveCount = 0;
foreach ($tokens as $t) if ($t !== '' and $A::sessionLive($t)) ++$liveCount;
check('20 concurrent sign-ins: 20 live sessions', $liveCount, 20);
check('...all different', count(array_unique(array_filter($tokens))), 20);
check('...one file each', count($S::sessionFiles()), 20);
@proc_terminate($proc, 15);
for ($i = 0; $i < 40; ++$i) { $s = @proc_get_status($proc); if (!$s or empty($s['running'])) break; usleep(250000); }
@proc_close($proc);

// ── qbixctl panel:check ─────────────────────────────────────────────
file_put_contents("$ok/config.json", json_encode(array('Q' => array('panel' => array('aclDir' => "$ok/acl", 'sessionsDir' => "$ok/sessions")))));
mkdir("$ok/web", 0755);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../sbin/qbixctl.php') . ' panel:check --root=' . escapeshellarg("$ok/web")
	. ' --config=' . escapeshellarg("$ok/config.json") . ' --json 2>&1', $out, $code);
$rep = json_decode(implode("\n", $out), true);
check('panel:check: a trusted store exits 0', $code, 0);
check('...and says so', $rep['ok'] ?? null, true);
file_put_contents("$att/config.json", json_encode(array('Q' => array('panel' => array('aclDir' => "$att/doc/acl", 'sessionsDir' => "$att/doc/sessions")))));
mkdir("$att/web", 0755);
$out = array();
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../sbin/qbixctl.php') . ' panel:check --root=' . escapeshellarg("$att/web")
	. ' --config=' . escapeshellarg("$att/config.json") . ' 2>&1', $out, $code);
check('panel:check: a planted store exits 1', $code, 1);
check('...saying LOCKED with the fix', strpos(implode("\n", $out), 'LOCKED') !== false and strpos(implode("\n", $out), 'chmod 700') !== false, true);

exec('rm -rf ' . escapeshellarg($base));
printf("  %s - %d case(s)%s\n", $fail ? 'FAIL' : 'PASS', $pass + $fail, $fail ? " ($fail failed)" : '');
exit($fail ? 1 : 0);
