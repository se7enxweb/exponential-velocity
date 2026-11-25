<?php
/**
 * @module Q
 */

/**
 * Hands a request, by its Host, to another HTTP server: a front listener that
 * routes every host it is told about to the server that serves it, as a
 * reverse proxy does. Each upstream can be another Qbix server running as a
 * different user, on a Unix socket or a loopback port.
 *
 * Config:
 *   "Q": { "webserver": { "upstreams": {
 *     "example.com":     "unix:/run/app/example.sock",
 *     "www.example.com": "unix:/run/app/example.sock",
 *     "shop.example":    "127.0.0.1:7181"
 *   }, "upstreamTimeout": 300 } }
 *
 * What is sent: the request line, the request headers (hop-by-hop ones
 * removed), the body as the server read it, Connection: close, and
 * X-Forwarded-For / X-Forwarded-Proto / X-Forwarded-Host for the upstream to
 * read (it should trust this server's address, as Q.webserver.proxy.trusted
 * trusts loopback by default). What comes back is passed to the client byte
 * for byte as it arrives, so a streamed response streams; the client's
 * connection is closed after it, as the upstream closes its own.
 *
 * Not passed on: a WebSocket or other Upgrade (answered 501), and HTTP/2
 * requests (answered 502 by the HTTP/2 path, which never runs a host that
 * has an upstream locally). An upstream that cannot be reached is answered
 * 502, one that does not answer in Q.webserver.upstreamTimeout seconds 504.
 *
 * @class Q_WebServer_Upstream
 * @static
 */
class Q_WebServer_Upstream
{
	/** Request headers that describe this hop only, never passed on. */
	static $hopByHop = array('connection', 'keep-alive', 'proxy-connection', 'te', 'trailer',
		'transfer-encoding', 'upgrade', 'proxy-authorization', 'x-forwarded-for', 'x-forwarded-proto',
		'x-forwarded-host', 'content-length');

	/** @var array relays in flight, by client key */
	static $relays = array();

	/**
	 * The upstream address for a Host, or null when the host is served here.
	 * @method target
	 * @static
	 * @param {string} $host Host header value (port is ignored)
	 * @return {string|null} a stream_socket_client() address
	 */
	static function target($host)
	{
		// A host in fallback goes back to the server that served it before.
		if (Q_WebServer_HostFallback::configured() and ($fb = Q_WebServer_HostFallback::target($host)) !== null) {
			return $fb;
		}
		$map = Q_Config::get('Q', 'webserver', 'upstreams', array());
		if (!is_array($map) or !$map) return null;
		$host = strtolower(preg_replace('/:\d+$/', '', (string) $host));
		$t = $map[$host] ?? null;
		if (!is_string($t) or $t === '') return null;
		return self::address($t);
	}

	/**
	 * Where a write must go when this node is a read replica: the primary's
	 * server, or null when the request is answered here.
	 *
	 * For a replicated database whose replicas are read-only -- LiteFS, which
	 * keeps a file in its mount naming the current primary while this node is
	 * a replica, and no such file while this node is the primary -- every
	 * request that may write is passed on to the primary:
	 *
	 *   "Q": { "webserver": { "hosts": { "shop.example": {
	 *     "root": "...",
	 *     "writeForward": {
	 *       "primaryFile": "/var/lib/litefs/shop/.primary",
	 *       "nodes": { "node-a": "10.0.0.1:7090", "node-b": "10.0.0.2:7090" },
	 *       "methods": ["POST", "PUT", "PATCH", "DELETE"]
	 *     } } } } }
	 *
	 * The file's first line is the primary's node name, looked up in "nodes"
	 * (a name not listed is used as host:port as it is). GET, HEAD and
	 * OPTIONS stay local unless "methods" lists them.
	 * @method writeTarget
	 * @static
	 * @param {array} $parsed
	 * @return {string|null} an address() result
	 */
	static function writeTarget($parsed)
	{
		$host = strtolower(preg_replace('/:\d+$/', '', (string) ($parsed['headers']['host'] ?? '')));
		$wf = $host !== '' ? Q_Config::get('Q', 'webserver', 'hosts', $host, 'writeForward', null) : null;
		if (!is_array($wf) or empty($wf['primaryFile'])) return null;
		// A write already forwarded once is answered here, never sent on again (no loop while the lease moves).
		if (isset($parsed['headers']['x-qbix-forwarded-write'])) return null;
		$methods = isset($wf['methods']) && is_array($wf['methods']) ? $wf['methods'] : array('POST', 'PUT', 'PATCH', 'DELETE');
		if (!in_array(strtoupper((string) $parsed['method']), array_map('strtoupper', $methods), true)) return null;
		clearstatcache(true, $wf['primaryFile']);
		if (!is_file($wf['primaryFile'])) return null;      // no file: this node is the primary
		$name = trim((string) strtok((string) @file_get_contents($wf['primaryFile']), "\n"));
		if ($name === '') return null;
		$t = isset($wf['nodes'][$name]) ? (string) $wf['nodes'][$name] : $name;
		return self::address($t);
	}

	/** "unix:/x.sock", "unix:///x.sock", "tcp://h:p", "h:p" -> a stream_socket_client() address. */
	static function address($t)
	{
		if (strncmp($t, 'unix://', 7) === 0) return $t;
		if (strncmp($t, 'unix:', 5) === 0) return 'unix://' . substr($t, 5);
		if (strncmp($t, 'tcp://', 6) === 0) return $t;
		return 'tcp://' . $t;
	}

	/**
	 * The request as it goes to the upstream.
	 * @method requestText
	 * @static
	 * @param {array} $parsed parseRequest()'s result
	 * @param {string} $clientIp the address of the peer this server got the request from (appended to X-Forwarded-For)
	 * @param {string} $proto http or https, as this server believes it
	 * @return {string}
	 */
	static function requestText($parsed, $clientIp, $proto, array $extra = array())
	{
		$out = $parsed['method'] . ' ' . $parsed['uri'] . " HTTP/1.1\r\n";
		$xff = '';
		foreach ((array) ($parsed['rawHeaders'] ?? array()) as $line) {
			$c = strpos($line, ':');
			if ($c === false) continue;
			$name = strtolower(trim(substr($line, 0, $c)));
			if ($name === 'x-forwarded-for') {
				$xff = trim(substr($line, $c + 1));
				continue;
			}
			if (in_array($name, self::$hopByHop, true)) continue;
			$out .= $line . "\r\n";
		}
		if (empty($parsed['rawHeaders'])) {
			foreach ((array) $parsed['headers'] as $k => $v) {
				if (in_array($k, self::$hopByHop, true)) continue;
				$out .= $k . ': ' . $v . "\r\n";
			}
			$xff = (string) ($parsed['headers']['x-forwarded-for'] ?? '');
		}
		// The chain as the next hop expects it: what came in, then the peer.
		$out .= 'X-Forwarded-For: ' . ($xff !== '' ? $xff . ', ' : '') . $clientIp . "\r\n";
		$out .= 'X-Forwarded-Proto: ' . $proto . "\r\n";
		$out .= 'X-Forwarded-Host: ' . ($parsed['headers']['host'] ?? '') . "\r\n";
		$body = (string) ($parsed['body'] ?? '');
		if ($body !== '' or in_array($parsed['method'], array('POST', 'PUT', 'PATCH'), true)) {
			$out .= 'Content-Length: ' . strlen($body) . "\r\n";
		}
		foreach ($extra as $line) $out .= $line . "\r\n";
		$out .= "Connection: close\r\n\r\n";
		return $out . $body;
	}

	/**
	 * Pass a request on. The caller has already taken the client out of the
	 * server's own bookkeeping; from here the relay owns the connection.
	 * @method relay
	 * @static
	 * @param {resource} $client
	 * @param {array} $parsed
	 * @param {string} $target an address() result
	 * @return {boolean} false (the connection is closed when the relay ends)
	 */
	static function relay($client, $parsed, $target, array $extra = array())
	{
		$key = (int) $client;
		if (!empty($parsed['headers']['upgrade'])) {
			self::answer($client, 501, 'Not Implemented');
			@fclose($client);
			return false;
		}
		$direct = Q_WebServer_Pool::peerAddressOf($client, false);
		$meta = @stream_get_meta_data($client);
		$tls = !empty($meta['crypto']);
		$proto = class_exists('Q_WebServer_Proxy', false) ? Q_WebServer_Proxy::clientProto($direct, $parsed['headers'], $tls)
			: ($tls ? 'https' : 'http');
		$errno = $errstr = null;
		$up = @stream_socket_client($target, $errno, $errstr, 3, STREAM_CLIENT_CONNECT);
		if (!is_resource($up)) {
			self::answer($client, 502, 'Bad Gateway');
			@fclose($client);
			if (class_exists('Q_WebServer_Log', false)) {
				Q_WebServer_Log::error("upstream $target for " . ($parsed['headers']['host'] ?? '') . " unreachable: $errstr");
			}
			return false;
		}
		stream_set_blocking($up, false);
		stream_set_blocking($client, false);
		$r = array(
			'client' => $client, 'up' => $up, 'toUp' => self::requestText($parsed, (string) $direct, $proto, $extra),
			'toClient' => '', 'upDone' => false, 'started' => microtime(true), 'got' => false,
			'w' => array(),
		);
		self::$relays[$key] = $r;
		self::$relays[$key]['w']['upW'] = Q_Evented::onWritable($up, function () use ($key) { self::pump($key); });
		self::$relays[$key]['w']['upR'] = Q_Evented::onReadable($up, function () use ($key) { self::fromUpstream($key); });
		$timeout = max(1, (int) Q_Config::get('Q', 'webserver', 'upstreamTimeout', 300));
		// Until the first byte of the answer; a response that has started may stream as long as it likes.
		self::$relays[$key]['w']['t'] = Q_Evented::delay($timeout, function () use ($key) {
			if (!isset(self::$relays[$key])) return;
			unset(self::$relays[$key]['w']['t']);
			if (self::$relays[$key]['got']) return;
			self::answer(self::$relays[$key]['client'], 504, 'Gateway Timeout');
			self::finish($key);
		});
		return false;
	}

	/** Write what is pending, each direction. */
	static function pump($key)
	{
		if (!isset(self::$relays[$key])) return;
		$r =& self::$relays[$key];
		if ($r['toUp'] !== '') {
			$n = @fwrite($r['up'], $r['toUp']);
			if ($n === false) { self::finish($key); return; }
			$r['toUp'] = (string) substr($r['toUp'], $n);
		}
		if ($r['toUp'] === '' and isset($r['w']['upW'])) {
			Q_Evented::cancel($r['w']['upW']);
			unset($r['w']['upW']);
		}
		if ($r['toClient'] !== '') {
			$n = @fwrite($r['client'], $r['toClient']);
			if ($n === false) { self::finish($key); return; }
			$r['toClient'] = (string) substr($r['toClient'], $n);
		}
		if ($r['toClient'] !== '' and !isset($r['w']['cW'])) {
			$r['w']['cW'] = Q_Evented::onWritable($r['client'], function () use ($key) { self::pump($key); });
		} elseif ($r['toClient'] === '' and isset($r['w']['cW'])) {
			Q_Evented::cancel($r['w']['cW']);
			unset($r['w']['cW']);
		}
		if ($r['upDone'] and $r['toClient'] === '') self::finish($key);
	}

	static function fromUpstream($key)
	{
		if (!isset(self::$relays[$key])) return;
		$r =& self::$relays[$key];
		// Do not read faster than the client takes it.
		if (strlen($r['toClient']) > 1048576) return;
		$chunk = @fread($r['up'], 65536);
		if ($chunk === false or ($chunk === '' and feof($r['up']))) {
			$r['upDone'] = true;
			if (isset($r['w']['upR'])) { Q_Evented::cancel($r['w']['upR']); unset($r['w']['upR']); }
			if (!$r['got']) {
				self::answer($r['client'], 502, 'Bad Gateway');
				self::finish($key);
				return;
			}
		} elseif ($chunk !== '') {
			$r['got'] = true;
			$r['toClient'] .= $chunk;
		}
		self::pump($key);
	}

	static function finish($key)
	{
		if (!isset(self::$relays[$key])) return;
		$r = self::$relays[$key];
		foreach ($r['w'] as $id) Q_Evented::cancel($id);
		if (is_resource($r['up'])) @fclose($r['up']);
		if (is_resource($r['client'])) {
			@stream_socket_shutdown($r['client'], STREAM_SHUT_WR);
			@fclose($r['client']);
		}
		unset(self::$relays[$key]);
	}

	/** A short answer of the front's own, on a client no relay has written to yet. */
	static function answer($client, $status, $text)
	{
		if (!is_resource($client)) return;
		$body = "$status $text\n";
		@fwrite($client, "HTTP/1.1 $status $text\r\nContent-Type: text/plain\r\nContent-Length: "
			. strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
	}
}
