#!/usr/bin/env php
<?php
/**
 * Writes one spc recipe per release matrix entry, for the source kit.
 *
 *   php packaging/ci/write-recipes.php <matrix.json> <dir>
 *
 * A recipe is a POSIX shell script that runs on the platform it names, with
 * spc on PATH, from the root of the kit.
 */
list(, $matrixFile, $dir) = $argv + array(null, null, null);
$m = json_decode((string) @file_get_contents($matrixFile), true);
if (!isset($m['include']) || !$m['include']) {
	fwrite(STDERR, "no matrix in $matrixFile\n");
	exit(1);
}
if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
	fwrite(STDERR, "cannot create $dir\n");
	exit(1);
}
foreach ($m['include'] as $b) {
	$exe = $b['platform'] === 'windows-x64' ? '.exe' : '';
	$libs = $b['libs'] !== '' ? ' --with-libs=' . escapeshellarg($b['libs']) : '';
	$forLibs = $b['libs'] !== '' ? ' --for-libs=' . escapeshellarg($b['libs']) : '';
	$out = 'qbixserver-' . $b['name'] . $exe;
	$script = "#!/usr/bin/env sh\n"
		. "# {$b['platform']}, PHP {$b['php']}, variant {$b['variant']} -- generated from the baseline.\n"
		. "# Run from the root of the source kit, on {$b['platform']}, with spc on PATH.\n"
		. "set -e\n"
		. "spc download --with-php=" . escapeshellarg($b['php'])
		. ' --for-extensions=' . escapeshellarg($b['extensions']) . $forLibs . "\n"
		. 'spc build ' . escapeshellarg($b['extensions']) . ' --build-cli --build-micro' . $libs . "\n"
		. "QBIX_STATIC_BUILD=1 PHP=buildroot/bin/php$exe sh packaging/bin/qbix-ext check --variant=" . escapeshellarg($b['variant']) . "\n"
		. "spc micro:combine sbin/qbixserver.phar -O " . escapeshellarg($out) . "\n"
		. "echo \"built $out\"\n";
	$file = $dir . '/' . $b['name'] . '.sh';
	file_put_contents($file, $script);
	chmod($file, 0755);
}
fwrite(STDERR, count($m['include']) . " recipe(s) in $dir\n");
