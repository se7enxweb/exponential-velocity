<?php

/**
 * fread() on a local file returns the length asked for, as it does on the
 * plain file layer, when the file is opened through the compat file wrapper.
 *
 * With the wrapper registered for file://, every fopen() of a local path is a
 * user-space stream, and PHP reads a user-space stream at most one chunk
 * (8192 bytes) per fread(), whatever length was asked for. On the plain file
 * layer fread($fp, 16384) returns 16384 bytes until the end of the file, and
 * applications rely on that: a download loop such as
 *
 *     while (!feof($fp) && $sent < $size) { echo fread($fp, 16384); $sent += 16384; }
 *
 * sent 8192 bytes for every 16384 it counted, stopped when it believed the
 * whole file was out, and delivered about half of it -- a correct prefix,
 * status 200, with a Content-Length computed from what was captured, so
 * neither the client nor a response cache could tell.
 *
 * fread() is now among the replaced functions: on a stream opened through the
 * wrapper it reads on until it has the length asked for or the file ends. Any
 * other stream -- a socket, a pipe -- keeps PHP's own fread() and its short
 * reads, which are what a reader of those streams expects.
 *
 *   php tests/unit-compat-fread-full-length.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q/WebServer/Compat.php';
// Switch the transform on without the server around it.
$en = new ReflectionProperty('Q_WebServer_Compat', 'enabled');
$en->setAccessible(true);
$en->setValue(null, true);

$pass = 0;
$fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; printf("  PASS  %s\n", $what); return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what,
		var_export($got, true), var_export($want, true));
}

$dir = sys_get_temp_dir() . DS . 'qbix-fread-' . getmypid();
@mkdir($dir, 0700, true);
register_shutdown_function(function () use ($dir) {
	@stream_wrapper_restore('file');
	foreach (glob($dir . DS . '*') ?: array() as $f) @unlink($f);
	@rmdir($dir);
});

// Sizes from real downloads that came out at about half: each is a whole
// number of 16384-byte packets plus a remainder below and above 8192.
$sizes = array(312611, 2209564, 16384 * 3, 100, 0);
$files = array();
foreach ($sizes as $size) {
	$f = $dir . DS . "data-$size.bin";
	$bytes = '';
	while (strlen($bytes) < $size) $bytes .= hash('sha256', strlen($bytes) . 'x', true);
	file_put_contents($f, substr($bytes, 0, $size));
	$files[$size] = $f;
}

// The application's download loop, in a file that is included through the
// wrapper -- and so transformed -- as an application's code is.
$loop = $dir . DS . 'download.php';
file_put_contents($loop, <<<'PHP'
<?php
return function ($file, $offset = 0, $length = false) {
	$fp = fopen($file, 'rb');
	$size = filesize($file);
	fseek($fp, $offset);
	$sent = $offset;
	$packet = 16384;
	$end = ($length === false) ? $size - 1 : $length + $offset - 1;
	$out = '';
	while (!feof($fp) && $sent < $end + 1) {
		if ($sent + $packet > $end + 1) $packet = $end + 1 - $sent;
		$out .= fread($fp, $packet);
		$sent += $packet;
	}
	fclose($fp);
	return $out;
};
PHP
);
touch($loop, time() - 120);

stream_wrapper_unregister('file');
stream_wrapper_register('file', 'Q_WebServer_CompatFileWrapper');

$download = include $loop;
foreach ($files as $size => $f) {
	$got = $download($f);
	check("a $size-byte file is read whole by a 16384-byte fread loop",
		array(strlen($got), md5($got)), array($size, md5_file($f)));
}

// A range, as for a request with Range: bytes=1000-.
$f = $files[312611];
$got = $download($f, 1000, 312611 - 1000);
check('a range from an offset to the end is read whole',
	md5($got), md5(substr(file_get_contents($f), 1000)));

// fread() straight from a test is not transformed, which is how the length
// PHP itself gives is seen: one chunk.
$fp = fopen($f, 'rb');
$native = strlen(fread($fp, 16384));
fclose($fp);
check('without the replacement PHP returns one chunk (what made this matter)',
	$native < 16384, true);

$fp = fopen($f, 'rb');
check('the replacement returns the length asked for',
	strlen(Q_WebServer_Compat::_fread($fp, 16384)), 16384);
check('...and ends at the end of the file',
	strlen(Q_WebServer_Compat::_fread($fp, 1 << 20)), 312611 - 16384);
check('...where it returns an empty string, as fread() does',
	Q_WebServer_Compat::_fread($fp, 10), '');
fclose($fp);

// Other streams keep their short reads: a socket with 5 bytes waiting
// returns those 5 rather than blocking for the rest.
$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
fwrite($pair[0], 'hello');
check('a socket returns what is there, without waiting for more',
	Q_WebServer_Compat::_fread($pair[1], 8192), 'hello');
fclose($pair[0]);
fclose($pair[1]);

printf("\n  %d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
