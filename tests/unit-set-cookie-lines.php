<?php
/**
 * Every cookie a script sends with header('Set-Cookie: ...', false) is its own
 * Set-Cookie line.
 *
 * Repeated headers were kept as one, joined with ", " -- right for most headers,
 * wrong for Set-Cookie (RFC 6265 3: never folded). A browser reads the joined
 * line as one cookie whose attributes run into the second, so the second cookie
 * is never set. The request shield answers a solved check that way: it deletes
 * the solution cookie and sets the pass cookie. Behind Velocity the pass never
 * arrived, every page asked for the check again, and the admin's background
 * requests got the challenge as JSON (se7enxweb/exponential#363).
 *
 * Asserted on Q_WebServer_State, which collects a worker's headers:
 *   - two header('Set-Cookie: ...', false) calls give two cookie lines, in order,
 *     and no Set-Cookie among the ordinary headers;
 *   - header('Set-Cookie: ...') (replace) drops the ones sent before it, as PHP's
 *     header() does;
 *   - setHeader() keeps them apart the same way;
 *   - a value with a line break is not kept (no header injection);
 *   - clearHeaders() forgets them;
 *   - other repeated headers are still joined with ", ".
 *
 *   php tests/unit-set-cookie-lines.php
 */
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
require_once __DIR__ . '/../src/Q/WebServer/State.php';

$delete = 'rss=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax; Secure';
$set = 'rsp=2.abc.def; Path=/; Max-Age=7200; HttpOnly; SameSite=Lax; Secure';

Q_WebServer_State::clearHeaders();
Q_WebServer_State::header('Set-Cookie: ' . $delete, false);
Q_WebServer_State::header('Set-Cookie: ' . $set, false);
check('two cookies sent with header(..., false) are two lines, in order',
	Q_WebServer_State::cookieHeaders(), array($delete, $set));
check('...and neither is among the ordinary headers',
	array_key_exists('Set-Cookie', Q_WebServer_State::getHeaders()), false);

Q_WebServer_State::header('set-cookie: ' . $set);
check('header() with replace drops the cookies sent before it (any case of the name)',
	Q_WebServer_State::cookieHeaders(), array($set));

Q_WebServer_State::clearHeaders();
Q_WebServer_State::setHeader('Set-Cookie', $delete, false);
Q_WebServer_State::setHeader('Set-Cookie', $set, false);
check('setHeader() keeps cookies apart too',
	Q_WebServer_State::cookieHeaders(), array($delete, $set));

Q_WebServer_State::header("Set-Cookie: bad=1\r\nX-Injected: yes", false);
check('a value with a line break is not kept',
	count(Q_WebServer_State::cookieHeaders()), 2);

Q_WebServer_State::clearHeaders();
check('clearHeaders() forgets the cookies', Q_WebServer_State::cookieHeaders(), array());

Q_WebServer_State::header('Vary: Accept', false);
Q_WebServer_State::header('Vary: Cookie', false);
check('other repeated headers are still joined with ", "',
	Q_WebServer_State::getHeaders()['Vary'] ?? null, 'Accept, Cookie');
Q_WebServer_State::clearHeaders();

printf("%s  set-cookie-lines: %d passed, %d failed\n", $fail ? 'FAIL' : '  ok', $pass, $fail);
exit($fail ? 1 : 0);
