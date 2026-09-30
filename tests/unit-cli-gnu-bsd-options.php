#!/usr/bin/env php
<?php

/**
 * Every qbix command line accepts GNU and BSD spellings of its options.
 *
 * Asserted, for Q_Console (qbixconsole, qbixctl):
 *   - a value option as --name=V, --name V, -name=V and -name V;
 *   - a flag as --name and -name, off with --no-name, and a flag never
 *     swallows the next argument;
 *   - a value option does not take a following option as its value;
 *   - one-letter options still bundle (-ab), and `--` still ends options.
 * And for qbixserver.php, through its real entry point (--layout):
 *   - --conf-dir=DIR, --conf-dir DIR, -conf-dir=DIR and -conf-dir DIR all
 *     select the same tree, and -layout works as --layout.
 *
 *   php tests/unit-cli-gnu-bsd-options.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/Console.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
require_once __DIR__ . '/../src/Q/WebServer/Ctl.php';
$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

// ── Q_Console, with the real command set ─────────────────────────────────
Q_WebServer_Ctl::register(dirname(__DIR__));
foreach (array('--conf-dir=/x', '--conf-dir /x', '-conf-dir=/x', '-conf-dir /x') as $form) {
	check("value as $form", Q_Console::parse(array_merge(array('layout:show'), explode(' ', $form))),
		array(array('layout:show'), array('conf-dir' => '/x')));
}
check('a flag never takes the next argument', Q_Console::parse(array('server:status', '--json', 'extra')),
	array(array('server:status', 'extra'), array('json' => true)));
check('a flag with one dash', Q_Console::parse(array('server:status', '-json')),
	array(array('server:status'), array('json' => true)));
check('--no-name turns a flag off', Q_Console::parse(array('server:status', '--no-json')),
	array(array('server:status'), array('json' => false)));
check('a value option leaves a following option alone', Q_Console::parse(array('server:start', '--root', '--json')),
	array(array('server:start'), array('root' => true, 'json' => true)));
check('one-letter options bundle; -- ends options', Q_Console::parse(array('-ab', '--', '-conf-dir', '/x')),
	array(array('-conf-dir', '/x'), array('a' => true, 'b' => true)));
check('an unknown single-dash word is bundled letters, as before', Q_Console::parse(array('-xy')),
	array(array(), array('x' => true, 'y' => true)));
check('json is declared a flag', in_array('json', Q_Console::valueOptions(), true), false);

// ── qbixserver.php, through its real entry point ─────────────────────────
$base = sys_get_temp_dir() . '/qbix-cli-styles-' . getmypid();
mkdir("$base/qbix/mods-available", 0755, true);
mkdir("$base/qbix/mods-enabled", 0755, true);
file_put_contents("$base/qbix/qbix.conf", '{}');
$srv = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../sbin/qbixserver.php');
$dir = escapeshellarg("$base/qbix");
$stacks = array();
foreach (array("--conf-dir=$dir", "--conf-dir $dir", "-conf-dir=$dir", "-conf-dir $dir") as $form) {
	foreach (array('--layout', '-layout') as $flag) {
		$out = array();
		exec("$srv --distribution none $form $flag 2>&1", $out, $code);
		$j = json_decode(implode("\n", $out), true);
		$stacks["$form $flag"] = array($code, $j['stack'] ?? null);
	}
}
foreach ($stacks as $form => $got) check("qbixserver $form", $got, array(0, array("$base/qbix")));

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { ($f->isDir() and !$f->isLink()) ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
rmdir($base);

if ($fail) { printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
