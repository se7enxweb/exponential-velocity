<?php
/**
 * A static-php-cli patch script (`spc build -P <this file>`): spc requires it
 * at every patch point of the build, inside the builder, so it sees spc's
 * constants and classes. It acts at one point only, right after php-src is
 * extracted and before the extensions' sources are.
 *
 * Why: the PHP 8.4.27 and 8.5.12 tarballs (October 2026) carry a stray
 * ext/imap/tests/ directory, although imap left php-src in 8.4. spc extracts
 * the PECL imap source into php-src/ext/imap only when that directory does
 * not exist yet, so it skipped it, and the build stopped at "Reading file
 * .../php-src/ext/imap/config.m4 failed" (every full build on PHP 8.4 and
 * 8.5). An extension directory with no config.m4 is not an extension php-src
 * can build, so it is removed and spc extracts the real one in its place.
 * Where php-src still bundles the extension (imap on 8.2 and 8.3), config.m4
 * is there and nothing is touched. The other extensions that left php-src
 * for PECL are checked the same way, so a stray directory of theirs cannot
 * do this again.
 *
 * Variables are prefixed: the file runs inside spc's own method scope.
 */
if (patch_point() !== 'after-php-extract') {
	return;
}
foreach (array('imap', 'oci8', 'pdo_oci', 'pspell') as $__ext) {
	$__dir = SOURCE_PATH . '/php-src/ext/' . $__ext;
	if (is_dir($__dir) && !file_exists($__dir . '/config.m4')) {
		\SPC\store\FileSystem::removeDir($__dir);
		logger()->info("Removed php-src/ext/{$__ext}: it has no config.m4, so the PECL source goes in its place");
	}
}
