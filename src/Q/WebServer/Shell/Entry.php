<?php
/**
 * How to start the shell's runner and the console tools, wherever the server
 * came from: a source checkout, the phar, or a single static binary.
 *
 * From source, bin/qshell.php and sbin/qbixconsole.php are scripts of the
 * engine's tree (docs/layout.md, "Programs") and run as scripts. In the phar
 * (and the deb/rpm packages and the container image, which start the phar)
 * they are embedded, and a script inside a phar cannot be handed to PHP by
 * path: the phar itself is started with --qshell or --qconsole, and
 * sbin/qbixserver.php hands over to the embedded file. A static binary is the
 * interpreter and the phar in one file, so it is started directly with the
 * same switch.
 *
 * A tree laid out the way it was before bin/ and sbin/ (qshell.php and
 * qbixconsole.php at the top) is still found, so a runner is never missing
 * because of where its files are.
 *
 * Kept free of other classes: qshell.php loads it before anything else.
 *
 * @class Q_WebServer_Shell_Entry
 * @static
 */
class Q_WebServer_Shell_Entry
{
	/** The shell's runner, relative to the engine's directory: this layout's, then the former one. */
	static $shellFiles = array('bin/qshell.php', 'qshell.php');

	/** The console tools, relative to the engine's directory: this layout's, then the former one. */
	static $consoleFiles = array('sbin/qbixconsole.php', 'qbixconsole.php');

	/**
	 * The directory the engine's files are read from: a real directory from
	 * source, phar://<file> in a phar. Where the shell's runner is found
	 * (bin/qshell.php, or qshell.php in a tree of the former layout), or null.
	 * @method dir
	 * @static
	 * @param {string|null} $near a server script to look beside first: the
	 *   engine's directory is the one it is in, or the one above when it is in
	 *   sbin/ or bin/
	 * @return {string|null}
	 */
	static function dir($near = null)
	{
		$dirs = array();
		if (is_string($near) && $near !== '') {
			$dirs[] = dirname($near);
			$dirs[] = dirname($near, 2);
		}
		$dirs[] = dirname(__DIR__, 4);
		foreach ($dirs as $d) {
			if (self::find($d, self::$shellFiles) !== null) return $d;
		}
		return null;
	}

	/**
	 * The engine's directory for a directory that may be the engine's own, or
	 * its sbin/ or bin/: the directory itself, or the one above.
	 * @method root
	 * @static
	 * @param {string} $dir
	 * @return {string}
	 */
	static function root($dir)
	{
		$dir = rtrim((string) $dir, '/');
		if (in_array(basename($dir), array('sbin', 'bin'), true) && is_file(dirname($dir) . '/src/Q.php')) return dirname($dir);
		return $dir;
	}

	/** The first of $files that is in $dir, as a path, or null. */
	static function find($dir, array $files)
	{
		foreach ($files as $f) {
			if (is_file($dir . '/' . $f)) return $dir . '/' . $f;
		}
		return null;
	}

	/**
	 * The argv that starts the runner (bin/qshell.php), without its own
	 * options; null when this installation has none.
	 * @method shell
	 * @static
	 * @param {string|null} $near see dir()
	 * @return {array|null}
	 */
	static function shell($near = null)
	{
		$dir = self::dir($near);
		if ($dir === null) return null;
		$packed = self::packed($dir);
		return $packed !== null ? array_merge($packed, array('--qshell')) : array(PHP_BINARY, self::find($dir, self::$shellFiles));
	}

	/**
	 * The argv that starts the console tools (sbin/qbixconsole.php), without
	 * the command. From source PHP's messages are sent to standard error with
	 * -d; packed, the entry point sets the same (a static binary takes no -d).
	 * @method console
	 * @static
	 * @param {string|null} $dir the engine's directory (see dir()), or its sbin/ or bin/
	 * @return {array|null}
	 */
	static function console($dir = null)
	{
		if ($dir === null || $dir === '') $dir = self::dir();
		if ($dir === null) return null;
		$dir = self::root($dir);
		$packed = self::packed($dir);
		if ($packed !== null) return array_merge($packed, array('--qconsole'));
		$file = self::find($dir, self::$consoleFiles);
		if ($file === null) return null;
		return array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', $file);
	}

	/**
	 * For a directory inside a phar: the argv that starts that phar -- the
	 * binary alone when the phar is the executable itself (a static binary),
	 * otherwise PHP and the phar. Null for a real directory.
	 * @method packed
	 * @static
	 * @param {string} $dir
	 * @param {string} [$binary] PHP_BINARY, for tests
	 * @param {string} [$sapi] PHP_SAPI, for tests
	 * @return {array|null}
	 */
	static function packed($dir, $binary = PHP_BINARY, $sapi = PHP_SAPI)
	{
		if (strncmp((string) $dir, 'phar://', 7) !== 0) return null;
		$file = substr((string) $dir, 7);
		// phar://<file>/<inside>: the phar is the longest prefix that is a file.
		while ($file !== '' && !is_file($file)) {
			$up = dirname($file);
			if ($up === $file) break;
			$file = $up;
		}
		if (!is_file($file)) return null;
		// A static (micro) binary has no PHP_BINARY: it is the empty string
		// there, and the phar is the executable itself.
		if ($sapi === 'micro' || (string) $binary === '') return array($file);
		$self = realpath($binary);
		if ($self !== false && $self === realpath($file)) return array($binary);
		return array($binary, $file);
	}
}
