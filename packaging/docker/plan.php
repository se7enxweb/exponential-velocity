#!/usr/bin/env php
<?php
/**
 * What the image has to install for a variant, on the PHP running this
 * (the image's own): one line per action, for install-extensions.sh.
 *
 *   php packaging/docker/plan.php <variant> <with-addons 0|1>
 *
 *   DEP <debian package>                 build-time package
 *   EXT <name> <tier> src|pecl [configure...]
 *   AFTER <name> <extension...>          load <name> after these (its ini sorts last)
 *   ADDON <name> <how>                   add-on driver (oracle, firebird, odbc-drivers)
 *   HAVE <name>                          already in this PHP
 *   SKIP <name> <reason>                 left out here, with the baseline's reason
 *
 * Which extensions: the baseline (build/extensions.json) for this variant, on
 * Linux, for a dynamic PHP -- so a driver a static binary cannot link
 * (odbc, say) is in. How: an extension php-src carries is compiled from the
 * image's own sources; anything else comes from PECL.
 */
require dirname(__DIR__) . '/ci/baseline.php';

$variant = $argv[1] ?? 'standard';
$addons = ($argv[2] ?? '1') === '1';
$php = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
$platform = php_uname('m') === 'aarch64' ? 'linux-aarch64' : 'linux-x86_64';
$deps = json_decode(file_get_contents(__DIR__ . '/debian-build-deps.json'), true);
$m = Q_WebServer_Extensions::manifest();
$srcDir = '/usr/src/php/ext';

$r = baseline_resolve($variant, $platform, $php, false);
$need = array();
foreach ($r['exclude'] as $name => $why) {
	echo "SKIP $name ", preg_replace('/\s+/', ' ', $why), "\n";
}
foreach ($r['include'] as $name) {
	if (Q_WebServer_Extensions::loaded($name)) { echo "HAVE $name\n"; continue; }
	$e = $m['extensions'][$name];
	$how = is_dir("$srcDir/$name") ? 'src' : 'pecl';
	foreach (array_merge($e['libs'] ?? array(), $m['withLibs'][$name] ?? array()) as $lib) {
		foreach ($deps['libs'][$lib] ?? array() as $p) $need[$p] = true;
	}
	echo "EXT $name {$e['tier']} $how", isset($deps['configure'][$name]) ? ' ' . $deps['configure'][$name] : '', "\n";
	if (isset($deps['loadAfter'][$name])) echo "AFTER $name ", implode(' ', $deps['loadAfter'][$name]), "\n";
}
if ($addons) {
	foreach (array_keys(baseline_addons()) as $name) {
		if (in_array($name, array('oci8', 'pdo_oci'), true)) {
			$how = is_dir("$srcDir/$name") ? 'src' : 'pecl';
			echo "ADDON $name oracle-$how\n";
		} elseif ($name === 'pdo_firebird') {
			foreach ($deps['libs']['firebird'] as $p) $need[$p] = true;
			echo "ADDON $name src\n";
		} else {
			echo "ADDON $name unknown\n";
		}
	}
	echo "ADDON odbc-drivers ", implode(',', $deps['odbcDrivers']['packages']), "\n";
}
foreach (array_keys($need) as $p) echo "DEP $p\n";
