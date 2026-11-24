<?php
/**
 * @module Q
 */

/**
 * The few process operations the server needs, on every system it runs on:
 * whether a process is alive, how to end one, where output goes to be
 * discarded, and how a console interrupt is caught.
 *
 * On Linux, the BSDs and macOS these are what the server always did --
 * posix_kill(), /proc, SIGTERM -- and nothing changes there. Windows has no
 * pcntl and no posix extension: a process is asked about with tasklist, ended
 * with taskkill, output is discarded into NUL rather than /dev/null, and
 * CTRL+C reaches PHP through sapi_windows_set_ctrl_handler(). Every external
 * program is started with an argument array (proc_open() without a shell),
 * so no command line has to be quoted for cmd.exe and no "2>/dev/null" can
 * reach a shell that does not know it.
 *
 * A server without signals is told to stop or reload through a request file
 * beside its pid file (requestControl(), takeControlRequest()): the command
 * that would have sent SIGTERM or SIGHUP writes "<pidfile>.stop" or
 * "<pidfile>.reload", and the running server picks it up within a second.
 *
 * @class Q_WebServer_Process
 * @static
 */
class Q_WebServer_Process
{
	/**
	 * Answer as Windows (true) or as a POSIX system (false) whatever PHP runs
	 * on; null asks PHP_OS_FAMILY. For tests that exercise the Windows paths
	 * on Linux.
	 * @property $forceWindows
	 * @type {boolean|null}
	 */
	public static $forceWindows = null;

	/**
	 * Whether this is Windows.
	 * @method isWindows
	 * @static
	 * @return {boolean}
	 */
	static function isWindows()
	{
		if (self::$forceWindows !== null) return (bool) self::$forceWindows;
		return PHP_OS_FAMILY === 'Windows';
	}

	/**
	 * Where discarded output goes: NUL on Windows, /dev/null elsewhere.
	 * @method nullDevice
	 * @static
	 * @return {string}
	 */
	static function nullDevice()
	{
		return self::isWindows() ? 'NUL' : '/dev/null';
	}

	/**
	 * Whether a path is absolute: /x, \x, C:\x, C:/x, \\server\share, or a
	 * stream URL (phar://, file://).
	 * @method isAbsolutePath
	 * @static
	 * @param {string} $path
	 * @return {boolean}
	 */
	static function isAbsolutePath($path)
	{
		$path = (string) $path;
		if ($path === '') return false;
		if ($path[0] === '/' or $path[0] === '\\') return true;
		if (preg_match('#^[A-Za-z]:[\\\\/]#', $path)) return true;
		return (bool) preg_match('#^[a-z][a-z0-9+.-]*://#i', $path);
	}

	/**
	 * Whether a process is running.
	 *
	 * POSIX: signal 0 reaches it and /proc does not call it a zombie.
	 * Windows: tasklist lists it.
	 * @method isAlive
	 * @static
	 * @param {integer} $pid
	 * @return {boolean}
	 */
	static function isAlive($pid)
	{
		$pid = (int) $pid;
		if ($pid <= 0) return false;
		if (self::isWindows()) {
			if ($pid === getmypid()) return true;
			$out = self::run(array('tasklist', '/FI', "PID eq $pid", '/NH', '/FO', 'CSV'));
			if ($out === null) return false;
			return self::tasklistHasPid($out, $pid);
		}
		$stat = @file_get_contents("/proc/$pid/stat");
		if (is_string($stat) and ($p = strrpos($stat, ')')) !== false) {
			$state = substr($stat, $p + 2, 1);
			return $state !== 'Z' and $state !== 'X';
		}
		if (function_exists('posix_kill')) return @posix_kill($pid, 0);
		return false;
	}

	/**
	 * Whether tasklist's CSV output (/NH /FO CSV) lists $pid. Its second
	 * column is the pid; "INFO: No tasks are running..." when nothing
	 * matched, in the system's language, so only the rows are read.
	 * @method tasklistHasPid
	 * @static
	 * @param {string} $csv
	 * @param {integer} $pid
	 * @return {boolean}
	 */
	static function tasklistHasPid($csv, $pid)
	{
		foreach (preg_split('/\r?\n/', (string) $csv) as $line) {
			$row = str_getcsv(trim($line), ',', '"', '');
			if (count($row) >= 2 and ctype_digit((string) $row[1]) and (int) $row[1] === (int) $pid) {
				return true;
			}
		}
		return false;
	}

	/**
	 * End a process: SIGTERM (or SIGKILL with $force) where there are
	 * signals; taskkill on Windows, with /T so the processes it started go
	 * too, and /F when forced. Without /F taskkill asks the process to close,
	 * which a console program without a window does not hear, so a server on
	 * Windows is stopped through its request file first (requestControl()).
	 * @method terminate
	 * @static
	 * @param {integer} $pid
	 * @param {boolean} [$force=false]
	 * @return {boolean} whether the request was delivered
	 */
	static function terminate($pid, $force = false)
	{
		$pid = (int) $pid;
		if ($pid <= 0) return false;
		if (self::isWindows()) {
			$args = array('taskkill', '/PID', (string) $pid, '/T');
			if ($force) $args[] = '/F';
			return self::run($args, $code) !== null and $code === 0;
		}
		if (!function_exists('posix_kill')) return false;
		$sig = $force ? (defined('SIGKILL') ? SIGKILL : 9) : (defined('SIGTERM') ? SIGTERM : 15);
		return @posix_kill($pid, $sig);
	}

	/**
	 * Run a program with an argument array, no shell, and return what it
	 * wrote to standard output (null if it could not be started). Standard
	 * error is discarded.
	 * @method run
	 * @static
	 * @param {array} $argv
	 * @param {integer} [&$code] its exit code
	 * @param {float} [$timeout=10.0] seconds before it is ended
	 * @return {string|null}
	 */
	static function run(array $argv, &$code = null, $timeout = 10.0)
	{
		$code = -1;
		if (!function_exists('proc_open')) return null;
		$spec = array(
			0 => array('file', self::nullDevice(), 'r'),
			1 => array('pipe', 'w'),
			2 => array('file', self::nullDevice(), 'w'),
		);
		$options = self::isWindows() ? array('bypass_shell' => true, 'suppress_errors' => true) : array();
		$proc = @proc_open(array_map('strval', $argv), $spec, $pipes, null, null, $options);
		if (!is_resource($proc)) return null;
		$out = '';
		$deadline = microtime(true) + $timeout;
		stream_set_blocking($pipes[1], false);
		while (true) {
			$chunk = @fread($pipes[1], 65536);
			if (is_string($chunk) and $chunk !== '') { $out .= $chunk; continue; }
			if (feof($pipes[1])) break;
			if (microtime(true) > $deadline) { @proc_terminate($proc); break; }
			$r = array($pipes[1]); $w = $e = null;
			@stream_select($r, $w, $e, 0, 50000);
		}
		fclose($pipes[1]);
		$code = proc_close($proc);
		return $out;
	}

	// ── Control requests (stop/reload without signals) ─────────────

	/**
	 * The request file for $what beside a pid file.
	 * @method controlFile
	 * @static
	 * @param {string} $pidFile
	 * @param {string} $what "stop" or "reload"
	 * @return {string}
	 */
	static function controlFile($pidFile, $what)
	{
		return $pidFile . '.' . $what;
	}

	/**
	 * Ask the server behind $pidFile to stop or reload, by request file.
	 * @method requestControl
	 * @static
	 * @param {string} $pidFile
	 * @param {string} $what "stop" or "reload"
	 * @return {boolean}
	 */
	static function requestControl($pidFile, $what)
	{
		if (!in_array($what, array('stop', 'reload'), true)) return false;
		$file = self::controlFile($pidFile, $what);
		$tmp = $file . '.' . getmypid() . '.tmp';
		if (@file_put_contents($tmp, (string) time()) === false) return false;
		if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
		return true;
	}

	/**
	 * Take the pending request for the server behind $pidFile, if any: the
	 * file is removed and "stop" or "reload" returned; stop wins when both
	 * are there.
	 * @method takeControlRequest
	 * @static
	 * @param {string} $pidFile
	 * @return {string|null}
	 */
	static function takeControlRequest($pidFile)
	{
		if ($pidFile === null or $pidFile === '') return null;
		$found = null;
		foreach (array('reload', 'stop') as $what) {
			$file = self::controlFile($pidFile, $what);
			if (is_file($file)) {
				@unlink($file);
				$found = $what;
			}
		}
		return $found;
	}

	// ── Console interrupts ──────────────────────────────────────

	/**
	 * Run $handler on CTRL+C or CTRL+BREAK in a Windows console. The handler
	 * runs between PHP instructions, as a pcntl signal handler would.
	 * Returns false where there is no such console API (not Windows, not the
	 * CLI SAPI); POSIX signals go through Q_Evented::onSignal() as before.
	 * @method onConsoleInterrupt
	 * @static
	 * @param {callable|null} $handler null to ignore the interrupts
	 * @return {boolean}
	 */
	static function onConsoleInterrupt($handler)
	{
		if (!function_exists('sapi_windows_set_ctrl_handler')) return false;
		$cb = $handler ?: function ($event) {};
		return (bool) @sapi_windows_set_ctrl_handler($cb, true);
	}
}
