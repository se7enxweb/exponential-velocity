<?php

/**
 * Streams layered on a file opened through the compat file wrapper work as
 * they do on the plain file layer: compression, archives, filters, locks,
 * truncation, metadata and stream options.
 *
 * With the wrapper registered for file://, every fopen() of a local path
 * goes through it, and PHP asks the wrapper for anything below the stream
 * API. Two entry points were missing or empty:
 *
 *   - stream_cast(): "compress.zlib://<path>" needs a real descriptor under
 *     it. Without one gzopen(), copy() into or out of a .gz and every
 *     .tar.gz archive -- an Exponential package is one -- failed with "can
 *     not be opened for reading", while PHP-FPM read them fine.
 *   - stream_set_option(): always false, so stream_set_write_buffer()
 *     returned -1, and stream_set_blocking() / stream_set_timeout() false,
 *     on every file.
 *
 * Checked, each against the same call with the wrapper out of the way:
 * gzopen/gzread/gzwrite, copy() both ways through compress.zlib://,
 * readgzfile() and gzfile(), a .tar.gz built and read with PharData (an
 * .ezpkg in shape), stream_filter_append() for reading and writing, flock()
 * including file_put_contents(LOCK_EX), ftruncate(), touch/chmod through
 * stream_metadata, the three stream options, and stream_select() on a file.
 *
 *   php tests/unit-compat-wrapper-streams.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q/WebServer/Compat.php';

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

if (!extension_loaded('zlib')) {
	echo "  skip  needs zlib\n";
	exit(0);
}

$base = sys_get_temp_dir() . DS . 'qbix-wrapstreams-' . getmypid();
@mkdir($base, 0700, true);
register_shutdown_function(function () use ($base) {
	@stream_wrapper_restore('file');
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,
		FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
		$f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
	}
	@rmdir($base);
});

$text = str_repeat("line of package data 0123456789\n", 4000);
$plain = $base . DS . 'plain.txt';
file_put_contents($plain, $text);

function wrapped($on)
{
	if ($on) {
		stream_wrapper_unregister('file');
		stream_wrapper_register('file', 'Q_WebServer_CompatFileWrapper');
	} else {
		@stream_wrapper_restore('file');
	}
}

// Everything below runs once natively and once wrapped; both must agree.
function scenario($base, $plain, $text, $tag)
{
	$r = array();
	$gz = $base . DS . "$tag.gz";

	// gzopen / gzwrite / gzread
	$h = @gzopen($gz, 'wb9');
	$r['gzopen for writing'] = $h !== false;
	if ($h) { gzwrite($h, $text); gzclose($h); }
	$h = @gzopen($gz, 'rb');
	$r['gzopen for reading'] = $h !== false;
	$got = '';
	if ($h) { while (!gzeof($h)) $got .= gzread($h, 8192); gzclose($h); }
	$r['gzread returns what was written'] = $got === $text;

	// copy() through compress.zlib://, both ways
	$gz2 = $base . DS . "$tag-copy.gz";
	$back = $base . DS . "$tag-back.txt";
	$r['copy() into compress.zlib://'] = @copy($plain, 'compress.zlib://' . $gz2);
	$r['copy() out of compress.zlib://'] = @copy('compress.zlib://' . $gz2, $back);
	$r['the round trip is byte for byte'] = @file_get_contents($back) === $text;
	$r['file_get_contents(compress.zlib://)'] = @file_get_contents('compress.zlib://' . $gz) === $text;
	ob_start(); $n = @readgzfile($gz); $out = ob_get_clean();
	$r['readgzfile()'] = $n === strlen($text) and $out === $text;
	$r['gzfile() line count'] = count((array) @gzfile($gz));

	// A .tar.gz archive, as an Exponential package is.
	if (class_exists('PharData')) {
		$tar = $base . DS . "$tag-pkg.tar";
		try {
			$p = new PharData($tar);
			$p->addFromString('package.xml', '<package name="t"/>');
			$p->addFromString('files/a.txt', $text);
			$p->compress(Phar::GZ);
			unset($p);
			$tgz = $tar . '.gz';
			$r['PharData builds a .tar.gz'] = is_file($tgz);
			$read = new PharData($tgz);
			$r['PharData reads the .tar.gz'] = (string) file_get_contents($read['files/a.txt']->getPathname()) === $text;
			$dest = $base . DS . "$tag-extract";
			$read->extractTo($dest, null, true);
			$r['PharData extracts it'] = @file_get_contents($dest . DS . 'package.xml') === '<package name="t"/>';
			unset($read);
		} catch (\Throwable $e) {
			$r['PharData'] = get_class($e) . ': ' . $e->getMessage();
		}
		// Read the tar inside the gzip by hand, the way archive libraries do:
		// fopen("compress.zlib://...") and parse 512-byte headers.
		$h = @fopen('compress.zlib://' . $tar . '.gz', 'rb');
		$names = array();
		while ($h and ($hdr = fread($h, 512)) !== false and strlen($hdr) === 512 and trim($hdr, "\0") !== '') {
			$names[] = rtrim(substr($hdr, 0, 100), "\0");
			$size = octdec(trim(substr($hdr, 124, 12), "\0 "));
			if ($size) fread($h, (int) (ceil($size / 512) * 512));
		}
		if ($h) fclose($h);
		sort($names);
		$r['tar entries read through compress.zlib://'] = $names;
	}

	// Stream filters, reading and writing.
	$f = $base . DS . "$tag-filter.txt";
	$h = fopen($f, 'wb');
	$r['stream_filter_append for writing'] = is_resource(stream_filter_append($h, 'string.toupper', STREAM_FILTER_WRITE));
	fwrite($h, 'abc');
	fclose($h);
	$h = fopen($f, 'rb');
	stream_filter_append($h, 'string.rot13', STREAM_FILTER_READ);
	$r['filters applied'] = fread($h, 10);
	fclose($h);
	$h = fopen($f, 'wb');
	stream_filter_append($h, 'zlib.deflate', STREAM_FILTER_WRITE, array('level' => 6));
	fwrite($h, $text);
	fclose($h);
	$r['zlib.deflate write filter'] = gzinflate(file_get_contents($f)) === $text;

	// Locks, truncation, metadata.
	$l = $base . DS . "$tag-lock.txt";
	$r['file_put_contents with LOCK_EX'] = file_put_contents($l, 'locked', LOCK_EX);
	$h = fopen($l, 'r+b');
	$r['flock LOCK_EX'] = flock($h, LOCK_EX);
	$r['flock LOCK_UN'] = flock($h, LOCK_UN);
	$r['ftruncate'] = ftruncate($h, 3);
	fclose($h);
	clearstatcache();
	$r['truncated size'] = filesize($l);
	$r['touch with a time'] = touch($l, 1000000000, 1000000000);
	clearstatcache();
	$r['mtime set'] = filemtime($l);
	$r['chmod'] = chmod($l, 0640);
	clearstatcache();
	$r['mode set'] = fileperms($l) & 0777;

	// Stream options.
	$h = fopen($l, 'r+b');
	$r['stream_set_write_buffer'] = stream_set_write_buffer($h, 0);
	$r['stream_set_blocking'] = stream_set_blocking($h, true);
	$r['stream_set_timeout'] = stream_set_timeout($h, 5);
	if (function_exists('stream_set_read_buffer')) {
		$r['stream_set_read_buffer'] = stream_set_read_buffer($h, 0);
	}
	// stream_select() on a file asks for the descriptor (stream_cast).
	$read = array($h); $w = $e = null;
	try {
		$r['stream_select on a file'] = @stream_select($read, $w, $e, 0);
	} catch (\Throwable $ex) {
		// Without stream_cast() PHP cannot select on the stream at all.
		$r['stream_select on a file'] = get_class($ex);
	}
	fclose($h);
	return $r;
}

wrapped(false);
$native = scenario($base, $plain, $text, 'native');
wrapped(true);
$wrapped = scenario($base, $plain, $text, 'wrapped');
wrapped(false);

// Every scenario must succeed natively; a native failure means the test
// machine lacks something, not that the wrapper is wrong.
foreach ($native as $what => $want) {
	check($what . ' (through the wrapper, as natively)', $wrapped[$what] ?? '(missing)', $want);
}
check('natively, gzread works at all (sanity)', $native['gzread returns what was written'], true);

echo "\n  $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
