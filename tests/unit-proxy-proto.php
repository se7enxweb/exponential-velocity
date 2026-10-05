#!/usr/bin/env php
<?php

/**
 * Which protocol a request came in on: Q_WebServer_Proxy::clientProto() and
 * Q_WebServer_Proxy::isHttps().
 *
 * A TLS connection is https from anyone. A plain one is http, unless the
 * connection comes from a trusted proxy (Q.webserver.proxy.trusted, the list
 * that also decides REMOTE_ADDR) and that proxy forwards https in
 * X-Forwarded-Proto (or the configured header), CloudFront-Forwarded-Proto or
 * CF-Visitor. The same headers from any other address change nothing.
 *
 * tests/unit-remote-addr.php asserts the same against a running server, on
 * the HTTPS and REQUEST_SCHEME a pooled script is given.
 *
 *   php tests/unit-proxy-proto.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Proxy.php';

$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

Q_Config::set('Q', 'webserver', 'proxy', 'trusted', array('127.0.0.3', '10.0.0.0/8', 'fd00::/8'));
Q_WebServer_Proxy::$trusted = null;

$visitor = '203.0.113.9';
$spoofs = array(
	'X-Forwarded-Proto' => array('x-forwarded-proto' => 'https'),
	'X-Forwarded-Proto, upper case' => array('x-forwarded-proto' => 'HTTPS'),
	'X-Forwarded-Proto, a chain' => array('x-forwarded-proto' => 'https, http'),
	'CloudFront-Forwarded-Proto' => array('cloudfront-forwarded-proto' => 'https'),
	'CF-Visitor' => array('cf-visitor' => '{"scheme":"https"}'),
);

// ── An untrusted client: the headers change nothing ───────────────────────
foreach ($spoofs as $name => $headers) {
	check("untrusted, $name: http", Q_WebServer_Proxy::clientProto($visitor, $headers, false), 'http');
	check("untrusted, $name: not HTTPS",
		Q_WebServer_Proxy::isHttps(array('_directIp' => $visitor, 'headers' => $headers), false), false);
}
check('no address at all: http',
	Q_WebServer_Proxy::isHttps(array('headers' => $spoofs['X-Forwarded-Proto']), false), false);
check('an empty address: http', Q_WebServer_Proxy::clientProto('', $spoofs['X-Forwarded-Proto'], false), 'http');

// ── A trusted proxy: each header is believed ─────────────────────────────
foreach (array('127.0.0.3', '10.1.2.3', 'fd00::1') as $proxy) {
	foreach ($spoofs as $name => $headers) {
		check("trusted $proxy, $name: https", Q_WebServer_Proxy::clientProto($proxy, $headers, false), 'https');
		check("trusted $proxy, $name: HTTPS",
			Q_WebServer_Proxy::isHttps(array('_directIp' => $proxy, 'headers' => $headers), false), true);
	}
}
check('trusted, no header: http', Q_WebServer_Proxy::clientProto('127.0.0.3', array(), false), 'http');
check('trusted, X-Forwarded-Proto: http', Q_WebServer_Proxy::clientProto('127.0.0.3',
	array('x-forwarded-proto' => 'http'), false), 'http');
check('trusted, a chain starting with http: http', Q_WebServer_Proxy::clientProto('127.0.0.3',
	array('x-forwarded-proto' => 'http, https'), false), 'http');
check('trusted, CF-Visitor saying http: http', Q_WebServer_Proxy::clientProto('127.0.0.3',
	array('cf-visitor' => '{"scheme":"http"}'), false), 'http');
check('trusted, CF-Visitor that is not JSON: http', Q_WebServer_Proxy::clientProto('127.0.0.3',
	array('cf-visitor' => 'nonsense "https"'), false), 'http');

// ── A configured header name ─────────────────────────────────────────────
Q_Config::set('Q', 'webserver', 'proxy', 'headers', 'proto', 'X-Scheme');
check('trusted, the configured header: https', Q_WebServer_Proxy::clientProto('127.0.0.3',
	array('x-scheme' => 'https'), false), 'https');
check('untrusted, the configured header: http', Q_WebServer_Proxy::clientProto($visitor,
	array('x-scheme' => 'https'), false), 'http');

// ── A TLS connection is https from anyone ────────────────────────────────
check('TLS, untrusted, no header: https', Q_WebServer_Proxy::clientProto($visitor, array(), true), 'https');
check('TLS, untrusted, forwarding http: https', Q_WebServer_Proxy::clientProto($visitor,
	array('x-forwarded-proto' => 'http'), true), 'https');
check('TLS, no address: HTTPS', Q_WebServer_Proxy::isHttps(array('headers' => array()), true), true);

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
