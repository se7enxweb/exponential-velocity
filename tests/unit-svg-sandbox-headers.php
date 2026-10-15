#!/usr/bin/env php
<?php
/**
 * SVG files are served with Content-Security-Policy: sandbox and
 * X-Content-Type-Options: nosniff (Q_WebServer_ResponseHeaders::forFile()).
 *
 *   - forFile(): .svg and .svgz only; Q.webserver.svgSandbox false turns it off;
 *   - real servers, HTTP/1.1 and, with TLS, HTTP/2: an SVG carries both
 *     headers (twice, so the in-memory copy is checked too), a text file
 *     neither; with Q.webserver.headers naming X-Content-Type-Options the
 *     header is sent once; with svgSandbox false the SVG gets no CSP.
 *
 *   php tests/unit-svg-sandbox-headers.php
 */
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel/Store.php';
require_once __DIR__ . '/../src/Q/WebServer/Domains.php';
require_once __DIR__ . '/../src/Q/WebServer/DomainUsage.php';
require_once __DIR__ . '/../src/Q/WebServer.php';
require_once __DIR__ . '/../src/Q/WebServer/ResponseHeaders.php';

$pass = 0; $fail = 0;
function ck($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

$R = 'Q_WebServer_ResponseHeaders';
$csp = "sandbox; default-src 'none'; style-src 'unsafe-inline'";
$both = array('Content-Security-Policy' => $csp, 'X-Content-Type-Options' => 'nosniff');

// ── forFile() ────────────────────────────────────────────────────────────
ck('an .svg', $R::forFile('svg'), $both);
ck('an .svgz', $R::forFile('svgz'), $both);
foreach (array('png', 'css', 'js', 'html', 'txt', '') as $ext) {
	ck("not a .$ext", $R::forFile($ext), array());
}
ck('as lines', $R::forFileLines('svg'), "Content-Security-Policy: $csp\r\nX-Content-Type-Options: nosniff\r\n");
foreach (array(false, 0, '0', 'false', 'off', 'disabled') as $off) {
	Q_Config::set('Q', 'webserver', 'svgSandbox', $off);
	ck('svgSandbox ' . var_export($off, true) . ' turns it off', $R::forFile('svg'), array());
}
foreach (array(true, 1, 'on', null) as $on) {
	Q_Config::set('Q', 'webserver', 'svgSandbox', $on);
	ck('svgSandbox ' . var_export($on, true) . ' keeps it', $R::forFile('svg'), $both);
}
Q_Config::set('Q', 'webserver', 'svgSandbox', true);

// ── Real servers ─────────────────────────────────────────────────────────
function rawReq($port, $path)
{
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5);
	if (!$s) return array(0, array());
	stream_set_timeout($s, 10);
	fwrite($s, "GET $path HTTP/1.1\r\nHost: x.test\r\nConnection: close\r\n\r\n");
	$raw = (string) stream_get_contents($s);
	fclose($s);
	$head = explode("\r\n\r\n", $raw, 2)[0];
	$status = preg_match('#^HTTP/1\.[01] (\d{3})#', $raw, $m) ? (int) $m[1] : 0;
	$headers = array();
	foreach (array_slice(explode("\r\n", $head), 1) as $line) {
		$c = strpos($line, ':');
		if ($c !== false) $headers[strtolower(trim(substr($line, 0, $c)))][] = trim(substr($line, $c + 1));
	}
	return array($status, $headers);
}
function pick($headers)
{
	$out = array();
	foreach (array('content-security-policy', 'x-content-type-options') as $n) {
		if (isset($headers[$n])) $out[$n] = count($headers[$n]) === 1 ? $headers[$n][0] : $headers[$n];
	}
	return $out;
}
$lower = array_change_key_case($both, CASE_LOWER);

if (function_exists('pcntl_fork')) {
	require_once __DIR__ . '/fixtures/race-harness.php';
	list($rbase, $root) = rh_setup('svghdr');
	file_put_contents($root . DS . 'logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><rect width="1" height="1"/></svg>');
	file_put_contents($root . DS . 'hello.txt', 'static');
	$h2ok = function_exists('curl_init') && defined('CURL_HTTP_VERSION_2TLS') && (curl_version()['features'] & CURL_VERSION_HTTP2);

	$cases = array(
		'default' => array(array(), $lower),
		'with Q.webserver.headers' => array(array('headers' => array('X-Content-Type-Options' => 'nosniff')), $lower),
		'svgSandbox off' => array(array('svgSandbox' => false), array()),
	);
	foreach ($cases as $label => $case) {
		list($webserver, $want) = $case;
		$tls = rh_free_port();
		$GLOBALS['rh_extra_args'] = array('--https-port=' . $tls);
		$name = 'svg' . md5($label);
		$port = rh_start($name, array('Q' => array(
			'web' => array('http2' => array('enabled' => true)),
			'webserver' => $webserver,
		)), 2);
		$GLOBALS['rh_extra_args'] = array();
		$plain = $label === 'with Q.webserver.headers' ? array('x-content-type-options' => 'nosniff') : array();

		list($st, $hd) = rawReq($port, '/logo.svg');
		ck("HTTP/1.1 $label: the SVG", array($st, pick($hd)), array(200, $want));
		list($st, $hd) = rawReq($port, '/logo.svg');
		ck("HTTP/1.1 $label: the SVG from the in-memory copy", array($st, pick($hd)), array(200, $want));
		list($st, $hd) = rawReq($port, '/hello.txt');
		ck("HTTP/1.1 $label: a text file", array($st, pick($hd)), array(200, $plain));

		if ($h2ok) {
			foreach (array('/logo.svg' => $want, '/hello.txt' => $plain) as $path => $expect) {
				$ch = curl_init("https://x.test:$tls$path");
				curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
					CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_TIMEOUT => 15,
					CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS, CURLOPT_RESOLVE => array("x.test:$tls:127.0.0.1")));
				$raw = (string) curl_exec($ch);
				$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
				$hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
				curl_close($ch);
				$headers = array();
				foreach (explode("\r\n", substr($raw, 0, $hsize)) as $line) {
					$c = strpos($line, ':');
					if ($c !== false) $headers[strtolower(trim(substr($line, 0, $c)))][] = trim(substr($line, $c + 1));
				}
				ck("HTTP/2 $label: $path", array($status, pick($headers)), array(200, $expect));
			}
		} else {
			printf("  skip  HTTP/2: this PHP's curl has no HTTP/2\n");
		}
		rh_stop($GLOBALS['rh']['servers'][$name]);
	}
}

printf("%s - %d case(s)%s\n", $fail ? '  FAIL' : '  PASS', $pass + $fail, $fail ? " ($fail failed)" : '');
exit($fail ? 1 : 0);
