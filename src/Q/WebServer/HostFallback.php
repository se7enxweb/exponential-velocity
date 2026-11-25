<?php
/**
 * @module Q
 */

/**
 * A host this server cannot serve is handed back to the web server that
 * served it before, says why on a page of its own when there is none, and
 * reports itself.
 *
 *   "Q": { "webserver": { "hostFallback": {
 *     "upstream": "127.0.0.1:7080",       the previous server (Apache behind
 *                                          Plesk's nginx: 127.0.0.1:7080);
 *                                          none: the explaining page, 503
 *     "errors": 20, "window": 60,         a 5xx storm: this many 5xx answers
 *                                          of one host within this many seconds
 *     "hold": 300,                        how long a storm keeps the host away
 *     "stateDir": "/var/lib/vc/fallback", a file <host> there sends the host
 *                                          back too, until it is removed (vcc
 *                                          writes it: a failed start, a rule
 *                                          the server cannot do); the reports
 *                                          are written there as <host>.json
 *     "report": "/usr/local/sbin/vcc fallback report"
 *                                          run once per episode, detached, with
 *                                          VC_FALLBACK_HOST, VC_FALLBACK_CAUSE,
 *                                          VC_FALLBACK_REPORT (the JSON file)
 *   }, "hosts": { "example.com": { "hostFallback": { "upstream": "..." } } } } }
 *
 * (Q.webserver.fallback is another setting: the page for a path nothing matched.)
 *
 * Nothing is configured: nothing changes. A host in fallback is relayed over
 * HTTP/1.1 like any Q.webserver.upstreams host (Q_WebServer_Upstream); the
 * HTTP/2 path answers the explaining page instead (it relays nothing).
 *
 * @class Q_WebServer_HostFallback
 * @static
 */
class Q_WebServer_HostFallback
{
	/** host => list of 5xx timestamps within the window */
	static $errors = array();

	/** host => array('until' => ts, 'cause' => string, 'since' => ts) */
	static $storms = array();

	/** host => array(checked-at, flag-file contents|null) */
	protected static $flags = array();

	/** The settings for a host: the server's, overlaid by the host's own. */
	static function settings($host)
	{
		if (!class_exists('Q_Config', false)) return array();
		$s = Q_Config::get('Q', 'webserver', 'hostFallback', array());
		if (!is_array($s)) $s = array();
		$host = self::hostName($host);
		$h = Q_Config::get('Q', 'webserver', 'hosts', $host, 'hostFallback', null);
		if (is_array($h)) $s = array_merge($s, $h);
		return $s;
	}

	/** Whether any fallback is configured at all. */
	static function configured()
	{
		if (!class_exists('Q_Config', false)) return false;
		$s = Q_Config::get('Q', 'webserver', 'hostFallback', null);
		return is_array($s) && $s;
	}

	static function hostName($host)
	{
		return strtolower(preg_replace('/:\d+$/', '', trim((string) $host)));
	}

	/**
	 * Note an answer. A 5xx storm puts the host into fallback for "hold"
	 * seconds and reports it once.
	 * @method record
	 * @static
	 * @param {string} $host
	 * @param {integer} $status
	 * @param {string} [$uri]
	 */
	static function record($host, $status, $uri = '')
	{
		if ($status < 500 or !self::configured()) return;
		$host = self::hostName($host);
		if ($host === '') return;
		// The page a host in fallback is answered with is no new error.
		if (self::cause($host) !== null) return;
		$s = self::settings($host);
		$limit = max(1, (int) ($s['errors'] ?? 20));
		$window = max(1, (int) ($s['window'] ?? 60));
		$now = time();
		$list = self::$errors[$host] ?? array();
		$list[] = $now;
		$cut = $now - $window;
		while ($list and $list[0] < $cut) array_shift($list);
		self::$errors[$host] = $list;
		if (count($list) < $limit or isset(self::$storms[$host]) && self::$storms[$host]['until'] > $now) return;
		$hold = max(1, (int) ($s['hold'] ?? 300));
		$cause = count($list) . " answers 5xx within {$window}s (last: $status $uri)";
		self::$storms[$host] = array('until' => $now + $hold, 'cause' => $cause, 'since' => $now);
		self::$errors[$host] = array();
		self::report($host, '5xx storm: ' . $cause);
	}

	/**
	 * Why a host is in fallback, or null when it is served here.
	 * @method cause
	 * @static
	 * @param {string} $host
	 * @return {string|null}
	 */
	static function cause($host)
	{
		if (!self::configured()) return null;
		$host = self::hostName($host);
		if ($host === '') return null;
		$now = time();
		if (isset(self::$storms[$host])) {
			if (self::$storms[$host]['until'] > $now) return '5xx storm: ' . self::$storms[$host]['cause'];
			unset(self::$storms[$host]);
		}
		$flag = self::flag($host);
		return $flag;
	}

	/** The contents of the host's flag file (at most once a second), or null. */
	protected static function flag($host)
	{
		$s = self::settings($host);
		$dir = $s['stateDir'] ?? null;
		if (!is_string($dir) or $dir === '') return null;
		$now = time();
		if (isset(self::$flags[$host]) and self::$flags[$host][0] === $now) return self::$flags[$host][1];
		$file = rtrim($dir, '/') . '/' . $host;
		clearstatcache(true, $file);
		$v = null;
		if (is_file($file)) {
			$t = trim((string) @file_get_contents($file, false, null, 0, 4096));
			$v = $t !== '' ? $t : 'sent back by the administrator';
		}
		self::$flags[$host] = array($now, $v);
		return $v;
	}

	/**
	 * Where a host in fallback goes: the previous server's address, or null
	 * (served here, or in fallback with nowhere to go: see page()).
	 * @method target
	 * @static
	 */
	static function target($host)
	{
		if (self::cause($host) === null) return null;
		$u = self::settings($host)['upstream'] ?? null;
		if (!is_string($u) or $u === '') return null;
		return Q_WebServer_Upstream::address($u);
	}

	/**
	 * The page for a host in fallback with no previous server to go to: 503,
	 * what happened, what an administrator does. Null when the host is served.
	 * @method page
	 * @static
	 * @return {array|null} array('status', 'headers', 'body')
	 */
	static function page($host)
	{
		$cause = self::cause($host);
		if ($cause === null) return null;
		$h = htmlspecialchars(self::hostName($host), ENT_QUOTES, 'UTF-8');
		$c = htmlspecialchars($cause, ENT_QUOTES, 'UTF-8');
		$body = "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width\">"
			. "<title>Temporarily unavailable</title></head><body>"
			. "<h1>$h is temporarily unavailable</h1>"
			. "<p>The web server could not serve this site and has taken it out of service for a few minutes."
			. " Please try again shortly.</p>"
			. "<h2>For the administrator</h2><p>Cause: $c</p>"
			. "<p>The server has reported this. <code>vcc fallback status</code> shows every site in fallback;"
			. " <code>vcc fallback clear $h</code> serves it again.</p></body></html>";
		return array('status' => 503, 'headers' => array('Content-Type' => 'text/html; charset=utf-8',
			'Retry-After' => '120', 'Cache-Control' => 'no-store', 'X-Vc-Fallback' => 'unavailable'), 'body' => $body);
	}

	/**
	 * Write the report and run Q.webserver.fallback.report for it, detached.
	 * @method report
	 * @static
	 */
	static function report($host, $cause)
	{
		$s = self::settings($host);
		$report = array('host' => $host, 'cause' => $cause, 'time' => gmdate('c'),
			'node' => function_exists('gethostname') ? gethostname() : '', 'pid' => getmypid(),
			'upstream' => $s['upstream'] ?? null, 'until' => isset(self::$storms[$host]) ? gmdate('c', self::$storms[$host]['until']) : null,
			'log' => self::logExcerpt());
		$file = null;
		$dir = $s['stateDir'] ?? null;
		if (is_string($dir) and $dir !== '' and (is_dir($dir) or @mkdir($dir, 0750, true))) {
			$file = rtrim($dir, '/') . '/' . $host . '.json';
			@file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}
		if (class_exists('Q_WebServer_Log', false)) {
			try { Q_WebServer_Log::error("fallback: $host: $cause"); } catch (\Throwable $e) {}
		}
		$cmd = $s['report'] ?? null;
		if (!is_string($cmd) or $cmd === '' or !function_exists('proc_open')) return $report;
		$env = array('VC_FALLBACK_HOST' => $host, 'VC_FALLBACK_CAUSE' => $cause,
			'VC_FALLBACK_REPORT' => (string) $file, 'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');
		// Detached: the event loop never waits for it.
		$p = @proc_open(array('/bin/sh', '-c', '( ' . $cmd . ' ) </dev/null >/dev/null 2>&1 &'),
			array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')),
			$pipes, null, $env);
		if (is_resource($p)) proc_close($p);
		return $report;
	}

	/** The last lines of the server's error log, for a report. */
	static function logExcerpt($lines = 20)
	{
		$dir = class_exists('Q_Config', false) ? Q_Config::get('Q', 'webserver', 'log', 'dir', null) : null;
		if (!is_string($dir) or !is_dir($dir)) return array();
		$best = null; $bestT = 0;
		foreach ((array) @glob(rtrim($dir, '/') . '/*.log') as $f) {
			$t = (int) @filemtime($f);
			if ($t > $bestT) { $best = $f; $bestT = $t; }
		}
		if ($best === null) return array();
		$size = (int) @filesize($best);
		$h = @fopen($best, 'rb');
		if (!$h) return array();
		if ($size > 16384) fseek($h, -16384, SEEK_END);
		$text = (string) stream_get_contents($h);
		fclose($h);
		$all = preg_split('/\r?\n/', rtrim($text));
		return array_slice($all, -$lines);
	}

	/** For /Q/health and vcc: every host in fallback and why. */
	static function status()
	{
		$out = array();
		$now = time();
		foreach (self::$storms as $host => $s) {
			if ($s['until'] > $now) $out[$host] = array('cause' => '5xx storm: ' . $s['cause'], 'until' => $s['until']);
		}
		return $out;
	}
}
