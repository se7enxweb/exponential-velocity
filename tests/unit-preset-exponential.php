<?php
/**
 * The exponential preset configures what Exponential needs to run under the
 * server, in one setting, so nobody has to rediscover it.
 *
 * Two of its settings were found the hard way: the source-code transform must
 * stay on (the kernel's header()/setcookie() calls reach the response through
 * it), and the type registries must be kept across requests (cleared, they
 * empty for the worker's life and fail publishing). The preset carries both,
 * and this holds them in place.
 *
 *   php tests/unit-preset-exponential.php
 */
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require __DIR__ . '/../src/Q/WebServer/Compat.php';

$pass = 0; $fail = 0;
function check($what, $got, $want) {
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what,
		var_export($got, true), var_export($want, true));
}

$ok = Q_WebServer_Compat::loadPreset('exponential');
check('the preset loads', $ok, true);

check('the front controller is index.php',
	Q_Config::get('Q', 'compat', 'rewrite', null), 'index.php');
check('the source-code transform is left ON (skip = false)',
	Q_Config::get('Q', 'compat', 'skipSourceCodeTransform', 'unset'), false);
check('compat is enabled',
	Q_Config::get('Q', 'compat', 'enabled', null), true);

// The registries -- the trap. They must land under Q.webserver, not Q.compat.
$keep = Q_Config::get('Q', 'webserver', 'keepGlobals', null);
check('the kept globals reached Q.webserver as an array', is_array($keep), true);
check('...and include the datatype registry',
	in_array('eZDataTypes', (array) $keep, true), true);
check('...and the notification registry that publishing needs',
	in_array('eZNotificationEventTypes', (array) $keep, true), true);
check('the private _webserver key did not leak into Q.compat',
	Q_Config::get('Q', 'compat', '_webserver', 'absent'), 'absent');

// The access lists: with nothing configured, the preset serves only what the
// application's .htaccess_root serves and runs only its front controllers.
$static = Q_Config::get('Q', 'web', 'static', 'paths', null);
check('the static paths reached Q.web.static.paths as a list', is_array($static) && count($static) === 1, true);
check('the private _unlessSet key did not leak into Q.compat',
	Q_Config::get('Q', 'compat', '_unlessSet', 'absent'), 'absent');
check('only the front controllers run',
	Q_Config::get('Q', 'webserver', 'scripts', null),
	array('/index.php', '/index_rest.php', '/index_treemenu.php'));
check('/api/ goes to index_rest.php',
	Q_Config::get('Q', 'webserver', 'frontControllers', array())['^/(api/|index_rest\.php)'] ?? null,
	'index_rest.php');
$served = function ($path) use ($static) { return preg_match('~' . $static[0] . '~', $path) === 1; };
foreach (array('/design/standard/stylesheets/core.css', '/var/site/storage/images/a/b.jpg',
		'/var/site/storage/original/image/logo.svg', '/var/site/cache/public/javascript/x.js',
		'/extension/x/design/standard/vendor/ace/ace.js', '/var/storage/packages/7x/a/thumbnail.png',
		'/share/icons/crystal/a.png', '/favicon.ico', '/robots.txt', '/sw.js') as $path) {
	check("served as a file: $path", $served($path), true);
}
foreach (array('/var/tmp/notes.txt', '/var/tmp/x.css', '/var/log/error.log', '/var/cache/ini/x.php',
		'/var/site/cache/template/compiled/x.php', '/var/storage/sqlite3/sqlite.db',
		'/var/storage/packages/7x/a/package.xml', '/var/storage/packages/7x/a/preview.svg',
		'/var/site/storage/original/application/contract.pdf', '/var/site/storage/original/image/x.php',
		'/settings/site.ini', '/extension/x/settings/x.ini', '/composer.json', '/README.md',
		'/sw.js.bak', '/share/filelist.md5') as $path) {
	check("not served as a file: $path", $served($path), false);
}

// Lists the configuration names win: the preset never widens or replaces them.
Q_Config::set('Q', 'web', 'static', 'paths', array('^/only/'));
Q_Config::set('Q', 'webserver', 'scripts', array('/app.php'));
Q_WebServer_Compat::loadPreset('exponential');
check('configured static paths are kept', Q_Config::get('Q', 'web', 'static', 'paths', null), array('^/only/'));
check('configured scripts are kept', Q_Config::get('Q', 'webserver', 'scripts', null), array('/app.php'));

// An unknown preset is refused, not silently ignored.
check('an unknown preset returns false',
	Q_WebServer_Compat::loadPreset('nonesuch'), false);

// The preset named in a site file (Q.compat.preset) is applied too, not only
// --preset: an Exponential site started from its site file -- the Windows
// service does that -- otherwise ran without the kept registries and its
// pages died on eZDataType::create() returning null.
$src = file_get_contents(__DIR__ . '/../sbin/qbixserver.php');
$cfgAt = strpos($src, "Q_Config::get('Q', 'compat', 'preset', null)");
check('qbixserver.php applies Q.compat.preset from the configuration, before loading the preset',
	$cfgAt !== false and $cfgAt < strpos($src, "Q_WebServer_Compat::loadPreset(\$opts['preset'])"), true);

if ($fail) { printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
