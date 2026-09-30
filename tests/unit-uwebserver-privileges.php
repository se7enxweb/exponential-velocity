#!/usr/bin/env php
<?php
/**
 * uwebserver's privileges and TLS: the files it writes (logs, the pid file)
 * are never another user's file or a second name of one; root does not take
 * its settings from a file others may change, and a chroot needs --user;
 * after dropping to --user the process cannot be read or dumped by that
 * user; TLS offers only forward-secret AEAD ciphers on 1.2, no session
 * tickets, no compression, and ALPN never picks a protocol it does not
 * speak.
 *
 *   php tests/unit-uwebserver-privileges.php
 */
require __DIR__ . '/uwebserver-helpers.php';

$bin = uw_build();
if ($bin === null) uw_skip('no C compiler or OpenSSL headers');
check('uwebserver builds', is_string($bin));
if (!is_string($bin)) uw_finish();
$d = uw_dir();
chmod($d, 0755);
$isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
$nobody = function_exists('posix_getpwnam') ? posix_getpwnam('nobody') : false;

// ── The files it writes ─────────────────────────────────────────────────
file_put_contents("$d/real.log", '');
link("$d/real.log", "$d/second-name.log");
list($rc, $out, $err) = uw_run($bin, array("--access-log=$d/second-name.log", '--check'));
check('a log with a second name (a hard link): refused', array($rc, strpos($err, 'has 2 names (hard links); refusing to write to it') !== false), array(1, true));
list($rc, $out, $err) = uw_run($bin, array_merge(uw_root_args(), array('--port=' . uw_free_port(), "--error-log=$d/second-name.log")), 3);
check('...and the server does not start with it', array($rc, strpos($err, 'has 2 names') !== false), array(1, true));
list($rc, $out, $err) = uw_run($bin, array_merge(uw_root_args(), array('--port=' . uw_free_port(), "--pid-file=$d/second-name.log")), 3);
check('a pid file with a second name: refused', array($rc, (string) file_get_contents("$d/real.log")), array(1, ''));
symlink("$d/real.log", "$d/link.log");
list($rc, $out, $err) = uw_run($bin, array("--error-log=$d/link.log", '--check'));
check('a log that is a symbolic link: refused', array($rc, strpos($err, 'it is a symbolic link') !== false), array(1, true));
if ($isRoot && $nobody) {
	file_put_contents("$d/nobodys.log", '');
	chown("$d/nobodys.log", $nobody['uid']);
	list($rc, $out, $err) = uw_run($bin, array("--access-log=$d/nobodys.log", '--check'));
	check('a log of another user: refused', array($rc, strpos($err, 'belongs to another user') !== false), array(1, true));
	list($rc, $out, $err) = uw_run($bin, array("--access-log=$d/nobodys.log", '--user=nobody', '--check'));
	check('...unless it is the user it will run as', $rc, 0);

	// Root does not read a configuration file others may change.
	file_put_contents("$d/c.conf", "port = 12345\n");
	chmod("$d/c.conf", 0646);
	list($rc, $out, $err) = uw_run($bin, array("--config=$d/c.conf", '--check'));
	check('as root, a configuration file any user may change: not read, exit 2', array($rc, strpos($err, 'may be changed by any user') !== false), array(2, true));
	chmod("$d/c.conf", 0644);
	chown("$d/c.conf", $nobody['uid']);
	list($rc, $out, $err) = uw_run($bin, array("--config=$d/c.conf", '--check'));
	check('...nor one another user owns', array($rc, strpos($err, 'chown root it') !== false), array(2, true));
	chown("$d/c.conf", 0);
	chmod("$d/c.conf", 0664);
	list($rc, $out, $err) = uw_run($bin, array("--config=$d/c.conf", '--check'));
	check('...one its group may change: read, with a warning', array($rc, strpos($err, 'may be changed by its group') !== false), array(0, true));

	// After the drop: not dumpable, so its /proc files stay root's and the user cannot read its memory.
	$port = uw_free_port();
	$srv = uw_start($bin, array("--port=$port", '--user=nobody'), $port);
	check('--user=nobody serves', $srv !== null);
	$st = @stat("/proc/{$srv['pid']}/mem");
	check('...and is not dumpable: its memory file stays root\'s, not readable by nobody', $st !== false && $st['uid'] === 0);
	uw_stop($srv);
} else {
	echo "  skip  not root, or no user nobody: the ownership and configuration checks\n";
}
list($rc, $out, $err) = uw_run($bin, array("--chroot=$d", '--allow-root', '--check'));
check('--chroot without --user: a usage error (a chroot does not hold root)', array($rc, strpos($err, '--chroot needs --user') !== false), array(2, true));

// ── TLS ─────────────────────────────────────────────────────────────────
if (!function_exists('openssl_pkey_new')) { echo "  skip  no openssl extension: TLS\n"; uw_finish(); }
$key = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
$crt = openssl_csr_sign(openssl_csr_new(array('commonName' => 'localhost'), $key), null, $key, 1, array('digest_alg' => 'sha256'));
openssl_x509_export($crt, $pem); openssl_pkey_export($key, $kpem);
file_put_contents("$d/t.crt", $pem); file_put_contents("$d/t.key", $kpem); chmod("$d/t.key", 0600);
$tp = uw_free_port();
$srv = uw_start($bin, array_merge(uw_root_args(), array("--tls-port=$tp", "--cert=$d/t.crt", "--key=$d/t.key", '--listen=127.0.0.1:' . uw_free_port())), $tp);
check('HTTPS starts', $srv !== null);
$try = function ($opts) use ($tp) {
	$ctx = stream_context_create(array('ssl' => array_merge(array('verify_peer' => false, 'verify_peer_name' => false), $opts)));
	$fp = @stream_socket_client("tls://127.0.0.1:$tp", $e, $es, 3, STREAM_CLIENT_CONNECT, $ctx);
	if (!$fp) return false;
	$meta = stream_get_meta_data($fp)['crypto'] ?? array();
	fclose($fp);
	return $meta;
};
$m = $try(array('crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT, 'ciphers' => 'ECDHE-ECDSA-AES128-GCM-SHA256'));
check('TLS 1.2 with ECDHE and AES-GCM: accepted', ($m['cipher_name'] ?? null), 'ECDHE-ECDSA-AES128-GCM-SHA256');
check('TLS 1.2 with a CBC cipher (ECDHE-ECDSA-AES128-SHA): refused', $try(array('crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT, 'ciphers' => 'ECDHE-ECDSA-AES128-SHA:@SECLEVEL=0')), false);
check('TLS 1.1: refused', $try(array('crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT, 'ciphers' => 'DEFAULT:@SECLEVEL=0')), false);
$m = $try(array());
check('the default: TLS 1.3', ($m['protocol'] ?? null), 'TLSv1.3');

$openssl = trim((string) shell_exec('command -v openssl 2>/dev/null'));
if ($openssl !== '') {
	$sc = function ($args) use ($openssl, $tp) {
		return (string) shell_exec('echo | timeout 5 ' . escapeshellarg($openssl) . " s_client -connect 127.0.0.1:$tp $args 2>&1");
	};
	$o = $sc('-tls1_2 -alpn h2');
	check('ALPN offering only h2: none chosen (it speaks HTTP/1.1 only)', strpos($o, 'No ALPN negotiated') !== false);
	$o = $sc('-tls1_2 -alpn h2,http/1.1');
	check('ALPN offering h2 and http/1.1: http/1.1', strpos($o, 'ALPN protocol: http/1.1') !== false);
	check('TLS 1.2: no session ticket', strpos($o, 'TLS session ticket:') === false);
	check('...no compression', strpos($o, 'Compression: NONE') !== false);
} else {
	echo "  skip  no openssl(1): ALPN, tickets, compression\n";
}
uw_stop($srv);

uw_finish();
