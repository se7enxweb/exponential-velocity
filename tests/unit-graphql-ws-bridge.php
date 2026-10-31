<?php

/**
 * GraphQL subscriptions over a WebSocket, bridged to the application's event
 * stream (Q_WebServer_GraphQLWs; the protocol graphql-transport-ws).
 *
 * The protocol's rules, message by message: connection_init and its ack, a
 * second init, a subscribe before the ack, a duplicate id, ping and pong, an
 * invalid message. Then the bridge: a subscribe becomes a POST of the
 * subscription with Accept: text/event-stream to the same path, carrying the
 * handshake's headers and the connection_init credentials and never the
 * WebSocket ones; the events of the stream become "next" messages, split
 * across pieces however they arrive; "event: complete" ends the subscription;
 * a refusal (a whole JSON answer) becomes "error"; a stream that ends without
 * "complete" is opened again with Last-Event-ID; the client's "complete" and
 * the connection's end stop the stream at its next piece or probe; and the
 * timers: the connection_init timeout, the idle timeout, the pings.
 *
 * No server, no socket, no worker: the class's seams take their place.
 *
 *   php tests/unit-graphql-ws-bridge.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q/WebServer/GraphQLWs.php';

$pass = 0;
$fail = 0;

function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what,
		var_export($got, true), var_export($want, true));
}

$B = 'Q_WebServer_GraphQLWs';
$now = 1000.0;
$sent = array();
$closed = array();
$requests = array();
$B::$clock = function () use (&$now) { return $now; };
$B::$send = function ($sk, $text) use (&$sent) { $sent[] = array($sk, json_decode($text, true)); return true; };
$B::$close = function ($sk, $code, $reason) use (&$closed) { $closed[] = array($sk, $code, $reason); };
$B::$dispatch = function ($parsed, $script, $responder) use (&$requests) { $requests[] = array($parsed, $script, $responder); };

function connect($sk)
{
	Q_WebServer_GraphQLWs::open($sk, array(
		'method' => 'GET', 'uri' => '/api/graphql/subscribe', 'path' => '/api/graphql/subscribe', 'query' => '',
		'headers' => array('host' => 'example.com', 'upgrade' => 'websocket', 'connection' => 'Upgrade',
			'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ==', 'sec-websocket-protocol' => 'graphql-transport-ws',
			'cookie' => 'a=b', 'origin' => 'https://example.com'),
		'body' => '', 'clientIp' => '10.0.0.1', '_remoteAddr' => '10.0.0.1'),
		'/api/graphql/subscribe', '/srv/site/index_rest.php');
}

function msg($sk, array $m)
{
	Q_WebServer_GraphQLWs::onMessage($sk, json_encode($m));
}

// ── which handshakes are taken ───────────────────────────────
$ws = array('headers' => array('upgrade' => 'websocket', 'sec-websocket-protocol' => 'graphql-ws, graphql-transport-ws'));
check('no path configured: never', $B::wants($ws, '/api/graphql/subscribe'), false);
class Q_Config { static $c = array(); static function get() { return self::$c; } }
Q_Config::$c = array('paths' => array('/api/graphql/subscribe'));
check('the protocol on a configured path', $B::wants($ws, '/api/graphql/subscribe'), true);
check('another path', $B::wants($ws, '/socket.io/'), false);
check('another protocol', $B::wants(array('headers' => array('upgrade' => 'websocket', 'sec-websocket-protocol' => 'graphql-ws')), '/api/graphql/subscribe'), false);
check('not a WebSocket', $B::wants(array('headers' => array('sec-websocket-protocol' => 'graphql-transport-ws')), '/api/graphql/subscribe'), false);
check('the settings\' defaults', array_diff_key($B::settings(), array('paths' => 1)),
	array('initTimeout' => 10, 'idleTimeout' => 60, 'pingInterval' => 30, 'maxSubscriptions' => 20));

// ── the protocol ─────────────────────────────────────────────
connect(1);
msg(1, array('type' => 'subscribe', 'id' => 'a', 'payload' => array('query' => 'subscription { contentMoved { event } }')));
check('subscribe before connection_init: 4401', $closed, array(array(1, 4401, 'Unauthorized')));
check('nothing sent to the application', $requests, array());

$closed = array();
connect(2);
msg(2, array('type' => 'connection_init', 'payload' => array('Authorization' => 'Bearer tok', 'x-siteaccess' => 'eng', 'cookie' => 'evil=1', 'x-evil' => "a\r\nb")));
check('connection_ack', $sent, array(array(2, array('type' => 'connection_ack'))));
msg(2, array('type' => 'ping', 'payload' => array('t' => 1)));
check('ping: pong with the payload', end($sent), array(2, array('type' => 'pong', 'payload' => array('t' => 1))));
msg(2, array('type' => 'subscribe', 'id' => 's1', 'payload' => array('query' => 'subscription { contentPublished(classes: ["article"]) { objectId } }', 'variables' => null)));
check('one request to the application', count($requests), 1);
list($req, $script, $responder) = $requests[0];
check('it is a POST to the same path', array($req['method'], $req['uri'], $req['path']), array('POST', '/api/graphql/subscribe', '/api/graphql/subscribe'));
check('to the script of the path', $script, '/srv/site/index_rest.php');
check('as an event stream', $req['headers']['accept'], 'text/event-stream');
check('the subscription as its JSON body', json_decode($req['body'], true)['query'], 'subscription { contentPublished(classes: ["article"]) { objectId } }');
check('with its length', $req['headers']['content-length'], (string) strlen($req['body']));
check('the init credentials as headers', array($req['headers']['authorization'], $req['headers']['x-siteaccess']), array('Bearer tok', 'eng'));
check('the handshake\'s own headers', array($req['headers']['cookie'], $req['headers']['origin']), array('a=b', 'https://example.com'));
check('no WebSocket headers', array_values(array_filter(array_keys($req['headers']), function ($h) { return strpos($h, 'sec-websocket') === 0 || $h === 'upgrade' || $h === 'connection'; })), array());
check('no other init key becomes a header', array(isset($req['headers']['x-evil']), $req['headers']['cookie']), array(false, 'a=b'));
check('the address of the client', $req['clientIp'], '10.0.0.1');

msg(2, array('type' => 'subscribe', 'id' => 's1', 'payload' => array('query' => 'subscription { contentMoved { event } }')));
check('a second subscribe of the same id: 4409', end($closed), array(2, 4409, 'Subscriber for s1 already exists'));

// ── the stream ───────────────────────────────────────────────
$closed = array();
$sent = array();
$requests = array();
connect(3);
msg(3, array('type' => 'connection_init'));
msg(3, array('type' => 'subscribe', 'id' => 'x', 'payload' => array('query' => 'subscription { contentPublished { objectId } }')));
$r = $requests[0][2];
check('the head: go on', $r(array('_stream' => 'head', 'status' => 200, 'headers' => array())), true);
check('a comment: go on', $r(array('_stream' => 'data', 'body' => ": subscribed\n\n")), true);
$sent = array();
check('half an event', $r(array('_stream' => 'data', 'body' => "event: next\nid: 1.200\ndata: {\"data\":{\"contentPublished\"")), true);
check('nothing sent for half an event', $sent, array());
$r(array('_stream' => 'data', 'body' => ":{\"objectId\":5}}}\n\nevent: next\r\nid: 1.400\r\ndata: {\"data\":{\"contentPublished\":{\"objectId\":6}}}\r\n\r\n: heartbeat\nid: 1.500\n\n"));
check('two events, two next messages', $sent, array(
	array(3, array('id' => 'x', 'type' => 'next', 'payload' => array('data' => array('contentPublished' => array('objectId' => 5))))),
	array(3, array('id' => 'x', 'type' => 'next', 'payload' => array('data' => array('contentPublished' => array('objectId' => 6))))),
));
check('the last event id is kept, the heartbeat\'s too', $B::$connections[3]['subscriptions']['x']['lastEventId'], '1.500');
check('probe: open', $r(array('_stream' => 'probe')), true);

// the stream ran its time and ended without "complete": opened again from where it was
$now += 280;
$sent = array();
check('end: the old stream is over', $r(array('_stream' => 'end')), false);
check('a new request', count($requests), 2);
check('from the last event id', $requests[1][0]['headers']['last-event-id'], '1.500');
check('the client hears nothing of it', $sent, array());

// the application completes it
$r2 = $requests[1][2];
$r2(array('_stream' => 'head', 'status' => 200));
check('complete: the stream ends', $r2(array('_stream' => 'data', 'body' => "event: complete\ndata:\n\n")), false);
check('complete is sent', end($sent), array(3, array('id' => 'x', 'type' => 'complete')));
check('the subscription is gone', isset($B::$connections[3]['subscriptions']['x']), false);
check('its stream gets no more', $r2(array('_stream' => 'probe')), false);

// a refusal: a whole JSON answer
$sent = array();
msg(3, array('type' => 'subscribe', 'id' => 'y', 'payload' => array('query' => 'subscription { contentPublished { objectId } }')));
$r3 = end($requests)[2];
$r3(array('status' => 429, 'headers' => array('Content-Type' => 'application/json'),
	'body' => '{"errors":[{"message":"You have 5 subscriptions open, the most allowed.","extensions":{"code":"SUBSCRIPTION_LIMIT"}}]}'));
check('a refusal is an error message', $sent, array(array(3, array('id' => 'y', 'type' => 'error',
	'payload' => array(array('message' => 'You have 5 subscriptions open, the most allowed.', 'extensions' => array('code' => 'SUBSCRIPTION_LIMIT')))))));

// a query sent as a subscribe: one next, then complete
$sent = array();
msg(3, array('type' => 'subscribe', 'id' => 'q', 'payload' => array('query' => '{ translations { code } }')));
$r4 = end($requests)[2];
$r4(array('_stream' => 'head', 'status' => 200));
$r4(array('_stream' => 'data', 'body' => "event: next\ndata: {\"data\":{\"translations\":[]}}\n\nevent: complete\ndata:\n\n"));
check('a query: next and complete', array_column(array_column($sent, 1), 'type'), array('next', 'complete'));

// the client completes a subscription: its stream ends at the next piece
msg(3, array('type' => 'subscribe', 'id' => 'z', 'payload' => array('query' => 'subscription { contentMoved { event } }')));
$r5 = end($requests)[2];
$r5(array('_stream' => 'head', 'status' => 200));
msg(3, array('type' => 'complete', 'id' => 'z'));
check('after the client\'s complete the stream ends', $r5(array('_stream' => 'data', 'body' => ": heartbeat\n\n")), false);
$count = count($requests);
$r5(array('_stream' => 'end'));
check('and is not opened again', count($requests), $count);

// a stream that keeps ending at once gives up
msg(3, array('type' => 'subscribe', 'id' => 'w', 'payload' => array('query' => 'subscription { contentMoved { event } }')));
for ($i = 0; $i < 5; $i++) {
	$rw = end($requests)[2];
	$rw(array('_stream' => 'end'));
}
check('a stream that keeps ending: error', end($sent)[1]['type'] ?? null, 'error');
check('no more requests for it', isset($B::$connections[3]['subscriptions']['w']), false);

// the connection ends: every stream ends with it
msg(3, array('type' => 'subscribe', 'id' => 'v', 'payload' => array('query' => 'subscription { contentMoved { event } }')));
$r6 = end($requests)[2];
$B::onClose(3);
check('after the connection ended its streams end', $r6(array('_stream' => 'probe')), false);

// an invalid message
$closed = array();
connect(4);
$B::onMessage(4, 'not json');
check('an invalid message: 4400', $closed, array(array(4, 4400, 'Invalid message received')));
connect(5);
msg(5, array('type' => 'connection_init'));
msg(5, array('type' => 'connection_init'));
check('a second connection_init: 4429', end($closed), array(5, 4429, 'Too many initialisation requests'));

// ── the timers ───────────────────────────────────────────────
$closed = array();
$sent = array();
$B::$connections = array();
connect(6);
connect(7);
msg(7, array('type' => 'connection_init'));
$now += 11;
$B::tick();
check('no connection_init within 10 s: 4408', $closed, array(array(6, 4408, 'Connection initialisation timeout')));
$now += 20;
$sent = array();
$B::tick();
check('a ping after 30 s', $sent, array(array(7, array('type' => 'ping'))));
$now += 30;
$B::tick();
check('without a subscription for 60 s: closed', end($closed), array(7, 1000, 'Idle'));

echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
