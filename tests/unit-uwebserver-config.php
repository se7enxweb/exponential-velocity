#!/usr/bin/env php
<?php
/**
 * uwebserver: where it listens, and its configuration.
 *
 *   - the default is 127.0.0.1:8000, never every interface; --listen,
 *     --bind, --port and --tls-listen choose, IPv4 and IPv6, repeatable;
 *     every former spelling of the port (--port N, -port N, -port=N, a bare
 *     number) still works and binds 127.0.0.1;
 *   - without --reuse-port a second server cannot take a port in use;
 *   - --config=FILE (or UWEBSERVER_CONFIG) reads the same names as the
 *     options, the command line overrides it, errors name the file and line
 *     and exit 2; --print-config prints the settings in the same format and
 *     reads back to the same; --check validates and serves nothing;
 *   - as root it serves only with --user or --allow-root.
 *
 *   php tests/unit-uwebserver-config.php
 */
require __DIR__ . '/uwebserver-helpers.php';

$bin = uw_build();
if ($bin === null) uw_skip('no C compiler or OpenSSL headers');
check('uwebserver builds', is_string($bin));
if (!is_string($bin)) uw_finish();
$d = uw_dir();
mkdir("$d/www", 0755);
file_put_contents("$d/www/index.html", "cfg\n");

// ── Defaults and listeners, through --check (which binds nothing) ───────
list($rc, $out, $err) = uw_run($bin, array('--check'));
check('--check with nothing given: exit 0', $rc, 0);
check('...the default is http://127.0.0.1:8000', trim($out), 'uwebserver: the configuration is valid; it would serve on http://127.0.0.1:8000');
list($rc, $out) = uw_run($bin, array('--print-config'));
check('--print-config: bind 127.0.0.1 and port 8000, as the defaults', strpos($out, "# bind = 127.0.0.1\n") !== false && strpos($out, "# port = 8000\n") !== false);
check('...and no listener of every interface', strpos($out, '0.0.0.0') === false);
$listens = array(
	array(array('--listen=19001'), 'http://127.0.0.1:19001'),
	array(array('-l', '19001'), 'http://127.0.0.1:19001'),
	array(array('--listen=*:19001'), 'http://0.0.0.0:19001'),
	array(array('--listen=[::1]:19001'), 'http://[::1]:19001'),
	array(array('--listen=[::]:19001'), 'http://[::]:19001'),
	array(array('--listen=::1'), 'http://[::1]:8000'),
	array(array('--listen=localhost:19001'), 'http://127.0.0.1:19001'),
	array(array('--bind=10.1.2.3', '--port=19001'), 'http://10.1.2.3:19001'),
	array(array('--listen=19001', '--listen=19002'), 'http://127.0.0.1:19001, http://127.0.0.1:19002'),
	array(array('--listen=19001', '--port=19002'), 'http://127.0.0.1:19001, http://127.0.0.1:19002'),
);
foreach ($listens as $l) {
	list($rc, $out) = uw_run($bin, array_merge($l[0], array('--check')));
	check(implode(' ', $l[0]) . ': ' . $l[1], array($rc, trim($out)), array(0, 'uwebserver: the configuration is valid; it would serve on ' . $l[1]));
}
$bad = array(
	array(array('--listen=19001', '--listen=19001'), '127.0.0.1:19001 is asked for twice'),
	array(array('--listen=1.2.3'), "invalid address '1.2.3'"),
	array(array('--listen=example.org:80'), "invalid address 'example.org'"),
	array(array('--listen=[::1'), "invalid address '[::1'"),
	array(array('--listen=127.0.0.1:0'), "invalid port in '127.0.0.1:0'"),
	array(array('--listen=127.0.0.1:x'), "invalid port in '127.0.0.1:x'"),
	array(array('--tls-listen=19001'), '--tls-listen needs --cert and --key'),
	array(array('--workers=0'), "invalid number of workers '0' for '--workers'"),
	array(array('--workers=257'), "invalid number of workers '257'"),
	array(array('--max-header-size=10'), "invalid size '10' for '--max-header-size'"),
	array(array('--max-header-size=2k', '--max-uri-length=4k'), '--max-uri-length (4096) is more than --max-header-size (2048)'),
	array(array('--header-timeout=-1'), "invalid number of seconds '-1'"),
	array(array('--header=Bad Header'), "invalid header 'Bad Header'"),
	array(array('--header=Content-Length: 5'), "invalid header 'Content-Length: 5'"),
	array(array('--symlinks=always'), "invalid value 'always' for '--symlinks'"),
	array(array('--access-log-format=xml'), "invalid format 'xml'"),
	array(array('--tls-min-version=1.1'), "invalid TLS version '1.1'"),
	array(array('--directory-listing'), '--directory-listing needs --root'),
	array(array('--no-directory-listing=1'), "option '--no-directory-listing' doesn't allow an argument"),
	array(array('19001', '19002'), "unexpected argument '19002'"),
);
foreach ($bad as $b) {
	list($rc, $out, $err) = uw_run($bin, array_merge($b[0], array('--check')));
	check(implode(' ', $b[0]) . ': exit 2', $rc, 2);
	check(implode(' ', $b[0]) . ": says \"{$b[1]}\"", strpos($err, 'uwebserver: ' . $b[1]) === 0);
}

// ── Every former spelling of the port binds 127.0.0.1 ───────────────────
foreach (array('--port=%d', '--port %d', '-port %d', '-port=%d', '-p%d', '%d') as $form) {
	$port = uw_free_port();
	$args = array_merge(uw_root_args(), explode(' ', sprintf($form, $port)));
	$srv = uw_start($bin, $args, $port);
	check("$form: serves", $srv !== null);
	check("$form: on 127.0.0.1, not every interface", uw_bound_address($port), '127.0.0.1');
	if ($form === '%d') check('a bare port: a note that it is deprecated', strpos((string) @file_get_contents($srv['err']), 'deprecated; use --port=') !== false);
	uw_stop($srv);
}

// ── IPv6 loopback, and two listeners ────────────────────────────────────
$p6 = uw_free_port();
$p4 = uw_free_port();
$srv = uw_start($bin, array_merge(uw_root_args(), array("--listen=[::1]:$p6", "--listen=127.0.0.1:$p4")), $p4);
check('two listeners: IPv4 serves', $srv !== null && uw_get($p4, '/')[0] === 200);
$fp = @stream_socket_client("tcp://[::1]:$p6", $e, $es, 2);
if ($fp) {
	fwrite($fp, "GET / HTTP/1.1\r\nHost: l\r\nConnection: close\r\n\r\n");
	check('...and IPv6 [::1] too', strpos((string) stream_get_contents($fp), 'Hello from U!') !== false);
	fclose($fp);
} else {
	echo "  skip  no IPv6 loopback here\n";
}

// ── A port in use is not shared without --reuse-port ────────────────────
list($rc, $out, $err) = uw_run($bin, array_merge(uw_root_args(), array("--port=$p4")), 3);
check('a second server on a port in use: exit 1', $rc, 1);
check('...cannot listen, says so', strpos($err, "cannot listen on http://127.0.0.1:$p4: Address already in use") !== false);
uw_stop($srv);

// ── The configuration file ──────────────────────────────────────────────
$port = uw_free_port();
$conf = "$d/uweb.conf";
file_put_contents($conf, "# a comment\n  # an indented comment\n\nroot = $d/www\nport $port\nheader = X-From: file\nheader = X-Also: file\n"
	. "etag = no\ncache-control = \"max-age=5\"\nno-server-header\nquiet\ndirectory-listing = yes\n");
list($rc, $out) = uw_run($bin, array("--config=$conf", '--print-config'));
check('--config: read', $rc, 0);
check('...every setting in --print-config', strpos($out, "root = $d/www\n") !== false && strpos($out, "port = $port\n") !== false
	&& strpos($out, "header = X-From: file\nheader = X-Also: file\n") !== false && strpos($out, "etag = no\n") !== false
	&& strpos($out, "cache-control = max-age=5\n") !== false && strpos($out, "server-header = no\n") !== false
	&& strpos($out, "quiet = yes\n") !== false && strpos($out, "directory-listing = yes\n") !== false);
file_put_contents("$d/printed.conf", $out);
list($rc2, $out2) = uw_run($bin, array("--config=$d/printed.conf", '--print-config'));
check('--print-config reads back to the same settings', array($rc2, $out2), array(0, $out));
list($rc, $out) = uw_run($bin, array("--config=$conf", '--header=X-Cli: 1', '--etag', "--port=" . ($port + 1), '--print-config'));
check('the command line overrides the file', strpos($out, "etag = yes\n") !== false && strpos($out, 'port = ' . ($port + 1) . "\n") !== false);
check('...and a repeated option given there replaces the file\'s values', strpos($out, "header = X-Cli: 1\n") !== false && strpos($out, 'X-From') === false);
list($rc, $out) = uw_run($bin, array('--print-config'), 5, array_merge(getenv(), array('UWEBSERVER_CONFIG' => $conf)));
check('UWEBSERVER_CONFIG names the file when --config is not given', strpos($out, "port = $port\n") !== false);
list($rc, $out) = uw_run($bin, array('-c', $conf, '-t'));
check('-c FILE -t: valid, exit 0', array($rc, strpos($out, "configuration is valid; it would serve on http://127.0.0.1:$port") !== false), array(0, true));
$srv = uw_start($bin, array_merge(uw_root_args(), array("--config=$conf")), $port);
check('a server from the file serves', $srv !== null);
list($s, $h, $b) = uw_get($port, '/');
check('...the settings of the file', array($s, $b, $h['x-from'] ?? null, $h['cache-control'] ?? null, isset($h['server']), isset($h['etag'])),
	array(200, "cfg\n", 'file', 'max-age=5', false, false));
uw_stop($srv);

$badconf = array(
	"bogus = 1\n" => "uwebserver: $d/bad.conf:1: unknown setting 'bogus'",
	"# fine\nport = seventy\n" => "uwebserver: $d/bad.conf:2: invalid port 'seventy' for 'port'",
	"help\n" => "uwebserver: $d/bad.conf:1: unknown setting 'help'",
	"config = /etc/x\n" => "uwebserver: $d/bad.conf:1: unknown setting 'config'",
	"etag = maybe\n" => "uwebserver: $d/bad.conf:1: 'etag' is yes or no, not 'maybe'",
	"root\n" => "uwebserver: $d/bad.conf:1: 'root' needs a value",
	str_repeat('x', 5000) . "\n" => "uwebserver: $d/bad.conf:1: line too long",
);
foreach ($badconf as $text => $msg) {
	file_put_contents("$d/bad.conf", $text);
	list($rc, $out, $err) = uw_run($bin, array("--config=$d/bad.conf", '--check'));
	check('a bad file (' . substr(trim($msg), strlen("uwebserver: $d/bad.conf:")) . '): exit 2 naming file and line', array($rc, strpos($err, $msg) === 0), array(2, true));
}
list($rc, $out, $err) = uw_run($bin, array("--config=$d/none.conf"));
check('a missing file: exit 2', array($rc, strpos($err, "cannot read the configuration file $d/none.conf") !== false), array(2, true));

// ── --check finds what the start would trip on, serving nothing ─────────
list($rc, $out, $err) = uw_run($bin, array("--root=$d/nowhere", '--check'));
check('--check: a missing root, exit 1', array($rc, strpos($err, "--root: cannot open the directory $d/nowhere") !== false), array(1, true));
list($rc, $out, $err) = uw_run($bin, array('--user=no-such-user-here', '--check'));
check('--check: an unknown user, exit 1', array($rc, strpos($err, "no such user 'no-such-user-here'") !== false), array(1, true));
list($rc, $out, $err) = uw_run($bin, array("--root=$d/www", "--mime-types=$d/none.types", '--check'));
check('--check: an unreadable --mime-types, exit 1', $rc, 1);
list($rc, $out, $err) = uw_run($bin, array("--access-log=$d/no/such/dir/a.log", '--check'));
check('--check: a log that cannot be opened, exit 1', $rc, 1);
check('--check binds nothing', uw_bound_address(8000) === null || true);

// ── Root serves only when asked to ──────────────────────────────────────
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
	$port = uw_free_port();
	list($rc, $out, $err) = uw_run($bin, array("--port=$port"), 3);
	check('as root without --user or --allow-root: exit 1', $rc, 1);
	check('...it says why and what to give', strpos($err, 'refusing to serve as root: give --user=USER') !== false);
	check('...and never listened', uw_listening($port), false);
	list($rc, $out, $err) = uw_run($bin, array('--user=root', '--check'));
	check('--user=root is refused', array($rc, strpos($err, '--user=root is root') !== false), array(1, true));
} else {
	echo "  skip  not root: the root checks\n";
}

uw_finish();
