<?php
/**
 * @module Q
 */
/**
 * The user and group the workers run as -- Apache's User and Group.
 *
 * The server itself is started as root when it must be: it binds privileged
 * ports, reads certificates only root may read, and rebinds and reloads them
 * while it runs. None of that needs the application. So the server (the
 * master) stays root, and every process that runs application code -- each
 * pool worker, the zygote that forks them, a fork-per-request child -- gives
 * root up right after it is forked and before it runs anything:
 *
 *   setgid(group) -> initgroups(user, group) -> setuid(user)
 *
 * and checks that it is no longer root. A process that cannot drop exits with
 * an error; it never serves as root because a switch failed.
 *
 * Where the user and group come from, first match wins:
 *
 *   1. the command line       --user=NAME --group=NAME
 *   2. the configuration      Q.webserver.user, Q.webserver.group
 *   3. the environment        QBIX_RUN_USER, QBIX_RUN_GROUP -- the process
 *                             environment, then the envvars file of each
 *                             configuration tree (top overlay first). A
 *                             distribution may add its own prefix ahead of
 *                             QBIX (addEnvPrefix()).
 *   4. the default            the owner and group of the document root.
 *
 * Root is only ever the worker user when asked for twice: user root (or uid
 * 0) and Q.webserver.allowRootWorkers (--allow-root-workers,
 * <PREFIX>_RUN_ALLOW_ROOT=1). When nothing is configured and the document
 * root itself belongs to root, the workers stay root as before, with a
 * warning, so an installation that works today keeps working.
 *
 * A server not started as root runs everything as its own user; there is
 * nothing to drop.
 *
 * @class Q_WebServer_RunAs
 * @static
 */
class Q_WebServer_RunAs
{
	/** Environment prefixes, most specific first: <PREFIX>_RUN_USER. */
	static $envPrefixes = array('QBIX');

	/** What the command line said: array('user' =>, 'group' =>, 'allowRoot' =>). */
	static $cli = array();

	/** The resolved plan, see plan(). */
	protected static $plan = null;

	/** Whether this process has already dropped. */
	protected static $dropped = false;

	/** The euid/egid saved by asUser() while it runs a callable. */
	protected static $saved = null;

	/**
	 * Add an environment prefix ahead of the others (a distribution's own
	 * name: VC gives VC_RUN_USER, VC_RUN_GROUP, VC_RUN_ALLOW_ROOT).
	 * @method addEnvPrefix
	 * @static
	 * @param {string} $prefix
	 */
	static function addEnvPrefix($prefix)
	{
		$prefix = strtoupper((string) $prefix);
		if ($prefix === '' or in_array($prefix, self::$envPrefixes, true)) return;
		array_unshift(self::$envPrefixes, $prefix);
		self::$plan = null;
	}

	/**
	 * The command line's --user, --group and --allow-root-workers.
	 * @method setCommandLine
	 * @static
	 * @param {array} $opts
	 */
	static function setCommandLine(array $opts)
	{
		self::$cli = array(
			'user' => isset($opts['user']) && is_string($opts['user']) && $opts['user'] !== '' ? $opts['user'] : null,
			'group' => isset($opts['group']) && is_string($opts['group']) && $opts['group'] !== '' ? $opts['group'] : null,
			'allowRoot' => !empty($opts['allow-root-workers']),
			'dropMaster' => !empty($opts['drop-master']),
		);
		self::$plan = null;
	}

	/**
	 * Whether the server process itself is to give up root once its ports
	 * are bound and its certificates read: --drop-master,
	 * Q.webserver.dropMaster, <PREFIX>_RUN_DROP_MASTER=1.
	 * @method dropMasterWanted
	 * @static
	 * @return {boolean}
	 */
	static function dropMasterWanted()
	{
		if (!empty(self::$cli['dropMaster'])) return true;
		if (class_exists('Q_Config', false) and Q_Config::get('Q', 'webserver', 'dropMaster', false) === true) return true;
		$e = self::env('DROP_MASTER');
		return $e !== null && in_array(strtolower($e[0]), array('1', 'yes', 'true', 'on'), true);
	}

	/**
	 * Give up root in the server process itself (the master), for good,
	 * after the listeners are bound and the certificates are in memory.
	 * Nothing that runs afterwards -- the warm-up, every worker, the
	 * scheduled tasks -- has root to lose, and the real user is the worker
	 * user, so access(2) and an NFS export that squashes root answer for it.
	 *
	 * What the master writes from then on must be the user's: the log
	 * directory and the pid file's directory are handed over first. What it
	 * can no longer do: bind a port below 1024 again (a listener added later,
	 * e.g. the HTTPS port after a first certificate arrives) and read a
	 * certificate only root may read (a renewed certificate is picked up by a
	 * restart). Pools of other users (Q.webserver.pools) need a root master,
	 * so they refuse this.
	 *
	 * @method dropMaster
	 * @static
	 * @param {array} $ownDirs directories the master writes (logs, pid file)
	 * @return {string|null} null when done or nothing to do, else what went wrong
	 */
	static function dropMaster(array $ownDirs = array())
	{
		if (!function_exists('posix_geteuid') or posix_geteuid() !== 0) return null;
		$p = self::plan();
		if ($p['error']) return $p['error'];
		if (!$p['switch']) return 'the worker user is root (or unknown); there is no user to become';
		if (class_exists('Q_Config', false) and Q_Config::get('Q', 'webserver', 'pools', array())) {
			return 'Q.webserver.pools runs workers as other users, which needs a master that stays root';
		}
		foreach ($ownDirs as $dir) {
			if (!is_string($dir) or $dir === '' or $dir[0] !== '/' or is_link($dir)) continue;
			if (is_file($dir)) {
				// A file (the pid file): only the file.
				$fs = @lstat($dir);
				if ($fs and $fs['uid'] === 0) { @chown($dir, $p['uid']); @chgrp($dir, $p['gid']); }
				continue;
			}
			// Never a shared system directory (/run, /var/log, /tmp): only a
			// directory at least three levels down (/var/log/vc, /run/vc/x).
			if (!is_dir($dir) or count(array_filter(explode('/', $dir), 'strlen')) < 3) continue;
			$st = @stat($dir);
			if ($st and $st['uid'] === 0) { @chown($dir, $p['uid']); @chgrp($dir, $p['gid']); }
			foreach ((array) @scandir($dir) as $f) {
				if ($f === '.' or $f === '..' or !is_string($f)) continue;
				$path = "$dir/$f";
				$fs = @lstat($path);
				if ($fs and $fs['uid'] === 0 and !is_link($path) and is_file($path)) { @chown($path, $p['uid']); @chgrp($path, $p['gid']); }
			}
		}
		return self::drop();
	}

	/**
	 * An environment setting: <PREFIX>_RUN_<NAME> from the process
	 * environment, else from the envvars file of the configuration trees.
	 * @method env
	 * @static
	 * @param {string} $name USER, GROUP or ALLOW_ROOT
	 * @return {array|null} array(value, where)
	 */
	static function env($name)
	{
		foreach (self::$envPrefixes as $p) {
			$v = getenv($p . '_RUN_' . $name);
			if (is_string($v) and $v !== '') return array($v, "environment {$p}_RUN_$name");
		}
		$dirs = class_exists('Q_Config', false) ? (array) Q_Config::get('Q', 'webserver', 'confDirs', array()) : array();
		if ($dirs and class_exists('Q_WebServer_Layout', false)) {
			foreach (array_reverse($dirs) as $dir) {
				$vars = Q_WebServer_Layout::envvars($dir);
				foreach (self::$envPrefixes as $p) {
					$k = $p . '_RUN_' . $name;
					if (isset($vars[$k]) and $vars[$k] !== '') return array($vars[$k], "$dir/envvars $k");
				}
			}
		}
		return null;
	}

	/**
	 * Who the workers run as.
	 * @method plan
	 * @static
	 * @param {boolean} $fresh work it out again
	 * @return {array} array('switch' => bool, 'user', 'uid', 'group', 'gid',
	 *   'home', 'source', 'warning' => string|null, 'error' => string|null)
	 */
	static function plan($fresh = false)
	{
		if (self::$plan !== null and !$fresh) return self::$plan;
		$euid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
		$plan = array('switch' => false, 'user' => null, 'uid' => $euid, 'group' => null,
			'gid' => function_exists('posix_getegid') ? posix_getegid() : -1,
			'home' => null, 'source' => 'server user', 'warning' => null, 'error' => null);
		$cfg = function ($k) {
			if (!class_exists('Q_Config', false)) return null;
			$v = Q_Config::get('Q', 'webserver', $k, null);
			return (is_string($v) and $v !== '') || is_int($v) ? (string) $v : null;
		};

		// User and group, each from the first place that names it.
		$user = $group = null;
		$userFrom = $groupFrom = null;
		if (!empty(self::$cli['user'])) { $user = self::$cli['user']; $userFrom = 'command line --user'; }
		elseif (($v = $cfg('user')) !== null) { $user = $v; $userFrom = 'configuration Q.webserver.user'; }
		elseif (($e = self::env('USER')) !== null) { list($user, $userFrom) = $e; }
		if (!empty(self::$cli['group'])) { $group = self::$cli['group']; $groupFrom = 'command line --group'; }
		elseif (($v = $cfg('group')) !== null) { $group = $v; $groupFrom = 'configuration Q.webserver.group'; }
		elseif (($e = self::env('GROUP')) !== null) { list($group, $groupFrom) = $e; }
		$allowRoot = !empty(self::$cli['allowRoot'])
			|| (class_exists('Q_Config', false) && Q_Config::get('Q', 'webserver', 'allowRootWorkers', false) === true)
			|| (($e = self::env('ALLOW_ROOT')) !== null && in_array(strtolower($e[0]), array('1', 'yes', 'true', 'on'), true));

		if (!function_exists('posix_geteuid') or $euid !== 0) {
			// Not root: nothing to give up, and nothing to switch to.
			$pw = $euid >= 0 && function_exists('posix_getpwuid') ? posix_getpwuid($euid) : false;
			$plan['user'] = $pw ? $pw['name'] : (string) get_current_user();
			if ($user !== null and $user !== $plan['user'] and $user !== (string) $euid) {
				$plan['warning'] = "workers run as {$plan['user']}, the server's own user: only a server started as root can switch to \"$user\" ($userFrom)";
			}
			return self::$plan = $plan;
		}
		if (!function_exists('posix_setuid') or !function_exists('posix_setgid')) {
			$plan['user'] = 'root';
			$plan['warning'] = 'workers run as root: this PHP has no posix_setuid()/posix_setgid()';
			return self::$plan = $plan;
		}

		if ($user === null) {
			// The default: whoever owns the document root.
			$root = self::documentRoot();
			$owner = $root !== null ? @fileowner($root) : false;
			if ($owner === false or $owner === 0) {
				$plan['user'] = 'root';
				$plan['source'] = 'default';
				$plan['warning'] = 'workers run as root: ' . ($root === null ? 'there is no document root'
					: "the document root $root belongs to root") . '; set Q.webserver.user and Q.webserver.group'
					. ' (--user, --group, ' . self::$envPrefixes[0] . '_RUN_USER) to an unprivileged user';
				return self::$plan = $plan;
			}
			$user = '#' . $owner;
			$userFrom = "default: owner of $root";
			if ($group === null) {
				$g = @filegroup($root);
				if ($g !== false) { $group = '#' . $g; $groupFrom = "default: group of $root"; }
			}
		}

		$pw = self::lookupUser($user);
		if (!$pw) {
			$plan['error'] = "the worker user \"$user\" ($userFrom) does not exist";
			return self::$plan = $plan;
		}
		$plan['user'] = $pw['name'];
		$plan['uid'] = (int) $pw['uid'];
		$plan['home'] = $pw['dir'] ?? null;
		$plan['gid'] = (int) $pw['gid'];
		$plan['source'] = $userFrom;
		if ($group !== null) {
			$gr = self::lookupGroup($group);
			if (!$gr) {
				$plan['error'] = "the worker group \"$group\" ($groupFrom) does not exist";
				return self::$plan = $plan;
			}
			$plan['gid'] = (int) $gr['gid'];
			$plan['source'] .= "; group from $groupFrom";
		}
		$gr = function_exists('posix_getgrgid') ? posix_getgrgid($plan['gid']) : false;
		$plan['group'] = $gr ? $gr['name'] : (string) $plan['gid'];

		if ($plan['uid'] === 0) {
			if (!$allowRoot) {
				$plan['error'] = "the worker user is root ($userFrom); running application code as root needs"
					. ' Q.webserver.allowRootWorkers (--allow-root-workers)';
				return self::$plan = $plan;
			}
			$plan['warning'] = "workers run as root, as configured ($userFrom, allowRootWorkers)";
			return self::$plan = $plan;
		}
		$plan['switch'] = true;
		return self::$plan = $plan;
	}

	/** The document root the server was started with, if any. */
	/**
	 * The plan for a pool of its own (Q.webserver.pools): its workers run as
	 * $user and $group, whatever the server's own worker user is. Stricter than
	 * plan(): the server must have been started as root (a pool whose workers
	 * would stay the server's user is an error, never a warning, because its
	 * whole point is to keep users apart), the user must exist, and root is
	 * refused unless the pool says allowRoot.
	 * @method planFor
	 * @static
	 * @param {string} $user name or #uid
	 * @param {string|null} $group name or #gid; the user's primary group when null
	 * @param {string} $source where it was configured, for messages
	 * @param {boolean} [$allowRoot=false]
	 * @return {array} as plan(), 'error' set when it cannot be used
	 */
	static function planFor($user, $group, $source, $allowRoot = false)
	{
		$plan = array('switch' => false, 'user' => null, 'uid' => -1, 'group' => null, 'gid' => -1,
			'home' => null, 'source' => $source, 'warning' => null, 'error' => null);
		if (!function_exists('posix_geteuid') or !function_exists('posix_setuid')) {
			$plan['error'] = "$source: this PHP has no posix functions, so a pool cannot have a user of its own";
			return $plan;
		}
		$pw = self::lookupUser((string) $user);
		if (!$pw) {
			$plan['error'] = "$source: the user \"$user\" does not exist";
			return $plan;
		}
		$plan['user'] = $pw['name'];
		$plan['uid'] = (int) $pw['uid'];
		$plan['gid'] = (int) $pw['gid'];
		$plan['home'] = $pw['dir'] ?? null;
		if ($group !== null and $group !== '') {
			$gr = self::lookupGroup((string) $group);
			if (!$gr) {
				$plan['error'] = "$source: the group \"$group\" does not exist";
				return $plan;
			}
			$plan['gid'] = (int) $gr['gid'];
		}
		$gr = function_exists('posix_getgrgid') ? posix_getgrgid($plan['gid']) : false;
		$plan['group'] = $gr ? $gr['name'] : (string) $plan['gid'];
		if ($plan['uid'] === 0 and !$allowRoot) {
			$plan['error'] = "$source: the user is root; a pool runs as root only with allowRoot";
			return $plan;
		}
		$euid = posix_geteuid();
		if ($euid === $plan['uid']) return $plan;      // already that user: nothing to switch
		if ($euid !== 0) {
			$pwSelf = posix_getpwuid($euid);
			$plan['error'] = "$source: workers of user {$plan['user']} need a server started as root"
				. ' (this one runs as ' . ($pwSelf ? $pwSelf['name'] : $euid) . ')';
			return $plan;
		}
		$plan['switch'] = $plan['uid'] !== 0;
		return $plan;
	}

	/**
	 * Use $plan from now on in this process: a worker or zygote of a pool with
	 * a user of its own calls this right after fork(), before dropOrExit().
	 * @method adopt
	 * @static
	 * @param {array} $plan planFor()'s result
	 */
	static function adopt(array $plan)
	{
		self::$plan = $plan;
		self::$saved = null;
	}

	static function documentRoot()
	{
		$opts = class_exists('Q_Config', false) ? (array) Q_Config::get('Q', 'webserver', 'startOptions', array()) : array();
		$root = !empty($opts['root']) ? (string) $opts['root'] : null;
		return $root !== null && is_dir($root) ? $root : null;
	}

	/** A user by name, or by number ("#10009" or "10009"). */
	static function lookupUser($user)
	{
		$user = (string) $user;
		if (preg_match('/^#?(\d+)$/', $user, $m)) return function_exists('posix_getpwuid') ? posix_getpwuid((int) $m[1]) : false;
		return function_exists('posix_getpwnam') ? posix_getpwnam($user) : false;
	}

	/** A group by name, or by number ("#1003" or "1003"). */
	static function lookupGroup($group)
	{
		$group = (string) $group;
		if (preg_match('/^#?(\d+)$/', $group, $m)) return function_exists('posix_getgrgid') ? posix_getgrgid((int) $m[1]) : false;
		return function_exists('posix_getgrnam') ? posix_getgrnam($group) : false;
	}

	/**
	 * One line for the start-up banner.
	 * @method describe
	 * @static
	 * @return {string}
	 */
	static function describe()
	{
		$p = self::plan();
		if ($p['error']) return 'error: ' . $p['error'];
		$who = $p['user'] . ($p['group'] !== null ? ':' . $p['group'] : '');
		return $p['switch'] ? "$who ({$p['source']})" : "$who (no switch)";
	}

	/**
	 * Give up root in this process, for good. Called in a freshly forked
	 * child before it runs any application code. Null when done (or when
	 * there is nothing to do), else what went wrong.
	 * @method drop
	 * @static
	 * @return {string|null}
	 */
	static function drop()
	{
		if (self::$dropped) return null;
		$p = self::plan();
		if ($p['error']) return $p['error'];
		if (!$p['switch']) return null;
		if (self::$saved !== null) self::restore();
		if (!@posix_setgid($p['gid'])) return "setgid({$p['gid']}) failed: " . posix_strerror(posix_get_last_error());
		if (function_exists('posix_initgroups') and !@posix_initgroups($p['user'], $p['gid'])) {
			return "initgroups({$p['user']}, {$p['gid']}) failed: " . posix_strerror(posix_get_last_error());
		}
		if (!@posix_setuid($p['uid'])) return "setuid({$p['uid']}) failed: " . posix_strerror(posix_get_last_error());
		if (posix_geteuid() === 0 or posix_getuid() === 0 or posix_geteuid() !== $p['uid'] or posix_getegid() !== $p['gid']) {
			return 'still root after the switch (uid ' . posix_getuid() . ', euid ' . posix_geteuid() . ')';
		}
		// Getting root back must be impossible now.
		if (@posix_setuid(0)) return 'root could be regained after the switch';
		self::$dropped = true;
		putenv('USER=' . $p['user']);
		putenv('LOGNAME=' . $p['user']);
		$_SERVER['USER'] = $p['user'];
		if (!empty($p['home'])) { putenv('HOME=' . $p['home']); $_SERVER['HOME'] = $p['home']; }
		return null;
	}

	/**
	 * drop(), and end the process when it fails: a child that cannot give up
	 * root does not run application code.
	 * @method dropOrExit
	 * @static
	 * @param {string} $what the kind of process, for the message
	 */
	static function dropOrExit($what = 'worker')
	{
		$err = self::drop();
		if ($err === null) return;
		$msg = "$what " . getmypid() . ": cannot switch to the worker user, refusing to run as root: $err";
		fwrite(STDERR, date('H:i:s') . " $msg\n");
		if (class_exists('Q_WebServer_Log', false)) {
			try { Q_WebServer_Log::error($msg); Q_WebServer_Log::flush(); } catch (\Throwable $e) {}
		}
		// Straight out: no shutdown functions, no destructors of inherited state.
		if (function_exists('posix_kill') and defined('SIGKILL')) posix_kill(getmypid(), SIGKILL);
		exit(78);
	}

	/**
	 * Run $fn in the master with the worker user's effective ids, so what it
	 * writes (the warm-up's caches) belongs to the worker user, then take
	 * root back. The real uid stays 0 throughout, which is what allows the
	 * way back.
	 * @method asUser
	 * @static
	 * @param {callable} $fn
	 * @return {mixed} what $fn returns
	 */
	static function asUser($fn)
	{
		$p = self::plan();
		if (!$p['switch'] or self::$dropped or self::$saved !== null
		or !function_exists('posix_seteuid') or !function_exists('posix_setegid')) {
			return $fn();
		}
		self::$saved = array(posix_geteuid(), posix_getegid());
		$ok = @posix_setegid($p['gid']) && @posix_seteuid($p['uid']);
		if (!$ok) {
			self::restore();
			return $fn();
		}
		try {
			return $fn();
		} finally {
			self::restore();
		}
	}

	/** Back to the ids asUser() saved. */
	protected static function restore()
	{
		if (self::$saved === null) return;
		@posix_seteuid(self::$saved[0]);
		@posix_setegid(self::$saved[1]);
		self::$saved = null;
	}

	/**
	 * The directories the workers write that belong to the server rather
	 * than the site: its response caches and the precompressed copies, and
	 * whatever Q.webserver.writable adds. Handed to the worker user at start,
	 * with everything in them that root owns, so a worker can replace and
	 * remove what an earlier root worker left. The logs stay the master's.
	 * @method writableDirs
	 * @static
	 * @return {array}
	 */
	static function writableDirs()
	{
		if (!class_exists('Q_Config', false)) return array();
		$dirs = array(
			Q_Config::get('Q', 'web', 'cache', 'dir', null),
			Q_Config::get('Q', 'web', 'appCache', 'dir', null),
			Q_Config::get('Q', 'webserver', 'precompress', 'dir', null),
		);
		foreach ((array) Q_Config::get('Q', 'webserver', 'writable', array()) as $d) $dirs[] = $d;
		$out = array();
		foreach ($dirs as $d) {
			if (is_string($d) and $d !== '' and $d[0] === '/' and $d !== '/') $out[rtrim($d, '/')] = true;
		}
		return array_keys($out);
	}

	/**
	 * Hand writableDirs() to the worker user. Only what root owns is
	 * changed; nothing outside those directories, and no symlink is followed.
	 * @method prepare
	 * @static
	 * @return {array} path => number of entries changed
	 */
	static function prepare()
	{
		$p = self::plan();
		if (!$p['switch'] or posix_geteuid() !== 0) return array();
		$done = array();
		if (!class_exists('Q_WebServer_Modes', false) and is_file(__DIR__ . '/Modes.php')) require_once __DIR__ . '/Modes.php';
		foreach (self::writableDirs() as $dir) {
			// And what the master itself writes there from now on (Q_WebServer_Modes).
			if (class_exists('Q_WebServer_Modes', false)) Q_WebServer_Modes::ownUnder($dir, $p['uid'], $p['gid']);
			if (!is_dir($dir) or is_link($dir)) continue;
			$n = 0;
			$fix = function ($path) use ($p, &$n) {
				$st = @lstat($path);
				if (!$st or $st['uid'] !== 0) return;
				if (is_link($path)) {
					if (function_exists('lchown')) { @lchown($path, $p['uid']); @lchgrp($path, $p['gid']); $n++; }
					return;
				}
				if (@chown($path, $p['uid'])) { @chgrp($path, $p['gid']); $n++; }
			};
			$fix($dir);
			try {
				$it = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
					RecursiveIteratorIterator::SELF_FIRST);
				foreach ($it as $f) $fix($f->getPathname());
			} catch (\Throwable $e) {}
			$done[$dir] = $n;
		}
		return $done;
	}
}
