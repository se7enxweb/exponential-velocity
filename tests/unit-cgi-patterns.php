<?php

/**
 * Q.webserver.cgi.patterns: "*" means every script, and the default is [].
 *
 * Without pcntl the default was array('*'), meant as "every script through
 * php-cgi". Wrapped as a regex, "*" is invalid ("quantifier does not follow a
 * repeatable item"), preg_match() returned false under @, and the entry
 * matched nothing, silently. Asserted:
 *
 *   - a lone "*" matches every script;
 *   - a regex entry still matches as before, with or without "#" delimiters;
 *   - an invalid regex matches nothing and is not mistaken for a match;
 *   - the default in the source is [] on every platform, as documented.
 *
 *   php tests/unit-cgi-patterns.php
 */

require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer.php';

$pass = 0;
$fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

check('"*" matches a script at the root', Q_WebServer::cgiPatternMatches('*', '/index.php'), true);
check('"*" matches a script below it', Q_WebServer::cgiPatternMatches(' * ', '/wp-admin/post.php'), true);
check('a regex matches what it names', Q_WebServer::cgiPatternMatches('wp-.*\\.php', '/wp-login.php'), true);
check('...and not what it does not', Q_WebServer::cgiPatternMatches('wp-.*\\.php', '/index.php'), false);
check('a #-delimited regex works as before', Q_WebServer::cgiPatternMatches('#^/legacy/#', '/legacy/a.php'), true);
check('an invalid regex matches nothing', Q_WebServer::cgiPatternMatches('(', '/index.php'), false);
check('a non-string entry matches nothing', Q_WebServer::cgiPatternMatches(array('*'), '/index.php'), false);

$src = file_get_contents(__DIR__ . '/../src/Q/WebServer.php');
check('the default is [] on every platform',
	strpos($src, "Q_Config::get('Q', 'webserver', 'cgi', 'patterns', array())") !== false, true);
check('no invalid "*" default is left', strpos($src, "array('*')"), false);
check('the carveout matches through cgiPatternMatches()',
	strpos($src, 'if (self::cgiPatternMatches($pattern, $relPath)) {') !== false, true);

echo ($fail ? 'FAIL' : 'PASS') . "  $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
