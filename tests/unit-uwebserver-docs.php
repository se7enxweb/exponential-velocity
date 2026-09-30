#!/usr/bin/env php
<?php
/**
 * uwebserver's documentation says what the program does: every option that
 * --help lists is in the manual page (docs/uwebserver.1), in
 * docs/uwebserver.md and in the bash completion, and nothing is in the
 * completion that the program does not take; every --print-config setting
 * is an option; --help fits 80 columns; the manual page renders without a
 * warning; the Makefile builds the program; the completion parses and
 * completes.
 *
 *   php tests/unit-uwebserver-docs.php
 */
require __DIR__ . '/uwebserver-helpers.php';

$root = dirname(__DIR__);
$bin = uw_build();
if ($bin === null) uw_skip('no C compiler or OpenSSL headers');
check('uwebserver builds', is_string($bin));
if (!is_string($bin)) uw_finish();

list($rc, $help) = uw_run($bin, array('--help'));
preg_match_all('/^  (?:-\w, )?\s*(--[a-z0-9-]+)/m', $help, $m);
$opts = array_unique($m[1]);
check('--help lists the options (more than 50)', count($opts) > 50);
check('--help fits 80 columns', array_values(array_filter(explode("\n", $help), function ($l) { return strlen($l) > 79; })), array());

$man = (string) file_get_contents("$root/docs/uwebserver.1");
$md = (string) file_get_contents("$root/docs/uwebserver.md");
$comp = (string) file_get_contents("$root/native/uwebserver/uwebserver.bash-completion");
foreach ($opts as $o) {
	check("$o is in the manual page", strpos($man, str_replace('-', '\\-', $o)) !== false);
	check("$o is in docs/uwebserver.md", strpos($md, $o) !== false);
	check("$o is in the bash completion", (bool) preg_match('/(^|\s|")' . preg_quote($o, '/') . '(\s|"|$)/', $comp));
}
preg_match('/local opts="([^"]+)"/', $comp, $cm);
$compOpts = preg_split('/\s+/', trim($cm[1] ?? ''));
foreach ($compOpts as $o) {
	if (in_array($o, $opts, true)) continue;
	// A --no- form: the program must take it.
	list($rc2, , $err2) = uw_run($bin, array($o, '--print-config'));
	check("the completion's $o is taken by the program", strpos($err2, 'unrecognized option') === false);
}
list($rc, $conf) = uw_run($bin, array('--print-config'));
preg_match_all('/^#? ?([a-z0-9-]+) =/m', $conf, $pm);
foreach (array_unique($pm[1]) as $k) {
	if ($k === 'uwebserver') continue;
	check("--print-config's $k is an option", in_array("--$k", $opts, true));
}

// The manual page renders without a warning.
if (trim((string) shell_exec('command -v man 2>/dev/null')) !== '') {
	$w = (string) shell_exec('MANWIDTH=80 man --warnings -l ' . escapeshellarg("$root/docs/uwebserver.1") . ' 2>&1 >/dev/null');
	check('the manual page renders without a warning', trim($w), '');
} else {
	echo "  skip  no man(1)\n";
}

// The completion parses and completes an option and a value.
exec('bash -n ' . escapeshellarg("$root/native/uwebserver/uwebserver.bash-completion") . ' 2>&1', $o, $brc);
check('the completion parses', $brc, 0);
$probe = 'source ' . escapeshellarg("$root/native/uwebserver/uwebserver.bash-completion")
	. '; COMP_WORDS=(uwebserver --access-log-f); COMP_CWORD=1; _uwebserver; echo "${COMPREPLY[*]}"'
	. '; COMP_WORDS=(uwebserver --symlinks = n); COMP_CWORD=3; _uwebserver; echo "${COMPREPLY[*]}"';
$lines = explode("\n", trim((string) shell_exec('bash -c ' . escapeshellarg($probe))));
check('the completion completes an option', $lines[0] ?? null, '--access-log-format');
check('...and a value after =', $lines[1] ?? null, 'never');

// The Makefile builds the program.
if (trim((string) shell_exec('command -v make 2>/dev/null')) !== '') {
	$out = uw_dir() . '/made';
	$extra = getenv('UWEB_TEST_LIBS') ? ' LIBS=' . escapeshellarg(getenv('UWEB_TEST_LIBS')) : '';
	if (trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' describe --tags --abbrev=0 2>/dev/null')) === '') $extra .= ' UWEB_VERSION=v0.0.0';
	exec('make -s -C ' . escapeshellarg("$root/native/uwebserver") . ' OUT=' . escapeshellarg($out) . $extra . ' 2>&1', $mo, $mrc);
	check('make -C native/uwebserver builds it', array($mrc, is_executable($out)), array(0, true));
	if (is_executable($out)) {
		list($vrc, $vout) = uw_run($out, array('--version'));
		check('...stamped with the release from git', (bool) preg_match('/^uwebserver \(Exponential Velocity\) v\d+\.\d+/', $vout));
	}
} else {
	echo "  skip  no make(1)\n";
}

uw_finish();
