#!/usr/bin/env php
<?php
/**
 * Build qbixserver.phar — single-file distributable.
 *
 * Usage: php -d phar.readonly=0 build-phar.php
 * Output: sbin/qbixserver.phar, and the same file at its former path,
 *         bin/qbixserver.phar (docs/layout.md, "Programs")
 *
 * Inside, the archive has the tree's own layout: sbin/qbixserver.php (which
 * the stub runs), sbin/qbixctl.php and sbin/qbixconsole.php, bin/qshell.php,
 * and the forwarders at the former paths (qbixserver.php, qbixctl.php,
 * qbixconsole.php, qshell.php), so phar://.../qbixserver.php still runs the
 * server for anything that names it.
 */


// --version, --about, --copyright and the rest: GNU-style, before anything else.
require_once __DIR__ . '/src/Q/WebServer/About.php';
Q_WebServer_About::handle(array_slice($argv, 1), basename($argv[0] ?? 'build-phar'), 'builds sbin/qbixserver.phar, the server as one archive, from the sources', __DIR__);
if (ini_get('phar.readonly')) {
	echo "Error: phar.readonly is enabled.\n";
	echo "Run with: php -d phar.readonly=0 build-phar.php\n";
	exit(1);
}

$pharFile = __DIR__ . '/sbin/qbixserver.phar';
// The former path: the same bytes, written after the build (below). A copy,
// not a link, so it works wherever the tree was unpacked -- a Composer zip
// extracted without symbolic links, a checkout on a file system without them.
$pharCopy = __DIR__ . '/bin/qbixserver.phar';
if (file_exists($pharFile)) {
	unlink($pharFile);
}

@mkdir(__DIR__ . '/sbin', 0755, true);
@mkdir(__DIR__ . '/bin', 0755, true);

echo "Building qbixserver.phar...\n";

$phar = new Phar($pharFile, 0, 'qbixserver.phar');
$phar->startBuffering();

// Add src/ tree WITH the src/ prefix so __DIR__.'/src/Q.php' works
$baseDir = __DIR__;
$srcDir = __DIR__ . '/src';
$it = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS)
);
foreach ($it as $file) {
	$rel = substr($file->getPathname(), strlen($baseDir) + 1); // e.g. src/Q.php
	$phar->addFile($file->getPathname(), $rel);
}

// The programs, where the tree has them: the server (which the stub runs) and
// the helpers it starts from the phar with --qshell / --qconsole -- the
// shell's runner and the console tools (and qbixctl, which loads them) --
// then the forwarders at the former paths.
foreach (build_phar_programs() as $__f) {
	$phar->addFile(__DIR__ . '/' . $__f, $__f);
}
// The PHP extension manifest: Q_WebServer_Extensions reads it from build/ beside src/.
foreach (array('build/extensions.json', 'build/extensions.schema.json') as $__f) {
	if (is_file(__DIR__ . '/' . $__f)) $phar->addFile(__DIR__ . '/' . $__f, $__f);
}
// Which distribution of the engine this is (see qbixserver.php, --distribution).
if (is_file(__DIR__ . '/DISTRIBUTION')) $phar->addFile(__DIR__ . '/DISTRIBUTION', 'DISTRIBUTION');

// Add web/ directory for self-contained mode
$webDir = __DIR__ . '/web';
if (is_dir($webDir)) {
	$wit = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($webDir, RecursiveDirectoryIterator::SKIP_DOTS)
	);
	foreach ($wit as $file) {
		$rel = substr($file->getPathname(), strlen($baseDir) + 1); // e.g. web/index.php
		$phar->addFile($file->getPathname(), $rel);
	}
}

// Add designs/: the server's own pages (dashboard, panel, docs, listing,
// error) are design files now, read by Q_WebServer_Design at runtime.
$designsDir = __DIR__ . '/designs';
if (is_dir($designsDir)) {
	$dit = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($designsDir, RecursiveDirectoryIterator::SKIP_DOTS)
	);
	foreach ($dit as $file) {
		$rel = substr($file->getPathname(), strlen($baseDir) + 1); // e.g. designs/default/dashboard/page.html
		$phar->addFile($file->getPathname(), $rel);
	}
}

// Add docs/*.md and README.md so the built-in viewer at /Q/docs works from the
// phar (Q_WebServer serves docs from serverDir/docs, which is inside the phar).
if (is_dir(__DIR__ . '/docs')) {
	foreach (glob(__DIR__ . '/docs/*.md') as $doc) {
		if (basename($doc) === 'outreach.md') continue; // internal notes, never shipped
		$phar->addFile($doc, 'docs/' . basename($doc));
	}
}
if (is_file(__DIR__ . '/README.md')) {
	$phar->addFile(__DIR__ . '/README.md', 'README.md');
}

// Stamp the build: the short commit and the date it was built. Inside a phar
// there is no git to ask at runtime, so it is recorded now, at build time, and
// bundled. From source the server falls back to asking git directly.
$sha = @trim((string) @shell_exec('git -C ' . escapeshellarg(__DIR__)
	. ' rev-parse --short HEAD 2>/dev/null'));
$dirtyOut = (string) @shell_exec('git -C ' . escapeshellarg(__DIR__)
	. ' status --porcelain 2>/dev/null');
$dirty = '';
foreach (explode("\n", $dirtyOut) as $__l) {
	$__l = trim($__l);
	if ($__l === '') continue;
	// The build outputs change every build by design; they are not
	// uncommitted source work, so they do not make the tree "dirty".
	$__path = preg_replace('/^\S+\s+/', '', $__l);
	if (in_array($__path, array('sbin/qbixserver.phar', 'bin/qbixserver.phar', 'qbix-build.php'), true)) continue;
	$dirty = $__l; break;
}
if ($sha === '') $sha = 'unknown';
if ($dirty !== '') $sha .= '-dirty';
$buildDate = gmdate('Y-m-d H:i:s') . ' UTC';
// Our shipped version -- the fork's release line (v0.0.*), not upstream's.
// The nearest release tag reachable from this commit; empty if none. A
// release commit is built before its tag exists, so describe would name the
// previous release: QBIX_SHIP_VERSION=vX.Y.Z.N names the one being cut.
$shipVer = (string) getenv('QBIX_SHIP_VERSION');
if ($shipVer !== '' and !preg_match('/^v0\.0\.\d+(\.\d+)*$/', $shipVer)) {
	fwrite(STDERR, "QBIX_SHIP_VERSION must look like v0.0.4.27, got '$shipVer'\n");
	exit(1);
}
if ($shipVer === '') $shipVer = @trim((string) @shell_exec('git -C ' . escapeshellarg(__DIR__)
	. " describe --tags --abbrev=0 --match 'v0.0.*' 2>/dev/null"));
$buildPhp = "<?php\n"
	. "if (!defined('QBIX_SERVER_BUILD')) define('QBIX_SERVER_BUILD', " . var_export($sha, true) . ");\n"
	. "if (!defined('QBIX_SERVER_BUILD_DATE')) define('QBIX_SERVER_BUILD_DATE', " . var_export($buildDate, true) . ");\n"
	. ($shipVer !== '' ? "if (!defined('QBIX_SHIP_VERSION')) define('QBIX_SHIP_VERSION', " . var_export($shipVer, true) . ");\n" : '');
$phar->addFromString('qbix-build.php', $buildPhp);
// Also on disk at the top of the tree, which sbin/qbixserver.php reads: the
// server is often run as a plain file (a Composer vendor copy), not through
// the phar stub, and then the bundled copy is never reached. Written both
// places, read whichever applies.
file_put_contents(__DIR__ . '/qbix-build.php', $buildPhp);
echo "Stamped build: $sha ($buildDate)\n";

$fileCount = $phar->count();

// Minimal stub
$stub = <<<'STUB'
#!/usr/bin/env php
<?php
Phar::mapPhar('qbixserver.phar');
@include 'phar://qbixserver.phar/qbix-build.php';
require 'phar://qbixserver.phar/sbin/qbixserver.php';
__HALT_COMPILER();
STUB;

$phar->setStub($stub);
$phar->stopBuffering();

chmod($pharFile, 0755);

// The former path, byte for byte: written beside and renamed over, so a
// server running from it never reads half a file.
$__tmp = $pharCopy . '.' . getmypid() . '.tmp';
if (!copy($pharFile, $__tmp) || !chmod($__tmp, 0755) || !rename($__tmp, $pharCopy)) {
	@unlink($__tmp);
	fwrite(STDERR, "could not write $pharCopy\n");
	exit(1);
}

$size = filesize($pharFile);
echo "Built: sbin/qbixserver.phar (" . round($size / 1024) . " KB, $fileCount files), and the same file at bin/qbixserver.phar\n";
echo "Run:   php sbin/qbixserver.phar --root=./web --port=8080\n";

/**
 * The programs the archive carries, relative to the tree: in sbin/ and bin/,
 * then the forwarders at their former paths (docs/layout.md, "Programs").
 * tests/phar-is-current.php makes the same selection.
 */
function build_phar_programs()
{
	return array('sbin/qbixserver.php', 'sbin/qbixctl.php', 'sbin/qbixconsole.php', 'bin/qshell.php',
		'qbixserver.php', 'qbixctl.php', 'qbixconsole.php', 'qshell.php');
}
