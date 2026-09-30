<?php
/**
 * @module Q
 */
/**
 * How a running server was started, so it can be started the same way again.
 *
 * A pid file says which process runs, not how it was started, and a restart
 * needs the second: the document root, the ports, the bind address, the
 * workers, --config, --conf-dir, --keep-globals and every other option, the
 * interpreter's own -d settings, the working directory relative paths were
 * given against, the environment that moves the configuration trees, and
 * where its output went. The server writes all of that beside its pid file
 * (<pid file>.json) when it starts and removes it with the pid file when it
 * stops. For a server started without a pid file, or by a version that did
 * not write one, the same record is read from the process table instead
 * (/proc/<pid>/cmdline, cwd, environ and fd/1).
 *
 * @class Q_WebServer_StartRecord
 * @static
 */
class Q_WebServer_StartRecord
{
	/** Environment variables a restart carries over: the ones the engine and its distributions read at start-up. */
	const ENV_PATTERN = '/^(QBIX_[A-Z0-9_]+|[A-Z][A-Z0-9]*_(CONF_DIR|STATE_DIR|DISTRIBUTION|RUN_USER|RUN_GROUP))$/';

	/**
	 * The record file that goes with a pid file.
	 * @method file
	 * @static
	 * @param {string} $pidFile
	 * @return {string}
	 */
	static function file($pidFile)
	{
		return $pidFile . '.json';
	}

	/**
	 * This process's own record: called by the server once it knows it runs.
	 * @method capture
	 * @static
	 * @param {array} $rawArgv the command line as PHP gave it ($_SERVER['argv'])
	 * @return {array} pid, command, args, cwd, env, log, started
	 */
	static function capture(array $rawArgv)
	{
		$args = array_values(array_slice($rawArgv, 1));
		$command = null;
		$cmdline = @file_get_contents('/proc/self/cmdline');
		if (is_string($cmdline) and $cmdline !== '') {
			$all = explode("\0", rtrim($cmdline, "\0"));
			$n = count($all) - count($args);
			// The tail of the process's command line is the script's own
			// arguments; what comes before is the interpreter, its -d
			// settings and the script (or the phar, or the binary).
			if ($n >= 1 and array_slice($all, $n) === $args) $command = array_slice($all, 0, $n);
		}
		if ($command === null) {
			$script = (string) ($rawArgv[0] ?? '');
			$command = (PHP_SAPI === 'micro' or $script === '') ? array($script !== '' ? $script : PHP_BINARY) : array(PHP_BINARY, $script);
		}
		return array(
			'pid' => getmypid(),
			'command' => $command,
			'args' => $args,
			'cwd' => (string) getcwd(),
			'env' => self::environment(getenv()),
			'log' => self::outputFile('/proc/self/fd/1'),
			'started' => time(),
		);
	}

	/**
	 * Write the record beside the pid file.
	 * @method write
	 * @static
	 * @param {string} $pidFile
	 * @param {array} $record
	 * @return {bool}
	 */
	static function write($pidFile, array $record)
	{
		$file = self::file($pidFile);
		$tmp = $file . '.' . getmypid() . '.tmp';
		if (@file_put_contents($tmp, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n") === false) return false;
		@chmod($tmp, 0640);
		if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
		return true;
	}

	/**
	 * The record beside a pid file, when it is that process's: a record left
	 * by an earlier server is not this one's.
	 * @method read
	 * @static
	 * @param {string} $pidFile
	 * @param {int} $pid the process it must describe
	 * @return {array|null}
	 */
	static function read($pidFile, $pid)
	{
		if (!is_string($pidFile) or $pidFile === '') return null;
		$raw = @file_get_contents(self::file($pidFile));
		$r = is_string($raw) ? json_decode($raw, true) : null;
		if (!is_array($r) or (int) ($r['pid'] ?? 0) !== (int) $pid) return null;
		if (!isset($r['command'], $r['args']) or !is_array($r['command']) or !is_array($r['args'])) return null;
		$r['source'] = 'record';
		return $r;
	}

	/**
	 * Remove the record, only while it describes this process.
	 * @method remove
	 * @static
	 */
	static function remove($pidFile, $pid)
	{
		if (self::read($pidFile, $pid) !== null) @unlink(self::file($pidFile));
	}

	/**
	 * The same record read from the process table, for a server that wrote
	 * none. $scriptIndex is where its script (or phar) is in the command line.
	 * @method fromProcess
	 * @static
	 * @param {int} $pid
	 * @param {int} $scriptIndex
	 * @return {array|null}
	 */
	static function fromProcess($pid, $scriptIndex)
	{
		$pid = (int) $pid;
		$cmdline = @file_get_contents("/proc/$pid/cmdline");
		if (!is_string($cmdline) or $cmdline === '') return null;
		$all = explode("\0", rtrim($cmdline, "\0"));
		if ($scriptIndex < 0 or $scriptIndex >= count($all)) return null;
		$env = array();
		$environ = @file_get_contents("/proc/$pid/environ");
		if (is_string($environ)) {
			foreach (explode("\0", $environ) as $kv) {
				$eq = strpos($kv, '=');
				if ($eq) $env[substr($kv, 0, $eq)] = substr($kv, $eq + 1);
			}
		}
		$cwd = @readlink("/proc/$pid/cwd");
		return array(
			'pid' => $pid,
			'command' => array_slice($all, 0, $scriptIndex + 1),
			'args' => array_values(array_slice($all, $scriptIndex + 1)),
			'cwd' => $cwd === false ? null : $cwd,
			'env' => self::environment($env),
			'log' => self::outputFile("/proc/$pid/fd/1"),
			'source' => 'process',
		);
	}

	/**
	 * Where the script is in a process's command line: after the PHP
	 * interpreter and its own options (-d name=value, -c file, -n ...), or
	 * the program itself when that is not PHP (a phar run directly, the
	 * static binary). Null when there is no such process.
	 * @method scriptIndex
	 * @static
	 * @param {int} $pid
	 * @return {int|null}
	 */
	static function scriptIndex($pid)
	{
		$cmdline = @file_get_contents('/proc/' . (int) $pid . '/cmdline');
		if (!is_string($cmdline) or $cmdline === '') return null;
		$all = explode("\0", rtrim($cmdline, "\0"));
		if (!preg_match('/^php[\d.-]*$|^php-cli$/', basename($all[0]))) return 0;
		for ($i = 1, $n = count($all); $i < $n; ++$i) {
			$a = $all[$i];
			if ($a === '-f') return $i + 1 < $n ? $i + 1 : null;
			if (in_array($a, array('-d', '-c', '-z'), true)) { ++$i; continue; }
			if ($a !== '' and $a[0] === '-') continue;
			return $i;
		}
		return null;
	}

	/**
	 * The options in a recorded command line, by name ("--root=x" or
	 * "--root x"; a flag is true). The last one given wins, as in the server.
	 * @method options
	 * @static
	 * @param {array} $args
	 * @return {array}
	 */
	static function options(array $args)
	{
		$opts = array();
		for ($i = 0, $n = count($args); $i < $n; ++$i) {
			$a = (string) $args[$i];
			if ($a === '--') break;
			if (!preg_match('/^--?([A-Za-z][\w-]*)(?:=(.*))?$/s', $a, $m)) continue;
			if (isset($m[2])) $opts[$m[1]] = $m[2];
			elseif ($i + 1 < $n and ((string) $args[$i + 1] === '' or ((string) $args[$i + 1])[0] !== '-')
				and in_array($m[1], self::$valued, true)) $opts[$m[1]] = (string) $args[++$i];
			else $opts[$m[1]] = true;
		}
		return $opts;
	}

	/**
	 * The recorded arguments with some options replaced (or added): what a
	 * restart given --port=N, say, starts with. Every other argument stays
	 * where it was.
	 * @method override
	 * @static
	 * @param {array} $args
	 * @param {array} $with name => value
	 * @return {array}
	 */
	static function override(array $args, array $with)
	{
		if (!$with) return array_values($args);
		$out = array();
		for ($i = 0, $n = count($args); $i < $n; ++$i) {
			$a = (string) $args[$i];
			if ($a === '--') { $out = array_merge($out, array_slice($args, $i)); break; }
			if (preg_match('/^--?([A-Za-z][\w-]*)(=.*)?$/s', $a, $m) and array_key_exists($m[1], $with)) {
				if (!isset($m[2]) and in_array($m[1], self::$valued, true) and $i + 1 < $n
					and ((string) $args[$i + 1] === '' or ((string) $args[$i + 1])[0] !== '-')) ++$i;
				continue;
			}
			$out[] = $a;
		}
		$tail = array();
		foreach ($with as $k => $v) {
			if ($v === false or $v === null) continue;
			$tail[] = $v === true ? "--$k" : "--$k=$v";
		}
		$dd = array_search('--', $out, true);
		if ($dd === false) return array_merge($out, $tail);
		return array_merge(array_slice($out, 0, $dd), $tail, array_slice($out, $dd));
	}

	/** The server's options that take a value (qbixserver.php's list). */
	static $valued = array('root', 'app', 'host', 'port', 'https-port', 'socket', 'socket-mode', 'workers', 'config',
		'preset', 'sign', 'verify', 'key', 'key-id', 'generate-key', 'policy', 'pid', 'pack', 'output',
		'keep-globals', 'conf-dir', 'distribution', 'deploy', 'signer', 'm', 'user', 'group');

	/** The variables of an environment a restart carries over. */
	static function environment(array $env)
	{
		$out = array();
		foreach ($env as $k => $v) {
			if (is_string($k) and preg_match(self::ENV_PATTERN, $k)) $out[$k] = (string) $v;
		}
		ksort($out);
		return $out;
	}

	/** The file a process's output goes to, when it is a file (not a terminal, pipe or /dev/null). */
	static function outputFile($fdLink)
	{
		$t = @readlink($fdLink);
		if (!is_string($t) or $t === '' or $t[0] !== '/' or strncmp($t, '/dev/', 5) === 0) return null;
		if (substr($t, -10) === ' (deleted)') return null;
		return is_file($t) ? $t : null;
	}
}
