<?php

/**
 * A multipart/form-data body gives $_POST and $_FILES exactly what PHP's own
 * SAPI gives them.
 *
 * The parser used to understand one level of brackets: "tags[]" and "a[b]"
 * worked, "Attributes[0][id]" arrived as a key with that literal name, so the
 * application found no "Attributes" at all. The same fields sent urlencoded
 * went through parse_str() and were fine, which made it look like a bug in
 * whichever form happened to post with FormData.
 *
 * PHP itself is the reference. Every case is posted to `php -S` running
 * tests/fixtures/multipart-echo.php -- the real multipart parser of the PHP
 * running this test -- and to both of the server's parsers,
 * Q_WebServer::parseMultipart() and Q_WebServer_Compat::parseMultipart(),
 * and the three results must be identical: keys, order, types and the
 * uploaded files' contents. A few cases are also checked against a literal
 * expectation, so the test says something even where `php -S` cannot run.
 *
 *   php tests/unit-multipart-form-fields.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

$pass = 0;
$fail = 0;

function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	if (is_array($got) && is_array($want)) {
		printf("  FAIL  %s\n", $what);
		foreach (differences($got, $want) as $line) printf("        %s\n", $line);
		return;
	}
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what,
		var_export($got, true), var_export($want, true));
}

/** Where two arrays differ, one line per path, order included. */
function differences($got, $want, $path = '')
{
	$out = array();
	if (!is_array($got) || !is_array($want)) {
		if ($got !== $want) {
			$out[] = sprintf('%s: got %s, want %s', $path === '' ? '(top)' : $path,
				var_export($got, true), var_export($want, true));
		}
		return $out;
	}
	if (array_keys($got) !== array_keys($want)) {
		$out[] = sprintf('%s keys: got %s, want %s', $path === '' ? '(top)' : $path,
			json_encode(array_keys($got)), json_encode(array_keys($want)));
	}
	foreach ($want as $k => $v) {
		if (array_key_exists($k, $got)) {
			$out = array_merge($out, differences($got[$k], $v, $path . '[' . $k . ']'));
		}
	}
	return $out;
}

// The compat parser asks the configuration for ini overrides; $ini holds them.
class Q_Config
{
	static $ini = array();

	static function get()
	{
		$args = func_get_args();
		if (count($args) === 5 && $args[2] === 'ini' && isset(self::$ini[$args[3]])) {
			return self::$ini[$args[3]];
		}
		return array_pop($args);
	}
}

spl_autoload_register(function ($class) {
	if (strpos($class, 'Q_') !== 0) return;
	$file = __DIR__ . '/../src/Q/' . str_replace('_', '/', substr($class, 2)) . '.php';
	if (is_file($file)) require_once $file;
});

Q_WebServer::$fileUploads = true;
Q_WebServer::$maxFileUploads = (int) ini_get('max_file_uploads');
Q_WebServer::$maxUploadSize = 1 << 30;

// ── The cases ─────────────────────────────────────────
// A field is array(name, value); a file is array(name, filename, contents, type).

$deep = 'd' . str_repeat('[x]', 70);
$shallow = 's' . str_repeat('[x]', 10);

$cases = array(
	'nested keys' => array(
		array('Attributes[0][id]', '1'), array('Attributes[0][name]', 'Title'),
		array('Attributes[1][id]', '2'), array('Attributes[1][name]', 'Intro'),
		array('a[b][c]', 'x'), array('a[b][d]', 'y'), array('plain', 'p'),
	),
	'appends' => array(
		array('tags[]', 'x'), array('tags[]', 'y'), array('m[k][]', '1'),
		array('m[k][]', '2'), array('m[][z]', '3'), array('m[][z]', '4'),
	),
	'mixed numeric and string keys' => array(
		array('a[0]', 'x'), array('a[s]', 'y'), array('a[]', 'z'), array('a[5]', 'w'),
		array('a[]', 'v'), array('a[-3]', 'n'), array('a[07]', 'o'), array('a[ 1]', 'sp'),
	),
	'repeated names' => array(
		array('x', '1'), array('x', '2'), array('y[]', '1'), array('y', 'scalar'),
		array('z', 'scalar'), array('z[a]', '1'), array('w[a]', '1'), array('w[a][b]', '2'),
	),
	'spaces and dots in names' => array(
		array('a b', '1'), array('a.b', '2'), array('c d[e f]', '3'), array('g.h[i.j]', '4'),
		array(' lead', '5'), array('k[l m][n.o]', '6'),
	),
	'unmatched brackets' => array(
		array('a[b', '1'), array('c]d', '2'), array('e[f]g', '3'), array('h[i][j', '4'),
		array('[k]', '5'), array('l[m]]', '6'), array('n[o[p]', '7'), array('q[', '8'),
	),
	'nesting level' => array(
		array($deep, 'too deep'), array($shallow, 'deep enough'), array('after', 'kept'),
	),
	'names and values that look like a query string' => array(
		array('a&b=c', 'v&w=x'), array('p%5Bq%5D', '%41'), array('r+s', 'y+z'),
		array('bin', "\0\x01\xff\r\n--"), array('empty', ''), array('lines', "one\r\ntwo\n"),
	),
	'files' => array(
		array('f', 'one.txt', 'first', 'text/plain'),
		array('g[]', 'a.txt', 'second', 'text/plain'), array('g[]', 'b.txt', 'third', 'text/plain'),
		array('h[a][b]', 'c.bin', "bytes\0\xff", 'application/octet-stream'),
		array('i[x][]', 'd.txt', 'fourth', 'text/plain'), array('i[x][]', 'e.txt', 'fifth', 'text/plain'),
		array('Attr[3][img]', 'pic.png', 'png', 'image/png'), array('Attr[3][id]', '3'),
	),
	'empty file fields and file names with a path' => array(
		array('empty', '', '', 'application/octet-stream'),
		array('path', 'C:\\Users\\me\\pic.jpg', 'jpg', 'image/jpeg'),
		array('unix', 'dir/sub/file.txt', 'unix', 'text/plain'),
		array('emptyarr[]', '', '', 'application/octet-stream'),
	),
	'file fields with malformed brackets' => array(
		array('bad[a]b', 'x.txt', 'dropped', 'text/plain'), array('bad2[a', 'y.txt', 'dropped', 'text/plain'),
		array('ok', 'o.txt', 'kept', 'text/plain'),
	),
	'a file field with a space and a dot in its name' => array(
		array('sp ace.d[k]', 'z.txt', 'normalized', 'text/plain'),
	),
	'a file and a text field of one name' => array(
		array('mixed', 'm.txt', 'file', 'text/plain'), array('mixed', 'text field of the same name'),
	),
	'a zero-byte file' => array(
		array('zero', 'zero.txt', '', 'text/plain'), array('zeroarr[a][]', 'zero2.txt', '', 'text/plain'),
	),
	'files after a malformed file name, and text fields after it' => array(
		array('first', 'a.txt', 'kept', 'text/plain'), array('broken[x', 'b.txt', 'dropped', 'text/plain'),
		array('later', 'c.txt', 'dropped too', 'text/plain'), array('text[after][0]', 'still read'),
		array('emptylater', '', '', 'application/octet-stream'),
	),
);

// Posted with max_file_uploads = 2, to both sides.
$limitCase = array(
	array('e1', '', '', 'application/octet-stream'),
	array('l[]', 'a.txt', 'one', 'text/plain'), array('e2', '', '', 'application/octet-stream'),
	array('l[]', 'b.txt', 'two', 'text/plain'), array('l[]', 'c.txt', 'three', 'text/plain'),
	array('e3', '', '', 'application/octet-stream'), array('after[k]', 'text'),
);

// ── Building a body ───────────────────────────────────

function multipartBody(array $fields, $boundary)
{
	$body = '';
	foreach ($fields as $f) {
		$body .= "--$boundary\r\n";
		if (count($f) === 2) {
			$body .= 'Content-Disposition: form-data; name="' . $f[0] . "\"\r\n\r\n" . $f[1] . "\r\n";
		} else {
			$body .= 'Content-Disposition: form-data; name="' . $f[0] . '"; filename="' . $f[1] . "\"\r\n"
				. 'Content-Type: ' . $f[3] . "\r\n\r\n" . $f[2] . "\r\n";
		}
	}
	return $body . "--$boundary--\r\n";
}

function withContents(array $files)
{
	$read = function ($tmp) use (&$read) {
		if (is_array($tmp)) return array_map($read, $tmp);
		return $tmp === '' ? '' : 'contents:' . file_get_contents($tmp);
	};
	foreach ($files as $field => $entry) {
		if (is_array($entry) && array_key_exists('tmp_name', $entry)) {
			$files[$field]['tmp_name'] = $read($entry['tmp_name']);
		}
	}
	return $files;
}

function viaServer($contentType, $body)
{
	$post = array();
	$files = array();
	Q_WebServer::parseMultipart($contentType, $body, $post, $files);
	$result = array('post' => $post, 'files' => withContents($files));
	foreach (Q_WebServer::$uploadTempFiles as $tmp) @unlink($tmp);
	Q_WebServer::$uploadTempFiles = array();
	return $result;
}

function viaCompat($contentType, $body)
{
	$_POST = $_FILES = array();
	Q_WebServer_Compat::parseMultipart($body, $contentType);
	$result = array('post' => $_POST, 'files' => withContents($_FILES));
	foreach ($_FILES as $entry) {
		$tmps = (array) $entry['tmp_name'];
		array_walk_recursive($tmps, function ($tmp) { if ($tmp !== '') @unlink($tmp); });
	}
	$_POST = $_FILES = array();
	return $result;
}

// ── PHP's own parser, under php -S ───────────────────

function startReference($maxFiles = null)
{
	$sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
	if (!$sock) return null;
	$port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
	fclose($sock);
	$cmd = array(PHP_BINARY, '-n',
		'-d', 'max_input_nesting_level=' . ini_get('max_input_nesting_level'),
		'-d', 'max_input_vars=' . ini_get('max_input_vars'),
		'-d', 'max_file_uploads=' . ($maxFiles ?? ini_get('max_file_uploads')),
		'-d', 'upload_max_filesize=1G', '-d', 'post_max_size=1G', '-d', 'display_errors=0',
		'-S', "127.0.0.1:$port", __DIR__ . '/fixtures/multipart-echo.php');
	$proc = proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('file', '/dev/null', 'w'),
		2 => array('file', '/dev/null', 'w')), $pipes);
	if (!is_resource($proc)) return null;
	for ($i = 0; $i < 100; $i++) {
		$c = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 0.2);
		if ($c) { fclose($c); return array($proc, $port); }
		usleep(50000);
	}
	proc_terminate($proc);
	return null;
}

function viaPhp($port, $contentType, $body)
{
	$c = stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 5);
	if (!$c) return null;
	fwrite($c, "POST / HTTP/1.0\r\nHost: 127.0.0.1\r\nContent-Type: $contentType\r\n"
		. 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
	$response = stream_get_contents($c);
	fclose($c);
	$split = strpos($response, "\r\n\r\n");
	return $split === false ? null : unserialize(substr($response, $split + 4));
}

// ── Literal expectations ─────────────────────────────

$boundary = '----qbixtestboundary7MA4YWxkTrZu0gW';
$ct = 'multipart/form-data; boundary=' . $boundary;

$r = viaServer($ct, multipartBody($cases['nested keys'], $boundary));
check('nested keys build nested arrays', $r['post'], array(
	'Attributes' => array(
		0 => array('id' => '1', 'name' => 'Title'),
		1 => array('id' => '2', 'name' => 'Intro'),
	),
	'a' => array('b' => array('c' => 'x', 'd' => 'y')),
	'plain' => 'p',
));

$r = viaServer($ct, multipartBody($cases['appends'], $boundary));
check('[] appends at every depth', $r['post'], array(
	'tags' => array('x', 'y'),
	'm' => array('k' => array('1', '2'), 0 => array('z' => '3'), 1 => array('z' => '4')),
));

$r = viaServer($ct, multipartBody($cases['spaces and dots in names'], $boundary));
check('spaces and dots in a top-level name become underscores, not inside brackets',
	array_keys($r['post']), array('a_b', 'c_d', 'g_h', 'lead', 'k'));
check('bracketed keys keep their spaces and dots', $r['post']['k'], array('l m' => array('n.o' => '6')));

$r = viaServer($ct, multipartBody($cases['files'], $boundary));
check('a nested file field puts each property after the top-level name',
	$r['files']['h']['tmp_name'], array('a' => array('b' => "contents:bytes\0\xff")));
check('file appends', $r['files']['g']['name'], array('a.txt', 'b.txt'));
check('a file and a text field under one array', array($r['files']['Attr']['name'], $r['post']['Attr']),
	array(array(3 => array('img' => 'pic.png')), array(3 => array('id' => '3'))));
check('error and size stay integers', array(is_int($r['files']['f']['error']), is_int($r['files']['f']['size'])),
	array(true, true));

$r = viaServer($ct, multipartBody($cases['empty file fields and file names with a path'], $boundary));
check('an empty file field reports UPLOAD_ERR_NO_FILE', array($r['files']['empty']['error'],
	$r['files']['empty']['tmp_name'], $r['files']['empty']['size']), array(UPLOAD_ERR_NO_FILE, '', 0));
check('the file name loses the path the client sent', $r['files']['path']['name'], 'pic.jpg');

$r = viaServer($ct, multipartBody($cases['file fields with malformed brackets'], $boundary));
check('a file field with malformed brackets leaves out every file from there on, as in PHP',
	array_keys($r['files']), array());

$urlencoded = array();
parse_str('Attributes%5B0%5D%5Bid%5D=1&Attributes%5B0%5D%5Bname%5D=Title&Attributes%5B1%5D%5Bid%5D=2'
	. '&Attributes%5B1%5D%5Bname%5D=Intro&a%5Bb%5D%5Bc%5D=x&a%5Bb%5D%5Bd%5D=y&plain=p', $urlencoded);
check('multipart and urlencoded give the same $_POST',
	viaServer($ct, multipartBody($cases['nested keys'], $boundary))['post'], $urlencoded);

// ── Against PHP itself ───────────────────────────────

$ref = startReference();
if (!$ref) {
	printf("  skip  php -S could not be started; compared against literal expectations only\n");
} else {
	list($proc, $port) = $ref;
	foreach ($cases as $label => $fields) {
		$body = multipartBody($fields, $boundary);
		$php = viaPhp($port, $ct, $body);
		if ($php === null) { check("$label: php -S answered", false, true); continue; }
		check("$label: Q_WebServer::parseMultipart matches PHP", viaServer($ct, $body), $php);
		check("$label: Q_WebServer_Compat::parseMultipart matches PHP", viaCompat($ct, $body), $php);
	}
	// A quoted boundary, as some clients send it.
	$body = multipartBody($cases['nested keys'], $boundary);
	$quoted = 'multipart/form-data; boundary="' . $boundary . '"';
	check('quoted boundary matches PHP', viaServer($quoted, $body), viaPhp($port, $quoted, $body));
	proc_terminate($proc);
	proc_close($proc);

	$ref = startReference(2);
	if ($ref) {
		list($proc, $port) = $ref;
		$body = multipartBody($limitCase, $boundary);
		$php = viaPhp($port, $ct, $body);
		$saved = Q_WebServer::$maxFileUploads;
		Q_WebServer::$maxFileUploads = 2;
		Q_Config::$ini['max_file_uploads'] = 2;
		check('max_file_uploads: Q_WebServer::parseMultipart matches PHP', viaServer($ct, $body), $php);
		check('max_file_uploads: Q_WebServer_Compat::parseMultipart matches PHP', viaCompat($ct, $body), $php);
		Q_WebServer::$maxFileUploads = $saved;
		Q_Config::$ini = array();
		proc_terminate($proc);
		proc_close($proc);
	} else {
		check('php -S with max_file_uploads = 2 started', false, true);
	}
}

printf("  %d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
