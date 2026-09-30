#!/usr/bin/env php
<?php
/**
 * uwebserver over TLS: --tls-listen/--tls-port with --cert and --key (and
 * --chain), keep-alive and pipelining over TLS, files over TLS, the lowest
 * version (--tls-min-version), a handshake that never comes does not hold up
 * anyone else, and the key and certificate are checked before it serves: a
 * key others may read, a key that is not the certificate's, a file that is
 * not a certificate.
 *
 *   php tests/unit-uwebserver-tls.php
 */
require __DIR__ . '/uwebserver-helpers.php';

$bin = uw_build();
if ($bin === null) uw_skip('no C compiler or OpenSSL headers');
if (!function_exists('openssl_pkey_new')) uw_skip('no openssl extension in this PHP');
check('uwebserver builds', is_string($bin));
if (!is_string($bin)) uw_finish();
$d = uw_dir();

/** A self-signed certificate and its key, written to $name.crt / $name.key (0600). */
function make_cert($d, $name)
{
	$key = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
	$csr = openssl_csr_new(array('commonName' => 'localhost'), $key, array('digest_alg' => 'sha256'));
	$crt = openssl_csr_sign($csr, null, $key, 2, array('digest_alg' => 'sha256'));
	openssl_x509_export($crt, $pem);
	openssl_pkey_export($key, $kpem);
	file_put_contents("$d/$name.crt", $pem);
	file_put_contents("$d/$name.key", $kpem);
	chmod("$d/$name.key", 0600);
	return array("$d/$name.crt", "$d/$name.key");
}
list($crt, $key) = make_cert($d, 'a');
list($crt2, $key2) = make_cert($d, 'b');
mkdir("$d/www");
$big = random_bytes(1024 * 1024 + 3);
file_put_contents("$d/www/big.bin", $big);
file_put_contents("$d/www/index.html", "tls\n");

$hp = uw_free_port();
$tp = uw_free_port();
$srv = uw_start($bin, array_merge(uw_root_args(), array("--root=$d/www", "--port=$hp", "--tls-listen=127.0.0.1:$tp", "--cert=$crt", "--key=$key", '--error-log=' . "$d/err.log")), $tp);
check('HTTP and HTTPS start', $srv !== null);
if (!$srv) { echo @file_get_contents("$d/err.log"); uw_finish(); }
check('...both on 127.0.0.1', array(uw_bound_address($hp), uw_bound_address($tp)), array('127.0.0.1', '127.0.0.1'));
check('the start-up log names the certificate', strpos((string) file_get_contents("$d/err.log"), 'TLS certificate: /CN=localhost') !== false);
list($s, $h, $b) = uw_get($tp, '/', array(), 'GET', true);
check('GET / over TLS', array($s, $b), array(200, "tls\n"));
list($s, $h, $b) = uw_get($tp, '/big.bin', array(), 'GET', true);
check('a 1 MiB file over TLS arrives whole', array($s, md5($b)), array(200, md5($big)));
$raw = uw_raw($tp, "GET / HTTP/1.1\r\nHost: l\r\n\r\nGET /index.html HTTP/1.1\r\nHost: l\r\n\r\nGET / HTTP/1.1\r\nHost: l\r\nConnection: close\r\n\r\n", 3, true);
check('three pipelined requests over one TLS connection', substr_count($raw, 'HTTP/1.1 200'), 3);
list($s, $h, $b) = uw_get($hp, '/');
check('plain HTTP beside it', array($s, $b), array(200, "tls\n"));

// A client that opens the TLS port and says nothing holds up no one.
$idle = stream_socket_client("tcp://127.0.0.1:$tp", $e, $es, 2);
$t = microtime(true);
list($s) = uw_get($tp, '/', array(), 'GET', true);
list($s2) = uw_get($hp, '/');
check('a silent TLS client does not block others', array($s, $s2, microtime(true) - $t < 3), array(200, 200, true));
fclose($idle);

// The version: only TLS 1.2 on offer against --tls-min-version=1.3.
$ctx = stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)));
$fp = @stream_socket_client("tls://127.0.0.1:$tp", $e, $es, 3, STREAM_CLIENT_CONNECT, $ctx);
check('TLS 1.2 is accepted by default', $fp !== false);
if ($fp) fclose($fp);
uw_stop($srv);

$tp = uw_free_port();
$srv = uw_start($bin, array_merge(uw_root_args(), array("--tls-port=$tp", "--cert=$crt", "--key=$key", '--tls-min-version=1.3', "--listen=127.0.0.1:" . uw_free_port())), $tp);
check('--tls-min-version=1.3 starts', $srv !== null);
$fp = @stream_socket_client("tls://127.0.0.1:$tp", $e, $es, 3, STREAM_CLIENT_CONNECT, $ctx);
check('...and refuses a TLS 1.2 client', $fp, false);
list($s) = uw_get($tp, '/', array(), 'GET', true);
check('...but serves a TLS 1.3 one', $s, 200);
uw_stop($srv);

// --cert alone: HTTPS on --tls-port's default beside HTTP (checked, not bound: 8443 may be in use).
list($rc, $out) = uw_run($bin, array("--cert=$crt", "--key=$key", '--check'));
check('--cert and --key: HTTPS on 127.0.0.1:8443 beside HTTP', trim($out), 'uwebserver: the configuration is valid; it would serve on http://127.0.0.1:8000, https://127.0.0.1:8443');

// --chain: more certificates, sent in the chain.
list($rc, $out, $err) = uw_run($bin, array("--cert=$crt", "--key=$key", "--chain=$crt2", '--check'));
check('--chain with a certificate: valid', $rc, 0);
file_put_contents("$d/empty.pem", "nothing here\n");
list($rc, $out, $err) = uw_run($bin, array("--cert=$crt", "--key=$key", "--chain=$d/empty.pem", '--check'));
check('--chain with no certificate in it: exit 1', array($rc, strpos($err, 'holds no certificate') !== false), array(1, true));

// Keys and certificates that must not be used.
chmod($key, 0644);
list($rc, $out, $err) = uw_run($bin, array("--cert=$crt", "--key=$key", '--check'));
check('a key any user may read: refused, exit 1', array($rc, strpos($err, 'may be read or changed by any user (mode 0644); chmod 600 it') !== false), array(1, true));
$tp = uw_free_port();
list($rc, $out, $err) = uw_run($bin, array_merge(uw_root_args(), array("--tls-port=$tp", "--cert=$crt", "--key=$key")), 3);
check('...and the server does not start with it', array($rc, uw_listening($tp)), array(1, false));
chmod($key, 0620);
list($rc, $out, $err) = uw_run($bin, array("--cert=$crt", "--key=$key", '--check'));
check('a key its group may change: refused', $rc, 1);
chmod($key, 0640);
list($rc, $out, $err) = uw_run($bin, array("--cert=$crt", "--key=$key", '--check'));
check('a key its group may read: a warning, accepted', array($rc, strpos($err, 'may be read by its group') !== false), array(0, true));
chmod($key, 0600);
list($rc, $out, $err) = uw_run($bin, array("--cert=$crt", "--key=$key2", '--check'));
check('a key that is not the certificate\'s: exit 1', array($rc, strpos($err, 'does not belong to the certificate') !== false), array(1, true));
list($rc, $out, $err) = uw_run($bin, array("--cert=$d/empty.pem", "--key=$key", '--check'));
check('a certificate file that is not one: exit 1', array($rc, strpos($err, 'cannot load the certificate') !== false), array(1, true));
list($rc, $out, $err) = uw_run($bin, array("--cert=$crt", "--key=$key", '--tls-ciphers=NOT-A-CIPHER', '--check'));
check('--tls-ciphers with nothing usable: exit 1', array($rc, strpos($err, "no usable cipher in 'NOT-A-CIPHER'") !== false), array(1, true));

uw_finish();
