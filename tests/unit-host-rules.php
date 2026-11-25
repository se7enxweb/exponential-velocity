#!/usr/bin/env php
<?php

/**
 * Q_WebServer_HostRules without a server: access (deny, files, filesMatch,
 * Basic auth on a directory and its subtree, bcrypt, $apr1$, {SHA}), the
 * rewrite subset ([F], [G], redirects, front controller behind !-f/!-d,
 * [OR] conditions, RewriteBase of a subdirectory), DirectoryIndex, and paths
 * that try to step past a rule. tests/host-rules.sh runs the same against a
 * live server.
 *
 *   php tests/unit-host-rules.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/HostRules.php';

$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

$root = sys_get_temp_dir() . '/unit-host-rules-' . getmypid();
@mkdir("$root/admin/deep", 0755, true);
@mkdir("$root/docs", 0755, true);
@mkdir("$root/sub", 0755, true);
file_put_contents("$root/admin/index.html", 'a');
file_put_contents("$root/docs/start.html", 'd');
file_put_contents("$root/plain.txt", 'p');
file_put_contents("$root/sub/real.txt", 'r');
$users = "$root.users";
file_put_contents($users, "alice:" . password_hash('s3cret', PASSWORD_BCRYPT) . "\n"
	. "bob:\$apr1\$Ab3dEf9h\$" . "PLACEHOLDER\n" . "carol:{SHA}" . base64_encode(sha1('pw', true)) . "\n");

// Apache MD5: a known pair (htpasswd -nbm, password "hunter2", salt Ab3dEf9h) is checked against openssl when present.
$openssl = trim((string) @shell_exec('openssl passwd -apr1 -salt Ab3dEf9h hunter2 2>/dev/null'));
if (strncmp($openssl, '$apr1$', 6) === 0) {
	check('apr1 matches openssl', Q_WebServer_HostRules::apr1('hunter2', $openssl), $openssl);
	file_put_contents($users, str_replace("\$apr1\$Ab3dEf9h\$PLACEHOLDER", $openssl, (string) file_get_contents($users)));
}

Q_Config::set('Q', 'webserver', 'hosts', 'h.test', array(
	'root' => $root,
	'index' => array('start.html', 'index.php'),
	'access' => array(
		'deny' => array(array('dir' => 'private/'), array('dir' => '', 'files' => '.user.ini'), array('dir' => '', 'filesMatch' => '\.log$')),
		'auth' => array(array('dir' => 'admin/', 'realm' => 'A', 'userFile' => $users)),
	),
	'rewrite' => array(
		array('dir' => '', 'rules' => array(
			array('pattern' => '^var/.*\.php$', 'target' => '-', 'flags' => array('F' => true)),
			array('pattern' => '^gone$', 'target' => '-', 'flags' => array('G' => true)),
			array('pattern' => '^app\.php(/(.*)|$)', 'target' => '/$2', 'flags' => array('R' => 301, 'L' => true),
				'conds' => array(array('test' => '%{ENV:REDIRECT_STATUS}', 'pattern' => '^$'))),
			array('pattern' => '^old/(.*)$', 'target' => 'https://n.example/$1', 'flags' => array('R' => 301, 'L' => true)),
			array('pattern' => '.', 'target' => '/index.php', 'flags' => array('L' => true), 'conds' => array(
				array('test' => '%{REQUEST_FILENAME}', 'file' => '-f', 'negate' => true),
				array('test' => '%{REQUEST_FILENAME}', 'file' => '-d', 'negate' => true))),
		)),
		array('dir' => 'sub/', 'base' => '/sub/', 'rules' => array(
			array('pattern' => '^x/(.*)$', 'target' => 'real.txt?k=$1', 'flags' => array('L' => true)),
		)),
	),
));
function req($path, $auth = null, $ua = '')
{
	$p = array('method' => 'GET', 'uri' => $path, 'path' => explode('?', $path)[0], 'query' => (string) parse_url('http://x' . $path, PHP_URL_QUERY),
		'headers' => array('host' => 'h.test', 'user-agent' => $ua));
	if ($auth !== null) $p['headers']['authorization'] = 'Basic ' . base64_encode($auth);
	$r = Q_WebServer_HostRules::apply($p);
	return $r === null ? 'pass ' . $p['path'] . ($p['query'] !== '' ? '?' . $p['query'] : '') : $r['status'] . (isset($r['headers']['Location']) ? ' ' . $r['headers']['Location'] : '');
}

check('/admin/ without a password', req('/admin/'), '401');
check('/admin (no slash) without a password', req('/admin'), '401');
check('/admin/deep/x without a password', req('/admin/deep/x'), '401');
check('/admin/ bcrypt right', req('/admin/', 'alice:s3cret'), 'pass /admin/');
check('/admin/ bcrypt wrong', req('/admin/', 'alice:x'), '401');
if (strncmp($openssl, '$apr1$', 6) === 0) {
	check('/admin/ apr1 right', req('/admin/', 'bob:hunter2'), 'pass /admin/');
	check('/admin/ apr1 wrong', req('/admin/', 'bob:hunter3'), '401');
}
check('/admin/ {SHA} right', req('/admin/', 'carol:pw'), 'pass /admin/');
check('/administrator is not /admin/', req('/administrator'), 'pass /index.php');
check('deny dir', req('/private/x'), '403');
check('deny dir via dots', req('/docs/../private/x'), '403');
check('deny dir via encoding', req('/%70rivate/x'), '403');
check('climbing out is refused', req('/../etc/passwd'), '400');
check('files deny', req('/sub/.user.ini'), '403');
check('filesMatch deny', req('/a/b.log'), '403');
check('[F]', req('/var/cache/x.php'), '403');
check('[G]', req('/gone'), '410');
check('external redirect', req('/old/a/b'), '301 https://n.example/a/b');
check('front controller', req('/clean/url'), 'pass /index.php');
check('%{ENV:REDIRECT_STATUS} is empty on the request as asked', req('/app.php/abc'), '301 /abc');
check('existing file', req('/plain.txt'), 'pass /plain.txt');
check('directory index', req('/docs/'), 'pass /docs/start.html');
check('subdirectory rules with RewriteBase', req('/sub/x/7'), 'pass /sub/real.txt?k=7');

// The virtual host's own rules (server context, from vhost.conf): whole URL path, before access and .htaccess.
file_put_contents("$root/e401.html", '<h1>custom 401</h1>');
$h = Q_Config::get('Q', 'webserver', 'hosts', 'h.test', array());
$h['serverRewrite'] = array(
	array('pattern' => '^/\.well-known/acme-challenge/', 'target' => '-', 'flags' => array('L' => true)),
	array('pattern' => '^/moved/(.*)$', 'target' => 'https://n.example/$1', 'flags' => array('R' => 301, 'L' => true)),
	array('pattern' => '^/(.*)$', 'target' => 'https://other.example/$1', 'flags' => array('R' => 301, 'L' => true),
		'conds' => array(array('test' => '%{HTTP_HOST}', 'pattern' => '^nothere\.example$', 'nocase' => true))),
	array('pattern' => '^/inside/(.*)$', 'target' => '/docs/$1', 'flags' => array('L' => true)),
);
$h['errorDocuments'] = array('401' => "$root/e401.html", '403' => "$root/missing.html");
Q_Config::set('Q', 'webserver', 'hosts', 'h.test', $h);
check('server rule redirect, before the password', req('/moved/admin/x'), '301 https://n.example/admin/x');
check('server rule [L] with "-" goes on to the directory rules', req('/.well-known/acme-challenge/t'), 'pass /index.php');
check('server rule whose condition fails does nothing', req('/plain.txt'), 'pass /plain.txt');
check('server rule internal rewrite, then the directory index', req('/inside/'), 'pass /docs/start.html');
check('server rule internal rewrite still meets the password', req('/inside/../admin/'), '401');
$p = array('method' => 'GET', 'uri' => '/admin/', 'path' => '/admin/', 'query' => '', 'headers' => array('host' => 'h.test'));
$r = Q_WebServer_HostRules::apply($p);
check('401 sends the host\'s error document', $r['body'] ?? null, '<h1>custom 401</h1>');
check('401 keeps its realm with an error document', $r['headers']['WWW-Authenticate'] ?? null, 'Basic realm="A"');
$p = array('method' => 'GET', 'uri' => '/private/x', 'path' => '/private/x', 'query' => '', 'headers' => array('host' => 'h.test'));
$r = Q_WebServer_HostRules::apply($p);
check('a missing error document falls back to the plain text', $r['body'] ?? null, "403 Forbidden\n");
@unlink("$root/e401.html");

// SymLinksIfOwnerMatch: a link out of the root counts when it has its target's owner.
if (!class_exists('Q_WebServer', false)) require_once __DIR__ . '/../src/Q/WebServer.php';
$outside = "$root.outside";
@mkdir($outside, 0755);
file_put_contents("$outside/x.php", '<?php');
@symlink("$outside/x.php", "$root/linked.php");
check('a link with its target\'s owner is followed', Q_WebServer::linksOwnerMatch($root, "$root/linked.php"), true);
check('a path that climbs is not', Q_WebServer::linksOwnerMatch($root, "$root/../x"), false);
if (function_exists('posix_getuid') and posix_getuid() === 0 and @lchown("$root/linked.php", 65534)) {
	check('a link owned by another user is not followed', Q_WebServer::linksOwnerMatch($root, "$root/linked.php"), false);
}
@unlink("$root/linked.php");
@unlink("$outside/x.php");
@rmdir($outside);

array_map('unlink', array_filter((array) glob("$root/*/*/*") + (array) glob("$root/*/*"), 'is_file'));
@unlink($users);
echo ($fail ? 'FAIL' : 'PASS') . ": unit-host-rules, $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
