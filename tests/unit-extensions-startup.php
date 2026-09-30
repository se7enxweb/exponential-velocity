#!/usr/bin/env php
<?php
/**
 * What the running server does with the extension baseline, against real
 * servers:
 *
 *   - /Q/health carries `extensions` (variant_detected, missing_required,
 *     missing_recommended, incomplete, hints), consistent with ext:check;
 *   - the start-up warning is printed exactly when something is missing, and
 *     Q.webserver.extensionsCheck = false silences it;
 *   - a PHP without tokenizer (php -n, where it is a shared extension) is
 *     refused at start with the reason and the fix, and exits non-zero.
 *
 *   php tests/unit-extensions-startup.php
 */
if (!function_exists('pcntl_fork')) { printf("  skip  needs pcntl\n"); exit(0); }
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
$server = __DIR__ . '/../sbin/qbixserver.php';
$base = sys_get_temp_dir() . '/qbix-extstart-' . getmypid();
@mkdir("$base/app/web", 0700, true);
file_put_contents("$base/app/web/index.php", '<?php echo "ok";');

function freePort()
{
	$s = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
	$name = stream_socket_get_name($s, false);
	fclose($s);
	return (int) substr($name, strrpos($name, ':') + 1);
}
/** Start a server; return array(process, port, log file). */
function start($name, array $config, array $phpArgs = array())
{
	global $base, $server;
	$port = freePort();
	file_put_contents("$base/$name.json", json_encode($config));
	$cmd = array_merge(array(PHP_BINARY), $phpArgs, array($server, "--root=$base/app/web", "--port=$port",
		"--config=$base/$name.json", '--workers=2'));
	$proc = proc_open($cmd, array(0 => array('file', '/dev/null', 'r'), 1 => array('file', "$base/$name.log", 'w'),
		2 => array('file', "$base/$name.log", 'a')), $pipes);
	for ($i = 0; $i < 60; ++$i) {
		$st = proc_get_status($proc);
		if (!$st['running']) return array($proc, 0, "$base/$name.log", $st['exitcode']);
		$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 1);
		if ($s) { fclose($s); return array($proc, $port, "$base/$name.log", null); }
		usleep(100000);
	}
	return array($proc, 0, "$base/$name.log", null);
}
function stop($proc)
{
	$st = proc_get_status($proc);
	if ($st['running']) { posix_kill($st['pid'], SIGTERM); usleep(300000); }
	proc_close($proc);
}
// The panel's default key would send /Q/health's stats behind a sign-in.
$cfg = array('Q' => array('panel' => array('defaultPassword' => null)));

// ── /Q/health and the warning ──────────────────────────────
list($proc, $port, $log) = start('health', $cfg);
check('a server starts', $port > 0, true);
$health = json_decode((string) @file_get_contents("http://127.0.0.1:$port/Q/health"), true);
$x = $health['extensions'] ?? null;
check('/Q/health carries extensions', is_array($x), true);
foreach (array('variant_detected', 'missing_required', 'missing_recommended', 'incomplete', 'hints', 'platform', 'php') as $k) {
	check("...with $k", is_array($x) && array_key_exists($k, $x), true);
}
stop($proc);
$out = (string) @file_get_contents($log);
$missing = $x ? array_merge($x['missing_required'], $x['missing_recommended']) : array();
check('the start-up warning appears exactly when something is missing',
	strpos($out, 'extensions: this PHP provides') !== false, (bool) ($missing || ($x['incomplete'] ?? array())));
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../sbin/qbixctl.php') . ' ext:check --format=json 2>/dev/null', $lines);
$c = json_decode(implode("\n", $lines), true);
check('/Q/health agrees with ext:check', array($x['variant_detected'] ?? null, $x['missing_required'] ?? null),
	array($c['variant_detected'] ?? 'x', $c['missing_required'] ?? 'x'));

// ── Silenced ───────────────────────────────────────────────
$quiet = $cfg; $quiet['Q']['webserver']['extensionsCheck'] = false;
list($proc, $port, $log) = start('quiet', $quiet);
stop($proc);
check('Q.webserver.extensionsCheck = false silences the warning', strpos((string) @file_get_contents($log), 'extensions: this PHP provides'), false);

// ── A hard need refuses the start ──────────────────────────
exec(escapeshellarg(PHP_BINARY) . ' -n -m 2>/dev/null', $mods);
$mods = array_map('strtolower', array_map('trim', $mods));
if (in_array('tokenizer', $mods, true) || !in_array('pcntl', $mods, true)) {
	printf("  skip  php -n here %s, so the refusal cannot be shown with it\n",
		in_array('tokenizer', $mods, true) ? 'has tokenizer built in' : 'has no pcntl');
} else {
	list($proc, $port, $log, $exit) = start('refused', $cfg, array('-n'));
	for ($i = 0; $i < 50 && $exit === null; ++$i) { $st = proc_get_status($proc); if (!$st['running']) $exit = $st['exitcode']; else usleep(100000); }
	stop($proc);
	$out = (string) @file_get_contents($log);
	check('without tokenizer the server does not start', $port, 0);
	check('...exits non-zero', $exit !== null && $exit !== 0, true);
	check('...and says what it lacks and why', strpos($out, 'lacks what the server needs') !== false && strpos($out, 'tokenizer') !== false, true);
}

foreach (glob("$base/*") as $f) if (is_file($f)) @unlink($f);
@unlink("$base/app/web/index.php"); @rmdir("$base/app/web"); @rmdir("$base/app"); @rmdir($base);
if ($fail) { printf("  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
