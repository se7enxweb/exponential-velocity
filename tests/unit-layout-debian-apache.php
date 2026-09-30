<?php

/**
 * The configuration directory is read the way Debian's /etc/apache2 is.
 *
 *   DIR/<dir name>.conf (qbix.conf), ports.conf, envvars
 *   DIR/{mods,conf,sites}-available/*.conf, enabled by symlinks in *-enabled/
 *
 * merged base, ports.conf, mods-enabled, conf-enabled, then the site file
 * (--config), later winning. Asserted:
 *   - when a directory is used at all: only when asked (--conf-dir, the
 *     environment, or a --config inside its sites-* directory) -- never just
 *     because it exists;
 *   - the files, in apache2.conf order, *.conf and *.json only;
 *   - a disabled module (no symlink) and a dangling symlink are not loaded;
 *   - the base file is <dir name>.conf, and qbix.conf in any tree;
 *   - envvars is read, not executed;
 *   - the merged result, and the server's --layout report.
 *
 *   php tests/unit-layout-debian-apache.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
$L = 'Q_WebServer_Layout';
$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

$base = sys_get_temp_dir() . '/qbix-layout-' . getmypid();
$dir = "$base/qbix";
foreach (array('', '/mods-available', '/mods-enabled', '/conf-available', '/conf-enabled', '/sites-available', '/sites-enabled') as $d) {
	mkdir($dir . $d, 0755, true);
}
function put($f, $a) { file_put_contents($f, json_encode($a)); }
put("$dir/qbix.conf", array('Q' => array('web' => array('a' => 'base', 'b' => 'base', 'c' => 'base', 'd' => 'base'))));
put("$dir/ports.conf", array('Q' => array('webserver' => array('port' => 8088))));
put("$dir/mods-available/cache.conf", array('Q' => array('web' => array('b' => 'mod'))));
put("$dir/mods-available/off.conf", array('Q' => array('web' => array('a' => 'DISABLED MODULE LOADED'))));
put("$dir/conf-available/security.conf", array('Q' => array('web' => array('c' => 'conf'))));
put("$dir/sites-available/example.conf", array('Q' => array('web' => array('d' => 'site'))));
symlink('../mods-available/cache.conf', "$dir/mods-enabled/cache.conf");
symlink('../mods-available/gone.conf', "$dir/mods-enabled/gone.conf");         // dangling
symlink('../conf-available/security.conf', "$dir/conf-enabled/security.conf");
symlink('../sites-available/example.conf', "$dir/sites-enabled/example.conf");
file_put_contents("$dir/conf-enabled/notes.conf~", 'editor backup');
file_put_contents("$dir/envvars", "# comment\nexport VC_RUN_USER=www-data\nVC_LOG=\"/var/log/vc\"\nexport BAD=\$(rm -rf /)\n");

// ── When a directory is used ────────────────────────────────────────────
putenv('QBIX_CONF_DIR'); putenv('VC_CONF_DIR');
check('nothing asked for: no directory, even though one exists', $L::resolve(null, null), null);
check('a --config elsewhere does not pull one in', $L::resolve(null, "$base/elsewhere.json"), null);
check('a --config inside sites-enabled names its directory', $L::resolve(null, "$dir/sites-enabled/example.conf"), $dir);
check('...and inside sites-available', $L::resolve(null, "$dir/sites-available/example.conf"), $dir);
check('--conf-dir wins', $L::resolve($dir . '/', null), $dir);
check('--conf-dir=none turns it off', $L::resolve('none', "$dir/sites-enabled/example.conf"), null);
check('a --conf-dir that does not exist is none, not a guess', $L::resolve("$base/missing", null), null);
putenv("QBIX_CONF_DIR=$dir");
check('QBIX_CONF_DIR is honoured', $L::resolve(null, null), $dir);
putenv('QBIX_CONF_DIR');
$L::$standardDirs = array("$base/etc-vc-absent", $dir);
check('auto searches the standard places in order', $L::resolve('auto', null), $dir);

// ── The files, in apache2.conf order ─────────────────────────────────────
check('base, ports.conf, mods-enabled, conf-enabled -- nothing disabled, dangling or a backup',
	array_map(function ($f) use ($dir) { return substr($f, strlen($dir) + 1); }, $L::files($dir)),
	array('qbix.conf', 'ports.conf', 'mods-enabled/cache.conf', 'conf-enabled/security.conf'));

// ── Merged, then the site on top, as the server does ─────────────────────
$L::load($dir);
Q_Config::load("$dir/sites-enabled/example.conf");
check('each layer overrides the one before, the site last',
	Q_Config::get('Q', 'web', array()) + array(), array('a' => 'base', 'b' => 'mod', 'c' => 'conf', 'd' => 'site'));
check('ports.conf reaches the server settings', Q_Config::get('Q', 'webserver', 'port', null), 8088);

// ── Base file names ──────────────────────────────────────────────────────
$q = "$base/example";
mkdir($q, 0755, true);
put("$q/qbix.conf", array());
check('a tree moved to another directory keeps its qbix.conf', $L::mainFile($q), "$q/qbix.conf");
put("$q/example.conf", array());
check('...and prefers the name matching its directory', $L::mainFile($q), "$q/example.conf");

// ── envvars ──────────────────────────────────────────────────────────────
check('envvars: export and plain lines, quotes removed, nothing executed', $L::envvars($dir),
	array('VC_RUN_USER' => 'www-data', 'VC_LOG' => '/var/log/vc', 'BAD' => '$(rm -rf /)'));

// ── The server reports it ────────────────────────────────────────────────
$out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../sbin/qbixserver.php')
	. ' --config=' . escapeshellarg("$dir/sites-enabled/example.conf") . ' --layout 2>&1');
$report = json_decode((string) $out, true);
check('--layout names the directory the site file belongs to', $report['confDir'] ?? $out, $dir);
check('--layout lists the enabled modules', $report['pairs']['mods']['enabled'] ?? null, array('cache.conf'));

// Clean up the scratch tree.
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { ($f->isDir() and !$f->isLink()) ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
rmdir($base);

if ($fail) { printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
