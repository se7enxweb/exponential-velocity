<?php

/**
 * Q_WebServer_Compat::cannotNeedTransform(), the check that lets a file skip
 * the source transform without being tokenized.
 *
 * It may say "needs no transform" only when that is certain: a wrong yes
 * leaves an exit() that ends the worker or a header() that goes nowhere. A
 * wrong no only costs the time it saves. So every case here that says yes is
 * also checked against the tokenizer: no exit or die, and no name the
 * transform rewrites, anywhere in the code.
 *
 *   php tests/unit-compat-transform-prefilter.php [file...]
 *
 * Files given on the command line are checked the same way (a corpus run).
 */

require __DIR__ . '/../src/Q/WebServer/Compat.php';

$pass = 0;
$fail = 0;

function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what,
		var_export($got, true), var_export($want, true));
}

/** Whether the tokenizer finds anything in $source that the transform could change. */
function hasTarget($source)
{
	$p = new ReflectionProperty('Q_WebServer_Compat', 'replacements');
	$p->setAccessible(true);
	$names = $p->getValue();
	foreach (token_get_all($source) as $t) {
		if (!is_array($t)) continue;
		if ($t[0] === T_EXIT) return true;
		if ($t[0] === T_STRING and isset($names[strtolower($t[1])])) return true;
		if (defined('T_NAME_FULLY_QUALIFIED') and $t[0] === T_NAME_FULLY_QUALIFIED
			and isset($names[strtolower(ltrim($t[1], '\\'))])) return true;
	}
	return false;
}

/** The check, and when it says yes, the tokenizer's agreement. */
function skip($source)
{
	$yes = Q_WebServer_Compat::cannotNeedTransform($source);
	if ($yes and hasTarget($source)) {
		check('a skipped source has nothing to transform: ' . substr(json_encode($source), 0, 120), true, false);
	}
	if ($yes) {
		check('a skipped source comes back unchanged', Q_WebServer_Compat::transformSource($source, ''), $source);
	}
	return $yes;
}

if (count($argv) > 1) {
	$skipped = 0;
	foreach (array_slice($argv, 1) as $file) {
		$s = @file_get_contents($file);
		if (is_string($s) and skip($s)) ++$skipped;
	}
	printf("  %d of %d file(s) skip the transform\n", $skipped, count($argv) - 1);
}

// ── Data files: no code, the words only inside strings and comments ──────

check('a var_export() of data skips the transform',
	skip("<?php\n// exit here, header() there\nreturn " . var_export(array(
		'a' => "exit; die('x'); header('X: 1');", 'b' => array('session_start()' => 'it\'s "quoted" \\ ok'),
		'c' => 1.5, 'd' => null, 'e' => "line\r\nbreak /* not a comment */ // nor this"), true) . ";\n"), true);

check('a class with no target skips it',
	skip("<?php\nclass A { /** @return int exit */ function f() { return strlen('die'); } }\n"), true);

check('a call to a method of the same name in a string skips it',
	skip("<?php\n\$x = 'header(';\n"), true);

// ── Code the transform changes: never skipped ────────────────────────────

$mustNot = array(
	'exit'                     => "<?php\nexit;",
	'die after a string'       => "<?php\n\$a = 'x'; die('y');",
	'header'                   => "<?php\nheader('X: 1');",
	'qualified header'         => "<?php\n\\header('X: 1');",
	'upper case'               => "<?php\nEXIT(1);",
	'after an escaped quote'   => "<?php\n\$a = 'it\\'s'; exit;",
	'after a comment quote'    => "<?php\n// don't\nexit;",
	'after a # comment quote'  => "<?php\n# don't\nexit;",
	'after a block comment'    => "<?php\n/* it's */ exit;",
	'attribute, not comment'   => "<?php\n#[A('x')] function f() { exit; }",
	'carriage return comment'  => "<?php\n// don't\rexit;",
	'double-quoted {\$}'       => "<?php\n\$a = \"{\$o->m(exit())}\";",
	'heredoc'                  => "<?php\n\$a = <<<EOT\n{\$f(header('X'))}\nEOT;\n",
	'nowdoc'                   => "<?php\n\$a = <<<'EOT'\nx\nEOT;\nexit;",
	'inline text after ?>'     => "<?php \$a = 1; ?>don't<?php exit; ?>it's",
	'?> ends a line comment'   => "<?php // a ?>don't<?php exit;",
	'inline text first'        => "don't<?php exit;",
	'short echo tag'           => "<?= 'x'; exit;",
	'unterminated string'      => "<?php\nexit; \$a = 'x",
	'unterminated comment'     => "<?php\nexit; /* x",
	'backtick'                 => "<?php\n\$a = `ls \$x`; exit;",
	'session_start'            => "<?php\nsession_start();",
	'file_exists'              => "<?php\nif (file_exists(\$f)) {}",
);
foreach ($mustNot as $label => $source) {
	check("$label is not skipped", Q_WebServer_Compat::cannotNeedTransform($source), false);
}

// A false positive only costs time, but these are the cases worth keeping cheap.
check('a variable named like a target is a false positive, not an error',
	Q_WebServer_Compat::cannotNeedTransform("<?php\n\$exit = 1;"), false);

// ── Speed: the point of the check ────────────────────────────────────────

$big = "<?php\nreturn " . var_export(array_fill(0, 40000, array('title' => "Don't exit", 'body' => str_repeat('header() die ', 20))), true) . ";\n";
$t = microtime(true);
$yes = Q_WebServer_Compat::cannotNeedTransform($big);
$ms = (microtime(true) - $t) * 1000;
check('a large data file skips the transform', $yes, true);
check(sprintf('a %.1f MB data file is checked in under a second (%.0f ms)', strlen($big) / 1048576, $ms), $ms < 1000, true);

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
