<?php
/**
 * An include never reads the head of one version of a file and the tail of
 * the next.
 *
 * file_get_contents() reads in 8 KB chunks. A file rewritten in place --
 * opened "w", truncated, written again -- between two of those chunks came
 * back as a mixture of two versions: valid PHP, so it ran, and it was answered
 * as a 200. Seen once in tens of thousands of requests in the include race
 * test, as 'V000096:...00009' . '97,...:V000097'. The compat wrapper now reads
 * a file in one read of its whole size and keeps the bytes only if the file
 * did not change while it read (CompatFileWrapper::readWhole()).
 *
 * A forked writer rewrites a 2 MB file in place, as fast as it can, with
 * versions of different lengths, while this process reads it repeatedly both
 * ways. The plain chunked read is counted tearing, to show the race was real
 * (the old read failed this test in most runs), and readWhole() must never.
 *
 *   php tests/unit-compat-read-never-torn.php
 */
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
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

if (!function_exists('pcntl_fork')) { printf("  skip  needs pcntl for the writer\n"); exit(0); }

$path = sys_get_temp_dir() . DS . 'qbix-torn-' . getmypid() . '.php';

/** Version $k: its id at both ends and throughout; lengths differ by version. */
function version($k)
{
	$id = sprintf('%06d', $k);
	return "<?php return 'V$id:" . str_repeat("$id,", 300000 + $k % 7) . ":V$id';\n";
}

/** The version a read names, or null if it is not one whole version. */
function whole($bytes)
{
	if (!preg_match('/^<\?php return .V(\d{6}):/', $bytes, $m)) return null;
	$want = version((int) $m[1]);
	return $bytes === $want ? (int) $m[1] : null;
}

file_put_contents($path, version(1));
$stop = $path . '.stop';

$pid = pcntl_fork();
if ($pid === 0) {
	// The writer: rewrite in place, quickly, until told to stop.
	for ($k = 2; !file_exists($stop); ++$k) {
		$h = fopen($path, 'w');
		fwrite($h, version($k));
		fclose($h);
		// A moment between versions, as a real writer leaves, and now and
		// then a longer one. On a CI runner's ext4 disk, closing a file that
		// was truncated and rewritten starts its writeback, and the next
		// truncate waits for it with the file already at size 0: the file
		// was whole for only the 1 ms between versions, and one run saw no
		// whole version in 4 seconds (0 whole, 0 torn). The pause gives
		// every run whole versions to read; the race stays the same.
		usleep($k % 20 === 0 ? 40000 : 1000);
	}
	exit(0);
}

$readWhole = new ReflectionMethod('Q_WebServer_CompatFileWrapper', 'readWhole');
if (PHP_VERSION_ID < 80100) $readWhole->setAccessible(true);   // PHP 8.0 needs it for a private method
$chunkedTorn = 0; $wholeTorn = 0; $wholeGood = 0; $wholeCut = 0; $example = null;
// At least 4 seconds of the race; longer, up to 30, until a whole version
// has been read, so a slow runner still shows that readWhole() returns one.
$start = microtime(true);
while (($now = microtime(true)) < $start + 4.0 or ($wholeGood < 1 and $now < $start + 30.0)) {
	$b = file_get_contents($path);
	// Empty or cut reads are what an in-place writer leaves; a torn read is
	// one that is the full length of some version yet no version at all.
	if ($b !== '' and whole($b) === null and substr($b, -3) === "';\n") ++$chunkedTorn;

	$b = $readWhole->invoke(null, $path);
	if ($b === false or $b === '') { ++$wholeCut; continue; }
	if (whole($b) !== null) ++$wholeGood;
	elseif (substr($b, -3) === "';\n") { ++$wholeTorn; $example = $example ?? substr($b, 0, 20) . '...' . substr($b, -20); }
	else ++$wholeCut;
}
$took = microtime(true) - $start;
touch($stop);
pcntl_waitpid($pid, $status);
@unlink($stop);
@unlink($path);

printf("  note  %.1f s; chunked reads torn: %d; readWhole: %d whole, %d torn, %d empty or cut\n",
	$took, $chunkedTorn, $wholeGood, $wholeTorn, $wholeCut);
if ($chunkedTorn === 0) {
	printf("  note  no chunked read tore this time, so a pass here is weaker evidence\n");
}
check('a whole-file read never returns two versions joined'
	. ($example !== null ? " (got $example)" : ''), $wholeTorn, 0);
check('...and it does return whole versions while the file is being rewritten', $wholeGood > 0, true);

if ($fail) { printf("  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
