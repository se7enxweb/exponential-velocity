<?php

/**
 * The Debian Apache layout on Windows: %ProgramData%\<tree> instead of /etc,
 * /var/lib, /var/log, /var/cache and /run, and a2ensite without symlinks.
 *
 * Asserted with Q_WebServer_Process::$forceWindows, so it runs on Linux too
 * (and for real on the Windows runners, docs/windows.md):
 *   - /etc/vc, /var/lib/vc, /var/log/vc, /var/cache/vc and /run/vc become
 *     %ProgramData%\vc, ...\state, ...\logs, ...\cache, ...\run;
 *   - any other path, and every path on POSIX, is unchanged;
 *   - %ProgramData% is read from the environment, with C:\ProgramData last;
 *   - backslashes, forward slashes, letter case and a trailing separator do
 *     not make two spellings of one directory different on Windows, and do
 *     on POSIX;
 *   - the vc overlay's state and log directories follow its tree;
 *   - a site enabled as a hard link or a copy (no symlink privilege) is
 *     refreshed when stale, removed by disable while it still matches, and
 *     left alone when it was edited in place.
 *
 *   php tests/unit-layout-windows.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Process.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
require_once __DIR__ . '/../src/Q/WebServer/Ctl.php';
$L = 'Q_WebServer_Layout';
$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

$realWindows = PHP_OS_FAMILY === 'Windows';
$savedPd = getenv('ProgramData');

// ── POSIX: nothing changes ───────────────────────────────────────────────
Q_WebServer_Process::$forceWindows = false;
check('POSIX: /etc/vc is /etc/vc', $L::platformDir('/etc/vc'), '/etc/vc');
check('POSIX: /var/log/vc is /var/log/vc', $L::platformDir('/var/log/vc'), '/var/log/vc');
check('POSIX: a trailing slash is trimmed', $L::normDir('/etc/vc/'), '/etc/vc');
check('POSIX: / stays /', $L::normDir('/'), '/');
check('POSIX: letter case matters', $L::same('/etc/VC', '/etc/vc'), false);

// ── Windows ──────────────────────────────────────────────────────────────
Q_WebServer_Process::$forceWindows = true;
putenv('ProgramData=D:\\Data\\PD\\');
check('Windows: %ProgramData% read from the environment', $L::programData(), 'D:/Data/PD');
check('Windows: /etc/vc', $L::platformDir('/etc/vc'), 'D:/Data/PD/vc');
check('Windows: /var/lib/vc', $L::platformDir('/var/lib/vc'), 'D:/Data/PD/vc/state');
check('Windows: /var/log/vc', $L::platformDir('/var/log/vc'), 'D:/Data/PD/vc/logs');
check('Windows: /var/cache/vc', $L::platformDir('/var/cache/vc'), 'D:/Data/PD/vc/cache');
check('Windows: /run/qbix', $L::platformDir('/run/qbix'), 'D:/Data/PD/qbix/run');
check('Windows: /etc/qbix', $L::platformDir('/etc/qbix'), 'D:/Data/PD/qbix');
check('Windows: another path is unchanged', $L::platformDir('C:\\sites\\conf'), 'C:\\sites\\conf');
check('Windows: a deeper /etc path is unchanged', $L::platformDir('/etc/vc/ssl'), '/etc/vc/ssl');
check('Windows: null stays null', $L::platformDir(null), null);
putenv('ProgramData');
$savedAll = getenv('ALLUSERSPROFILE');
putenv('ALLUSERSPROFILE');
check('Windows: C:\\ProgramData when nothing is set', $L::programData(), 'C:/ProgramData');
if ($savedAll !== false) putenv("ALLUSERSPROFILE=$savedAll");
putenv('ProgramData=C:\\ProgramData');

check('Windows: backslashes become forward slashes', $L::normDir('C:\\ProgramData\\vc\\'), 'C:/ProgramData/vc');
check('Windows: a drive root keeps its slash', $L::normDir('C:\\'), 'C:/');
check('Windows: case and slashes do not matter', $L::same('c:\\programdata\\VC', 'C:/ProgramData/vc/'), true);
check('Windows: different folders differ', $L::same('C:/ProgramData/vc', 'C:/ProgramData/qbix'), false);
check('Windows: null equals only null', array($L::same(null, null), $L::same(null, 'C:/')), array(true, false));

// The vc overlay's state and log directories, where its tree is the standard place.
$L::$overlays = array();
$L::addOverlay('/etc/vc', 'VC_CONF_DIR_TEST_UNSET', '/var/lib/vc', '/var/log/vc');
putenv('QBIX_STATE_DIR'); putenv('QBIX_LOG_DIR');
check('Windows: the overlay\'s state directory', $L::stateDir('C:\\ProgramData\\vc'), 'C:/ProgramData/vc/state');
check('Windows: the overlay\'s log directory', $L::logDir('c:/programdata/vc/'), 'C:/ProgramData/vc/logs');
check('Windows: the base tree\'s log directory', $L::logDir('C:/ProgramData/qbix'), 'C:/ProgramData/qbix/logs');
check('Windows: an unrelated tree has none', $L::logDir('C:/elsewhere'), null);
$L::$overlays = array();

// A real directory, spelled two ways: the stack treats both as the base tree.
$base = sys_get_temp_dir() . '/qbix-layout-windows-' . getmypid();
@mkdir("$base/sites-available", 0755, true);
file_put_contents("$base/qbix.conf", '{}');
putenv("QBIX_CONF_DIR=$base");
$asked = $realWindows ? str_replace('/', '\\', strtoupper($base)) : $base;
check('Windows: the stack keeps the directory as asked', $L::stack($asked), array($L::normDir($asked)));
putenv('QBIX_CONF_DIR');

// ── a2ensite without symlinks ────────────────────────────────────────────
// What symlink() refusal leaves behind is simulated: a copy in sites-enabled.
file_put_contents("$base/sites-available/a.conf", '{"a":1}');
@mkdir("$base/sites-enabled", 0755, true);
copy("$base/sites-available/a.conf", "$base/sites-enabled/a.conf");
$r = Q_WebServer_Ctl::toggle($base, 'site', 'a', true);
check('an enabled copy counts as enabled', array($r[0], strpos($r[1], 'already enabled') !== false), array(true, true));
file_put_contents("$base/sites-available/a.conf", '{"a":2}');
$r = Q_WebServer_Ctl::toggle($base, 'site', 'a', true);
check('a stale copy is refreshed by enabling again', array($r[0], (string) file_get_contents("$base/sites-enabled/a.conf")), array(true, '{"a":2}'));
$r = Q_WebServer_Ctl::toggle($base, 'site', 'a', false);
check('disable removes a copy that still matches', array($r[0], file_exists("$base/sites-enabled/a.conf")), array(true, false));
copy("$base/sites-available/a.conf", "$base/sites-enabled/a.conf");
file_put_contents("$base/sites-enabled/a.conf", '{"edited":true}');
$r = Q_WebServer_Ctl::toggle($base, 'site', 'a', false);
check('disable leaves a copy edited in place alone', array($r[0], file_exists("$base/sites-enabled/a.conf")), array(true, true));
@unlink("$base/sites-enabled/a.conf");
$r = Q_WebServer_Ctl::toggle($base, 'site', 'a', true);
check('enable on a fresh tree works (symlink, hard link or copy)', array($r[0], is_file("$base/sites-enabled/a.conf")), array(true, true));
check('...and the enabled file reads as the available one', (string) file_get_contents("$base/sites-enabled/a.conf"), '{"a":2}');
$r = Q_WebServer_Ctl::toggle($base, 'site', 'a', false);
check('...and disable removes it', array($r[0], file_exists("$base/sites-enabled/a.conf")), array(true, false));

foreach (array("$base/sites-enabled/a.conf", "$base/sites-available/a.conf", "$base/qbix.conf") as $f) @unlink($f);
@rmdir("$base/sites-enabled"); @rmdir("$base/sites-available"); @rmdir($base);
Q_WebServer_Process::$forceWindows = null;
if ($savedPd !== false) putenv("ProgramData=$savedPd"); else putenv('ProgramData');

echo $fail ? "  FAIL - $fail of " . ($pass + $fail) . " case(s)\n" : "  PASS - $pass case(s)\n";
exit($fail ? 1 : 0);
