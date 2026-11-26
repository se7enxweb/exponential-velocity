<?php
/**
 * @module Q
 */
/**
 * What a program of this server is: its name, what it does, its version and
 * build, copyright and license -- answered by every program the same way:
 *
 *   --version  -version  -v  -V  --about  -about  --copyright  -copyright
 *
 * One text for all of them, from one place, so the server, the console, the
 * control tool, the shell and the phar and static binaries built from them
 * never disagree. A distribution of the engine (Q_WebServer_Distribution_*)
 * adds its own name, description and copyright with a static about().
 *
 * Needs nothing else of the engine: it runs before any option parsing, in a
 * program that may not load the rest.
 *
 * @class Q_WebServer_About
 * @static
 */
class Q_WebServer_About
{
	/** The upstream engine this is built on, when QBIX_SERVER_VERSION is not defined. */
	const BASE_VERSION = '1.5.0';

	/** The upstream engine's home page, when no distribution names its own. */
	const HOMEPAGE = 'https://github.com/Qbix/Server';

	/** The options that ask. */
	static $flags = array('--version', '-version', '-v', '-V', '--about', '-about', '--copyright', '-copyright');

	/**
	 * Print the text and exit when an argument asks for it. Arguments after
	 * "--" belong to something else and are not looked at.
	 *
	 * @method handle
	 * @static
	 * @param {array} $args the arguments, without the program name
	 * @param {string} $program the program's name, as it is run
	 * @param {string} $purpose one line: what this program does
	 * @param {string} $root the engine's source tree
	 */
	static function handle(array $args, $program, $purpose, $root)
	{
		foreach ($args as $arg) {
			if ($arg === '--') break;
			if (in_array($arg, self::$flags, true)) {
				echo self::text($program, $purpose, $root);
				exit(0);
			}
		}
	}

	/**
	 * The text: product, program, version, build, PHP, copyright, license.
	 *
	 * @method text
	 * @static
	 * @return {string}
	 */
	static function text($program, $purpose, $root)
	{
		$i = self::info($program, $purpose, $root);
		$row = function ($label, $value) {
			return sprintf("  %-13s %s\n", $label . ':', $value);
		};
		// GNU style: "program (package) version" first, the licence and the
		// warranty disclaimer last; the details a bug report needs between.
		$out = preg_replace('/\.(php|phar)$/', '', $i['program']) . ' (' . $i['short'] . ') ' . $i['version'] . "\n"
			. wordwrap($i['description'], 78) . "\n\n"
			. $row('Program', $i['program'] . ' -- ' . $i['purpose'])
			. $row('Version', $i['version'] . ($i['build'] !== 'source' ? '+' . $i['build'] : ''))
			. $row('Build', $i['build'] . ($i['date'] !== '' ? ', ' . $i['date'] : ''))
			. $row('Running from', $i['from'])
			. $row('Engine', $i['engine'])
			. $row('Distribution', $i['distribution'])
			. $row('PHP', PHP_VERSION . ' (' . PHP_SAPI . ', ' . (PHP_ZTS ? 'thread-safe' : 'non-thread-safe') . ', ' . (PHP_INT_SIZE * 8) . '-bit)')
			. $row('System', php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m'))
			. $row('Extensions', $i['extensions'])
			. $row('Home page', $i['homepage'])
			. "\n";
		foreach ($i['copyright'] as $line) $out .= $line . "\n";
		$out .= "License MIT: <https://opensource.org/license/mit>; the full text is in\n"
			. "the LICENSE file that comes with the program.\n"
			. "This is free software: you are free to change and redistribute it.\n"
			. "There is NO WARRANTY, to the extent permitted by law.\n\n"
			. 'Written by ' . $i['authors'] . ".\n";
		return $out;
	}

	/**
	 * The same, as data.
	 *
	 * @method info
	 * @static
	 * @return {array}
	 */
	static function info($program, $purpose, $root)
	{
		list($version, $build, $date) = self::version($root);
		$base = defined('QBIX_SERVER_VERSION') ? QBIX_SERVER_VERSION : self::BASE_VERSION;
		$info = array(
			'product'     => 'Qbix Server',
			'short'       => 'Qbix Server',
			'description' => 'A web server and application server written in PHP: static files, PHP applications in persistent workers, HTTPS, HTTP/2 and a response cache, without nginx or php-fpm.',
			'program'     => (string) $program,
			'purpose'     => (string) $purpose,
			'version'     => $version,
			'build'       => $build,
			'date'        => $date,
			'engine'      => 'Qbix Server ' . $base,
			'homepage'    => self::HOMEPAGE,
			'copyright'   => array('Copyright (C) 2024-2026 Qbix, Inc.'),
			'authors'     => 'Qbix, Inc. and contributors',
			'distribution' => 'none (the upstream engine)',
			'from'        => strncmp($root, 'phar://', 7) === 0
				? 'the archive ' . substr($root, 7)
				: 'source files in ' . $root . (file_exists($root . '/.git') ? ' (a git checkout)' : ''),
			'extensions'  => self::extensions(),
		);
		$class = self::distributionClass($root);
		if ($class !== null) {
			$info['distribution'] = defined($class . '::NAME') ? constant($class . '::NAME') : $class;
		}
		if ($class !== null and method_exists($class, 'about')) {
			$extra = (array) $class::about();
			foreach (array('product', 'short', 'description', 'homepage', 'authors') as $k) {
				if (isset($extra[$k])) $info[$k] = (string) $extra[$k];
			}
			if (!empty($extra['copyright'])) {
				$info['copyright'] = array_merge((array) $extra['copyright'], $info['copyright']);
			}
		}
		return $info;
	}

	/**
	 * The home page to point a reader at (--help, the quick start, the welcome
	 * page): the distribution's when one is selected, else the upstream
	 * engine's. Pages that named a repository by hand drifted; this is the one
	 * place it is kept.
	 * @method homepage
	 * @static
	 * @param {string} $root the server's root directory
	 * @return {string}
	 */
	static function homepage($root)
	{
		$class = self::distributionClass($root);
		if ($class !== null and method_exists($class, 'about')) {
			$extra = (array) $class::about();
			if (!empty($extra['homepage'])) return (string) $extra['homepage'];
		}
		return self::HOMEPAGE;
	}

	/** The extensions the server uses, each with whether it is loaded. */
	private static function extensions()
	{
		$list = array();
		foreach (array('sockets', 'pcntl', 'posix', 'openssl', 'zlib', 'apcu', 'Zend OPcache', 'curl', 'mbstring') as $ext) {
			$list[] = ($ext === 'Zend OPcache' ? 'opcache' : $ext) . (extension_loaded($ext) ? '+' : '-');
		}
		return implode(' ', $list) . '  (+ loaded, - missing)';
	}

	/**
	 * Release, build and build date: the constants a phar or the server
	 * defined, else the build stamp beside the sources (only where the tree
	 * is not a checkout of its own), else git, else the upstream base.
	 *
	 * @method version
	 * @static
	 * @return {array} [version, build, date]
	 */
	static function version($root)
	{
		$version = defined('QBIX_SHIP_VERSION') ? (string) QBIX_SHIP_VERSION : '';
		$build = defined('QBIX_SERVER_BUILD') ? (string) QBIX_SERVER_BUILD : '';
		$date = defined('QBIX_SERVER_BUILD_DATE') ? (string) QBIX_SERVER_BUILD_DATE : '';
		$ownCheckout = file_exists($root . '/.git');
		// From a checkout the server defines the build date as the time it
		// started; the commit's own date is what says how old the code is.
		if ($ownCheckout and function_exists('shell_exec')) {
			$commit = trim((string) @shell_exec('TZ=UTC git -C ' . escapeshellarg($root) . " log -1 --format=%cd --date=format-local:'%Y-%m-%d %H:%M:%S' 2>/dev/null"));
			if ($commit !== '') $date = $commit . ' UTC (commit)';
		}
		if (($version === '' or $build === '') and !$ownCheckout and is_file($root . '/qbix-build.php')) {
			$stamp = (string) @file_get_contents($root . '/qbix-build.php');
			if ($build === '' and preg_match("/'QBIX_SERVER_BUILD', '([^']*)'/", $stamp, $m)) $build = $m[1];
			if ($date === '' and preg_match("/'QBIX_SERVER_BUILD_DATE', '([^']*)'/", $stamp, $m)) $date = $m[1];
			if ($version === '' and preg_match("/'QBIX_SHIP_VERSION', '([^']*)'/", $stamp, $m)) $version = $m[1];
		}
		if (($version === '' or $build === '') and $ownCheckout and function_exists('shell_exec')) {
			$git = 'git -C ' . escapeshellarg($root);
			if ($version === '') {
				$version = trim((string) @shell_exec($git . " describe --tags --abbrev=0 --match 'v0.0.*' 2>/dev/null"));
			}
			if ($build === '') {
				$build = trim((string) @shell_exec($git . ' rev-parse --short HEAD 2>/dev/null'));
			}
		}
		if ($version === '') {
			$version = 'v' . (defined('QBIX_SERVER_VERSION') ? QBIX_SERVER_VERSION : self::BASE_VERSION);
		}
		return array($version, $build !== '' ? $build : 'source', $date);
	}

	/**
	 * The distribution's class, loaded but not registered: named by
	 * --distribution=, QBIX_DISTRIBUTION or the DISTRIBUTION file.
	 */
	private static function distributionClass($root)
	{
		$name = getenv('QBIX_DISTRIBUTION') ?: null;
		foreach ((array) ($GLOBALS['argv'] ?? array()) as $arg) {
			if (strncmp((string) $arg, '--distribution=', 15) === 0) $name = substr($arg, 15);
		}
		if ($name === null and is_file($root . '/DISTRIBUTION')) {
			$name = trim((string) file_get_contents($root . '/DISTRIBUTION'));
		}
		if (!is_string($name) or !preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $name)) return null;
		$class = 'Q_WebServer_Distribution_' . ucfirst(strtolower($name));
		$file = $root . '/src/Q/WebServer/Distribution/' . ucfirst(strtolower($name)) . '.php';
		if (!class_exists($class, false) and is_file($file)) require_once $file;
		return class_exists($class, false) ? $class : null;
	}
}
