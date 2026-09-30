<?php
/**
 * The control panel's password rules, its bcrypt hashing, and its lockout --
 * in process, without a server.
 *
 *   - every rule of Q_WebServer_Panel_PasswordPolicy, on its own: a password
 *     that breaks only that rule fails with it, and a strong one passes;
 *   - configured minimums can be raised, and one below its floor is ignored;
 *   - hashes are bcrypt at Q.panel.bcryptCost (held between 10 and 15); a
 *     stored hash of another cost is replaced on the next sign-in, and an
 *     older unsalted format is accepted once and upgraded to bcrypt;
 *   - nothing over 72 bytes is accepted, as a new password or at sign-in;
 *   - five failed sign-ins lock an address out for 60 s, then 120 s, doubling
 *     up to an hour; a success clears the record;
 *   - `qbixctl panel:password` refuses a weak password with a non-zero exit
 *     and the same rules the web gives; --generate sets one that passes.
 *
 *   php tests/unit-panel-password-policy.php
 */
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
$base = sys_get_temp_dir() . DS . 'qbix-pwpolicy-' . getmypid();
@mkdir($base . DS . 'web', 0700, true);
define('APP_DIR', $base);
require __DIR__ . '/../src/Q.php';

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

$P = 'Q_WebServer_Panel_PasswordPolicy';
$A = 'Q_WebServer_Panel_Auth';
$ctx = array('default' => 'panel', 'brand' => 'Qbix Server', 'host' => 'example.org:8443');

/** Whether $pw fails, and only with a rule matching $needle. */
function failsOnly($pw, $needle, $ctx)
{
	$f = Q_WebServer_Panel_PasswordPolicy::check($pw, $ctx);
	return count($f) === 1 && stripos($f[0], $needle) !== false ? true : $f;
}

// ── Every rule on its own ───────────────────────────────────────
$good = 'Vx7#qLm2!Rt9@Kw4';
check('a strong password passes every rule', $P::check($good, $ctx), array());
check('length: 15 characters fails', failsOnly('Vx7#qLm2!Rt9@Kw', 'At least 16 characters', $ctx), true);
check('bytes: over 72 fails, and says why', count(array_filter($P::check(str_repeat('Vx7#qLm2!Rt9@Kw4', 5), $ctx),
	function ($r) { return strpos($r, 'bytes long') !== false; })), 1);
check('uppercase: none fails', failsOnly('vx7#qlm2!rt9@kw4', 'uppercase', $ctx), true);
check('lowercase: none fails', failsOnly('VX7#QLM2!RT9@KW4', 'lowercase', $ctx), true);
check('digit: none fails', failsOnly('Vxh#qLmz!Rtj@Kwp', 'digit', $ctx), true);
check('symbol: none fails', failsOnly('Vx7kqLm2cRt9dKw4', 'symbol', $ctx), true);
check('a non-ASCII letter counts as its case', $P::check('Éx7#qLm2!Rt9@Kw4', $ctx), array());
check('distinct: fewer than 10 fails', failsOnly('Ab1!Ab1!Ab1!Ab1!Ab1!Cd', 'different characters', $ctx), true);
check('repeats: a letter three times in a row fails', failsOnly('Vx7#qLm2!Rt9@Kwww4', 'No letter three or more times', $ctx), true);
check('repeats: digits, symbols and separators may repeat', $P::check('Vx7#qL-m2!Rt999@K***w4___', $ctx), array());
// An operator's real key: a year, a symbol cluster, separators, and a host
// label (alpha) that is not the whole host name.
check('an operator key with a year, symbols and a host label passes',
	$P::check('app-cp-alpha-demo-2999-VC-7X-$0*#(&0);[0]', array('default' => 'panel', 'brand' => 'Qbix Server', 'host' => 'alpha.example.com')), array());
check('runs: abcd fails', failsOnly('Vx7#abcdL2!Rt9@K', 'run of four', $ctx), true);
check('runs: 4321 fails', failsOnly('Vx#qL4321m!Rt@Kw', 'run of four', $ctx), true);
check('runs: a keyboard row (qwer) fails', failsOnly('Vx7#QwerL2!t9@K4', 'run of four', $ctx), true);
check('words: the default key fails', failsOnly('Vx7#PaNeL2!Rt9@K', '"panel"', $ctx), true);
check('words: "qbix" fails', failsOnly('Vx7#QbiX2!Rt9@Kw', '"qbix"', $ctx), true);
check('words: "admin" fails', failsOnly('Vx7#AdMiN2!Rt9@K', '"admin"', $ctx), true);
check('words: the brand fails', failsOnly('Vx7#qbix server2!R9@K', '"qbix', array('brand' => 'Qbix Server') + $ctx) !== array(), true);
check('words: the whole host name fails', failsOnly('Vx7#Example.org2!R9@K', '"example.org"', $ctx), true);
check('words: only part of the host name is fine', $P::check('Vx7#Example2!R9@Kq', $ctx), array());
check('common: a common password with digits and symbols added fails',
	count(array_filter($P::check('Sunshine!!2024##', $ctx), function ($r) { return strpos($r, 'common') !== false; })), 1);
check('strength: under 80 bits fails', failsOnly('Kw7!', 'bits', array('default' => null)) === true
	|| in_array(true, array_map(function ($r) { return strpos($r, 'bits') !== false; }, $P::check('Kw7!', $ctx)), true), true);
$hash = password_hash($good, PASSWORD_BCRYPT, array('cost' => 10));
check('current: the same password as now fails', failsOnly($good, 'differ from the current', array('currentHash' => $hash) + $ctx), true);

// ── Minimums: raised, never lowered ─────────────────────────────
Q_Config::set('Q', 'panel', 'passwordMinLength', 20);
check('a raised minimum length applies', failsOnly('Vx7#qLm2!Rt9@Kw4xZ', 'At least 20 characters', $ctx), true);
Q_Config::set('Q', 'panel', 'passwordMinLength', 8);
ob_start();
$r = $P::check('Vx7#qLm2!Rt9', $ctx);
ob_end_clean();
check('a minimum below its floor is ignored (16 still applies)', (bool) array_filter($r, function ($x) { return strpos($x, 'At least 16') !== false; }), true);
Q_Config::set('Q', 'panel', 'passwordMinLength', null);

// ── Hashing ─────────────────────────────────────────────────────
Q_Config::set('Q', 'panel', 'bcryptCost', 11);
check('bcrypt at the configured cost', password_get_info($A::hash($good))['algo'] === PASSWORD_BCRYPT
	&& strpos($A::hash($good), '$2y$11$') === 0, true);
Q_Config::set('Q', 'panel', 'bcryptCost', 99);
check('the cost is held at 15 at most', $A::cost(), 15);
Q_Config::set('Q', 'panel', 'bcryptCost', 4);
check('...and 10 at least', $A::cost(), 10);
Q_Config::set('Q', 'panel', 'bcryptCost', 10);
Q_Config::set('Q', 'panel', 'defaultPassword', null);

$file = $base . DS . 'local' . DS . 'panel.json';
@mkdir(dirname($file), 0700, true);
$parsed = array('clientIp' => '127.0.0.1', 'headers' => array());
file_put_contents($file, json_encode(array('passwordHash' => password_hash($good, PASSWORD_BCRYPT, array('cost' => 11)))));
list($st, $body) = $A::login($parsed, array('password' => $good));
$stored = json_decode(file_get_contents($file), true)['passwordHash'];
check('a hash of another cost signs in', $st, 200);
check('...and is rehashed at the configured cost', strpos($stored, '$2y$10$'), 0);

file_put_contents($file, json_encode(array('passwordHash' => hash('sha256', $good))));
list($st) = $A::login($parsed, array('password' => $good));
$stored = json_decode(file_get_contents($file), true)['passwordHash'];
check('an older unsalted SHA-256 hash is accepted once', $st, 200);
check('...and upgraded to bcrypt', password_get_info($stored)['algo'], PASSWORD_BCRYPT);
list($st) = $A::login($parsed, array('password' => $good . str_repeat('x', 60)));
check('a sign-in over 72 bytes is refused, even when its first 72 match', $st, 401);

// ── Lockout ─────────────────────────────────────────────────────
Q_WebServer_Panel_LockoutObserver::reset();
Q_WebServer_Panel_LockoutObserver::$clock = 1000000;
$E = 'Q_WebServer_Panel_Events';
$log = fopen('php://memory', 'w+');
$E::reset(false);
$E::attach(new Q_WebServer_Panel_LockoutObserver());
$E::attach(new Q_WebServer_Panel_LogObserver($log));
$E::attach(new Q_WebServer_Panel_DefaultPasswordObserver());
$ip = array('ip' => '198.51.100.7');
for ($i = 0; $i < 4; ++$i) $E::notify('login.failed', $ip);
check('four failures: not locked out', $E::notify('login.attempt', $ip), null);
$E::notify('login.failed', $ip);
$v = $E::notify('login.attempt', $ip);
check('the fifth locks the address out', $v['status'] ?? null, 429);
check('...for 60 seconds', $v['body']['retryAfter'] ?? null, 60);
Q_WebServer_Panel_LockoutObserver::$clock += 61;
check('after it, sign-in is allowed again', $E::notify('login.attempt', $ip), null);
for ($i = 0; $i < 5; ++$i) $E::notify('login.failed', $ip);
check('the next lockout doubles to 120 seconds', $E::notify('login.attempt', $ip)['body']['retryAfter'] ?? null, 120);
for ($k = 0; $k < 8; ++$k) {
	Q_WebServer_Panel_LockoutObserver::$clock += 4000;
	for ($i = 0; $i < 5; ++$i) $E::notify('login.failed', $ip);
}
check('...and stops at an hour', $E::notify('login.attempt', $ip)['body']['retryAfter'] ?? null, 3600);
check('another address is unaffected', $E::notify('login.attempt', array('ip' => '198.51.100.8')), null);
Q_WebServer_Panel_LockoutObserver::$clock += 4000;
$E::notify('login.succeeded', $ip + array('token' => 't', 'default' => false));
for ($i = 0; $i < 4; ++$i) $E::notify('login.failed', $ip);
check('a success clears the record', $E::notify('login.attempt', $ip), null);
rewind($log);
check('lockouts are logged', (bool) preg_match('/198\.51\.100\.7 locked out for 60s after 5 failed sign-ins/', stream_get_contents($log)), true);
Q_WebServer_Panel_LockoutObserver::$clock = null;
$E::reset();

// ── The command line ────────────────────────────────────────────
$ctl = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../sbin/qbixctl.php') . ' panel:password --root=' . escapeshellarg($base . DS . 'web');
@unlink($file);
foreach (array('short1!A', 'Sunshine!!2024##', 'Vx7#abcdL2!Rt9@K') as $weak) {
	$out = array();
	exec($ctl . ' --password=' . escapeshellarg($weak) . ' 2>&1', $out, $rc);
	$cliRules = array_values(array_map(function ($l) { return substr(trim($l), 2); },
		array_filter($out, function ($l) { return strpos(trim($l), '- ') === 0; })));
	$webRules = $P::check($weak, $A::policyContext(null, $file));
	check("the CLI refuses \"$weak\" with a non-zero exit", $rc !== 0, true);
	check("...with the same rules the web gives", $cliRules, $webRules);
}
check('...and writes nothing', is_file($file), false);
$out = array();
exec($ctl . ' --generate 2>&1', $out, $rc);
$generated = trim($out[1] ?? '');
check('--generate exits 0', $rc, 0);
check('...prints a 24-character password once', strlen($generated), 24);
check('...which passes the policy', $P::check($generated, array('default' => 'panel', 'brand' => 'Qbix Server')), array());
$stored = json_decode((string) @file_get_contents($file), true) ?: array();
list($ok) = $A::check($generated, $stored['passwordHash'] ?? '');
check('...and is the password stored, as bcrypt, not the default', array($ok, $stored['default'] ?? null), array(true, false));

foreach (array($file) as $f) @unlink($f);
@rmdir(dirname($file)); @rmdir($base . DS . 'web'); @rmdir($base);

if ($fail) { printf("  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
