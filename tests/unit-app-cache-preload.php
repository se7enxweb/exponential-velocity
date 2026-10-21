<?php
/**
 * Q.web.appCache.file is loaded in the server process, before the source
 * transform, and every worker inherits what it compiled. A class the file
 * loads besides its own never gets the transform in a worker, so its
 * header() and setcookie() do nothing there. The server says so at start:
 *
 *   - a clean file (its class and nothing else): no warning, nothing listed;
 *   - a file that loads another class calling header(): a warning naming the
 *     file and the class, listed with needsTransform;
 *   - one that loads a class the transform would not change: listed for the
 *     control panel, but no warning in the log;
 *   - a second class declared in the listed file itself is reported too;
 *   - Q.web.appCache.allow, by class name or by file: nothing reported;
 *   - Q.web.appCache.strict: the warning sets $refuse (the server exits);
 *     without strict, or with a clean file, it does not;
 *   - the control panel's cache tab shows it as a warning.
 *
 *   php tests/unit-app-cache-preload.php
 */

class Q_Config
{
	static $data = array();
	static function get()
	{
		$args = func_get_args();
		$default = array_pop($args);
		$node = self::$data;
		foreach ($args as $k) {
			if (!is_array($node) or !array_key_exists($k, $node)) return $default;
			$node = $node[$k];
		}
		return $node;
	}
}
class Q_WebServer
{
	static $port = 8787;
}
class Q
{
	static function ifset($a, $k, $d = null) { return isset($a[$k]) ? $a[$k] : $d; }
}

require __DIR__ . '/../src/Q/WebServer/AppCache.php';
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

$dir = sys_get_temp_dir() . '/qbix-app-cache-preload-' . getmypid();
@mkdir($dir, 0700, true);
$dir = realpath($dir);
$log = $dir . '/error.log';
ini_set('log_errors', '1');
ini_set('error_log', $log);

// One cache class per case: require_once loads each file once per process.
function fixture($name, $body)
{
	global $dir;
	file_put_contents("$dir/$name.php", "<?php\n$body\n");
	return "$dir/$name.php";
}
function cacheClass($class, $loads = '')
{
	return fixture($class, $loads . "class $class { static function fromDir(\$d) { return new self(); } "
		. "function serve(array \$r) { return null; } }");
}
function helper($class, $calls)
{
	return fixture($class, "class $class { static function send() { $calls } }");
}
function run($class, $file, $extra = array())
{
	global $log;
	file_put_contents($log, '');
	Q_Config::$data = array('Q' => array('web' => array('appCache' => array_merge(
		array('file' => $file, 'class' => $class, 'dir' => '/d'), $extra))));
	Q_WebServer_AppCache::init();
	return (string) file_get_contents($log);
}

$A = 'Q_WebServer_AppCache';

// Clean: the class and nothing else.
$out = run('CleanCache', cacheClass('CleanCache'));
check('clean: nothing listed', $A::$preload, array());
check('clean: nothing logged', $out, '');
check('clean: not refused', $A::$refuse, false);
check('clean: still asked', $A::$class, 'CleanCache');

// Clean under strict: starts.
$out = run('StrictCleanCache', cacheClass('StrictCleanCache'), array('strict' => true));
check('clean, strict: not refused', $A::$refuse, false);
check('clean, strict: nothing logged', $out, '');

// Loads a class that calls header(): the case that broke every download.
$h = helper('DownloadHelper', 'header("Content-Type: application/pdf");');
$out = run('DirtyCache', cacheClass('DirtyCache', "require_once " . var_export($h, true) . ";\n"));
check('dirty: one file listed', count($A::$preload), 1);
check('dirty: the helper file', $A::$preload[0]['file'] ?? null, $h);
check('dirty: its class', $A::$preload[0]['classes'] ?? null, array('DownloadHelper'));
check('dirty: needs the transform', $A::$preload[0]['needsTransform'] ?? null, true);
check('dirty: warning logged', strpos($out, 'WARNING') !== false, true);
check('dirty: warning names the class', strpos($out, 'DownloadHelper') !== false, true);
check('dirty: warning names the file', strpos($out, $h) !== false, true);
check('dirty: warning says what goes wrong', strpos($out, 'do nothing') !== false, true);
check('dirty: not refused without strict', $A::$refuse, false);
check('dirty: still asked', $A::$class, 'DirtyCache');

// The panel's cache tab lists it as a warning.
$C = 'Q_WebServer_Cache';
class Q_WebServer_Cache { static $apcuWarnings = array(); static $enabled = true; static $dir = '/c'; static $defaultTtl = 60; }
require __DIR__ . '/../src/Q/WebServer/Panel/Cache.php';
$w = Q_WebServer_Panel_Cache::warnings();
check('panel: one warning', count($w), 1);
check('panel: level warn', $w[0]['level'] ?? null, 'warn');
check('panel: names the class', strpos($w[0]['title'] ?? '', 'DownloadHelper') !== false, true);

// Strict: the same refuses to start.
$h = helper('StrictHelper', 'setcookie("a", "b");');
$out = run('StrictDirtyCache', cacheClass('StrictDirtyCache', "require_once " . var_export($h, true) . ";\n"),
	array('strict' => true));
check('dirty, strict: refused', $A::$refuse, true);
check('dirty, strict: says it does not start', strpos($out, 'does not start') !== false, true);

// Loads a class the transform would not change: listed, not warned about.
$h = helper('PlainHelper', '$header = "x"; return strlen($header);');
$out = run('HarmlessCache', cacheClass('HarmlessCache', "require_once " . var_export($h, true) . ";\n"),
	array('strict' => true));
check('harmless: listed', count($A::$preload), 1);
check('harmless: does not need the transform', $A::$preload[0]['needsTransform'] ?? null, false);
check('harmless: nothing logged', $out, '');
check('harmless, strict: not refused', $A::$refuse, false);
$w = Q_WebServer_Panel_Cache::warnings();
check('harmless: panel says info', $w[0]['level'] ?? null, 'info');

// A second class in the listed file itself.
$f = fixture('TwoClassCache', "class TwoClassCache { static function fromDir(\$d) { return new self(); } "
	. "function serve(array \$r) { return null; } }\nclass TwoClassExtra { function f() { header('X: y'); } }");
$out = run('TwoClassCache', $f);
check('two classes: the extra one listed', $A::$preload[0]['classes'] ?? null, array('TwoClassExtra'));
check('two classes: warned', strpos($out, 'TwoClassExtra') !== false, true);

// Allowed by class name, and by file.
$h = helper('AllowedByName', 'header("X: 1");');
$out = run('AllowNameCache', cacheClass('AllowNameCache', "require_once " . var_export($h, true) . ";\n"),
	array('allow' => array('allowedbyname'), 'strict' => true));
check('allow class: nothing listed', $A::$preload, array());
check('allow class: nothing logged', $out, '');
check('allow class: not refused', $A::$refuse, false);
$h = helper('AllowedByFile', 'header("X: 1");');
$out = run('AllowFileCache', cacheClass('AllowFileCache', "require_once " . var_export($h, true) . ";\n"),
	array('allow' => array($h)));
check('allow file: nothing listed', $A::$preload, array());
check('allow file: nothing logged', $out, '');

// A file without a class (a function library) that calls header().
$lib = fixture('functions-lib', 'function send_it() { header("X: 1"); }');
$out = run('LibCache', cacheClass('LibCache', "require_once " . var_export($lib, true) . ";\n"));
check('library: listed without classes', $A::$preload[0]['classes'] ?? null, array());
check('library: warned', strpos($out, $lib) !== false, true);

foreach (glob("$dir/*") as $f) unlink($f);
rmdir($dir);

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
