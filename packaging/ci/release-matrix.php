#!/usr/bin/env php
<?php
/**
 * The release build matrix, computed rather than written out: one job per
 * platform x PHP version x variant, each carrying the extension and library
 * lists the baseline gives for exactly that build. The platforms, PHP
 * versions and variants come from the manifest too; this file only knows
 * which runner builds which platform. Printed as a GitHub Actions output:
 *
 *   matrix={"include":[{...}, ...]}
 *
 *   php packaging/ci/release-matrix.php [platforms] [php versions] [variants]
 *
 * Each argument is a comma list; empty means all. The source variant is not
 * a binary and is never a matrix entry (the source-kit job builds it).
 * Unknown names are an error, so a typo in a manual run fails in seconds.
 */
require __DIR__ . '/baseline.php';

// Which runner builds which platform, and how spc names it.
$runners = array(
	'linux-x86_64'  => array('ubuntu-latest', 'linux', 'x86_64', false),
	'linux-aarch64' => array('ubuntu-24.04-arm', 'linux', 'aarch64', false),
	'macos-arm64'   => array('macos-latest', 'macos', 'aarch64', false),
	// Pinned, not windows-latest: that label moved to an image with Visual
	// Studio 2026, which spc does not look for. Experimental: a Windows
	// failure never blocks the other platforms. docs/binaries.md, "The
	// Windows Build", has both stories.
	'windows-x64'   => array('windows-2022', 'windows', 'x64', true),
);

// Sources static-php-cli must not take at their newest, keyed by the spc
// extension that pulls them in (each has a source of the same name). A build
// whose extension list carries the extension downloads the archive named here
// instead (`spc download --custom-url`); every other build is unaffected.
// Each entry says why, and what would let it go.
$pinnedSources = array(
	// yac 3.0 (PECL, 2026-10-19) removed serializer/igbinary.c and
	// serializer/json.c and moved from fastlz to a bundled lz4. static-php-cli
	// still builds yac the 2.x way -- it patches both files and configures
	// --with-system-fastlz -- so every build with yac stopped with "Reading
	// file .../ext/yac/serializer/igbinary.c failed" (linux-x86_64 full on
	// PHP 8.2 and 8.3; 8.4 and 8.5 stopped earlier, on imap). 2.4.2 is the
	// last release that build knows. Drop this once static-php-cli builds
	// yac 3.
	'yac' => 'https://pecl.php.net/get/yac-2.4.2.tgz',
);

function pick($arg, array $all, $what)
{
	$arg = trim((string) $arg);
	if ($arg === '') return $all;
	$want = array_values(array_filter(array_map('trim', explode(',', $arg)), 'strlen'));
	$bad = array_diff($want, $all);
	if ($bad) {
		fwrite(STDERR, "unknown $what: " . implode(', ', $bad) . ' (known: ' . implode(', ', $all) . ")\n");
		exit(2);
	}
	return $want;
}

$platforms = baseline_platforms();
if ($missing = array_diff($platforms, array_keys($runners))) {
	fwrite(STDERR, 'no runner for platform(s) ' . implode(', ', $missing) . "; add them to \$runners\n");
	exit(1);
}
$binaryVariants = array_values(array_filter(baseline_variants(), function ($v) {
	return empty(Q_WebServer_Extensions::manifest()['variants'][$v]['kit']);
}));

$include = array();
foreach (pick($argv[1] ?? '', $platforms, 'platform') as $p) {
	foreach (pick($argv[2] ?? '', baseline_php_versions(), 'PHP version') as $php) {
		foreach (pick($argv[3] ?? '', $binaryVariants, 'variant') as $v) {
			list($runner, $spcOs, $spcArch, $experimental) = $runners[$p];
			$r = baseline_resolve($v, $p, $php);
			if (!$r['spc']) {
				fwrite(STDERR, "empty extension list for $v/$p/$php\n");
				exit(1);
			}
			$exts = implode(',', $r['spc']);
			$libs = implode(',', $r['libs']);
			$pins = array();
			foreach ($pinnedSources as $ext => $url) {
				if (in_array($ext, $r['spc'], true)) $pins[] = "$ext:$url";
			}
			$include[] = array(
				'os' => $runner, 'platform' => $p, 'spc_os' => $spcOs, 'spc_arch' => $spcArch,
				'php' => $php, 'variant' => $v, 'extensions' => $exts, 'libs' => $libs,
				// full is best effort, as the full container image is: it carries
				// every extension static-php-cli can build, and a third-party
				// source that disappears upstream must not hold back a release of
				// the variants people run. It publishes where it builds.
				'experimental' => $experimental || $v === 'full',
				'name' => "$p-php$php-$v",
				// "source:url" pairs, space separated, each passed to
				// `spc download` as --custom-url.
				'source_urls' => implode(' ', $pins),
				// Names this build's set of sources, for the download cache key.
				'libs_key' => substr(sha1($exts . '|' . $libs), 0, 12),
			);
		}
	}
}
fwrite(STDERR, count($include) . " build(s)\n");
echo 'matrix=' . json_encode(array('include' => $include), JSON_UNESCAPED_SLASHES), "\n";
