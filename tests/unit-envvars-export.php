<?php

/**
 * envvars works like Debian's /etc/apache2/envvars: what it sets reaches the
 * server process, so every worker sees it with getenv().
 *
 * Asserted, with scratch trees:
 *   - the file is parsed, never run: export NAME=value, NAME=value, quotes,
 *     comments, a trailing " # comment" on a bare value;
 *   - exportEnvvars() puts the values into the environment, the top overlay
 *     winning over the base tree;
 *   - a variable already in the environment is kept (the caller beats the file);
 *   - PATH, LD_*, DYLD_*, MALLOC_*, HTTP_*, PHPRC and the rest of the denylist
 *     are refused, and so is a value only a shell could work out ($X, `cmd`);
 *     a single-quoted value is taken literally;
 *   - the start record leaves out what came from the file, so a restart reads
 *     the file again instead of carrying an old value over;
 *   - the *_RUN_* settings are still read from the file (Q_WebServer_RunAs);
 *   - end to end: a server started with --conf-dir, from an environment that
 *     lacks the variable, answers a pool worker's getenv() with the file's
 *     value, and says on its log which lines it refused.
 *
 *   php tests/unit-envvars-export.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
require_once __DIR__ . '/../src/Q/WebServer/RunAs.php';
require_once __DIR__ . '/../src/Q/WebServer/StartRecord.php';
$L = 'Q_WebServer_Layout';
$pass = 0; $fail = 0;
function ev_check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

$base = sys_get_temp_dir() . DS . 'qbix-envvars-' . getmypid();
$lower = "$base/lower"; $upper = "$base/upper";
@mkdir($lower, 0700, true); @mkdir($upper, 0700, true);
foreach (array('QBIXT_A', 'QBIXT_B', 'QBIXT_C', 'QBIXT_KEPT', 'QBIXT_SHELL', 'QBIXT_TICK', 'QBIXT_LIT', 'QBIXT_SPACE',
	'QBIXT_COMMENT', 'QBIXT_RUN_USER', 'HTTP_PROXY', 'LD_PRELOAD', 'PHPRC') as $n) putenv($n);

file_put_contents("$lower/envvars", <<<'ENV'
# the base tree
export QBIXT_A=lower
QBIXT_B="from lower"
export QBIXT_C=lower
ENV
);
file_put_contents("$upper/envvars", <<<'ENV'
# the overlay
export QBIXT_C=upper
export QBIXT_KEPT=file
export QBIXT_SHELL=$HOME/x
export QBIXT_TICK="`id`"
export QBIXT_LIT='$NOT_EXPANDED'
export QBIXT_SPACE=two words
export QBIXT_COMMENT=1 # a comment
export QBIXT_RUN_USER=nobody
export PATH=/evil/bin
export LD_PRELOAD=/evil.so
export ld_library_path=/evil
export DYLD_INSERT_LIBRARIES=/evil
export MALLOC_CHECK_=3
export HTTP_PROXY=http://evil
export PHPRC=/evil
export PHP_INI_SCAN_DIR=/evil
export HOME=/evil
export GLIBC_TUNABLES=x
not a line
1BAD=x
unset QBIXT_A
ENV
);

// ── Parsing ──────────────────────────────────────────────────────────────
$vars = $L::envvars($upper);
ev_check('a bare value ends at an unquoted " #"', $vars['QBIXT_COMMENT'] ?? null, '1');
ev_check('single quotes are taken off', $vars['QBIXT_LIT'] ?? null, '$NOT_EXPANDED');
ev_check('a line that is not an assignment is skipped', isset($vars['1BAD']) || isset($vars['not']), false);

// ── Exporting ────────────────────────────────────────────────────────────
putenv('QBIXT_KEPT=caller');
$r = $L::exportEnvvars(array($lower, $upper));
ev_check('the base tree\'s value reaches the environment', getenv('QBIXT_A'), 'lower');
ev_check('a double-quoted value reaches it unquoted', getenv('QBIXT_B'), 'from lower');
ev_check('the overlay wins over the base tree', getenv('QBIXT_C'), 'upper');
ev_check('and $_ENV and $_SERVER see the same', array($_ENV['QBIXT_C'] ?? null, $_SERVER['QBIXT_C'] ?? null), array('upper', 'upper'));
ev_check('a variable the server was started with is kept', getenv('QBIXT_KEPT'), 'caller');
ev_check('and reported as kept', in_array('QBIXT_KEPT', $r['kept'], true), true);
ev_check('a single-quoted $ is literal', getenv('QBIXT_LIT'), '$NOT_EXPANDED');
ev_check('a bare value with a trailing comment', getenv('QBIXT_COMMENT'), '1');
ev_check('$VAR is not expanded or exported', getenv('QBIXT_SHELL'), false);
ev_check('`cmd` is not run or exported', getenv('QBIXT_TICK'), false);
ev_check('an unquoted space is refused', getenv('QBIXT_SPACE'), false);
ev_check('PATH is untouched', getenv('PATH') !== '/evil/bin', true);
foreach (array('LD_PRELOAD', 'ld_library_path', 'DYLD_INSERT_LIBRARIES', 'MALLOC_CHECK_', 'HTTP_PROXY', 'PHPRC',
	'PHP_INI_SCAN_DIR', 'GLIBC_TUNABLES') as $n) {
	ev_check("$n is refused", array(getenv($n), isset($r['refused'][$n])), array(false, true));
}
ev_check('HOME is refused', isset($r['refused']['HOME']) and getenv('HOME') !== '/evil', true);
ev_check('every refusal names its file', strpos($r['refused']['PATH'] ?? '', "$upper/envvars") === 0, true);
ev_check('the *_RUN_* settings are exported too', getenv('QBIXT_RUN_USER'), 'nobody');

// A second call (a reload) may change what the file itself set before.
file_put_contents("$upper/envvars", "export QBIXT_C=changed\n");
$L::exportEnvvars(array($lower, $upper));
ev_check('a value the file set can be changed by the file', getenv('QBIXT_C'), 'changed');
ev_check('one the caller set still cannot', getenv('QBIXT_KEPT'), 'caller');

// ── The start record ─────────────────────────────────────────────────────
putenv('QBIX_RUN_GROUP=from-caller');
$L::$exported['QBIX_RUN_USER'] = 'from-file';
putenv('QBIX_RUN_USER=from-file');
$env = Q_WebServer_StartRecord::environment(Q_WebServer_StartRecord::withoutExported(getenv()));
ev_check('the start record leaves out what came from envvars', isset($env['QBIX_RUN_USER']), false);
ev_check('and keeps what the caller set', $env['QBIX_RUN_GROUP'] ?? null, 'from-caller');
putenv('QBIX_RUN_USER'); putenv('QBIX_RUN_GROUP'); unset($L::$exported['QBIX_RUN_USER']);

// ── RunAs still reads the file ───────────────────────────────────────────
file_put_contents("$upper/envvars", "export QBIX_RUN_GROUP=staff # the group\n");
Q_Config::set('Q', 'webserver', 'confDirs', array($upper));
ev_check('RunAs reads <PREFIX>_RUN_* from envvars as before', Q_WebServer_RunAs::env('GROUP'), array('staff', "$upper/envvars QBIX_RUN_GROUP"));
Q_Config::clear('Q', 'webserver', 'confDirs');

// ── End to end: a pool worker's getenv() ─────────────────────────────────
if (function_exists('pcntl_fork') and function_exists('posix_kill')) {
	$GLOBALS['rh'] = array('base' => null, 'root' => null, 'children' => array(), 'servers' => array(), 'names' => array(),
		'pass' => 0, 'fail' => 0, 'bugs' => array());
	require_once __DIR__ . '/fixtures/race-harness.php';
	list($rbase, $root) = rh_setup('envvars-e2e');
	$etc = "$rbase/etc";
	@mkdir($etc, 0700, true);
	file_put_contents("$etc/qbix.conf", '{}');
	file_put_contents("$etc/envvars", "# as /etc/apache2/envvars\nexport QBIXT_DEMO=1\nexport LD_PRELOAD=/nonexistent.so\n");
	file_put_contents("$root/index.php", '<?php echo json_encode(array("demo" => getenv("QBIXT_DEMO"), "ld" => getenv("LD_PRELOAD")));');
	foreach (array('QBIXT_DEMO', 'LD_PRELOAD', 'QBIX_CONF_DIR', 'VC_CONF_DIR') as $n) putenv($n);
	$GLOBALS['rh_extra_args'] = array('--conf-dir=' . $etc, '--distribution=none');
	$port = rh_start('ev', array('Q' => array('webserver' => array('forkPerRequest' => false, 'cache' => false))), 1);
	$res = rh_get($port, '/index.php');
	$got = json_decode((string) ($res['body'] ?? ''), true);
	ev_check('a worker of a server started without the variable sees the envvars value', $got['demo'] ?? null, '1');
	ev_check('and never the refused loader variable', $got['ld'] ?? null, false);
	// (The harness writes standard output and error through two separate
	// descriptors into one file, so start-up lines on standard error can be
	// overwritten there; --layout reports the refusals instead.)
	$out = shell_exec('env -u QBIX_CONF_DIR -u VC_CONF_DIR ' . escapeshellarg(PHP_BINARY) . ' '
		. escapeshellarg(__DIR__ . '/../sbin/qbixserver.php') . ' --distribution=none --conf-dir=' . escapeshellarg($etc) . ' --layout 2>&1');
	$layout = json_decode((string) $out, true);
	ev_check('--layout names the refused line and why', isset($layout['envvarsRefused']['LD_PRELOAD']), true);
	ev_check('--layout lists what the file sets', $layout['envvars'] ?? null, array('QBIXT_DEMO', 'LD_PRELOAD'));
} else {
	printf("  skip  end to end: needs pcntl and posix for the worker pool\n");
}

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
rmdir($base);

printf("%s: %d passed, %d failed\n", $fail ? 'FAIL' : 'PASS', $pass, $fail);
exit($fail ? 1 : 0);
