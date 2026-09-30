#!/usr/bin/env php
<?php
/**
 * qbixconsole -- the server's command console.
 *
 *   php sbin/qbixconsole.php list
 *   php sbin/qbixconsole.php help server:start
 *   php sbin/qbixconsole.php server:status --config=/etc/qbix/sites-enabled/example.conf
 *   php sbin/qbixconsole.php ser:stat          (unique abbreviations work)
 *
 * Commands in the manner of Symfony's console, with no library behind it
 * (Q_Console). The control commands are Q_WebServer_Ctl's; a distribution of
 * the engine can add its own from its register() (see --distribution).
 *
 * This file lives in sbin/ and reads the engine's files from the directory
 * above it (docs/layout.md, "Programs"). The former path, qbixconsole.php at
 * the top of the tree, is a forwarder to this file.
 */

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'micro') { exit("qbixconsole runs from the command line\n"); }
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require_once dirname(__DIR__) . '/src/Q.php';
require_once dirname(__DIR__) . '/src/Q/Console.php';
require_once dirname(__DIR__) . '/src/Q/WebServer/Layout.php';
require_once dirname(__DIR__) . '/src/Q/WebServer/Ctl.php';


// --version, --about, --copyright and the rest: GNU-style, before anything else.
require_once dirname(__DIR__) . '/src/Q/WebServer/About.php';
Q_WebServer_About::handle(array_slice($argv, 1), basename($argv[0] ?? 'qbixconsole'), 'the command console: runs the server commands (server, cache, certificates, sites, panel ...)', dirname(__DIR__));
Q_Console::$program = basename($argv[0] ?? 'qbixconsole');
Q_Console::$title = 'Qbix server console' . (function_exists('qbix_version_label') ? ' ' . qbix_version_label(true) : '');
Q_WebServer_Ctl::register(dirname(__DIR__));

// The distribution, if any, may add commands: registered now, before the
// command runs, so they appear in `list` too.
list(, $__opts) = Q_Console::parse(array_slice($argv, 1));
Q_WebServer_Layout::loadDistribution(isset($__opts['distribution']) ? (string) $__opts['distribution'] : null, dirname(__DIR__));

exit(Q_Console::run($argv));
