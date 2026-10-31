<?php

/**
 * GraphQL subscriptions over a WebSocket: the protocol graphql-transport-ws
 * (https://github.com/enisdenjo/graphql-ws/blob/master/PROTOCOL.md), spoken by
 * this server and bridged to the application's event stream.
 *
 * The application answers subscriptions as Server-Sent Events on a path (the
 * graphql-sse "distinct connections" protocol: an "event: next" with the
 * answer as data for each result, "event: complete" when it is finished). A
 * browser that wants a WebSocket instead connects to the same path with
 * Sec-WebSocket-Protocol: graphql-transport-ws. This class keeps that
 * connection in the server process and, for every "subscribe" message, sends
 * the application an ordinary POST of the subscription with
 * Accept: text/event-stream through the worker pool -- exactly what an SSE
 * client would send -- and turns each event of the answer into a "next"
 * message, its end into "complete".
 *
 * So the application has one implementation for both transports, the server
 * process never runs application code, and nothing here holds a database or
 * anything else of the application's: a subscription is a pooled stream like
 * any other (it counts against Q.webserver.streamShare / maxStreams, it ends
 * at streamTimeout). When the application ends a stream without "complete"
 * (it ran for as long as it may), the subscription is opened again with
 * Last-Event-ID, so the client sees one subscription that never stops.
 *
 * The client's credentials: the handshake's own headers (Cookie, Authorization)
 * go with every request, and the connection_init payload may add
 * Authorization, X-Api-Key, X-Siteaccess and Accept-Language (a browser cannot
 * set headers on a WebSocket). A string "token" in the payload becomes
 * Authorization: Bearer.
 *
 * Configuration, Q.webserver.graphqlWs:
 *   paths             the paths that speak it, e.g. ["/api/graphql/subscribe"]
 *                     (default []: nowhere; the application's configuration names them)
 *   initTimeout       seconds a client has to send connection_init (10)
 *   idleTimeout       seconds a connection may stay open without a subscription (60)
 *   pingInterval      seconds between two pings to the client (30; 0: none)
 *   maxSubscriptions  subscriptions one connection may have open (20)
 *
 * @class Q_WebServer_GraphQLWs
 */
class Q_WebServer_GraphQLWs
{
	const PROTOCOL = 'graphql-transport-ws';

	/** Close codes of the protocol. */
	const CLOSE_BAD_REQUEST = 4400;
	const CLOSE_UNAUTHORIZED = 4401;
	const CLOSE_INIT_TIMEOUT = 4408;
	const CLOSE_SUBSCRIBER_EXISTS = 4409;
	const CLOSE_TOO_MANY_INITS = 4429;

	/** The connection_init keys passed on as request headers. */
	const PASSED_HEADERS = array('authorization', 'x-api-key', 'x-siteaccess', 'accept-language');

	/** @var array socket key => connection state */
	static $connections = array();

	/** @var boolean whether the timer of the connections runs */
	static $timer = false;

	/** @var callable|null tests: function ($socketKey, $text) instead of a frame */
	static $send = null;

	/** @var callable|null tests: function ($socketKey, $code, $reason) instead of a close */
	static $close = null;

	/** @var callable|null tests: function ($parsed, $scriptPath, $responder) instead of the pool */
	static $dispatch = null;

	/** @var callable|null tests: function () returning the time */
	static $clock = null;

	/**
	 * The settings, with their defaults.
	 *
	 * @method settings
	 * @static
	 * @return {array}
	 */
	static function settings()
	{
		$c = class_exists('Q_Config', false) ? (array) Q_Config::get('Q', 'webserver', 'graphqlWs', array()) : array();
		return array(
			'paths' => isset($c['paths']) && is_array($c['paths']) ? array_values(array_filter($c['paths'], 'is_string')) : array(),
			'initTimeout' => max(1, (int) ($c['initTimeout'] ?? 10)),
			'idleTimeout' => max(0, (int) ($c['idleTimeout'] ?? 60)),
			'pingInterval' => max(0, (int) ($c['pingInterval'] ?? 30)),
			'maxSubscriptions' => max(1, (int) ($c['maxSubscriptions'] ?? 20)),
		);
	}

	/**
	 * Whether a WebSocket handshake asks for this protocol on a path that
	 * speaks it.
	 *
	 * @method wants
	 * @static
	 * @param {array} $parsed the request
	 * @param {string} $path its path, without the query
	 * @return {boolean}
	 */
	static function wants($parsed, $path)
	{
		if (strtolower((string) ($parsed['headers']['upgrade'] ?? '')) !== 'websocket') return false;
		$offered = array_map('trim', explode(',', strtolower((string) ($parsed['headers']['sec-websocket-protocol'] ?? ''))));
		if (!in_array(self::PROTOCOL, $offered, true)) return false;
		return in_array($path, self::settings()['paths'], true);
	}

	/**
	 * Take a connection over: the handshake, and the state its messages need.
	 *
	 * @method accept
	 * @static
	 * @param {resource} $client
	 * @param {array} $parsed the handshake request
	 * @param {string} $path
	 * @param {string|null} $scriptPath the script the path resolves to
	 * @param {string|null} $rootDir the document root it was resolved in
	 * @return {boolean} true: the server keeps the socket open
	 */
	static function accept($client, $parsed, $path, $scriptPath, $rootDir = null)
	{
		if ($scriptPath === null) {
			Q_WebServer::sendResponse($client, 404, "Not Found\n");
			return false;
		}
		$ok = Q_WebSocket::upgrade($client, $parsed['headers'],
			function ($sk, $text) { Q_WebServer_GraphQLWs::onMessage($sk, $text); },
			null, $path, self::PROTOCOL,
			function ($sk) { Q_WebServer_GraphQLWs::onClose($sk); });
		if (!$ok) return false;
		$parsed['_socket'] = $client;
		self::open((int) $client, $parsed, $path, $scriptPath, $rootDir);
		return true;
	}

	/**
	 * The state of a new connection (also what the tests start from).
	 *
	 * @method open
	 * @static
	 */
	static function open($sk, $parsed, $path, $scriptPath, $rootDir = null)
	{
		$now = self::now();
		self::$connections[$sk] = array(
			'socket' => is_resource($parsed['_socket'] ?? null) ? $parsed['_socket'] : null,
			'request' => $parsed, 'path' => $path, 'script' => $scriptPath, 'root' => $rootDir,
			'opened' => $now, 'acked' => false, 'params' => array(), 'subscriptions' => array(),
			'idleSince' => $now, 'lastPing' => $now,
		);
		self::startTimer();
	}

	/**
	 * A text message from the client.
	 *
	 * @method onMessage
	 * @static
	 * @param {integer} $sk
	 * @param {string} $text
	 */
	static function onMessage($sk, $text)
	{
		if (!isset(self::$connections[$sk])) return;
		$msg = json_decode((string) $text, true);
		if (!is_array($msg) or !isset($msg['type']) or !is_string($msg['type'])) {
			self::closeWith($sk, self::CLOSE_BAD_REQUEST, 'Invalid message received');
			return;
		}
		$c =& self::$connections[$sk];
		switch ($msg['type']) {
		case 'connection_init':
			if ($c['acked']) {
				self::closeWith($sk, self::CLOSE_TOO_MANY_INITS, 'Too many initialisation requests');
				return;
			}
			$c['params'] = is_array($msg['payload'] ?? null) ? $msg['payload'] : array();
			$c['acked'] = true;
			self::send($sk, array('type' => 'connection_ack'));
			return;
		case 'ping':
			self::send($sk, array('type' => 'pong') + (isset($msg['payload']) ? array('payload' => $msg['payload']) : array()));
			return;
		case 'pong':
			return;
		case 'subscribe':
			if (!$c['acked']) {
				self::closeWith($sk, self::CLOSE_UNAUTHORIZED, 'Unauthorized');
				return;
			}
			$id = $msg['id'] ?? null;
			if (!is_string($id) or $id === '' or strlen($id) > 128 or !is_array($msg['payload'] ?? null)) {
				self::closeWith($sk, self::CLOSE_BAD_REQUEST, 'Invalid message received');
				return;
			}
			if (isset($c['subscriptions'][$id])) {
				self::closeWith($sk, self::CLOSE_SUBSCRIBER_EXISTS, 'Subscriber for ' . $id . ' already exists');
				return;
			}
			if (count($c['subscriptions']) >= self::settings()['maxSubscriptions']) {
				self::send($sk, array('id' => $id, 'type' => 'error', 'payload' => array(array(
					'message' => 'Too many subscriptions on this connection.',
					'extensions' => array('code' => 'SUBSCRIPTION_LIMIT')))));
				return;
			}
			$c['subscriptions'][$id] = array('payload' => $msg['payload'], 'buffer' => '', 'lastEventId' => null,
				'started' => self::now(), 'done' => false, 'streaming' => false, 'resumes' => 0);
			unset($c);
			self::start($sk, $id);
			return;
		case 'complete':
			$id = $msg['id'] ?? null;
			if (is_string($id) and isset($c['subscriptions'][$id])) {
				// the client is done with it: the stream ends at its next
				// piece or probe, the worker at its next flush
				$c['subscriptions'][$id]['done'] = true;
				unset($c['subscriptions'][$id]);
				if (!$c['subscriptions']) $c['idleSince'] = self::now();
			}
			return;
		default:
			self::closeWith($sk, self::CLOSE_BAD_REQUEST, 'Invalid message received');
		}
	}

	/**
	 * Send the application the request of a subscription (or open it again).
	 *
	 * @method start
	 * @static
	 * @param {integer} $sk
	 * @param {string} $id
	 */
	static function start($sk, $id)
	{
		$c = self::$connections[$sk] ?? null;
		$s = $c['subscriptions'][$id] ?? null;
		if (!$c or !$s or $s['done']) return;
		$parsed = self::requestFor($c, $s);
		self::$connections[$sk]['subscriptions'][$id]['started'] = self::now();
		self::$connections[$sk]['subscriptions'][$id]['buffer'] = '';
		$responder = self::responder($sk, $id);
		if (self::$dispatch) {
			call_user_func(self::$dispatch, $parsed, $c['script'], $responder);
			return;
		}
		if (!Q_WebServer::$pool) {
			self::finish($sk, $id, array(array('message' => 'This server runs no PHP workers.', 'extensions' => array('code' => 'INTERNAL'))));
			return;
		}
		$saved = Q_WebServer::$rootDir;
		if ($c['root'] !== null) Q_WebServer::$rootDir = $c['root'];
		try {
			Q_WebServer::$pool->dispatch($c['socket'], $parsed, $c['script'], $responder);
		} finally {
			Q_WebServer::$rootDir = $saved;
		}
	}

	/**
	 * The POST a subscription is sent to the application as.
	 *
	 * @method requestFor
	 * @static
	 * @param {array} $c the connection
	 * @param {array} $s the subscription
	 * @return {array} a parsed request
	 */
	static function requestFor($c, $s)
	{
		$parsed = $c['request'];
		$headers = array();
		foreach ((array) ($parsed['headers'] ?? array()) as $name => $value) {
			$lower = strtolower((string) $name);
			if ($lower === 'upgrade' or $lower === 'connection' or $lower === 'content-length'
				or $lower === 'content-type' or $lower === 'last-event-id' or strpos($lower, 'sec-websocket-') === 0) continue;
			$headers[$lower] = $value;
		}
		foreach ((array) $c['params'] as $name => $value) {
			$lower = strtolower((string) $name);
			if (!is_string($value) or $value === '' or preg_match('/[\r\n\0]/', $value)) continue;
			if (in_array($lower, self::PASSED_HEADERS, true)) $headers[$lower] = $value;
			elseif ($lower === 'token' and !isset($headers['authorization'])) $headers['authorization'] = 'Bearer ' . $value;
		}
		$body = json_encode($s['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$headers['content-type'] = 'application/json';
		$headers['accept'] = 'text/event-stream';
		$headers['content-length'] = (string) strlen((string) $body);
		if ($s['lastEventId'] !== null) $headers['last-event-id'] = $s['lastEventId'];
		$parsed['method'] = 'POST';
		$parsed['uri'] = $c['path'];
		$parsed['path'] = $c['path'];
		$parsed['query'] = '';
		$parsed['headers'] = $headers;
		$parsed['rawHeaders'] = array();
		$parsed['body'] = (string) $body;
		unset($parsed['_socket'], $parsed['poolRetries']);
		return $parsed;
	}

	/**
	 * What the pool calls with the answer of a subscription's request: a
	 * whole answer (a refusal), or a stream in parts.
	 *
	 * @method responder
	 * @static
	 * @param {integer} $sk
	 * @param {string} $id
	 * @return {callable}
	 */
	static function responder($sk, $id)
	{
		return function ($resp) use ($sk, $id) {
			$s = Q_WebServer_GraphQLWs::$connections[$sk]['subscriptions'][$id] ?? null;
			$alive = ($s !== null && !$s['done']);
			if (is_array($resp) and isset($resp['_stream'])) {
				switch ($resp['_stream']) {
				case 'head':
					if ($alive) Q_WebServer_GraphQLWs::$connections[$sk]['subscriptions'][$id]['streaming'] = true;
					return $alive;
				case 'data':
					return $alive && Q_WebServer_GraphQLWs::receive($sk, $id, (string) ($resp['body'] ?? ''));
				case 'probe':
					return $alive;
				case 'end':
				case 'reset':
					if ($alive) Q_WebServer_GraphQLWs::ended($sk, $id);
					return false;
				}
				return false;
			}
			if (!$alive) return;
			// A whole answer: the subscription was refused (or was a query,
			// answered at once)
			Q_WebServer_GraphQLWs::answered($sk, $id, is_array($resp) ? $resp : array());
		};
	}

	/**
	 * A piece of a subscription's event stream: each complete event becomes
	 * a message.
	 *
	 * @method receive
	 * @static
	 * @return {boolean} false: the stream should end
	 */
	static function receive($sk, $id, $data)
	{
		$s =& self::$connections[$sk]['subscriptions'][$id];
		$s['buffer'] .= str_replace("\r\n", "\n", $data);
		if (strlen($s['buffer']) > 4194304) {
			unset($s);
			self::finish($sk, $id, array(array('message' => 'An event is too large.', 'extensions' => array('code' => 'INTERNAL'))));
			return false;
		}
		while (($end = strpos($s['buffer'], "\n\n")) !== false) {
			$block = substr($s['buffer'], 0, $end);
			$s['buffer'] = (string) substr($s['buffer'], $end + 2);
			$event = 'message';
			$lines = array();
			$hasData = false;
			foreach (explode("\n", $block) as $line) {
				if ($line === '' or $line[0] === ':') continue;
				$colon = strpos($line, ':');
				$field = $colon === false ? $line : substr($line, 0, $colon);
				$value = $colon === false ? '' : ltrim(substr($line, $colon + 1), ' ');
				if ($field === 'event') $event = $value;
				elseif ($field === 'data') { $lines[] = $value; $hasData = true; }
				elseif ($field === 'id') $s['lastEventId'] = $value;
			}
			if ($event === 'complete') {
				unset($s);
				self::finish($sk, $id, null);
				return false;
			}
			if (!$hasData or ($event !== 'next' and $event !== 'message')) continue;
			$payload = json_decode(implode("\n", $lines), true);
			if (!is_array($payload)) continue;
			if (!self::send($sk, array('id' => $id, 'type' => 'next', 'payload' => $payload))) return false;
		}
		return true;
	}

	/**
	 * A stream ended without "complete": it ran for as long as the
	 * application lets one run, or its worker went. Opened again from where
	 * it was, unless it keeps ending at once.
	 *
	 * @method ended
	 * @static
	 */
	static function ended($sk, $id)
	{
		$s = self::$connections[$sk]['subscriptions'][$id] ?? null;
		if (!$s or $s['done']) return;
		$quick = self::now() - $s['started'] < 5;
		$resumes = $quick ? $s['resumes'] + 1 : 0;
		if ($resumes > 3) {
			self::finish($sk, $id, array(array('message' => 'The subscription stream keeps ending.', 'extensions' => array('code' => 'INTERNAL'))));
			return;
		}
		self::$connections[$sk]['subscriptions'][$id]['resumes'] = $resumes;
		self::$connections[$sk]['subscriptions'][$id]['streaming'] = false;
		self::start($sk, $id);
	}

	/**
	 * A whole answer to a subscription's request.
	 *
	 * @method answered
	 * @static
	 */
	static function answered($sk, $id, $resp)
	{
		$status = (int) ($resp['status'] ?? 500);
		$body = json_decode((string) ($resp['body'] ?? ''), true);
		if (is_array($body) and !empty($body['errors']) and is_array($body['errors'])) {
			self::finish($sk, $id, array_values($body['errors']));
			return;
		}
		if ($status >= 200 and $status < 300 and is_array($body) and array_key_exists('data', $body)) {
			self::send($sk, array('id' => $id, 'type' => 'next', 'payload' => $body));
			self::finish($sk, $id, null);
			return;
		}
		self::finish($sk, $id, array(array('message' => 'The subscription was refused (HTTP ' . $status . ').',
			'extensions' => array('code' => $status === 404 ? 'NOT_FOUND' : 'INTERNAL'))));
	}

	/**
	 * A subscription is over: "complete", or "error" with its errors.
	 *
	 * @method finish
	 * @static
	 */
	static function finish($sk, $id, $errors)
	{
		if (!isset(self::$connections[$sk]['subscriptions'][$id])) return;
		unset(self::$connections[$sk]['subscriptions'][$id]);
		if (!self::$connections[$sk]['subscriptions']) self::$connections[$sk]['idleSince'] = self::now();
		self::send($sk, $errors === null ? array('id' => $id, 'type' => 'complete')
			: array('id' => $id, 'type' => 'error', 'payload' => $errors));
	}

	/**
	 * The connection ended: every subscription ends with it (their streams at
	 * their next piece or probe).
	 *
	 * @method onClose
	 * @static
	 */
	static function onClose($sk)
	{
		foreach (array_keys(self::$connections[$sk]['subscriptions'] ?? array()) as $id) {
			self::$connections[$sk]['subscriptions'][$id]['done'] = true;
		}
		unset(self::$connections[$sk]);
	}

	/**
	 * Once a second: connections that never sent connection_init, that stayed
	 * without a subscription too long, and the pings.
	 *
	 * @method tick
	 * @static
	 */
	static function tick()
	{
		$now = self::now();
		$settings = self::settings();
		foreach (array_keys(self::$connections) as $sk) {
			$c = self::$connections[$sk] ?? null;
			if (!$c) continue;
			if (!$c['acked'] and $now - $c['opened'] >= $settings['initTimeout']) {
				self::closeWith($sk, self::CLOSE_INIT_TIMEOUT, 'Connection initialisation timeout');
				continue;
			}
			if ($settings['idleTimeout'] > 0 and !$c['subscriptions'] and $now - $c['idleSince'] >= $settings['idleTimeout']) {
				self::closeWith($sk, 1000, 'Idle');
				continue;
			}
			if ($settings['pingInterval'] > 0 and $c['acked'] and $now - $c['lastPing'] >= $settings['pingInterval']) {
				self::$connections[$sk]['lastPing'] = $now;
				self::send($sk, array('type' => 'ping'));
			}
		}
	}

	protected static function startTimer()
	{
		if (self::$timer or !class_exists('Q_Evented', false) or self::$clock !== null) return;
		self::$timer = true;
		Q_Evented::repeat(1.0, function () { Q_WebServer_GraphQLWs::tick(); });
	}

	protected static function now()
	{
		return self::$clock ? (float) call_user_func(self::$clock) : microtime(true);
	}

	/**
	 * @return {boolean} false: the connection is gone
	 */
	protected static function send($sk, $message)
	{
		$text = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (self::$send) return call_user_func(self::$send, $sk, $text) !== false;
		$socket = Q_WebSocket::$clients[$sk]['socket'] ?? null;
		if (!is_resource($socket)) return false;
		return Q_WebSocket::encodeAndSend($socket, 0x1, $text) !== false;
	}

	protected static function closeWith($sk, $code, $reason)
	{
		if (self::$close) {
			call_user_func(self::$close, $sk, $code, $reason);
			self::onClose($sk);
			return;
		}
		$socket = Q_WebSocket::$clients[$sk]['socket'] ?? null;
		if (is_resource($socket)) Q_WebSocket::encodeAndSend($socket, 0x8, pack('n', $code) . substr($reason, 0, 120));
		Q_WebSocket::disconnect($sk);
		self::onClose($sk);
	}
}
