<?php
/**
 * @module Q
 */
/**
 * Where the server's configuration lives on disk: the Debian Apache layout.
 *
 * The configuration directory, /etc/qbix, is organised exactly like
 * /etc/apache2, because that is the structure organisations already run:
 *
 *   /etc/qbix/qbix.conf              base settings        (apache2.conf)
 *   /etc/qbix/ports.conf             listen ports         (ports.conf)
 *   /etc/qbix/envvars                process environment  (envvars)
 *   /etc/qbix/conf-available/*.conf  shared snippets, enabled by a symlink
 *   /etc/qbix/conf-enabled/          in conf-enabled
 *   /etc/qbix/mods-available/*.conf  engine modules (http2, cache, compat...),
 *   /etc/qbix/mods-enabled/          enabled by a symlink in mods-enabled
 *   /etc/qbix/sites-available/*.conf one file per installation, enabled by
 *   /etc/qbix/sites-enabled/         a symlink in sites-enabled
 *   /etc/qbix/designs/               the server's own page designs
 *
 * Other trees laid out the same way can be stacked on top of it as
 * overlays (addOverlay(), stack()): the base is loaded first and each
 * overlay after it, so an overlay changes only what it sets. A distribution
 * of the engine that keeps its own tree registers it that way (see
 * qbixserver.php, --distribution).
 *
 * Files hold JSON objects -- the engine's configuration format -- under
 * Apache's names; envvars holds "export NAME=value" lines, as Apache's does.
 * They are merged in the order apache2.conf includes them, later winning:
 * the base file, ports.conf, mods-enabled, conf-enabled, and then the
 * --config file, which is normally a sites-enabled file.
 *
 * The directory is used only when asked for: --conf-dir, QBIX_CONF_DIR, or
 * a --config file inside a sites-* directory of one. It is never picked up
 * just because it exists -- a machine's /etc/qbix must not
 * change how an unrelated server, or a test suite, behaves. --conf-dir=auto
 * searches the standard places.
 *
 * @class Q_WebServer_Layout
 * @static
 */
class Q_WebServer_Layout
{
	/**
	 * Standard places, in search order.
	 * @property $standardDirs
	 */
	static $standardDirs = array('/etc/qbix');

	/** The available/enabled pairs, in load order (sites are loaded as --config). */
	static $pairs = array('mods', 'conf', 'sites');

	/** The variable that moves the base tree. */
	const ENV = 'QBIX_CONF_DIR';

	/**
	 * Trees stacked on top of the base, in order, each laid out like it:
	 * array(dir, environment variable that moves it). Empty unless something
	 * registers one -- a distribution of the engine that keeps its own tree
	 * beside /etc/qbix, say (see qbixserver.php, --distribution).
	 * @property $overlays
	 */
	static $overlays = array();

	/** The base tree's state directory (/var/lib counterpart of /etc/qbix), and its variable. */
	const STATE_DIR = '/var/lib/qbix';
	const STATE_ENV = 'QBIX_STATE_DIR';

	/**
	 * Stack another tree on top of the base one.
	 * @method addOverlay
	 * @static
	 * @param {string} $dir its standard place, e.g. /etc/example
	 * @param {string|null} $env a variable that, when set, moves it
	 * @param {string|null} $stateDir its state directory, e.g. /var/lib/example
	 * @param {string|null} $logDir its log directory, e.g. /var/log/example
	 */
	static function addOverlay($dir, $env = null, $stateDir = null, $logDir = null)
	{
		foreach (self::$overlays as $o) {
			if ($o[0] === $dir) return;
		}
		self::$overlays[] = array(rtrim($dir, '/'), $env, $stateDir === null ? null : rtrim($stateDir, '/'),
			$logDir === null ? null : rtrim($logDir, '/'));
	}

	/** The base tree's log directory (/var/log counterpart of /etc/qbix), and its variable. */
	const LOG_DIR = '/var/log/qbix';
	const LOG_ENV = 'QBIX_LOG_DIR';

	/**
	 * The log directory that goes with a configuration directory, as /var/log
	 * is to /etc: QBIX_LOG_DIR when set; otherwise the top overlay's own, when
	 * it names one, else /var/log/qbix for the base tree. Null for a directory
	 * that is not the base or an overlay, and for none.
	 * @method logDir
	 * @static
	 * @param {string|null} $confDir the directory in use (the top of the stack)
	 * @return {string|null}
	 */
	static function logDir($confDir)
	{
		$env = getenv(self::LOG_ENV);
		if (is_string($env) and $env !== '') return rtrim($env, '/');
		if ($confDir === null or $confDir === '') return null;
		$confDir = rtrim($confDir, '/');
		foreach (array_reverse(self::$overlays) as $o) {
			if (self::place($o[1], $o[0]) === $confDir) return $o[3] ?? null;
		}
		if (self::place(self::ENV, self::$standardDirs[0] ?? null) === $confDir) return self::LOG_DIR;
		return null;
	}

	/**
	 * The state directory that goes with a configuration directory: what the
	 * server writes and keeps between runs (the control panel's sessions,
	 * say), as /var/lib is to /etc. Q.webserver.stateDir or QBIX_STATE_DIR
	 * when set; otherwise the counterpart of the tree in use -- the top
	 * overlay's own, when it names one, else /var/lib/qbix for the base tree.
	 * Null for a directory that is not the base or an overlay, and for none.
	 * @method stateDir
	 * @static
	 * @param {string|null} $confDir the directory in use (the top of the stack)
	 * @return {string|null}
	 */
	static function stateDir($confDir)
	{
		$cfg = class_exists('Q_Config', false) ? Q_Config::get('Q', 'webserver', 'stateDir', null) : null;
		if (is_string($cfg) and $cfg !== '') return rtrim($cfg, '/');
		$env = getenv(self::STATE_ENV);
		if (is_string($env) and $env !== '') return rtrim($env, '/');
		if ($confDir === null or $confDir === '') return null;
		$confDir = rtrim($confDir, '/');
		foreach (array_reverse(self::$overlays) as $o) {
			if (self::place($o[1], $o[0]) === $confDir) return $o[2] ?? null;
		}
		if (self::place(self::ENV, self::$standardDirs[0] ?? null) === $confDir) return self::STATE_DIR;
		return null;
	}

	/**
	 * Load a distribution of the engine: the one named by $name, else
	 * QBIX_DISTRIBUTION, else the DISTRIBUTION file in the source tree. Its
	 * class Q_WebServer_Distribution_<Name> (src/Q/WebServer/Distribution/)
	 * is asked to register() what it adds -- an overlay tree, for one. With
	 * no distribution, "none", or no such class, nothing changes.
	 *
	 * @method loadDistribution
	 * @static
	 * @param {string|null} $name --distribution
	 * @param {string} $sourceDir the engine's source tree
	 * @return {string|null} the distribution loaded
	 */
	static function loadDistribution($name, $sourceDir)
	{
		$name = $name ?? (getenv('QBIX_DISTRIBUTION') ?: null);
		if ($name === null and is_file($sourceDir . '/DISTRIBUTION')) {
			$name = trim((string) file_get_contents($sourceDir . '/DISTRIBUTION'));
		}
		if (!is_string($name) or !preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $name)) return null;
		$class = 'Q_WebServer_Distribution_' . ucfirst(strtolower($name));
		$file = $sourceDir . '/src/Q/WebServer/Distribution/' . ucfirst(strtolower($name)) . '.php';
		if (!class_exists($class, false) and is_file($file)) require_once $file;
		if (!class_exists($class, false) or !method_exists($class, 'register')) return null;
		$class::register();
		if (class_exists('Q_Config', false)) Q_Config::set('Q', 'webserver', 'distribution', strtolower($name));
		return strtolower($name);
	}

	/**
	 * The configuration directory to use, or null for none.
	 *
	 * @method resolve
	 * @static
	 * @param {string|null} $explicit --conf-dir: a path, 'auto', 'none', or null
	 * @param {string|null} $configFile the --config file
	 * @return {string|null}
	 */
	static function resolve($explicit = null, $configFile = null)
	{
		if ($explicit === 'none' or $explicit === 'disabled') return null;
		if ($explicit === 'auto') return self::search();
		if ($explicit !== null and $explicit !== '') {
			return is_dir($explicit) ? rtrim($explicit, '/') : null;
		}
		foreach (array_merge(array(self::ENV), array_filter(array_column(self::$overlays, 1))) as $env) {
			$dir = getenv($env);
			if (is_string($dir) and $dir !== '') {
				return $dir === 'auto' ? self::search() : (is_dir($dir) ? rtrim($dir, '/') : null);
			}
		}
		if ($configFile) return self::fromSiteFile($configFile);
		return null;
	}

	/**
	 * The trees to load, base first and overlays after it, for the directory
	 * that was asked for. Only the base and its overlays stack: a directory
	 * that is none of them is used alone, so a server pointed at its own tree
	 * does not pick up the machine's because it happens to exist.
	 *
	 * @method stack
	 * @static
	 * @param {string|null} $asked what resolve() returned
	 * @return {array}
	 */
	static function stack($asked)
	{
		if ($asked === null) return array();
		$asked = rtrim($asked, '/');
		$family = array(self::place(self::ENV, self::$standardDirs[0] ?? null));
		foreach (self::$overlays as $o) {
			$family[] = self::place($o[1], $o[0]);
		}
		if (!in_array($asked, $family, true)) return array($asked);
		$stack = array();
		foreach ($family as $dir) {
			if ($dir !== null and ($dir === $asked or self::isConfDir($dir))) $stack[] = $dir;
		}
		return array_values(array_unique($stack));
	}

	/** A tree's directory: its variable when set to a directory, else its standard place. */
	private static function place($env, $default)
	{
		$value = $env ? getenv($env) : false;
		if (is_string($value) and $value !== '' and $value !== 'auto') {
			return is_dir($value) ? rtrim($value, '/') : null;
		}
		return $default === null ? null : rtrim($default, '/');
	}

	/**
	 * The first standard directory that holds a configuration.
	 * @method search
	 * @static
	 * @return {string|null}
	 */
	static function search()
	{
		foreach (array_merge(self::$standardDirs, array_column(self::$overlays, 0)) as $dir) {
			if (self::isConfDir($dir)) return $dir;
		}
		return null;
	}

	/**
	 * The directory a site file belongs to: X for X/sites-enabled/a.conf.
	 * @method fromSiteFile
	 * @static
	 * @param {string} $configFile
	 * @return {string|null}
	 */
	static function fromSiteFile($configFile)
	{
		// The path as given, not realpath(): sites-enabled holds symlinks into
		// sites-available, and both belong to the same directory.
		$parent = dirname($configFile);
		if (!in_array(basename($parent), array('sites-enabled', 'sites-available'), true)) return null;
		$dir = dirname($parent);
		return self::isConfDir($dir) ? $dir : null;
	}

	/**
	 * Whether a directory is laid out as a configuration directory.
	 * @method isConfDir
	 * @static
	 * @param {string} $dir
	 * @return {boolean}
	 */
	static function isConfDir($dir)
	{
		if (!is_dir($dir)) return false;
		if (self::mainFile($dir) !== null) return true;
		foreach (self::$pairs as $p) {
			if (is_dir("$dir/$p-available") or is_dir("$dir/$p-enabled")) return true;
		}
		return false;
	}

	/**
	 * The base file: <dir name>.conf -- qbix.conf in /etc/qbix, and the same
	 * rule for an overlay tree (/etc/example/example.conf) -- and qbix.conf
	 * in any tree, so a tree moved from /etc/qbix keeps working.
	 * @method mainFile
	 * @static
	 * @param {string} $dir
	 * @return {string|null}
	 */
	static function mainFile($dir)
	{
		foreach (array_unique(array(basename($dir) . '.conf', 'qbix.conf')) as $name) {
			if (is_file("$dir/$name")) return "$dir/$name";
		}
		return null;
	}

	/**
	 * The enabled files of one pair, in name order. Only *.conf and *.json,
	 * as Apache includes only *.conf -- an editor's backup is not a setting.
	 * @method enabled
	 * @static
	 * @param {string} $dir
	 * @param {string} $pair mods, conf or sites
	 * @return {array}
	 */
	static function enabled($dir, $pair)
	{
		$files = array_merge(
			glob("$dir/$pair-enabled/*.conf") ?: array(),
			glob("$dir/$pair-enabled/*.json") ?: array()
		);
		sort($files, SORT_STRING);
		// A dangling symlink is a disabled target, not an error.
		return array_values(array_filter($files, 'is_file'));
	}

	/**
	 * Every file to load from a configuration directory, in apache2.conf
	 * order: base, ports.conf, mods-enabled, conf-enabled. The site comes
	 * last, as --config.
	 * @method files
	 * @static
	 * @param {string} $dir
	 * @return {array}
	 */
	static function files($dir)
	{
		$files = array();
		$main = self::mainFile($dir);
		if ($main !== null) $files[] = $main;
		if (is_file("$dir/ports.conf")) $files[] = "$dir/ports.conf";
		foreach (array('mods', 'conf') as $pair) {
			$files = array_merge($files, self::enabled($dir, $pair));
		}
		return $files;
	}

	/**
	 * Load a configuration directory into Q_Config, below whatever the
	 * caller loads next. Returns the files loaded.
	 * @method load
	 * @static
	 * @param {string|null} $dir
	 * @return {array}
	 */
	static function load($dir)
	{
		if ($dir === null) return array();
		$loaded = array();
		foreach (self::files($dir) as $file) {
			$json = json_decode((string) @file_get_contents($file), true);
			if (!is_array($json)) {
				fwrite(STDERR, "  config: $file is not a JSON object, skipped\n");
				continue;
			}
			Q_Config::load($file);
			$loaded[] = $file;
		}
		return $loaded;
	}

	/**
	 * The environment envvars sets: "export NAME=value" or "NAME=value" per
	 * line, # comments, optional single or double quotes. No expansion --
	 * the file is read, not executed.
	 * @method envvars
	 * @static
	 * @param {string|null} $dir
	 * @return {array} name => value
	 */
	static function envvars($dir)
	{
		$vars = array();
		if ($dir === null or !is_file("$dir/envvars")) return $vars;
		foreach (file("$dir/envvars", FILE_IGNORE_NEW_LINES) ?: array() as $line) {
			$line = trim($line);
			if ($line === '' or $line[0] === '#') continue;
			if (!preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) continue;
			$value = trim($m[2]);
			if (strlen($value) >= 2 and ($value[0] === '"' or $value[0] === "'") and substr($value, -1) === $value[0]) {
				$value = substr($value, 1, -1);
			}
			$vars[$m[1]] = $value;
		}
		return $vars;
	}

	/**
	 * What would be used, for --layout and for people.
	 * @method describe
	 * @static
	 * @param {string|null} $dir
	 * @param {string|null} $configFile
	 * @return {array}
	 */
	static function describe($dir, $configFile = null)
	{
		$pairs = array();
		if ($dir !== null) {
			foreach (self::$pairs as $p) {
				$pairs[$p] = array(
					'available' => array_map(function ($f) { return basename($f); },
						array_merge(glob("$dir/$p-available/*.conf") ?: array(), glob("$dir/$p-available/*.json") ?: array())),
					'enabled' => array_map('basename', self::enabled($dir, $p)),
				);
			}
		}
		return array(
			'confDir'  => $dir,
			'searched' => self::$standardDirs,
			'files'    => $dir !== null ? self::files($dir) : array(),
			'site'     => $configFile,
			'pairs'    => $pairs,
			'envvars'  => array_keys(self::envvars($dir)),
			'designs'  => ($dir !== null and is_dir("$dir/designs")) ? "$dir/designs" : null,
		);
	}
}
