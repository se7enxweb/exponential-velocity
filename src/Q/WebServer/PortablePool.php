<?php
/**
 * @module Q
 */

if (!class_exists('Q_WebServer_Pool', false)) require_once __DIR__ . '/Pool.php';
if (!class_exists('Q_WebServer_Process', false)) require_once __DIR__ . '/Process.php';

/**
 * A worker pool for systems without fork(): Windows, or any PHP built
 * without pcntl. Q.webserver.poolMode "portable" (or "auto" where there is
 * no fork; see Q_WebServer_Pool::mode()).
 *
 * Every worker is a process of its own, started with proc_open() -- on
 * Windows without cmd.exe (bypass_shell) and in a process group of its own,
 * so a CTRL+C in the server's console does not reach it. It runs the
 * server's own command line again (sbin/qbixserver.php with the same
 * options), reads the same configuration, boots the application -- the
 * compat layer, the warm-up, the statics snapshot, what a forked worker
 * inherits from the server instead -- and then serves requests exactly as a
 * forked worker does: the same length-prefixed frames, the same reset
 * between requests (Q_WebServer_Pool::serve()).
 *
 *   Server (event loop)
 *     ├── loopback listener 127.0.0.1:<ephemeral>  workers connect here
 *     ├── Worker 0  socket: requests and answers   pipes: stdin, control
 *     ├── Worker 1  ...
 *
 * Two channels per worker:
 *
 *   - Requests and answers go over a loopback TCP connection the worker
 *     opens to the server. It is the same protocol as a forked worker's
 *     socket pair; sockets are what stream_select() watches reliably on
 *     every system. The worker proves who it is with a token the server
 *     gave it on its standard input -- never on the command line or in the
 *     environment, where other processes can read it -- so no other local
 *     process can pose as a worker and be sent visitors' requests.
 *
 *   - Its standard output is its control pipe: one ASCII line per event,
 *       READY <pid>        booted and connected
 *       DONE <requests>    answered a request; how many so far
 *       EXIT <code>        leaving, with this exit code
 *     read by the supervisor with a non-blocking stream_select(). Anything
 *     else a worker writes there is passed on to the server's log.
 *
 * Supervision: a worker that has not connected within
 * Q.webserver.portable.startTimeout seconds (60) is ended and replaced; one
 * that dies while booting is replaced after a delay that doubles with each
 * failure in a row (up to 30 s), so a broken configuration does not spin;
 * one that serves Q.webserver.maxRequests requests leaves and is replaced;
 * one that dies on a request is handled as any pool handles a dead worker
 * (the request retried or answered 502). Stopping closes every worker's
 * socket and standard input -- a worker leaves when its socket closes --
 * waits, and only then ends what is left.
 *
 * The fork pool stays the default wherever fork exists; this one is chosen
 * there only by Q.webserver.poolMode "portable", which is how it is tested
 * on Linux.
 *
 * @class Q_WebServer_PortablePool
 * @extends Q_WebServer_Pool
 */
class Q_WebServer_PortablePool extends Q_WebServer_Pool
{
	/** The first word of a worker's greeting on its socket. */
	const HELLO = 'QPW1';

	/** The longest control or greeting line read; anything longer is cut. */
	const MAX_LINE = 512;

	/**
	 * Inherited from Q_WebServer_Pool, declared here again (same visibility,
	 * same default) because this class assigns them and every class's
	 * assignments are checked against its own declarations
	 * (tests/unit-dynamic-properties.php).
	 */
	protected $workers = array();
	protected $watchers = array();

	/** @var resource|null the loopback listener workers connect to */
	protected $listener = null;
	/** @var string the listener's address: tcp://host:port or unix:///path */
	protected $listenAddress = '';
	/** @var string|null the Unix socket's path, removed on shutdown */
	protected $socketPath = null;
	/** @var mixed Q_Evented watcher of the listener */
	protected $listenerWatcher = null;
	/** @var mixed Q_Evented timer of the supervisor */
	protected $supervisorTimer = null;

	/**
	 * The processes, by worker index: proc (proc_open resource), stdin,
	 * ctl (its standard output), token, started (time), line (unfinished
	 * control line), readyPid, exitCode.
	 * @var array
	 */
	protected $procs = array();
	/** @var array token => worker index, for workers that have not connected yet */
	protected $tokens = array();
	/** @var array pid => worker index */
	protected $byPid = array();
	/** @var array connections not yet identified: id => [sock, buf, since, watcher] */
	protected $greetings = array();
	/** @var array processes told to go, not yet closed: key => proc entry + since */
	protected $leaving = array();
	/** @var integer workers that died before connecting, in a row */
	protected $bootFailures = 0;
	/** @var float no new worker before this time (after boot failures) */
	protected $nextSpawnAt = 0.0;
	/** @var float seconds a worker may take to boot and connect */
	protected $startTimeout = 60.0;
	/** @var string what the last worker that failed to boot said, for the log */
	protected $lastBootError = '';

	/** Seconds a worker told to go may take before it is ended. */
	const LEAVE_GRACE = 30.0;

	// ── What differs from a forking pool ─────────────────────────

	protected function canStartWorkers()
	{
		return function_exists('proc_open') && function_exists('stream_socket_server');
	}

	protected function forksWorkers()
	{
		return false;
	}

	/**
	 * Nothing: the server runs no application code here, and the workers
	 * prepare themselves (workerMain()). A warm-up run in the server would
	 * only cost it memory no worker shares.
	 */
	protected function prepareParent()
	{
		$this->startTimeout = max(1.0, (float) Q_Config::get('Q', 'webserver', 'portable', 'startTimeout', 60));
	}

	/**
	 * Start a worker process. It is in the pool at once -- counted, busy,
	 * "starting" -- but has no socket until it has booted and connected, so
	 * nothing is sent to it before then; there is no index to give a request
	 * yet, and null says so (dispatch() queues the request, and the queue is
	 * drained when the worker connects).
	 * @method forkWorker
	 * @return {null}
	 */
	protected function forkWorker()
	{
		if (!$this->listen()) return null;
		if (microtime(true) < $this->nextSpawnAt) return null;   // after boot failures; supervise() catches up

		$token = bin2hex(random_bytes(16));
		$index = $this->nextIndex++;
		$windows = Q_WebServer_Process::isWindows();
		self::trace("spawn worker #$index");
		// The control channel: a pipe, or on Windows a file per worker. A
		// pipe there is read through an emulation of non-blocking reads, and
		// stream_select() on it is unreliable; a file never blocks.
		$ctlFile = null;
		if (self::controlChannel() === 'file') {
			$ctlFile = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
				. 'qbix-pool-' . getmypid() . '-' . $index . '-' . substr($token, 0, 8) . '.ctl';
		}
		$spec = array(
			0 => array('pipe', 'r'),
			1 => $ctlFile !== null ? array('file', $ctlFile, 'w') : array('pipe', 'w'),
			2 => (defined('STDERR') and is_resource(STDERR)) ? STDERR
				: array('file', Q_WebServer_Process::nullDevice(), 'w'),
		);
		// A process started with proc_open() inherits every descriptor the
		// server has open on POSIX: listeners, visitors' connections, other
		// workers' sockets. Each is replaced by /dev/null in the child, so a
		// worker holds none of them (the same hygiene a forked worker gets
		// from closeInheritedSockets()).
		foreach (self::inheritedDescriptors() as $fd) {
			if (!isset($spec[$fd])) $spec[$fd] = array('file', '/dev/null', 'r');
		}
		$env = getenv();
		if (!is_array($env)) $env = array();
		$env['QBIX_POOL_WORKER'] = $this->listenAddress;
		$env['QBIX_POOL_INDEX'] = (string) $index;
		$options = $windows
			? array('bypass_shell' => true, 'create_process_group' => true, 'suppress_errors' => true)
			: array();
		$cmd = self::workerCommand((array) ($_SERVER['argv'] ?? array()), PHP_BINARY, PHP_SAPI,
			php_ini_loaded_file(), Q_Config::get('Q', 'webserver', 'portable', 'command', null),
			(array) Q_Config::get('Q', 'webserver', 'portable', 'phpArgs', array()),
			self::interpreterCommand());
		self::trace('command: ' . implode(' ', $cmd));
		$proc = @proc_open($cmd, $spec, $pipes, getcwd() ?: null, $env, $options);
		if (!is_resource($proc)) {
			$this->bootFailed(-1, 'proc_open() could not start ' . $cmd[0]);
			return null;
		}
		// The token, on standard input: the one channel no other process sees.
		@fwrite($pipes[0], 'TOKEN ' . $token . "\n");
		@fflush($pipes[0]);
		if (isset($pipes[1])) stream_set_blocking($pipes[1], false);
		$st = @proc_get_status($proc);
		$pid = (int) ($st['pid'] ?? 0);
		self::trace("worker #$index started, pid $pid" . ($ctlFile !== null ? ", control file $ctlFile" : ''));
		$this->workers[$index] = array(
			'pid' => $pid, 'socket' => null, 'busy' => true, 'starting' => true,
			'idleSince' => microtime(true), 'zygote' => false,
		);
		$this->procs[$index] = array(
			'proc' => $proc, 'stdin' => $pipes[0], 'ctl' => $pipes[1] ?? null, 'token' => $token,
			'ctlFile' => $ctlFile, 'ctlOffset' => 0,
			'started' => microtime(true), 'line' => '', 'readyPid' => 0, 'exitCode' => null, 'pid' => $pid,
		);
		$this->tokens[$token] = $index;
		if ($pid > 0) $this->byPid[$pid] = $index;
		return null;
	}

	/**
	 * Wait for the first workers to boot and connect, as a forking pool's
	 * first workers exist before the server takes its first request. Up to
	 * startTimeout; a server none of whose workers came up does not start.
	 */
	protected function initialWorkersStarted()
	{
		$deadline = microtime(true) + $this->startTimeout;
		self::trace('waiting for the first workers, listener ' . $this->listenAddress);
		while (microtime(true) < $deadline) {
			$this->pump(0.1);
			if (!$this->startingCount()) break;
			if ($this->bootFailures >= 3 and $this->connectedCount() === 0) break;
		}
		$up = $this->connectedCount();
		if ($up === 0) {
			$this->shutdown(1.0);
			throw new Exception('portable pool: no worker started'
				. ($this->lastBootError !== '' ? ' (' . $this->lastBootError . ')' : ''));
		}
		if ($this->startingCount()) {
			fwrite(STDERR, sprintf("  portable pool: %d of %d workers up; the rest are still starting\n",
				$up, count($this->workers)));
		}
		self::trace(sprintf('first workers: %d up, %d starting', $up, $this->startingCount()));
		$pool = $this;
		$this->supervisorTimer = Q_Evented::repeat(0.25, function () use ($pool) {
			$pool->supervise();
		});
	}

	protected function workerGone($w)
	{
		$pid = (int) ($w['pid'] ?? 0);
		if ($pid <= 0) return true;
		$index = $this->byPid[$pid] ?? null;
		if ($index === null or !isset($this->procs[$index])) return true;
		$st = @proc_get_status($this->procs[$index]['proc']);
		return !$st or empty($st['running']);
	}

	/** A worker's process is closed by forgetWorker(), not waited for. */
	protected function reapWorker($w)
	{
	}

	protected function forgetWorker($index)
	{
		if (isset($this->procs[$index])) {
			$p = $this->procs[$index];
			unset($this->procs[$index], $this->tokens[$p['token']]);
			if (!empty($p['pid'])) unset($this->byPid[$p['pid']]);
			// Its standard input closes; its socket was closed by the caller.
			// It leaves on its own, and is closed once it has (reapPending()).
			if (is_resource($p['stdin'])) @fclose($p['stdin']);
			$p['stdin'] = null;
			$p['since'] = microtime(true);
			$this->leaving[] = $p;
		}
		parent::forgetWorker($index);
	}

	/**
	 * Close the processes that have left. Those still there LEAVE_GRACE
	 * seconds after they were told to go are ended.
	 */
	function reapPending()
	{
		$now = microtime(true);
		foreach (array_keys($this->leaving) as $k) {
			$this->readControlOf($this->leaving[$k]);
			$p = $this->leaving[$k];
			$st = @proc_get_status($p['proc']);
			$running = $st && !empty($st['running']);
			if ($running and $now - $p['since'] < self::LEAVE_GRACE) continue;
			if ($running) @proc_terminate($p['proc'], 9);
			$this->closeProc($p);
			unset($this->leaving[$k]);
		}
		return count($this->leaving);
	}

	protected function signalWorker($w, $force)
	{
		$index = $this->byPid[(int) ($w['pid'] ?? 0)] ?? null;
		if ($index === null or !isset($this->procs[$index])) return false;
		// On Windows proc_terminate() ends the process at once whatever the
		// signal; on POSIX it sends this one.
		return @proc_terminate($this->procs[$index]['proc'], $force ? 9 : 15);
	}

	function workerStats()
	{
		$stats = parent::workerStats();
		$stats['poolMode'] = 'portable';
		$stats['starting'] = $this->startingCount();
		return $stats;
	}

	function processIds()
	{
		$pids = parent::processIds();
		foreach ($this->leaving as $p) if (!empty($p['pid'])) $pids[] = (int) $p['pid'];
		return $pids;
	}

	/**
	 * Stop every worker: sockets and standard input closed (a worker leaves
	 * when its socket closes, after the request it is on), up to $timeout
	 * seconds to go, then ended.
	 */
	function shutdown($timeout = 3.0)
	{
		if ($this->supervisorTimer !== null) { Q_Evented::cancel($this->supervisorTimer); $this->supervisorTimer = null; }
		if ($this->listenerWatcher !== null) { Q_Evented::cancel($this->listenerWatcher); $this->listenerWatcher = null; }
		if (is_resource($this->listener)) @fclose($this->listener);
		$this->listener = null;
		if ($this->socketPath !== null) { @unlink($this->socketPath); $this->socketPath = null; }
		foreach (array_keys($this->greetings) as $id) $this->dropGreeting($id);
		foreach ($this->workers as $i => $w) {
			if (isset($this->watchers[$i])) Q_Evented::cancel($this->watchers[$i]);
			if (is_resource($w['socket'] ?? null)) @fclose($w['socket']);
		}
		$all = $this->leaving;
		foreach ($this->procs as $p) {
			if (is_resource($p['stdin'])) @fclose($p['stdin']);
			$p['stdin'] = null;
			$all[] = $p;
		}
		$deadline = microtime(true) + $timeout;
		while ($all and microtime(true) < $deadline) {
			foreach (array_keys($all) as $k) {
				$this->readControlOf($all[$k]);
				$st = @proc_get_status($all[$k]['proc']);
				if (!$st or empty($st['running'])) {
					$this->closeProc($all[$k]);
					unset($all[$k]);
				}
			}
			if ($all) usleep(50000);
		}
		foreach ($all as $p) {
			@proc_terminate($p['proc'], 9);
			$this->closeProc($p);
		}
		$this->workers = $this->procs = $this->tokens = $this->byPid = $this->leaving = array();
		$this->watchers = array();
	}

	/** No zygote in a portable pool. */
	function stopZygote()
	{
	}

	// ── Supervisor ─────────────────────────────────────────────

	/**
	 * Every quarter second: read the control pipes, replace workers that
	 * died while booting or took too long, drop greetings that never came,
	 * and start workers the pool is short of (after a boot-failure delay).
	 * @method supervise
	 */
	function supervise()
	{
		$this->acceptWorkers();
		$this->readControl();
		$now = microtime(true);
		foreach ($this->procs as $index => $p) {
			if (empty($this->workers[$index]['starting'])) continue;
			$st = @proc_get_status($p['proc']);
			if (!$st or empty($st['running'])) {
				$this->readControlOf($this->procs[$index]);
				$code = $this->procs[$index]['exitCode'] ?? (isset($st['exitcode']) ? (int) $st['exitcode'] : -1);
				$this->forgetWorker($index);
				$this->bootFailed($code, "worker exited with code $code before it connected");
				continue;
			}
			if ($now - $p['started'] > $this->startTimeout) {
				@proc_terminate($p['proc'], 9);
				$this->forgetWorker($index);
				$this->bootFailed(-1, sprintf('worker did not connect within %.0fs', $this->startTimeout));
			}
		}
		foreach ($this->greetings as $id => $g) {
			if ($now - $g['since'] > 5.0) $this->dropGreeting($id);
		}
		// Short of workers (boot failures held some back): start them now.
		if ($now >= $this->nextSpawnAt) {
			$want = self::wantedSize($this->targetSize, $this->spareWorkers, count($this->workers),
				count($this->pending), $this->startingCount());
			for ($i = count($this->workers); $i < $want; ++$i) {
				$this->forkWorker();
				if (microtime(true) < $this->nextSpawnAt) break;
			}
		}
	}

	/**
	 * How many workers the pool should have now: its size for a fixed pool;
	 * for a dynamic one its spare count, or more while requests wait and no
	 * worker is already on its way.
	 * @method wantedSize
	 * @static
	 * @param {integer} $target
	 * @param {integer} $spare
	 * @param {integer} $have
	 * @param {integer} $queued
	 * @param {integer} $starting
	 * @return {integer}
	 */
	static function wantedSize($target, $spare, $have, $queued, $starting)
	{
		if ($spare <= 0) return $target;
		$want = $spare;
		if ($queued > $starting) $want = max($want, min($target, $have + $queued - $starting));
		return min($target, $want);
	}

	/**
	 * A worker died before connecting, or could not be started: note it and
	 * hold the next start back -- 0.5 s, doubling with each failure in a row,
	 * at most 30 s.
	 */
	protected function bootFailed($code, $why)
	{
		$this->bootFailures++;
		$this->lastBootError = $why;
		$delay = self::backoffDelay($this->bootFailures);
		$this->nextSpawnAt = microtime(true) + $delay;
		fwrite(STDERR, sprintf("  portable pool: %s; next start in %.1fs (%d in a row)\n",
			$why, $delay, $this->bootFailures));
	}

	/**
	 * Seconds to wait before starting a worker after $failures boot failures
	 * in a row.
	 * @method backoffDelay
	 * @static
	 * @param {integer} $failures
	 * @return {float}
	 */
	static function backoffDelay($failures)
	{
		if ($failures <= 0) return 0.0;
		return min(30.0, 0.5 * pow(2, min(10, $failures - 1)));
	}

	/** Run the supervisor's I/O for up to $seconds, outside the event loop. */
	protected function pump($seconds)
	{
		$read = array();
		if (is_resource($this->listener)) $read[] = $this->listener;
		foreach ($this->greetings as $g) $read[] = $g['sock'];
		$w = $e = null;
		if ($read) @stream_select($read, $w, $e, 0, (int) ($seconds * 1e6));
		else usleep((int) ($seconds * 1e6));
		foreach (array_keys($this->greetings) as $id) $this->readGreeting($id);
		$this->supervise();
	}

	protected function startingCount()
	{
		$n = 0;
		foreach ($this->workers as $w) if (!empty($w['starting'])) $n++;
		return $n;
	}

	protected function connectedCount()
	{
		return count($this->workers) - $this->startingCount();
	}

	// ── The loopback listener and the workers' greetings ─────────

	/** Open the listener workers connect to, once. */
	protected function listen()
	{
		if (is_resource($this->listener)) return true;
		if (self::transport() === 'unix') {
			// A Unix socket only the server and its workers can open: no
			// TCP port of the pool's own among the server's listening ports,
			// which is what qbixctl restart and status go by.
			$path = rtrim(sys_get_temp_dir(), '/') . '/qbix-pool-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.sock';
			$old = umask(0077);
			$sock = @stream_socket_server('unix://' . $path, $errno, $errstr);
			umask($old);
			if (!$sock) {
				fwrite(STDERR, "  portable pool: cannot listen on $path for workers: $errstr\n");
				return false;
			}
			@chmod($path, 0600);
			// Workers give up root before they connect (Q_WebServer_RunAs).
			if (class_exists('Q_WebServer_RunAs', false)) {
				$plan = Q_WebServer_RunAs::plan();
				if (!empty($plan['switch']) and isset($plan['uid']) and $plan['uid'] >= 0) {
					@chown($path, (int) $plan['uid']);
					if (isset($plan['gid']) and $plan['gid'] >= 0) @chgrp($path, (int) $plan['gid']);
				}
			}
			$this->socketPath = $path;
			$this->listenAddress = 'unix://' . $path;
		} else {
			$host = (string) Q_Config::get('Q', 'webserver', 'portable', 'host', '127.0.0.1');
			$sock = @stream_socket_server("tcp://$host:0", $errno, $errstr);
			if (!$sock) {
				fwrite(STDERR, "  portable pool: cannot listen on $host for workers: $errstr\n");
				return false;
			}
			$this->listenAddress = 'tcp://' . stream_socket_get_name($sock, false);
		}
		stream_set_blocking($sock, false);
		$this->listener = $sock;
		$pool = $this;
		$this->listenerWatcher = Q_Evented::onReadable($sock, function () use ($pool) {
			$pool->acceptWorkers();
		});
		return true;
	}

	/** Take every connection waiting on the listener; each must greet. */
	function acceptWorkers()
	{
		if (!is_resource($this->listener)) return;
		while ($c = @stream_socket_accept($this->listener, 0)) {
			self::trace('connection accepted from ' . stream_socket_get_name($c, true));
			stream_set_blocking($c, false);
			$id = (int) $c;
			$pool = $this;
			$this->greetings[$id] = array('sock' => $c, 'buf' => '', 'since' => microtime(true),
				'watcher' => Q_Evented::onReadable($c, function () use ($pool, $id) {
					$pool->readGreeting($id);
				}));
			$this->readGreeting($id);
		}
	}

	/**
	 * Read a connection's greeting, "QPW1 <token> <pid>\n". The right token
	 * makes it the socket of the worker it was given to; anything else
	 * closes it.
	 * @method readGreeting
	 * @param {integer} $id
	 */
	function readGreeting($id)
	{
		if (!isset($this->greetings[$id])) return;
		$g =& $this->greetings[$id];
		$chunk = @fread($g['sock'], self::MAX_LINE);
		if (is_string($chunk)) $g['buf'] .= $chunk;
		$nl = strpos($g['buf'], "\n");
		if ($nl === false) {
			if (strlen($g['buf']) >= self::MAX_LINE or feof($g['sock'])) $this->dropGreeting($id);
			return;
		}
		$hello = self::parseHello(substr($g['buf'], 0, $nl));
		$rest = (string) substr($g['buf'], $nl + 1);
		$sock = $g['sock'];
		unset($g);
		$index = $hello ? ($this->tokens[$hello['token']] ?? null) : null;
		self::trace('greeting ' . ($hello ? 'from pid ' . $hello['pid'] : 'unreadable') . ($index === null ? ', refused' : ", worker #$index"));
		if ($index === null or $rest !== '' or empty($this->workers[$index]['starting'])) {
			$this->dropGreeting($id);
			return;
		}
		Q_Evented::cancel($this->greetings[$id]['watcher']);
		unset($this->greetings[$id]);
		$this->attach($index, $sock, $hello['pid']);
	}

	protected function dropGreeting($id)
	{
		if (!isset($this->greetings[$id])) return;
		if ($this->greetings[$id]['watcher'] !== null) Q_Evented::cancel($this->greetings[$id]['watcher']);
		if (is_resource($this->greetings[$id]['sock'])) @fclose($this->greetings[$id]['sock']);
		unset($this->greetings[$id]);
	}

	/**
	 * A worker has connected: it gets its socket, is idle, and the queue is
	 * served from it.
	 */
	protected function attach($index, $sock, $pid)
	{
		self::trace("worker #$index attached (pid $pid)");
		unset($this->tokens[$this->procs[$index]['token']]);
		$this->bootFailures = 0;
		$this->nextSpawnAt = 0.0;
		$now = microtime(true);
		$this->workers[$index]['socket'] = $sock;
		$this->workers[$index]['busy'] = false;
		$this->workers[$index]['idleSince'] = $now;
		$this->workers[$index]['readyAt'] = $now;
		$this->workers[$index]['recycleAfter'] = false;
		unset($this->workers[$index]['starting']);
		// The pid it reports is the PHP process itself; on Windows the one
		// proc_open() knows can differ only if something wrapped it.
		if ($pid > 0 and $pid !== (int) $this->workers[$index]['pid']) {
			unset($this->byPid[(int) $this->workers[$index]['pid']]);
			$this->workers[$index]['pid'] = $pid;
			$this->procs[$index]['pid'] = $pid;
			$this->byPid[$pid] = $index;
		}
		$pool = $this;
		$this->watchers[$index] = Q_Evented::onReadable($sock, function ($s) use ($pool, $index) {
			$pool->onWorkerData($index, $s);
		});
		Q_Evented::disable($this->watchers[$index]);
		if (!empty($this->pending)) {
			$next = array_shift($this->pending);
			$this->sendTo($index, $next[0], $next[1], $next[2], $next[3] ?? null);
		}
	}

	// ── Control pipes ──────────────────────────────────────────

	/** Read every control pipe that has something, without waiting. */
	protected function readControl()
	{
		$pipes = array();
		foreach ($this->procs as $index => $p) {
			if (!empty($p['ctlFile'])) $this->readControlOf($this->procs[$index], $index);
			elseif (is_resource($p['ctl'])) $pipes[$index] = $p['ctl'];
		}
		if (!$pipes) return;
		$r = array_values($pipes);
		$w = $e = null;
		if (@stream_select($r, $w, $e, 0, 0) < 1) return;
		foreach ($pipes as $index => $pipe) {
			if (in_array($pipe, $r, true)) $this->readControlOf($this->procs[$index], $index);
		}
	}

	/**
	 * Read what a worker's control pipe holds and act on each whole line.
	 * @param {array} &$p a process entry
	 * @param {integer|null} $index its worker index, if it is still in the pool
	 */
	protected function readControlOf(array &$p, $index = null)
	{
		if (!empty($p['ctlFile'])) {
			// A file: read what was added since the last time.
			clearstatcache(true, $p['ctlFile']);
			$size = @filesize($p['ctlFile']);
			if ($size !== false and $size > $p['ctlOffset'] and ($fh = @fopen($p['ctlFile'], 'rb'))) {
				@fseek($fh, $p['ctlOffset']);
				$chunk = (string) @fread($fh, min(65536, $size - $p['ctlOffset']));
				@fclose($fh);
				$p['ctlOffset'] += strlen($chunk);
				$p['line'] .= $chunk;
			}
		} else {
			if (!is_resource($p['ctl'] ?? null)) return;
			for ($i = 0; $i < 16; ++$i) {
				$chunk = @fread($p['ctl'], 8192);
				if (!is_string($chunk) or $chunk === '') break;
				$p['line'] .= $chunk;
			}
		}
		while (($nl = strpos($p['line'], "\n")) !== false) {
			$line = rtrim(substr($p['line'], 0, $nl), "\r");
			$p['line'] = (string) substr($p['line'], $nl + 1);
			$this->onControlLine($p, $index, $line);
		}
		if (strlen($p['line']) > self::MAX_LINE) {
			$this->onControlLine($p, $index, $p['line']);
			$p['line'] = '';
		}
		if (is_resource($p['ctl'] ?? null) and feof($p['ctl'])) {
			@fclose($p['ctl']);
			$p['ctl'] = null;
		}
	}

	protected function onControlLine(array &$p, $index, $line)
	{
		self::trace('control from pid ' . (int) $p['pid'] . ': ' . $line);
		$msg = self::parseControlLine($line);
		if ($msg === null) {
			// Not the protocol: something the application or PHP printed.
			if (trim($line) !== '') fwrite(STDERR, '  worker ' . (int) $p['pid'] . ': ' . $line . "\n");
			return;
		}
		switch ($msg[0]) {
			case 'READY': $p['readyPid'] = $msg[1]; break;
			case 'EXIT': $p['exitCode'] = $msg[1]; break;
			case 'DONE':
				if ($index !== null and isset($this->workers[$index])) $this->workers[$index]['requests'] = $msg[1];
				break;
		}
	}

	/**
	 * Parse one control line: array(verb, number) for READY, DONE and EXIT,
	 * null for anything else.
	 * @method parseControlLine
	 * @static
	 * @param {string} $line
	 * @return {array|null}
	 */
	static function parseControlLine($line)
	{
		if (!preg_match('/^(READY|DONE|EXIT) (-?\d{1,10})$/', rtrim((string) $line, "\r\n"), $m)) return null;
		return array($m[1], (int) $m[2]);
	}

	/**
	 * One control line, as a worker writes it.
	 * @method controlLine
	 * @static
	 * @param {string} $verb READY, DONE or EXIT
	 * @param {integer} $n
	 * @return {string}
	 */
	static function controlLine($verb, $n)
	{
		return $verb . ' ' . (int) $n . "\n";
	}

	/**
	 * Parse a worker's greeting: array('token' => ..., 'pid' => ...) or null.
	 * @method parseHello
	 * @static
	 * @param {string} $line
	 * @return {array|null}
	 */
	static function parseHello($line)
	{
		if (!preg_match('/^' . self::HELLO . ' ([0-9a-f]{32}) (\d{1,10})$/', rtrim((string) $line, "\r\n"), $m)) return null;
		return array('token' => $m[1], 'pid' => (int) $m[2]);
	}

	protected function closeProc(array $p)
	{
		foreach (array('stdin', 'ctl') as $k) {
			if (is_resource($p[$k] ?? null)) @fclose($p[$k]);
		}
		if (is_resource($p['proc'] ?? null)) @proc_close($p['proc']);
		if (!empty($p['ctlFile'])) @unlink($p['ctlFile']);
	}

	/**
	 * How workers reach the server: "unix" (a Unix socket in the temporary
	 * directory, mode 0600) or "tcp" (127.0.0.1, an ephemeral port).
	 * Q.webserver.portable.transport, "auto" by default: tcp on Windows,
	 * where PHP's streams have no Unix sockets, unix elsewhere.
	 * @method transport
	 * @static
	 * @return {string}
	 */
	static function transport()
	{
		$t = strtolower((string) Q_Config::get('Q', 'webserver', 'portable', 'transport', 'auto'));
		$unix = !Q_WebServer_Process::isWindows() && in_array('unix', stream_get_transports(), true);
		if ($t === 'tcp') return 'tcp';
		if ($t === 'unix' and $unix) return 'unix';
		return $unix ? 'unix' : 'tcp';
	}

	/**
	 * How workers report: "pipe" (their standard output) or "file" (a file
	 * per worker in the temporary directory). Q.webserver.portable.controlChannel,
	 * "auto" by default: a file on Windows, a pipe elsewhere.
	 * @method controlChannel
	 * @static
	 * @return {string}
	 */
	static function controlChannel()
	{
		$c = strtolower((string) Q_Config::get('Q', 'webserver', 'portable', 'controlChannel', 'auto'));
		if ($c === 'pipe' or $c === 'file') return $c;
		return Q_WebServer_Process::isWindows() ? 'file' : 'pipe';
	}

	/**
	 * A line on standard error when QBIX_POOL_TRACE is set: what the
	 * supervisor and the workers do, with the time, for finding where a
	 * start hangs on a system nobody can watch.
	 * @method trace
	 * @static
	 * @param {string} $what
	 */
	static function trace($what)
	{
		static $on = null;
		if ($on === null) $on = (bool) getenv('QBIX_POOL_TRACE');
		if (!$on) return;
		fwrite(STDERR, sprintf("  [pool %s %d] %s\n", date('H:i:s') . substr(sprintf('%.3f', fmod(microtime(true), 1)), 1),
			getmypid(), $what));
	}

	// ── Starting a worker ──────────────────────────────────────

	/**
	 * The command line a worker runs: this PHP, its php.ini, and the
	 * server's own script and options. A static binary (micro SAPI) is its
	 * own script. Q.webserver.portable.command replaces all of it;
	 * Q.webserver.portable.phpArgs adds PHP options (-d ...).
	 * @method workerCommand
	 * @static
	 * @param {array} $argv the server's $_SERVER['argv']
	 * @param {string} $php PHP_BINARY
	 * @param {string} $sapi PHP_SAPI
	 * @param {string|false} $ini php_ini_loaded_file()
	 * @param {array|null} $custom Q.webserver.portable.command
	 * @param {array} $phpArgs Q.webserver.portable.phpArgs
	 * @return {array}
	 */
	static function workerCommand(array $argv, $php, $sapi, $ini, $custom = null, array $phpArgs = array(),
		$interpreter = null)
	{
		if (is_array($custom) and $custom) return array_values(array_map('strval', $custom));
		$argv = array_values(array_map('strval', $argv));
		if ($sapi === 'micro') return array_merge(array((string) $php), array_slice($argv, 1));
		// The server's own interpreter line, where the system tells it
		// (interpreterCommand()): with it come its -d settings, -c, -n.
		if (is_array($interpreter) and count($interpreter) >= 2) {
			$interpreter = array_values(array_map('strval', $interpreter));
			$script = array_pop($interpreter);
			foreach ($phpArgs as $a) $interpreter[] = (string) $a;
			$interpreter[] = $script;
			return array_merge($interpreter, array_slice($argv, 1));
		}
		$cmd = array((string) $php);
		if (is_string($ini) and $ini !== '') { $cmd[] = '-c'; $cmd[] = $ini; }
		foreach ($phpArgs as $a) $cmd[] = (string) $a;
		return array_merge($cmd, $argv);
	}

	/**
	 * This process's interpreter and its own options, up to and including
	 * the script -- "php -d precision=11 sbin/qbixserver.php" -- read from
	 * /proc/self/cmdline (Q_WebServer_StartRecord::capture()). PHP does not
	 * tell a script the -d settings it was started with; without them a
	 * worker would run with different settings from the server. Null where
	 * the system does not say (Windows): Q.webserver.portable.phpArgs then.
	 * @method interpreterCommand
	 * @static
	 * @return {array|null}
	 */
	static function interpreterCommand()
	{
		if (Q_WebServer_Process::isWindows() or !is_readable('/proc/self/cmdline')) return null;
		if (!class_exists('Q_WebServer_StartRecord', false)) require_once __DIR__ . '/StartRecord.php';
		$rec = Q_WebServer_StartRecord::capture((array) ($_SERVER['argv'] ?? array()));
		$cmd = $rec['command'] ?? null;
		return (is_array($cmd) and count($cmd) >= 2) ? $cmd : null;
	}

	/**
	 * The descriptors above 2 this process has open (POSIX: /proc/self/fd or
	 * /dev/fd); none on Windows, where a child gets only the handles it is
	 * given.
	 * @method inheritedDescriptors
	 * @static
	 * @return {array} of integer
	 */
	static function inheritedDescriptors()
	{
		if (Q_WebServer_Process::isWindows()) return array();
		foreach (array('/proc/self/fd', '/dev/fd') as $dir) {
			$list = @scandir($dir);
			if (!$list) continue;
			$fds = array();
			foreach ($list as $e) {
				if (ctype_digit($e) and (int) $e > 2) $fds[] = (int) $e;
			}
			return $fds;
		}
		return array();
	}

	// ── The worker's side ──────────────────────────────────────

	/**
	 * A worker's life, from sbin/qbixserver.php once it has read the
	 * configuration: read the token, prepare what a forked worker inherits,
	 * connect, say READY, serve, say EXIT.
	 * @method workerMain
	 * @static
	 * @param {string} $webDir the document root
	 * @return {integer} the exit code
	 */
	static function workerMain($webDir)
	{
		// What the start-up printed (held since the top of qbixserver.php):
		// standard output is the control pipe, so it goes to the log.
		while (ob_get_level() > 0) {
			$held = ob_get_clean();
			if ($held !== false and $held !== '') fwrite(STDERR, $held);
		}
		$out = defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w');
		$exitSaid = false;
		$say = function ($verb, $n) use ($out, &$exitSaid) {
			if ($verb === 'EXIT') {
				if ($exitSaid) return;
				$exitSaid = true;
			}
			@fwrite($out, self::controlLine($verb, $n));
			@fflush($out);
		};
		// A fatal error ends the process without a word; say it here.
		register_shutdown_function(function () use ($say) {
			$e = error_get_last();
			$fatal = $e && in_array($e['type'], array(E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_PARSE), true);
			$say('EXIT', $fatal ? 255 : 0);
		});

		$hello = defined('STDIN') ? fgets(STDIN, self::MAX_LINE) : false;
		if (!is_string($hello) or !preg_match('/^TOKEN ([0-9a-f]{32})\s*$/', $hello, $m)) {
			fwrite(STDERR, "  portable worker: no token on standard input; not started by a server\n");
			$say('EXIT', 64);
			return 64;
		}
		$token = $m[1];
		$address = (string) getenv('QBIX_POOL_WORKER');
		self::trace("worker booting, server at $address");

		// CTRL+C and CTRL+BREAK are for the server, which ends its workers
		// through their sockets. (Started in a process group of its own, the
		// worker gets no CTRL+C; CTRL+BREAK still reaches the whole console.)
		Q_WebServer_Process::onConsoleInterrupt(null);

		// What booting prints goes to the log too, not into the control pipe.
		$obLevel = ob_get_level();
		ob_start();
		$flushBoot = function () use ($obLevel) {
			while (ob_get_level() > $obLevel) {
				$held = ob_get_clean();
				if ($held !== false and $held !== '') fwrite(STDERR, $held);
			}
		};
		try {
			Q_WebServer::prepareWorkerProcess($webDir);
			if (!class_exists('Q_WebServer_RunAs', false)) require_once __DIR__ . '/RunAs.php';
			$plan = Q_WebServer_RunAs::plan(true);
			if (!empty($plan['error'])) {
				$flushBoot();
				fwrite(STDERR, '  portable worker: ' . $plan['error'] . "\n");
				$say('EXIT', 1);
				return 1;
			}
			Q_WebServer_RunAs::dropOrExit('worker');
			$octane = !Q_Config::get('Q', 'webserver', 'forkPerRequest', false);
			$maxRequests = (int) Q_Config::get('Q', 'webserver', 'maxRequests', 1000);
			self::prepareRuntime($octane);
			if (class_exists('Q_WebServer_CompatFileWrapper', false)) {
				Q_WebServer_CompatFileWrapper::forgetStats(true);
				Q_WebServer_CompatFileWrapper::rememberExistence(true);
			}
		} catch (\Throwable $e) {
			$flushBoot();
			fwrite(STDERR, '  portable worker: boot failed: ' . $e->getMessage() . "\n");
			$say('EXIT', 70);
			return 70;
		}

		$flushBoot();
		self::trace('worker booted, connecting');
		$sock = @stream_socket_client(strpos($address, '://') !== false ? $address : 'tcp://' . $address, $errno, $errstr, 10);
		if (!$sock) {
			fwrite(STDERR, "  portable worker: cannot connect to the server at $address: $errstr\n");
			$say('EXIT', 69);
			return 69;
		}
		stream_set_blocking($sock, true);
		fwrite($sock, self::HELLO . ' ' . $token . ' ' . getmypid() . "\n");
		$say('READY', getmypid());
		self::trace('worker connected and ready');

		self::$afterRequest = function ($handled) use ($say) { $say('DONE', $handled); };
		self::workerSignals();
		self::serve($sock, $octane, $maxRequests);
		$say('EXIT', 0);
		return 0;
	}
}
