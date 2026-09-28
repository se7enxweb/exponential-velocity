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
	 * Reads Q.web.appCache and loads the class.
	 * @method init
	 * @static
	 */
	static function init()
	{
		self::$class = null;
		$config = Q_Config::get('Q', 'web', 'appCache', array());
		if (!is_array($config) or empty($config['class']) or empty($config['dir'])) return;
		$file = (string) Q::ifset($config, 'file', '');
		try {
			if ($file !== '' and !class_exists($config['class'], false)) {
				if (!is_file($file)) {
					error_log("Q.web.appCache: $file does not exist; the application cache is not asked");
					return;
				}
				require_once $file;
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
				'method' => $method,
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
			'body' => $method === 'HEAD' ? '' : (string) $body,
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
