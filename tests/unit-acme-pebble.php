<?php

/**
 * The built-in ACME client gets a real certificate from a real ACME server.
 *
 * Against Pebble, Let's Encrypt's own test CA, in docker (skipped without
 * docker): an account is registered, an order placed, the HTTP-01 challenge
 * answered by a running qbixserver.php from the challenge files, the order
 * finalized and the chain downloaded. Pebble rejects a share of nonces on
 * purpose, so the retry on badNonce is exercised too. Then the same through
 * the "acme" certificate source: a background job issues it while this
 * process carries on. And DNS-01 for a wildcard, through a hook script, with
 * Pebble's DNS test server. And external account binding.
 *
 *   php tests/unit-acme-pebble.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}
function skip($why) { printf("  skip  %s\n", $why); exit(0); }
function port_free($p) { $s = @stream_socket_client("tcp://127.0.0.1:$p", $e, $es, 0.5); if ($s) { fclose($s); return false; } return true; }

$docker = Q_WebServer_Certificate_Source_Base::tool(array('docker'));
if (!$docker) skip('needs docker');
if (!port_free(14000) or !port_free(5002)) skip('ports 14000 and 5002 must be free');
$name = 'qbix-pebble-' . getmypid();
$code = Q_WebServer_Certificate_Source_Base::run(array($docker, 'run', '-d', '--rm', '--name', $name, '--network', 'host',
	'--add-host', 'acme.qbix.test:127.0.0.1', '-e', 'PEBBLE_VA_NOSLEEP=1',
	'ghcr.io/letsencrypt/pebble:latest', '-config', '/test/config/pebble-config.json'), $out);
if ($code !== 0) skip('could not start pebble: ' . trim($out));
$base = sys_get_temp_dir() . DS . 'qbix-acme-' . getmypid();
@mkdir("$base/web", 0700, true);
$server = null;
try {
	for ($i = 0; $i < 40 and port_free(14000); $i++) usleep(250000);
	Q_WebServer_Certificate_Source_Base::run(array($docker, 'cp', "$name:/test/certs/pebble.minica.pem", "$base/pebble-ca.pem"), $out);
	if (!is_file("$base/pebble-ca.pem")) skip('could not read pebble\'s CA: ' . trim($out));

	// The server that answers the challenge: plain HTTP on the port Pebble checks.
	file_put_contents("$base/web/index.php", '<?php echo "ok";');
	file_put_contents("$base/server.json", json_encode(array('Q' => array('web' => array('https' => array(
		'acme' => array('challengeDir' => "$base/challenges")))))));
	$server = proc_open(array(PHP_BINARY, __DIR__ . '/../sbin/qbixserver.php', '--config=' . "$base/server.json",
		'--root=' . "$base/web", '--host=127.0.0.1', '--port=5002', '--workers=1', '--pid=' . "$base/server.pid"),
		array(0 => array('file', '/dev/null', 'r'), 1 => array('file', "$base/server.log", 'w'), 2 => array('file', "$base/server.log", 'a')), $pipes);
	for ($i = 0; $i < 60 and port_free(5002); $i++) usleep(250000);

	Q_Config::set('Q', 'web', 'https', 'selfSigned', 'dir', "$base/ssl");
	Q_Config::set('Q', 'web', 'https', 'acme', 'challengeDir', "$base/challenges");
	$config = array('mode' => 'acme', 'acme' => array(
		'directory' => 'https://localhost:14000/dir', 'caBundle' => "$base/pebble-ca.pem",
		'email' => 'admin@qbix.test', 'domains' => array('acme.qbix.test'), 'timeout' => 120));

	$r = Q_WebServer_Acme::issue($config);
	check('pebble issues a certificate over HTTP-01', $r['ok'] ?: $r['error'], true);
	$c = !empty($r['ok']) ? Q_WebServer_Certificate::fromFiles($r['cert'], $r['key']) : null;
	check('...usable, for the domain asked', $c ? array($c->isUsable(), $c->hosts()) : null, array(true, array('acme.qbix.test')));
	check('...issued by the CA, not by us', $c ? stripos(openssl_x509_parse($c->certPem)['issuer']['CN'] ?? '', 'Pebble') !== false : null, true);
	check('...with its chain', $c ? count(Q_WebServer_Certificate_Import::certificates($c->certPem)) >= 2 : null, true);
	check('...an EC key, private', $c ? array($c->keyType(), fileperms($r['key']) & 0777) : null, array('EC', 0600));
	check('the challenge file is cleaned up', glob("$base/challenges/*") ?: array(), array());

	// Again through the source, as the server does it: a background job.
	unlink($r['cert']); unlink($r['key']);
	$src = new Q_WebServer_Certificate_Source_Acme();
	$pair = $src->pair($config, $why);
	check('the source points at where the certificate will be', $pair, array($r['cert'], $r['key']));
	$got = false;
	for ($t = microtime(true); microtime(true) - $t < 120 and !$got; usleep(500000)) {
		$got = Q_WebServer_Certs::pairUsable($r['cert'], $r['key']);
	}
	check('...and a background job issues it', $got, true);
	$state = Q_WebServer_Certificate_Job::state(Q_WebServer_Acme::files($config)[2]);
	check('...recording success', array(empty($state['lastSuccess']), $state['failures'] ?? null), array(false, 0));
	check('...and it is not due again', Q_WebServer_Acme::renewalDue($r['cert'], $r['key'], array('acme.qbix.test')), null);

	// ── DNS-01 and a wildcard, with Pebble's DNS test server ──────────────
	$dnsName = 'qbix-challtestsrv-' . getmypid();
	Q_WebServer_Certificate_Source_Base::run(array($docker, 'rm', '-f', $name), $out);
	for ($i = 0; $i < 40 and !port_free(14000); $i++) usleep(250000);
	$c1 = Q_WebServer_Certificate_Source_Base::run(array($docker, 'run', '-d', '--rm', '--name', $dnsName, '--network', 'host',
		'ghcr.io/letsencrypt/pebble-challtestsrv:latest', '-defaultIPv4', '127.0.0.1', '-defaultIPv6', '',
		'-dnsserver', ':8053', '-management', ':8055', '-http01', '', '-https01', '', '-tlsalpn01', '', '-doh', ''), $out);
	$c2 = Q_WebServer_Certificate_Source_Base::run(array($docker, 'run', '-d', '--rm', '--name', $name, '--network', 'host',
		'-e', 'PEBBLE_VA_NOSLEEP=1', 'ghcr.io/letsencrypt/pebble:latest', '-config', '/test/config/pebble-config.json',
		'-dnsserver', '127.0.0.1:8053'), $out);
	if ($c1 === 0 and $c2 === 0) {
		for ($i = 0; $i < 40 and port_free(14000); $i++) usleep(250000);
		for ($i = 0; $i < 40 and port_free(8055); $i++) usleep(250000);
		$dns = array('mode' => 'acme', 'acme' => array(
			'directory' => 'https://localhost:14000/dir', 'caBundle' => "$base/pebble-ca.pem",
			'domains' => array('*.wild.qbix.test', 'wild.qbix.test'), 'challenge' => 'dns-01', 'dnsWait' => 0,
			'dnsHook' => array(PHP_BINARY, __DIR__ . '/fixtures/acme-dns-hook-challtestsrv.php'), 'timeout' => 120));
		$r = Q_WebServer_Acme::issue($dns);
		check('dns-01 issues a wildcard certificate through the hook', $r['ok'] ?: $r['error'], true);
		$c = !empty($r['ok']) ? Q_WebServer_Certificate::fromFiles($r['cert'], $r['key']) : null;
		check('...covering the wildcard and the bare name', $c ? $c->hosts() : null, array('*.wild.qbix.test', 'wild.qbix.test'));
	} else {
		printf("  skip  dns-01: could not start challtestsrv: %s\n", trim($out));
	}
	Q_WebServer_Certificate_Source_Base::run(array($docker, 'rm', '-f', $dnsName), $out);

	// ── External account binding, as ZeroSSL and Google require ───────────
	Q_WebServer_Certificate_Source_Base::run(array($docker, 'rm', '-f', $name), $out);
	for ($i = 0; $i < 40 and !port_free(14000); $i++) usleep(250000);
	$tmpc = 'qbix-pebble-conf-' . getmypid();
	Q_WebServer_Certificate_Source_Base::run(array($docker, 'create', '--name', $tmpc, 'ghcr.io/letsencrypt/pebble:latest'), $out);
	Q_WebServer_Certificate_Source_Base::run(array($docker, 'cp', "$tmpc:/test/config/pebble-config.json", "$base/pebble-default.json"), $out);
	Q_WebServer_Certificate_Source_Base::run(array($docker, 'rm', '-f', $tmpc), $out);
	$pebbleConf = (string) @file_get_contents("$base/pebble-default.json");
	$pc = json_decode($pebbleConf, true);
	if (is_array($pc) and isset($pc['pebble'])) {
		$hmac = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
		$pc['pebble']['externalAccountBindingRequired'] = true;
		$pc['pebble']['externalAccountMACKeys'] = array('kid-qbix' => $hmac);
		file_put_contents("$base/pebble-eab.json", json_encode($pc));
		chmod("$base/pebble-eab.json", 0644);
		Q_WebServer_Certificate_Source_Base::run(array($docker, 'run', '-d', '--rm', '--name', $name, '--network', 'host',
			'--add-host', 'acme.qbix.test:127.0.0.1', '-e', 'PEBBLE_VA_NOSLEEP=1', '-v', "$base/pebble-eab.json:/eab.json:ro",
			'ghcr.io/letsencrypt/pebble:latest', '-config', '/eab.json'), $out);
		for ($i = 0; $i < 40 and port_free(14000); $i++) usleep(250000);
		Q_Config::set('Q', 'web', 'https', 'acme', 'dir', "$base/acme-eab");
		$eabConfig = $config;
		$r = Q_WebServer_Acme::issue($eabConfig);
		check('a CA that requires account binding refuses an account without it', !$r['ok'] && stripos($r['error'], 'externalAccount') !== false, true);
		$eabConfig['acme']['eab'] = array('kid' => 'kid-qbix', 'hmacKeyEnv' => 'QBIX_TEST_EAB');
		putenv("QBIX_TEST_EAB=$hmac");
		$r = Q_WebServer_Acme::issue($eabConfig);
		check('...and issues with it (the HMAC key read from the environment)', $r['ok'] ?: $r['error'], true);
	} else {
		printf("  skip  eab: could not read pebble's configuration\n");
	}
} finally {
	if ($server) { $st = proc_get_status($server); if ($st['running']) posix_kill($st['pid'], SIGTERM); proc_close($server); }
	Q_WebServer_Certificate_Source_Base::run(array($docker, 'rm', '-f', $name), $out);
	if (is_dir($base)) {
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($it as $f) { ($f->isDir() and !$f->isLink()) ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
		@rmdir($base);
	}
}

if ($fail) { printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
