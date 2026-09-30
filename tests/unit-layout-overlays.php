<?php

/**
 * Overlay trees stack on top of the base /etc/qbix instead of replacing it.
 *
 * Asserted, with scratch trees standing in for the base and one overlay
 * registered through Q_WebServer_Layout::addOverlay() (QBIX_CONF_DIR and the
 * overlay's own variable point at them):
 *   - asking for either tree loads both, upstream first and the overlay on
 *     top, so the overlay's values win and the rest of upstream still holds;
 *   - a tree that is neither is used alone -- the machine's trees are not
 *     picked up because they exist;
 *   - asking for nothing loads nothing;
 *   - designs are looked up overlay first, then upstream, then the engine;
 *   - the server's --layout reports the stack.
 *
 *   php tests/unit-layout-overlays.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
require_once __DIR__ . '/../src/Q/WebServer/Design.php';
$L = 'Q_WebServer_Layout';
$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}
function put($f, $a) { if (!is_dir(dirname($f))) mkdir(dirname($f), 0755, true); file_put_contents($f, json_encode($a)); }

$base = sys_get_temp_dir() . '/qbix-layout-overlays-' . getmypid();
$qbix = "$base/qbix"; $vc = "$base/example"; $other = "$base/other";
put("$qbix/qbix.conf", array('Q' => array('web' => array('a' => 'qbix', 'b' => 'qbix'))));
put("$qbix/mods-available/cache.conf", array('Q' => array('web' => array('c' => 'qbix-mod'))));
mkdir("$qbix/mods-enabled", 0755, true);
symlink('../mods-available/cache.conf', "$qbix/mods-enabled/cache.conf");
put("$vc/example.conf", array('Q' => array('web' => array('b' => 'vc'))));
put("$vc/sites-available/site.conf", array('Q' => array('web' => array('d' => 'site'))));
mkdir("$vc/sites-enabled", 0755, true);
symlink('../sites-available/site.conf', "$vc/sites-enabled/site.conf");
put("$other/other.conf", array('Q' => array('web' => array('a' => 'other'))));
putenv("QBIX_CONF_DIR=$qbix"); putenv("EXAMPLE_CONF_DIR=$vc");
$L::addOverlay('/etc/example', 'EXAMPLE_CONF_DIR');

// ── The stack ────────────────────────────────────────────────────────────
check('asking for the overlay (its site file) loads upstream first, then the overlay',
	$L::stack($L::resolve(null, "$vc/sites-enabled/site.conf")), array($qbix, $vc));
check('asking for the base loads the overlay on top of it too', $L::stack($qbix), array($qbix, $vc));
check('a tree that is neither is used alone', $L::stack($other), array($other));
check('asking for nothing loads nothing', $L::stack(null), array());

foreach ($L::stack($vc) as $d) $L::load($d);
Q_Config::load("$vc/sites-enabled/site.conf");
check('the overlay wins where it says something; upstream holds everywhere else',
	Q_Config::get('Q', 'web', array()), array('a' => 'qbix', 'b' => 'vc', 'c' => 'qbix-mod', 'd' => 'site'));

// ── Designs: overlay, upstream, engine ───────────────────────────────────
put("$qbix/designs/default/error/style.css", array());
file_put_contents("$qbix/designs/default/error/style.css", 'qbix');
file_put_contents("$qbix/designs/default/error/page.html", 'upstream page {{@style.css}}');
Q_Config::set('Q', 'webserver', 'confDirs', array($qbix, $vc));
check('with no overlay file, the upstream tree\'s design file is used', $D = Q_WebServer_Design::render('error', array()), 'upstream page qbix');
mkdir("$vc/designs/default/error", 0755, true);
file_put_contents("$vc/designs/default/error/style.css", 'vc');
check('an overlay design file wins over upstream, file by file', Q_WebServer_Design::render('error', array()), 'upstream page vc');

// ── The server reports the stack ─────────────────────────────────────────
// With no overlay registered, the server uses the tree asked for alone.
$out = shell_exec('env -u QBIX_CONF_DIR -u EXAMPLE_CONF_DIR '
	. escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../sbin/qbixserver.php')
	. ' --distribution=none --config=' . escapeshellarg("$vc/sites-enabled/site.conf") . ' --layout 2>&1');
$report = json_decode((string) $out, true);
check('--layout: with no overlay registered, only the tree asked for', $report['stack'] ?? $out, array($vc));

putenv('QBIX_CONF_DIR'); putenv('EXAMPLE_CONF_DIR');
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { ($f->isDir() and !$f->isLink()) ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
rmdir($base);

if ($fail) { printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
