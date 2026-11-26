#!/usr/bin/env php
<?php

/**
 * Whether a TLS session can be resumed against a PHP stream server.
 *
 * The HTTPS listener shares one stream context between every connection it
 * accepts (Q_WebServer::startTls), in the hope that the session cache and the
 * ticket key live there. They do not: PHP's OpenSSL streams build a new
 * SSL_CTX for every stream when its crypto is enabled, and the session cache
 * and the ticket key belong to that SSL_CTX. Nothing a stream context can say
 * (PHP offers only no_ticket) makes two connections share one. So a client
 * that comes back gets a full handshake every time -- openssl s_client
 * -reconnect reports "New" for all six of its handshakes.
 *
 * This probe states that limit rather than hiding it: it serves a self-signed
 * certificate from a plain PHP stream server, reconnects with the openssl
 * client, and reports how many handshakes were resumed. It PASSes when the
 * result matches what this PHP can do: 0 resumed while PHP exposes no session
 * cache, at least one if a future PHP does (then startTls should use it, and
 * this probe will say so).
 *
 * Not part of tests/run-unit.php: it needs the openssl command line client.
 *
 *   php tests/tls-session-resumption.php
 */

if (!function_exists('openssl_pkey_new') || !function_exists('pcntl_fork')) {
	echo "SKIP  needs the openssl and pcntl extensions\n";
	exit(0);
}
$client = trim((string) @shell_exec('command -v openssl 2>/dev/null'));
if ($client === '') {
	echo "SKIP  needs the openssl command line client\n";
	exit(0);
}

$dir = sys_get_temp_dir() . '/qbix-tls-resume-' . getmypid();
@mkdir($dir, 0700, true);
$key = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
$csr = openssl_csr_new(array('commonName' => 'localhost'), $key, array('digest_alg' => 'sha256'));
$crt = openssl_csr_sign($csr, null, $key, 1, array('digest_alg' => 'sha256'));
openssl_x509_export($crt, $crtPem);
openssl_pkey_export($key, $keyPem);
file_put_contents("$dir/cert.pem", $crtPem);
file_put_contents("$dir/key.pem", $keyPem);

// The listener exactly as startTls() builds it: one context, plain tcp://,
// crypto enabled on each accepted socket.
$ctx = stream_context_create(array('ssl' => array(
	'local_cert' => "$dir/cert.pem",
	'local_pk' => "$dir/key.pem",
	'allow_self_signed' => true,
	'verify_peer' => false,
	'verify_peer_name' => false,
	'honor_cipher_order' => true,
)));
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr,
	STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if (!$server) {
	echo "FAIL  could not listen: $errstr\n";
	exit(1);
}
$port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

$pid = pcntl_fork();
if ($pid === 0) {
	$deadline = time() + 20;
	while (time() < $deadline) {
		$c = @stream_socket_accept($server, 2);
		if (!$c) continue;
		stream_set_blocking($c, true);
		if (@stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
			@fread($c, 1);
		}
		@fclose($c);
	}
	exit(0);
}

$out = (string) shell_exec('echo | timeout 20 ' . escapeshellarg($client)
	. ' s_client -connect 127.0.0.1:' . $port . ' -servername localhost -tls1_2 -reconnect 2>&1');
posix_kill($pid, SIGTERM);
pcntl_waitpid($pid, $status);
foreach (array('cert.pem', 'key.pem') as $f) @unlink("$dir/$f");
@rmdir($dir);

$new = preg_match_all('/^New, /m', $out);
$reused = preg_match_all('/^Reused, /m', $out);
if ($new + $reused === 0) {
	echo "FAIL  no handshake completed\n$out\n";
	exit(1);
}
echo "  handshakes: $new new, $reused reused (PHP " . PHP_VERSION . ")\n";
if ($reused === 0) {
	echo "PASS  PHP creates an OpenSSL context per stream, so no session is resumed; see the note in Q_WebServer::startTls\n";
} else {
	echo "PASS  this PHP resumes sessions on a shared stream context\n";
}
exit(0);
