<?php
/**
 * @module Q
 */

/**
 * Reverse proxy header handling for Q_WebServer.
 *
 * When behind Cloudflare, AWS ALB, Caddy, nginx, etc.,
 * the client's real IP and protocol are in X-Forwarded-*
 * headers. This class extracts them from trusted proxies.
 *
 * Config:
 *   "Q": { "webserver": { "proxy": {
 *     "trusted": ["127.0.0.1", "10.0.0.0/8", "172.16.0.0/12",
 *       "192.168.0.0/16", "173.245.48.0/20", "103.21.244.0/22"],
 *     "headers": {
 *       "ip": "X-Forwarded-For",
 *       "proto": "X-Forwarded-Proto",
 *       "host": "X-Forwarded-Host"
 *     }
 *   }}}
 *
 * Cloudflare IPs are in the default trusted list. Add your
 * own load balancer IPs as needed.
 *
 * @class Q_WebServer_Proxy
 */
class Q_WebServer_Proxy
{
	static $trusted = null;

	/**
	 * Extract the real client IP from proxy headers.
	 * Only trusts headers from configured proxy IPs.
	 *
	 * @method clientIp
	 * @static
	 * @param {string} $directIp The socket-level remote IP
	 * @param {array} $headers Request headers (lowercase keys)
	 * @return {string} Real client IP
	 */
	static function clientIp($directIp, $headers)
	{
		if (!self::isTrusted($directIp)) return $directIp;

		$headerName = strtolower(Q_Config::get(
			'Q', 'webserver', 'proxy', 'headers', 'ip',
			'x-forwarded-for'
		));

		$forwarded = $headers[$headerName] ?? '';
		if (!$forwarded) return $directIp;

		// X-Forwarded-For: client, proxy1, proxy2
		// Rightmost untrusted IP is the real client
		$ips = array_map('trim', explode(',', $forwarded));
		for ($i = count($ips) - 1; $i >= 0; $i--) {
			if (!self::isTrusted($ips[$i])) {
				return $ips[$i];
			}
		}
		return $ips[0]; // all trusted, use leftmost
	}

	/**
	 * Extract the real protocol (http/https).
	 *
	 * A TLS connection is https whoever sent it. Otherwise the protocol a
	 * proxy forwarded is believed only when the connection comes from a
	 * trusted proxy, exactly as clientIp() believes only a trusted proxy's
	 * address. The configured header is read first (X-Forwarded-Proto by
	 * default), then CloudFront-Forwarded-Proto and Cloudflare's CF-Visitor.
	 *
	 * The headers used to be read wherever HTTPS was set, from any client,
	 * so a visitor on a plain listener could have the application believe
	 * the request was secure -- its absolute URLs, its secure cookies and
	 * its own "is this HTTPS" answers -- just by sending the header.
	 *
	 * @method clientProto
	 * @static
	 * @param {string} $directIp The socket-level remote IP
	 * @param {array} $headers Request headers (lowercase keys)
	 * @param {boolean} $isTls Whether connection is TLS
	 * @return {string} 'http' or 'https'
	 */
	static function clientProto($directIp, $headers, $isTls = false)
	{
		if ($isTls) return 'https';
		if (!is_string($directIp) or $directIp === '' or !self::isTrusted($directIp)) return 'http';

		$headerName = strtolower(Q_Config::get(
			'Q', 'webserver', 'proxy', 'headers', 'proto',
			'x-forwarded-proto'
		));
		foreach (array_unique(array($headerName, 'x-forwarded-proto', 'cloudfront-forwarded-proto')) as $name) {
			$proto = (string) ($headers[$name] ?? '');
			if ($proto === '') continue;
			// "https, http" from a chain of proxies: the first is the visitor's.
			$first = strtolower(trim(explode(',', $proto, 2)[0]));
			if ($first === 'https') return 'https';
		}
		// Cloudflare: CF-Visitor: {"scheme":"https"}
		$visitor = json_decode((string) ($headers['cf-visitor'] ?? ''), true);
		if (is_array($visitor) and strtolower((string) ($visitor['scheme'] ?? '')) === 'https') {
			return 'https';
		}
		return 'http';
	}

	/**
	 * Whether a parsed request is HTTPS: its own connection is TLS, or a
	 * trusted proxy says the visitor's was. See clientProto().
	 *
	 * @method isHttps
	 * @static
	 * @param {array} $parsed The parsed request; "_directIp" is the connection's address
	 * @param {boolean} $isTls Whether the request's own connection is TLS
	 * @return {boolean}
	 */
	static function isHttps($parsed, $isTls)
	{
		return self::clientProto($parsed['_directIp'] ?? '',
			$parsed['headers'] ?? array(), $isTls) === 'https';
	}

	/**
	 * Extract the real host.
	 *
	 * @method clientHost
	 * @static
	 * @param {string} $directIp
	 * @param {array} $headers
	 * @return {string}
	 */
	static function clientHost($directIp, $headers)
	{
		if (self::isTrusted($directIp)) {
			$headerName = strtolower(Q_Config::get(
				'Q', 'webserver', 'proxy', 'headers', 'host',
				'x-forwarded-host'
			));
			$host = $headers[$headerName] ?? '';
			if ($host) return $host;
		}
		return $headers['host'] ?? 'localhost';
	}

	/**
	 * Check if an IP is a trusted proxy.
	 *
	 * @method isTrusted
	 * @static
	 * @param {string} $ip
	 * @return {boolean}
	 */
	static function isTrusted($ip)
	{
		if (self::$trusted === null) {
			self::$trusted = Q_Config::get(
				'Q', 'webserver', 'proxy', 'trusted',
				array('127.0.0.1', '::1')
			);
		}
		foreach (self::$trusted as $range) {
			if (strpos($range, '/') !== false) {
				if (self::ipInCidr($ip, $range)) return true;
			} else {
				if ($ip === $range) return true;
			}
		}
		return false;
	}

	/**
	 * Check if IP is within a CIDR range.
	 */
	static function ipInCidr($ip, $cidr)
	{
		// IPv4 and IPv6 alike, compared as packed bytes. This was ip2long()
		// only, so an IPv6 range in the trusted list (a CDN's, or fc00::/7
		// for a private network) matched nothing, and "/0" built a wrong
		// mask on 64-bit PHP.
		$parts = explode('/', $cidr, 2);
		if (count($parts) !== 2 or !ctype_digit($parts[1])) return false;
		$a = @inet_pton(trim((string) $ip, '[]'));
		$b = @inet_pton(trim($parts[0], '[]'));
		if ($a === false || $b === false || strlen($a) !== strlen($b)) return false;
		$bits = (int) $parts[1];
		if ($bits > strlen($a) * 8) return false;
		$whole = intdiv($bits, 8);
		if (strncmp($a, $b, $whole) !== 0) return false;
		$rest = $bits % 8;
		if ($rest === 0) return true;
		$mask = (0xff << (8 - $rest)) & 0xff;
		return (ord($a[$whole]) & $mask) === (ord($b[$whole]) & $mask);
	}
}
