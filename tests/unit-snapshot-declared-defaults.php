<?php

/**
 * A worker puts the statics of a class first declared during a request back
 * to the values the class declares, on every PHP the server runs on.
 *
 * PHP 8.0 keeps a user class's static values in the same table as its declared
 * defaults (unless the opcode cache made the class immutable), so there
 * ReflectionProperty::getDefaultValue() answers with whatever the property
 * holds now. The snapshot took that for the default: a class first loaded in
 * a request was "reset" to that request's values, and every later request in
 * the worker began with them -- Exponential's siteaccess stayed the first
 * request's, so the admin answered as the public site. On 8.0 the defaults
 * are read from the class's source instead (Snapshot::declaredDefault()).
 *
 * Asserted on whatever PHP runs the test: the declared values come back after
 * every static has been changed, for the forms a declaration takes (private,
 * protected, typed, grouped, constants, self::, imported names, __DIR__, a
 * trait's statics, a braced namespace); a class with no source is reported as
 * unknown on 8.0; and Snapshot puts a class declared after the snapshot back
 * to its declared values.
 *
 *   php tests/unit-snapshot-declared-defaults.php
 */

require __DIR__ . '/../src/Q/WebServer/Snapshot.php';

$pass = 0;
$fail = 0;
function sdd_check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qbix-declared-defaults-' . getmypid();
@mkdir($dir, 0700, true);
// Written now and included at once: the opcode cache does not cache a file
// this new (file_update_protection), so on 8.0 the class is a mutable one,
// the case the source reader is there for.
file_put_contents("$dir/a.php", <<<'PHP'
<?php
namespace App\Model;

use App\Support\Limits as L;

const LOCAL = 'local-const';

trait Counts { private static $count = 3; protected static $seen = array('t'); }

class Settings
{
	use Counts;
	const MODE = 'mode-a';
	public static $mode = self::MODE;
	protected static $instance = null;
	private static $list = array(1, 'two' => [2, 2.5], 'three' => "x;y,z");
	static $grouped1 = 1, $grouped2, $grouped3 = PHP_INT_SIZE * 2;
	public static ?array $typed = null;
	public static int $typedNoDefault;
	public static $path = __DIR__ . '/data';
	public static $limit = L::MAX;
	public static $local = LOCAL;
	/* a comment */ public static $commented /* inside */ = 'c'; // after
	public static $heredoc = <<<TXT
		one; two, three
		TXT;
	static function change()
	{
		static $x = 1;
		self::$mode = 'changed'; self::$instance = new \stdClass; self::$list = array();
		self::$grouped1 = 9; self::$grouped2 = 9; self::$grouped3 = 9; self::$typed = array(9);
		self::$typedNoDefault = 9; self::$path = 'p'; self::$limit = 0; self::$local = 'l';
		self::$count = 9; self::$seen = array(); self::$commented = 'x'; self::$heredoc = 'h';
	}
}
PHP
);
file_put_contents("$dir/b.php", <<<'PHP'
<?php
namespace App\Support {
	class Limits { const MAX = 42; }
}
namespace Other {
	class Braced { public static $v = \App\Support\Limits::MAX + 1; private static $w = [__CLASS__]; }
}
PHP
);

$snapshotTaken = Q_WebServer_Snapshot::take();
require "$dir/b.php";
require "$dir/a.php";
\App\Model\Settings::change();
$r = new ReflectionClass('Other\Braced');
foreach (array('v', 'w') as $n) { $p = $r->getProperty($n); Q_WebServer_Snapshot::reach($p); $p->setValue(null, 'changed'); }

$want = array(
	'mode' => 'mode-a', 'instance' => null, 'list' => array(1, 'two' => array(2, 2.5), 'three' => 'x;y,z'),
	'grouped1' => 1, 'grouped2' => null, 'grouped3' => PHP_INT_SIZE * 2, 'typed' => null,
	'path' => "$dir/data", 'limit' => 42, 'local' => 'local-const', 'count' => 3, 'seen' => array('t'),
	'commented' => 'c', 'heredoc' => "one; two, three",
);
$r = new ReflectionClass('App\Model\Settings');
foreach ($want as $name => $value) {
	$got = Q_WebServer_Snapshot::declaredDefault($r->getProperty($name), $known);
	sdd_check("Settings::\$$name: the declared value", array($known, $got), array(true, $value));
}
$r = new ReflectionClass('Other\Braced');
sdd_check('a braced namespace, a constant of another class', Q_WebServer_Snapshot::declaredDefault($r->getProperty('v')), 43);
sdd_check('__CLASS__ in a default', Q_WebServer_Snapshot::declaredDefault($r->getProperty('w')), array('Other\Braced'));
$r = new ReflectionClass('App\Model\Settings');
Q_WebServer_Snapshot::declaredDefault($r->getProperty('typedNoDefault'), $known);
sdd_check('a typed static without a default has none to tell', $known, false);

eval('class SddEvaluated { public static $e = 5; }');
SddEvaluated::$e = 6;
$v = Q_WebServer_Snapshot::declaredDefault(new ReflectionProperty('SddEvaluated', 'e'), $known);
if (PHP_VERSION_ID >= 80100) {
	sdd_check('a class from eval(): told by PHP itself', array($known, $v), array(true, 5));
} else {
	sdd_check('a class from eval(): unknown on PHP 8.0, never the current value', $known, false);
}

// The snapshot puts a class declared after it back, as a worker does between requests.
if ($snapshotTaken !== null) {
	Q_WebServer_Snapshot::updateNewClasses();
	Q_WebServer_Snapshot::restoreStatics();
	$r = new ReflectionClass('App\Model\Settings');
	$after = array();
	foreach (array('mode', 'instance', 'list', 'count', 'path') as $n) {
		$p = $r->getProperty($n); Q_WebServer_Snapshot::reach($p); $after[$n] = $p->getValue();
	}
	sdd_check('after a restore the class holds its declared values', $after,
		array('mode' => 'mode-a', 'instance' => null, 'list' => $want['list'], 'count' => 3, 'path' => "$dir/data"));
	$unknown = Q_WebServer_Snapshot::unknownDefaults();
	sdd_check('only the eval()\'d class is unknown', $unknown, PHP_VERSION_ID >= 80100 ? array() : array('SddEvaluated::$e'));
}

foreach (glob("$dir/*") as $f) @unlink($f);
@rmdir($dir);
echo $fail ? "  FAIL - $fail of " . ($pass + $fail) . " case(s)\n" : "  PASS - $pass case(s)\n";
exit($fail ? 1 : 0);
