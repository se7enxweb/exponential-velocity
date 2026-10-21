<?php

/**
 * An application's own page cache, asked by the server before a worker.
 *
 * Some applications keep rendered pages themselves, keyed by more than the
 * server can see -- who the visitor is, which permissions they have -- and
 * can answer a request from a session cookie and a few files without their
 * framework. The server's response cache cannot do that: it skips every
 * request with a session cookie. This asks the application's class instead,
 * in the server process, so a hit never wakes a worker.
 *
 * Configured under Q.web.appCache:
 *   file   the PHP file that defines the class (loaded once, at start)
 *   class  its name; it must have static fromDir($dir), returning an object
 *          (or null when the cache is off), whose serve(array $request)
 *          returns [status, headers, body] for a hit and null otherwise.
 *          $request: scheme, host, uri, method, cookies, acceptEncoding,
 *          ifNoneMatch, headers (every request header, lower-case names),
 *          port (the listener's)
 *   dir    passed to fromDir()
 *   allow  optional: classes (or files) the file may load besides its class
 *          without a warning (see checkPreload())
 *   strict optional: true refuses to start when the file loaded a class that
 *          needs the source transform (see checkPreload())
 *
 * A new object is made for every request, so what it read (a purge, a
 * switched-off configuration) is never older than the request. Anything the
 * class throws turns the hook off for ten seconds and is logged once.
 *
 * @class Q_WebServer_AppCache
 * @static
 */
class Q_WebServer_AppCache
{
	/** @var string|null class to ask; null when not configured */
	static $class = null;

	/** @var string */
	static $dir = '';

	/** @var int hits and lookups since the server started */
	static $hits = 0;
	static $lookups = 0;

	/** @var float until when the hook is off after a failure */
	private static $offUntil = 0.0;

	/**
	 * What loading the file brought in besides its class, one entry per file:
	 * file, classes (declared from it), needsTransform (the source transform
	 * would change it). Filled by init(); see checkPreload().
	 * @var array
	 */
	static $preload = array();

	/** @var bool Q.web.appCache.strict and a preloaded class needs the transform: the server must not start */
	static $refuse = false;

	/**
	 * Reads Q.web.appCache and loads the class.
	 * @method init
	 * @static
	 */
	static function init()
	{
		self::$class = null;
		self::$preload = array();
		self::$refuse = false;
		$config = Q_Config::get('Q', 'web', 'appCache', array());
		if (!is_array($config) or empty($config['class']) or empty($config['dir'])) return;
		$file = (string) Q::ifset($config, 'file', '');
		try {
			if ($file !== '' and !class_exists($config['class'], false)) {
				if (!is_file($file)) {
					error_log("Q.web.appCache: $file does not exist; the application cache is not asked");
					return;
				}
				$classesBefore = self::declared();
				$filesBefore = get_included_files();
				require_once $file;
				self::checkPreload($file, $config, $classesBefore, $filesBefore);
			}
			if (!class_exists($config['class'], false)
				or !method_exists($config['class'], 'fromDir')
			) {
				error_log("Q.web.appCache: class {$config['class']} with fromDir() not found; the application cache is not asked");
				return;
			}
		} catch (Throwable $e) {
			error_log('Q.web.appCache: ' . $e->getMessage());
			return;
		}
		self::$class = (string) $config['class'];
		self::$dir = (string) $config['dir'];
	}

	/**
	 * Every class, interface and trait declared so far.
	 * @method declared
	 * @static
	 * @return {array}
	 */
	static function declared()
	{
		return array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits());
	}

	/**
	 * Says when the file loaded more than its class.
	 *
	 * The file is loaded here, in the server process, before the source
	 * transform is in place -- and every worker inherits what it compiled.
	 * A class it loads besides its own is therefore never transformed in a
	 * worker: header(), setcookie(), exit and the other calls the transform
	 * rewrites stay PHP's own, which do nothing under the CLI SAPI. Nothing
	 * fails and nothing is logged; the calls go nowhere. That happened once:
	 * an application's cache class started loading its file helpers, and for
	 * two weeks every download went out as text/html.
	 *
	 * So each file (and class) the require brought in besides the listed file
	 * and class is recorded in self::$preload, unless Q.web.appCache.allow
	 * names the class or the file. One the transform would change is logged
	 * as a warning, naming the file and its classes; with
	 * Q.web.appCache.strict, self::$refuse is set and the server does not
	 * start. One the transform would leave as it is cannot be harmed, and is
	 * only listed in the control panel. Engine files are not counted: they are
	 * loaded before the transform anyway.
	 *
	 * @method checkPreload
	 * @static
	 * @param {string} $file the configured file
	 * @param {array} $config Q.web.appCache
	 * @param {array} $classesBefore self::declared() before the require
	 * @param {array} $filesBefore get_included_files() before the require
	 * @return {array} self::$preload
	 */
	static function checkPreload($file, $config, $classesBefore, $filesBefore)
	{
		$listedClass = strtolower((string) Q::ifset($config, 'class', ''));
		$allowClasses = array();
		$allowFiles = array();
		foreach ((array) Q::ifset($config, 'allow', array()) as $a) {
			$a = (string) $a;
			if ($a === '') continue;
			if (strpbrk($a, '/\\') !== false or substr($a, -4) === '.php') {
				$allowFiles[self::samePath($a)] = true;
			} else {
				$allowClasses[strtolower(ltrim($a, '\\'))] = true;
			}
		}
		$listedFile = self::samePath($file);
		$engine = self::samePath(dirname(__DIR__, 2)) . '/';

		$byFile = array();
		foreach (array_diff(self::declared(), $classesBefore) as $class) {
			$lower = strtolower($class);
			if ($lower === $listedClass) continue;
			try {
				$from = (new ReflectionClass($class))->getFileName();
			} catch (Throwable $e) {
				$from = false;
			}
			$from = $from === false ? '(unknown file)' : self::samePath($from);
			$byFile[$from][] = $class;
		}
		foreach (array_diff(get_included_files(), $filesBefore) as $f) {
			$f = self::samePath($f);
			if (!isset($byFile[$f])) $byFile[$f] = array();
		}

		$out = array();
		foreach ($byFile as $f => $classes) {
			if (strpos($f, $engine) === 0) continue;
			if (isset($allowFiles[$f])) continue;
			$left = array();
			foreach ($classes as $c) {
				if (!isset($allowClasses[strtolower($c)])) $left[] = $c;
			}
			// The listed file itself: only its classes besides the listed one.
			if ($f === $listedFile and !$left) continue;
			if ($classes and !$left) continue;
			$out[] = array(
				'file' => $f,
				'classes' => $left,
				'needsTransform' => self::needsTransform($f),
			);
		}
		self::$preload = $out;

		$strict = (bool) Q::ifset($config, 'strict', false);
		foreach ($out as $p) {
			if (!$p['needsTransform']) continue;
			$what = $p['classes'] ? 'class ' . implode(', ', $p['classes']) . ' from ' . $p['file'] : $p['file'];
			error_log("Q.web.appCache: WARNING: loading $file also loaded $what. "
				. 'It was compiled in the server process, before the source transform is in place, and every worker '
				. 'inherits it as it is: header(), setcookie(), exit and the other calls the transform rewrites do '
				. 'nothing in it on this server. Load it lazily in the application, or list it in '
				. 'Q.web.appCache.allow if it never needs the transform.');
			if ($strict) self::$refuse = true;
		}
		if (self::$refuse) {
			error_log('Q.web.appCache: Q.web.appCache.strict is set; the server does not start');
		}
		return $out;
	}

	/**
	 * Whether the source transform would change a file; true when it cannot
	 * be told (no transform class, an unreadable file).
	 * @method needsTransform
	 * @static
	 * @param {string} $file
	 * @return {boolean}
	 */
	static function needsTransform($file)
	{
		if (!class_exists('Q_WebServer_Compat')) return true;
		$source = @file_get_contents($file);
		if (!is_string($source)) return true;
		try {
			return Q_WebServer_Compat::transformSource($source) !== $source;
		} catch (Throwable $e) {
			return true;
		}
	}

	/**
	 * A path as compared here: resolved when it exists, with forward slashes.
	 * @method samePath
	 * @static
	 * @param {string} $path
	 * @return {string}
	 */
	private static function samePath($path)
	{
		$path = (string) $path;
		if (strpos($path, '://') === false) {
			$real = realpath($path);
			if ($real !== false) $path = $real;
		}
		return rtrim(str_replace('\\', '/', $path), '/');
	}

	/**
	 * The application's answer for a request, as a response array, or null.
	 * @method get
	 * @static
	 * @param {array} $parsed the parsed request
	 * @return {array|null} [status, headers, body]
	 */
	static function get($parsed)
	{
		if (self::$class === null) return null;
		$method = $parsed['method'] ?? 'GET';
		if ($method !== 'GET' and $method !== 'HEAD') return null;
		if (self::$offUntil > 0 and microtime(true) < self::$offUntil) return null;

		$headers = $parsed['headers'] ?? array();
		$cookies = $parsed['cookies'] ?? null;
		if (!is_array($cookies)) {
			$cookies = isset($headers['cookie']) ? self::parseCookies((string) $headers['cookie']) : array();
		}
		$query = (string) ($parsed['query'] ?? '');
		$uri = $parsed['uri'] ?? (($parsed['path'] ?? '/') . ($query !== '' ? '?' . $query : ''));
		self::$lookups++;
		try {
			$class = self::$class;
			$cache = $class::fromDir(self::$dir);
			if (!$cache) return null;
			$answer = $cache->serve(array(
				// HTTP/2 is served over TLS only here, and its request carries no _https.
				'scheme' => !empty($parsed['_https']) || !empty($parsed['https'])
					|| ($parsed['httpVersion'] ?? '') === '2' ? 'https' : 'http',
				'host' => (string) ($headers['host'] ?? ''),
				'uri' => (string) $uri,
				// Asked as the GET a HEAD stands for: the answer then carries
				// the GET's body, from which the writer states the length and
				// which it never sends (both writers drop a HEAD's body). Asked
				// as a HEAD it came back empty, and an empty body is a
				// Content-Length of 0 -- not what the GET would send.
				'method' => 'GET',
				'cookies' => $cookies,
				'acceptEncoding' => (string) ($headers['accept-encoding'] ?? ''),
				'ifNoneMatch' => isset($headers['if-none-match']) ? (string) $headers['if-none-match'] : null,
				// Every request header, and the port it arrived on, so the
				// application can work out scheme and host as its own code
				// does behind a load balancer: the worker sees
				// X-Forwarded-Proto and X-Forwarded-Host and renders (and
				// stores) the page for https://www.example.com, while
				// scheme and host above only say http and exp:8080 -- the
				// page was stored under one key and looked for under another.
				'headers' => $headers,
				'port' => class_exists('Q_WebServer', false) ? (int) Q_WebServer::$port : 0,
			));
		} catch (Throwable $e) {
			self::$offUntil = microtime(true) + 10;
			error_log('Q.web.appCache: ' . get_class($e) . ': ' . $e->getMessage() . ' -- not asked for 10 seconds');
			return null;
		}
		if (!is_array($answer) or count($answer) < 3) return null;
		self::$hits++;
		list($status, $responseHeaders, $body) = array_values($answer);
		return array(
			'status' => (int) $status,
			'headers' => is_array($responseHeaders) ? $responseHeaders : array(),
			'body' => (string) $body,
		);
	}

	private static function parseCookies($header)
	{
		$cookies = array();
		foreach (explode(';', $header) as $pair) {
			$eq = strpos($pair, '=');
			if ($eq === false) continue;
			$name = trim(substr($pair, 0, $eq));
			if ($name !== '' and !isset($cookies[$name])) {
				$cookies[$name] = urldecode(trim(substr($pair, $eq + 1)));
			}
		}
		return $cookies;
	}
}
