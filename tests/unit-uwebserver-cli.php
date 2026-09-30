#!/usr/bin/env php
<?php
/**
 * uwebserver's command line, GNU style: --help and --version answer on
 * standard output and exit 0; an unknown option, a missing or malformed
 * value, or a word it does not expect is reported on standard error with the
 * program's name and "Try 'uwebserver --help'", exits 2, and never starts a
 * server. Until 0.0.4.42 any argument but the about flags started one on
 * 0.0.0.0:8080, --help included.
 *
 *   php tests/unit-uwebserver-cli.php
 */
require __DIR__ . '/uwebserver-helpers.php';

$bin = uw_build();
if ($bin === null) uw_skip('no C compiler or OpenSSL headers');
check('uwebserver builds', is_string($bin));
if (!is_string($bin)) uw_finish();

// ── --help ──────────────────────────────────────────────────────────────
foreach (array(array('--help'), array('-h'), array('-help'), array('--help', '--port=1')) as $args) {
	list($rc, $out, $err, $secs) = uw_run($bin, $args);
	$w = implode(' ', $args);
	check("$w: exit 0", $rc, 0);
	check("$w: usage on standard output", strpos($out, 'Usage: uwebserver [OPTION]...') === 0);
	check("$w: nothing on standard error", $err, '');
	check("$w: returns at once, it serves nothing", $secs < 2);
}

// ── --version and the house about flags ──────────────────────────────────
foreach (array('--version', '-V', '-v', '-version', '--about', '-about', '--copyright', '-copyright') as $flag) {
	list($rc, $out, $err) = uw_run($bin, array($flag));
	$first = strtok($out, "\n");
	check("$flag: exit 0", $rc, 0);
	check("$flag: first line names the program, the package and a release", (bool) preg_match('/^uwebserver \(Exponential Velocity\) v\d/', (string) $first));
	check("$flag: copyright, licence and warranty", strpos($out, 'Copyright (C)') !== false && strpos($out, 'License MIT') !== false && strpos($out, 'There is NO WARRANTY') !== false);
	check("$flag: nothing on standard error", $err, '');
}

// ── Usage errors: a message, exit 2, and nothing started ────────────────
$errors = array(
	array(array('--bogus'), "unrecognized option '--bogus'"),
	array(array('--hel'), "unrecognized option '--hel'"),     // no abbreviations
	array(array('-x'), "invalid option -- 'x'"),
	array(array('-hx'), "invalid option -- 'x'"),
	array(array('--port'), "option '--port' requires an argument"),
	array(array('-p'), "option requires an argument -- 'p'"),
	array(array('--port', '--help'), "option '--port' requires an argument"),
	array(array('--port=abc'), "invalid port 'abc' for '--port'"),
	array(array('--port=0'), "invalid port '0' for '--port'"),
	array(array('--port=65536'), "invalid port '65536' for '--port'"),
	array(array('--port=99999999999999999999'), "invalid port '99999999999999999999'"),
	array(array('-p', '12a'), "invalid port '12a' for '-p'"),
	array(array('--help=yes'), "option '--help' doesn't allow an argument"),
	array(array('foo'), "unexpected argument 'foo'"),
	array(array('--', '--help'), "unexpected argument '--help'"),  // after -- nothing is an option
	array(array('--cert=/nonexistent'), '--cert needs --key'),
	array(array('--key=/nonexistent'), '--key needs --cert'),
	array(array('--tls-port=19999'), '--tls-port needs --cert and --key'),
	array(array('--bogus', '--help'), "unrecognized option '--bogus'"),  // the error wins over --help
);
foreach ($errors as $e) {
	list($args, $msg) = $e;
	list($rc, $out, $err, $secs) = uw_run($bin, $args);
	$w = implode(' ', $args);
	check("$w: exit 2", $rc, 2);
	check("$w: says \"$msg\"", strpos($err, "uwebserver: $msg") === 0);
	check("$w: points to --help", strpos($err, "Try 'uwebserver --help' for more information.") !== false);
	check("$w: nothing on standard output", $out, '');
	check("$w: returns at once, it serves nothing", $secs < 2);
}

uw_finish();
