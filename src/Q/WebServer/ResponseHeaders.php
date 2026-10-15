<?php
/**
 * @module Q
 */

/**
 * Headers the configuration adds to the server's responses, and
 * Strict-Transport-Security for every HTTPS response.
 *
 *     { "Q": { "webserver": {
 *         "headers": {
 *             "X-Content-Type-Options": "nosniff",
 *             "X-Frame-Options": "SAMEORIGIN"
 *         },
 *         "headersOnScripts": true,
 *         "hsts": { "maxAge": 300, "includeSubDomains": false, "preload": false }
 *     } } }
 *
 * Q.webserver.headers
 *   Added to every response the server builds itself: static files, image
 *   variants, its own error pages, redirects and 304s. What Apache does with
 *   "Header always set" in a server-wide context, and nginx with add_header
 *   ... always, except that a header the response already carries is never
 *   replaced or sent twice.
 *
 * Q.webserver.headersOnScripts (default false)
 *   Also add them to a script's response, each one only when the script did
 *   not send that header itself: an application that sets its own
 *   X-Frame-Options keeps its value. Answers the response cache stores are
 *   the script's and get the same treatment.
 *
 * Q.webserver.hsts
 *   Strict-Transport-Security on every response sent over TLS, a script's
 *   included, unless the response already has one. A domain in the panel
 *   store with an HSTS record of its own (Q_WebServer_Domains) is answered
 *   with that record's value instead. Never over plain HTTP, where RFC 6797
 *   section 7.2 says a browser must ignore it.
 *   A number is the max-age in seconds; an object takes maxAge,
 *   includeSubDomains and preload; a string is sent as it is. 0, false,
 *   null or no setting at all send none.
 *
 * A name that is not an HTTP token, one of RESERVED, or a value carrying CR,
 * LF or NUL, is left out: these come from a file an operator edits, and a
 * typo must not be able to write a header of its own into every response,
 * or break the framing of one.
 *
 * @class Q_WebServer_ResponseHeaders
 */
class Q_WebServer_ResponseHeaders
{
	/**
	 * Names Q.webserver.headers cannot set: the server works out the framing,
	 * the connection and the media type of each response itself (HTTP/2
	 * forbids the connection-specific ones outright), and
	 * Strict-Transport-Security has Q.webserver.hsts, which knows not to send
	 * it over plain HTTP.
	 */
	const RESERVED = array(
		'content-length', 'transfer-encoding', 'connection', 'keep-alive',
		'upgrade', 'te', 'trailer', 'proxy-connection', 'content-encoding',
		'content-type', 'date', 'status', 'strict-transport-security',
	);

	/**
	 * The configured headers, name => value, the invalid ones left out.
	 *
	 * @method configured
	 * @static
	 * @return {array}
	 */
	static function configured()
	{
		$list = class_exists('Q_Config', false) ? Q_Config::get('Q', 'webserver', 'headers', array()) : array();
		if (!is_array($list)) return array();
		$out = array();
		foreach ($list as $name => $value) {
			if (!is_string($name) or !self::validName($name)) continue;
			if (in_array(strtolower($name), self::RESERVED, true)) continue;
			if (is_bool($value) or is_array($value) or is_object($value) or $value === null) continue;
			$value = trim((string) $value);
			if ($value === '' or !self::validValue($value)) continue;
			$out[$name] = $value;
		}
		return $out;
	}

	/** The Content-Security-Policy an SVG file is served with */
	const SVG_CSP = "sandbox; default-src 'none'; style-src 'unsafe-inline'";

	/**
	 * Headers a static file is served with because of its type, name =>
	 * value: an SVG (.svg, .svgz) is a document a browser runs script from
	 * when it is opened directly, so it goes out with
	 * Content-Security-Policy: sandbox (no script, no request, inline style
	 * only) and X-Content-Type-Options: nosniff. Shown with <img> or from a
	 * style sheet it renders as before. Q.webserver.svgSandbox false turns
	 * it off.
	 *
	 * @method forFile
	 * @static
	 * @param {string} $ext the file's extension, lower case
	 * @return {array}
	 */
	static function forFile($ext)
	{
		if ($ext !== 'svg' and $ext !== 'svgz') return array();
		if (class_exists('Q_Config', false)) {
			$v = Q_Config::get('Q', 'webserver', 'svgSandbox', true);
			if ($v === false or $v === 0 or $v === '0'
				or (is_string($v) and in_array(strtolower(trim($v)), array('false', 'no', 'off', 'disabled'), true))) {
				return array();
			}
		}
		return array(
			'Content-Security-Policy' => self::SVG_CSP,
			'X-Content-Type-Options' => 'nosniff',
		);
	}

	/**
	 * forFile() as response lines ("Name: value\r\n").
	 *
	 * @method forFileLines
	 * @static
	 * @param {string} $ext
	 * @return {string}
	 */
	static function forFileLines($ext)
	{
		$out = '';
		foreach (self::forFile($ext) as $name => $value) {
			$out .= $name . ': ' . $value . "\r\n";
		}
		return $out;
	}

	/**
	 * Whether the configured headers also go on a script's response.
	 *
	 * @method onScripts
	 * @static
	 * @return {boolean}
	 */
	static function onScripts()
	{
		if (!class_exists('Q_Config', false)) return false;
		$v = Q_Config::get('Q', 'webserver', 'headersOnScripts', false);
		return $v === true or $v === 1 or (is_string($v) and in_array(strtolower(trim($v)), array('1', 'true', 'yes', 'on', 'enabled'), true));
	}

	/**
	 * The server-wide Strict-Transport-Security value, or null for none.
	 *
	 * @method hsts
	 * @static
	 * @return {string|null}
	 */
	static function hsts()
	{
		$h = class_exists('Q_Config', false) ? Q_Config::get('Q', 'webserver', 'hsts', null) : null;
		return self::hstsValue($h);
	}

	/**
	 * A Q.webserver.hsts setting as the header value it stands for.
	 *
	 * @method hstsValue
	 * @static
	 * @param {mixed} $h
	 * @return {string|null}
	 */
	static function hstsValue($h)
	{
		if ($h === null or $h === false or $h === '' or $h === 0 or $h === '0') return null;
		if (is_int($h) or is_float($h) or (is_string($h) and ctype_digit(trim($h)))) {
			$age = (int) $h;
			return $age > 0 ? 'max-age=' . $age : null;
		}
		if (is_string($h)) {
			$h = trim($h);
			return ($h !== '' and self::validValue($h) and stripos($h, 'max-age=') !== false) ? $h : null;
		}
		if (!is_array($h)) return null;
		if (array_key_exists('enabled', $h) and !$h['enabled']) return null;
		$age = isset($h['maxAge']) ? (int) $h['maxAge'] : 0;
		if ($age <= 0) return null;
		return 'max-age=' . $age
			. (!empty($h['includeSubDomains']) ? '; includeSubDomains' : '')
			. (!empty($h['preload']) ? '; preload' : '');
	}

	/**
	 * The Strict-Transport-Security value for a response to $host: the
	 * domain's own record if it has one, else the server-wide setting;
	 * null over plain HTTP.
	 *
	 * @method hstsFor
	 * @static
	 * @param {string|null} $host
	 * @param {boolean} $https
	 * @return {string|null}
	 */
	static function hstsFor($host, $https)
	{
		if (!$https) return null;
		if ($host !== null and $host !== '' and class_exists('Q_WebServer_Domains', false)) {
			$v = Q_WebServer_Domains::hstsHeader(Q_WebServer_Domains::normalize($host), true);
			if ($v !== null) return $v;
		}
		return self::hsts();
	}

	/**
	 * Add what applies to a response's headers (name => value), each only
	 * when the response does not have it yet, in any letter case.
	 *
	 * @method apply
	 * @static
	 * @param {array} $headers
	 * @param {string|null} $host the request's Host, for a domain's HSTS
	 * @param {boolean} $https whether the response goes out over TLS
	 * @param {boolean} [$script=false] a script's response rather than the server's own
	 */
	static function apply(array &$headers, $host, $https, $script = false)
	{
		$add = array();
		$hsts = self::hstsFor($host, $https);
		if ($hsts !== null) $add['Strict-Transport-Security'] = $hsts;
		if (!$script or self::onScripts()) $add += self::configured();
		if (!$add) return;

		$present = array();
		foreach ($headers as $k => $_) {
			if (is_string($k)) $present[strtolower($k)] = true;
		}
		foreach ($add as $name => $value) {
			if (isset($present[strtolower($name)])) continue;
			$headers[$name] = $value;
		}
	}

	/**
	 * The configured headers as response lines ("Name: value\r\n"), for the
	 * paths that write a header block by hand, leaving out the names in
	 * $present (lower case). Strict-Transport-Security is never among them
	 * (see RESERVED): hstsFor() gives it, because it depends on the host and
	 * the transport.
	 *
	 * @method lines
	 * @static
	 * @param {array} [$present=array()] lower-case names the block already has
	 * @return {string}
	 */
	static function lines(array $present = array())
	{
		return Q_WebServer::headerLines(self::pick($present));
	}

	/** The configured headers the block does not have yet, name => value. */
	protected static function pick(array $present)
	{
		$out = array();
		foreach (self::configured() as $name => $value) {
			if (!in_array(strtolower($name), $present, true)) $out[$name] = $value;
		}
		return $out;
	}

	/**
	 * Lines for a response the server writes by hand to $client: the
	 * configured headers and, over TLS, Strict-Transport-Security for the
	 * host the request is being answered for.
	 *
	 * @method serverLines
	 * @static
	 * @param {resource} $client
	 * @param {array} [$present=array()] lower-case names the block already has
	 * @return {string}
	 */
	static function serverLines($client, array $present = array())
	{
		$meta = is_resource($client) ? @stream_get_meta_data($client) : array();
		$host = class_exists('Q_WebServer_Domains', false) ? Q_WebServer_Domains::$currentHost : null;
		$hsts = self::hstsFor($host, !empty($meta['crypto']));
		$add = self::pick($present);
		if ($hsts !== null and !in_array('strict-transport-security', $present, true)) {
			$add['Strict-Transport-Security'] = $hsts;
		}
		return Q_WebServer::headerLines($add);
	}

	/** An HTTP field name (RFC 9110 section 5.1: a token). */
	static function validName($name)
	{
		return is_string($name) and preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $name) === 1;
	}

	/** A field value that cannot end the header or start another. */
	static function validValue($value)
	{
		return preg_match('/[\r\n\0]/', (string) $value) !== 1;
	}
}
