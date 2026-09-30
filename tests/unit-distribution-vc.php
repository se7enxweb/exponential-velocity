<?php

/**
 * The vc distribution (Exponential Velocity) stacks /etc/vc on /etc/qbix,
 * and is switched on by the distribution option -- nothing else changes.
 *
 * Asserted, with scratch trees standing in for /etc/qbix and /etc/vc
 * (QBIX_CONF_DIR and VC_CONF_DIR point at them):
 *   - registering the distribution adds exactly one overlay, /etc/vc;
 *   - the server started for a site in the vc tree loads the qbix tree first
 *     and the vc tree on top, whether the switch is the DISTRIBUTION file of
 *     this source tree or --distribution=vc;
 *   - --distribution=none turns it off: only the base tree is loaded.
 *
 *   php tests/unit-distribution-vc.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
require_once __DIR__ . '/../src/Q/WebServer/Distribution/Vc.php';
$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

Q_WebServer_Distribution_Vc::register();
check('registering vc adds one overlay, /etc/vc moved by VC_CONF_DIR, with /var/lib/vc as its state directory and /var/log/vc as its log directory',
	Q_WebServer_Layout::$overlays, array(array('/etc/vc', 'VC_CONF_DIR', '/var/lib/vc', '/var/log/vc')));
check('...so the panel\'s sessions go to /var/lib/vc/sessions', Q_WebServer_Layout::stateDir('/etc/vc'), '/var/lib/vc');
putenv('QBIX_LOG_DIR');
check('...and qbixctl\'s default server log, failing any closer one, to /var/log/vc', Q_WebServer_Layout::logDir('/etc/vc'), '/var/log/vc');
Q_WebServer_Distribution_Vc::register();
check('...once, however often it is called', count(Q_WebServer_Layout::$overlays), 1);

$base = sys_get_temp_dir() . '/qbix-distribution-vc-' . getmypid();
$qbix = "$base/qbix"; $vc = "$base/vc";
foreach (array("$qbix/mods-enabled", "$vc/sites-available", "$vc/sites-enabled") as $d) mkdir($d, 0755, true);
file_put_contents("$qbix/qbix.conf", '{"Q":{"web":{"a":"qbix"}}}');
file_put_contents("$vc/vc.conf", '{"Q":{"web":{"a":"vc"}}}');
file_put_contents("$vc/sites-available/site.conf", '{}');
symlink('../sites-available/site.conf', "$vc/sites-enabled/site.conf");

// The base tree is the scratch qbix tree (QBIX_CONF_DIR), the overlay the
// scratch vc tree (VC_CONF_DIR); what the server reports is the stack.
$run = function ($extra) use ($qbix, $vc) {
	$out = shell_exec('env -u QBIX_DISTRIBUTION QBIX_CONF_DIR=' . escapeshellarg($qbix) . ' VC_CONF_DIR=' . escapeshellarg($vc)
		. ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../qbixserver.php')
		. " $extra --config=" . escapeshellarg("$vc/sites-enabled/site.conf") . ' --layout 2>&1');
	$r = json_decode((string) $out, true);
	return $r['stack'] ?? $out;
};

$distFile = __DIR__ . '/../DISTRIBUTION';
check('this source tree names its distribution vc', is_file($distFile) ? trim(file_get_contents($distFile)) : null, 'vc');
check('with the switch on (the DISTRIBUTION file): qbix first, vc on top',
	$run(''), array($qbix, $vc));
check('--distribution=vc does the same', $run('--distribution=vc'), array($qbix, $vc));
check('--distribution=none: no overlay, only the base tree', $run('--distribution=none'), array($qbix));

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { ($f->isDir() and !$f->isLink()) ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
rmdir($base);

if ($fail) { printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
