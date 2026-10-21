<?php
/**
 * @module Q
 */
/**
 * Several servers on the same ports that share one opcode cache and one APCu.
 *
 * Q.webserver.instances runs that many servers side by side, each with its
 * own event loop and worker pool, on ports they share with SO_REUSEPORT
 * (Q.webserver.reusePort), so the kernel spreads connections across them and
 * each answers cached pages on a core of its own.
 *
 * Started as separate processes, each server would set up PHP on its own,
 * and with it its own opcode cache and its own APCu segment: every script
 * compiled once per server, and every entry an application keeps in APCu --
 * a query result, a response -- stored, missed and paid for once per server.
 * With four servers a page whose database work takes two seconds the first
 * time took two seconds four times over after every change, and a visitor
 * landed on whichever server had not paid yet. Shared memory is set up when
 * PHP starts and is handed to every process forked after that, so the
 * servers are forked here, from one process that has started PHP, before
 * any of them binds a port or forks workers.
 *
 * Each one is detached as if it had been started on its own: a new session,
 * the init process as its parent, its own pid file (the first one's name with
 * the instance number before the extension: server.pid, server.1.pid,
 * server.2.pid, the names a launcher uses for separately started instances)
 * and its own start record. QBIX_FORKED_INSTANCE in its environment says
 * which one it is, so a reload (SIGHUP re-executes the server with the same
 * arguments) comes back as the same instance and forks nothing; a server
 * reloaded that way sets PHP up again and from then on has caches of its own.
 * Their output goes where the first one's goes.
 *
 * @class Q_WebServer_Instances
 * @static
 */
class Q_WebServer_Instances
{
	/** The environment variable that names a forked instance (0 is the first). */
	const ENV = 'QBIX_FORKED_INSTANCE';

	/**
	 * How many servers Q.webserver.instances asks for: 1 to 64.
	 * @method count
	 * @static
	 * @param {mixed} $setting
	 * @return {integer}
	 */
	static function count($setting)
	{
		return max(1, min(64, (int) $setting));
	}

	/**
	 * The instance this process already is, from its environment, or null
	 * when it is the first start of the set.
	 * @method current
	 * @static
	 * @return {integer|null}
	 */
	static function current()
	{
		$v = getenv(self::ENV);
		if (!is_string($v) or $v === '' or !ctype_digit($v)) return null;
		return (int) $v;
	}

	/**
	 * A file of instance $i beside the first one's: the instance number before
	 * the extension (server.pid -> server.1.pid), appended when there is none.
	 * Instance 0 keeps the name.
	 * @method path
	 * @static
	 * @param {string} $path
	 * @param {integer} $i
	 * @return {string}
	 */
	static function path($path, $i)
	{
		$i = (int) $i;
		if ($i <= 0) return $path;
		$base = basename($path);
		$dot = strrpos($base, '.');
		if ($dot === false or $dot === 0) return $path . '.' . $i;
		$dir = dirname($path);
		return ($dir === '.' ? '' : $dir . '/') . substr($base, 0, $dot) . '.' . $i . substr($base, $dot);
	}

	/**
	 * Fork instances 1 to $n - 1 and say which one this process now is.
	 *
	 * Each one is forked twice -- the middle process starts a session and ends
	 * at once -- so it has no parent among the servers: a server that ends,
	 * or that the shutdown deadline kills along with every process it forked,
	 * takes none of the others with it. The middle process ends with SIGKILL,
	 * so nothing the start-up registered runs twice.
	 *
	 * Without pcntl or posix nothing is forked and this is instance 0 alone.
	 *
	 * @method fork
	 * @static
	 * @param {integer} $n
	 * @return {integer} the instance number of this process
	 */
	static function fork($n)
	{
		putenv(self::ENV . '=0');
		if ($n <= 1 or !function_exists('pcntl_fork') or !function_exists('posix_setsid')) return 0;
		for ($i = 1; $i < $n; $i++) {
			$middle = pcntl_fork();
			if ($middle < 0) {
				fwrite(STDERR, "  [instances] could not fork instance $i; running " . $i . " of $n\n");
				return 0;
			}
			if ($middle === 0) {
				posix_setsid();
				$server = pcntl_fork();
				if ($server === 0) {
					putenv(self::ENV . '=' . $i);
					return $i;
				}
				if ($server < 0) fwrite(STDERR, "  [instances] could not fork instance $i\n");
				// The middle process: nothing of the start-up may run here.
				posix_kill(posix_getpid(), SIGKILL);
				exit(0);
			}
			pcntl_waitpid($middle, $status);
		}
		return 0;
	}
}
