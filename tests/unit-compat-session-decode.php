<?php

/**
 * The compat session handler reads back exactly what PHP's own session
 * encoder wrote, without a warning.
 *
 * unserializeSession() decoded each value from the whole rest of the session
 * string, which PHP 8.3 and later reports as "unserialize(): Extra data" for
 * every key but the last -- a warning per key on every request a persistent
 * worker served -- and found the next key by serializing the value again and
 * skipping that many bytes. Where that is not the text that was stored (0.1
 * as a float, an object with __serialize or __sleep) the offset slipped and
 * the keys after it came back wrong.
 *
 * The sessions here are made by PHP itself (session_encode()) from values
 * chosen to be awkward, decoded by the compat handler, and compared.
 *
 *   php tests/unit-compat-session-decode.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require_once __DIR__ . '/../src/Q/WebServer/Compat.php';

$pass = 0;
$fail = 0;
function sd_check($what, $ok, $detail = '')
{
	global $pass, $fail;
	if ($ok) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s%s\n", $what, $detail !== '' ? "\n        $detail" : '');
}

class SdSerializable
{
	public $a = 1;
	function __serialize(): array { return array('x' => $this->a, 'note' => 'a|b;c'); }
	function __unserialize(array $d): void { $this->a = $d['x']; }
}
class SdSleep
{
	public $keep = 'k';
	public $drop = 'd';
	function __sleep() { return array('keep'); }
}
enum SdSuit: string { case Hearts = 'H'; case Spades = 'S'; }

$cases = array(
	'scalars' => array('n' => null, 't' => true, 'f' => false, 'i' => -42, 'z' => 0, 's' => 'hello'),
	'floats' => array('tenth' => 0.1, 'third' => 1 / 3, 'big' => 1.0e300, 'after' => 'still here'),
	'separators in strings' => array('pipe' => 'a|b|c', 'semi' => 'x;y;', 'quote' => 'say "hi"', 'multi' => "é|\n|;", 'last' => 1),
	'nested arrays' => array('eZUserLoggedInID' => 14, 'tree' => array(1 => array('a' => array(2.5, 'b|c')), 'k' => array()), 'after' => 'ok'),
	'objects' => array('ser' => new SdSerializable(), 'sleep' => new SdSleep(), 'std' => (object) array('p' => 0.1), 'after' => 'ok'),
	'enum' => array('suit' => SdSuit::Spades, 'after' => 'ok'),
	'empty' => array(),
);

$dir = sys_get_temp_dir() . DS . 'qbix-sessdecode-' . getmypid();
@mkdir($dir, 0700, true);
ini_set('session.save_path', $dir);
ini_set('session.serialize_handler', 'php');
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.cache_limiter', '');
session_start();

$decode = new ReflectionMethod('Q_WebServer_Compat', 'unserializeSession');
$decode->setAccessible(true);

foreach ($cases as $label => $values) {
	$_SESSION = $values;
	$encoded = session_encode();
	$warnings = array();
	set_error_handler(function ($no, $str) use (&$warnings) { $warnings[] = $str; return true; });
	$got = $decode->invoke(null, $encoded);
	restore_error_handler();
	sd_check("$label: no warning while decoding", !$warnings, implode(' / ', $warnings));
	// What PHP itself would read back, as the reference (objects compare by value).
	$_SESSION = array();
	session_decode($encoded);
	$want = $_SESSION;
	sd_check("$label: same keys in the same order", array_keys($got) === array_keys($want),
		json_encode(array_keys($got)) . ' vs ' . json_encode(array_keys($want)));
	sd_check("$label: same values", $got == $want, var_export($got, true));
}

session_write_close();
foreach (glob($dir . DS . '*') ?: array() as $f) @unlink($f);
@rmdir($dir);

printf("%s: %d passed, %d failed\n", $fail ? 'FAIL' : 'PASS', $pass, $fail);
exit($fail ? 1 : 0);
