<?php
/**
 * @module Q
 */

/**
 * Per-host access control and URL rules, evaluated by the server process for
 * every request before the cache, a file or a script is looked at: what an
 * Apache server does with a site's .htaccess files (mod_auth_basic,
 * mod_authz_core / mod_access_compat, mod_rewrite, mod_alias, mod_dir), in a
 * declared form a tool can write from them.
 *
 *   "Q": { "webserver": { "hosts": { "example.com": {
 *     "root": "/var/www/example/httpdocs",
 *     "index": ["index.php", "index.html"],
 *     "access": {
 *       "deny": [ { "dir": "attachments/" },
 *                 { "dir": "", "files": ".user.ini" },
 *                 { "dir": "", "filesMatch": "\\.(ini|log)$" } ],
 *       "auth": [ { "dir": "admin/", "realm": "Staff", "userFile": "/var/www/example/.htpasswd" } ]
 *     },
 *     "rewrite": [ { "dir": "", "base": "/", "rules": [
 *       { "pattern": "^var/.*\\.php$", "target": "-", "flags": { "F": true } },
 *       { "pattern": "^old/(.*)$", "target": "https://example.com/new/$1", "flags": { "R": 301, "L": true } },
 *       { "pattern": ".", "target": "/index.php", "flags": { "L": true },
 *         "conds": [ { "test": "%{REQUEST_FILENAME}", "file": "-f", "negate": true },
 *                    { "test": "%{REQUEST_FILENAME}", "file": "-d", "negate": true } ] }
 *     ] } ]
 *   } } } }
 *
 * Order, as in Apache: access first (any matching deny is 403; the deepest
 * auth block that covers the path asks for a user from its file), then the
 * rewrite rules of the deepest directory that has any, then the directory
 * index. A rewrite to another path is run through the rules again (at most
 * ten times), as Apache does with per-directory rules. A user file that
 * cannot be read is never taken as "no password": the request gets 500.
 *
 * Password formats: bcrypt ($2y$, $2a$, $2b$), Apache MD5 ($apr1$), {SHA},
 * and crypt(); plain text is refused.
 *
 * @class Q_WebServer_HostRules
 * @static
 */
class Q_WebServer_HostRules
{
	/** @var array userFile => [mtime, users] */
	static $userFiles = array();

	/**
	 * Apply the rules of the request's host. $parsed may be changed (an
	 * internal rewrite: path and query). Returns null to go on, or an answer
	 * array('status' =>, 'headers' =>, 'body' =>) to send as it is.
	 * @method apply
	 * @static
	 * @param {array} $parsed
	 * @return {array|null}
	 */
	static function apply(array &$parsed)
	{
		$host = strtolower(preg_replace('/:\d+$/', '', (string) ($parsed['headers']['host'] ?? '')));
		if ($host === '') return null;
		$hc = Q_Config::get('Q', 'webserver', 'hosts', $host, null);
		self::$current = is_array($hc) ? $hc : null;
		// What the client asked for, before any rule rewrites 'path': the response cache keys by it.
		if (!isset($parsed['_askedPath'])) $parsed['_askedPath'] = (string) ($parsed['path'] ?? '/');
		if (!is_array($hc) or (empty($hc['access']) and empty($hc['rewrite']) and empty($hc['index'])
			and empty($hc['serverRewrite']))) return null;
		$root = isset($hc['root']) ? rtrim((string) $hc['root'], '/') : '';
		// What the client asked for (the URI), as Apache's per-directory rules see it; the server may have
		// resolved a directory to its index in 'path' already.
		$asked = isset($parsed['uri']) ? (string) parse_url('http://x' . $parsed['uri'], PHP_URL_PATH) : (string) ($parsed['path'] ?? '/');
		if ($asked === '') $asked = '/';
		// The virtual host's own rules (Apache: mod_rewrite in server context, from Plesk's vhost.conf or
		// vhost_ssl.conf) come first, before access control and before any .htaccess, as in Apache.
		if (!empty($hc['serverRewrite'])) {
			$r = self::serverRewrite((array) $hc['serverRewrite'], $root, $asked, $parsed);
			if (is_array($r)) return $r;
			if (is_string($r)) {
				$asked = $r;
				$parsed['path'] = $r;
			}
		}
		$rel = self::relPath($asked);
		if ($rel === null) return self::answer(400, 'Bad Request');

		$a = self::access($hc, $rel, $parsed);
		if ($a !== null) return $a;

		for ($round = 0; $round < 10; $round++) {
			// %{ENV:REDIRECT_STATUS}: empty on the request as asked, 200 after an internal rewrite (as Apache).
			$parsed['_rewriteRound'] = $round;
			$r = self::rewrite($hc, $root, $rel, $parsed);
			if (is_array($r) and isset($r['status'])) return $r;
			if ($r === null) break;
			// Internal rewrite: the new path goes through access and the rules again.
			$rel = self::relPath('/' . ltrim($r, '/'));
			if ($rel === null) return self::answer(400, 'Bad Request');
			$parsed['path'] = '/' . $rel;
			$a = self::access($hc, $rel, $parsed);
			if ($a !== null) return $a;
		}
		if (!empty($hc['index']) and $root !== '') {
			$dir = $root . '/' . $rel;
			if ($rel === '' or substr($rel, -1) === '/' or is_dir($dir)) {
				foreach ((array) $hc['index'] as $idx) {
					$idx = basename((string) $idx);
					if ($idx !== '' and is_file(rtrim($dir, '/') . '/' . $idx)) {
						$parsed['path'] = '/' . ($rel === '' ? '' : rtrim($rel, '/') . '/') . $idx;
						break;
					}
				}
			}
		}
		return null;
	}

	/** The path below the document root, decoded, dot segments resolved, no leading slash; null if it climbs out. */
	static function relPath($path)
	{
		$path = rawurldecode((string) $path);
		if (strpos($path, "\0") !== false) return null;
		$out = array();
		$trail = substr($path, -1) === '/';
		foreach (explode('/', $path) as $seg) {
			if ($seg === '' or $seg === '.') continue;
			if ($seg === '..') {
				if (!$out) return null;
				array_pop($out);
				continue;
			}
			$out[] = $seg;
		}
		$rel = implode('/', $out);
		return $rel . ($trail && $rel !== '' ? '/' : '');
	}

	/** Whether $rel is $dir or below it ($dir '' = everything). */
	static function under($rel, $dir)
	{
		$dir = trim((string) $dir, '/');
		if ($dir === '') return true;
		return $rel === $dir or strpos($rel, $dir . '/') === 0;
	}

	static function access(array $hc, $rel, array $parsed)
	{
		$ac = isset($hc['access']) && is_array($hc['access']) ? $hc['access'] : array();
		$name = basename(rtrim($rel, '/'));
		foreach ((array) ($ac['deny'] ?? array()) as $d) {
			if (!self::under($rel, $d['dir'] ?? '')) continue;
			if (isset($d['files']) and $d['files'] !== $name) continue;
			if (isset($d['filesMatch']) and !@preg_match('#' . str_replace('#', '\#', $d['filesMatch']) . '#', $name)) continue;
			return self::answer(403, 'Forbidden');
		}
		$best = null;
		foreach ((array) ($ac['auth'] ?? array()) as $au) {
			$dir = trim((string) ($au['dir'] ?? ''), '/');
			if (!self::under($rel, $dir)) continue;
			if ($best === null or strlen($dir) >= strlen(trim((string) $best['dir'], '/'))) $best = $au + array('dir' => '');
		}
		if ($best === null or !empty($best['off'])) return null;
		$realm = str_replace('"', '', (string) ($best['realm'] ?? 'Restricted'));
		$ask = self::answer(401, 'Unauthorized');
		$ask['headers']['WWW-Authenticate'] = 'Basic realm="' . $realm . '"';
		$h = (string) ($parsed['headers']['authorization'] ?? '');
		if (stripos($h, 'basic ') !== 0) return $ask;
		$pair = base64_decode(trim(substr($h, 6)), true);
		if ($pair === false or strpos($pair, ':') === false) return $ask;
		list($user, $pass) = explode(':', $pair, 2);
		$users = self::users((string) ($best['userFile'] ?? ''));
		if ($users === null) {
			if (class_exists('Q_WebServer_Log', false)) Q_WebServer_Log::error('access: cannot read user file ' . ($best['userFile'] ?? ''));
			return self::answer(500, 'Internal Server Error');
		}
		if (!isset($users[$user]) or !self::verify($pass, $users[$user])) return $ask;
		return null;
	}

	/** user => hash, or null when the file cannot be read. */
	static function users($file)
	{
		if ($file === '' or !is_readable($file)) return null;
		clearstatcache(true, $file);
		$mt = @filemtime($file);
		if (isset(self::$userFiles[$file]) and self::$userFiles[$file][0] === $mt) return self::$userFiles[$file][1];
		$users = array();
		foreach (preg_split('/\r?\n/', (string) @file_get_contents($file)) as $line) {
			$line = trim($line);
			if ($line === '' or $line[0] === '#' or strpos($line, ':') === false) continue;
			list($u, $hash) = explode(':', $line, 2);
			$users[$u] = $hash;
		}
		self::$userFiles[$file] = array($mt, $users);
		return $users;
	}

	static function verify($pass, $hash)
	{
		if (preg_match('/^\$2[aby]\$/', $hash)) return password_verify($pass, $hash);
		if (strncmp($hash, '$apr1$', 6) === 0) return hash_equals($hash, self::apr1($pass, $hash));
		if (strncmp($hash, '{SHA}', 5) === 0) return hash_equals($hash, '{SHA}' . base64_encode(sha1($pass, true)));
		if (preg_match('/^\$[156]\$/', $hash) or strlen($hash) === 13) {
			$c = crypt($pass, $hash);
			return is_string($c) and strlen($c) > 12 and hash_equals($hash, $c);
		}
		return false;
	}

	/** Apache's MD5 ($apr1$salt$hash). */
	static function apr1($pass, $hash)
	{
		$salt = substr(explode('$', $hash)[2] ?? '', 0, 8);
		$len = strlen($pass);
		$text = $pass . '$apr1$' . $salt;
		$bin = md5($pass . $salt . $pass, true);
		for ($i = $len; $i > 0; $i -= 16) $text .= substr($bin, 0, min(16, $i));
		for ($i = $len; $i > 0; $i >>= 1) $text .= ($i & 1) ? chr(0) : $pass[0];
		$bin = md5($text, true);
		for ($i = 0; $i < 1000; $i++) {
			$new = ($i & 1) ? $pass : $bin;
			if ($i % 3) $new .= $salt;
			if ($i % 7) $new .= $pass;
			$new .= ($i & 1) ? $bin : $pass;
			$bin = md5($new, true);
		}
		$tmp = '';
		foreach (array(array(0, 6, 12), array(1, 7, 13), array(2, 8, 14), array(3, 9, 15), array(4, 10, 5)) as $t) {
			$tmp .= self::to64((ord($bin[$t[0]]) << 16) | (ord($bin[$t[1]]) << 8) | ord($bin[$t[2]]), 4);
		}
		$tmp .= self::to64(ord($bin[11]), 2);
		return '$apr1$' . $salt . '$' . $tmp;
	}

	private static function to64($v, $n)
	{
		$itoa = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
		$s = '';
		while (--$n >= 0) { $s .= $itoa[$v & 0x3f]; $v >>= 6; }
		return $s;
	}

	/**
	 * The rules of the deepest directory with any. Returns null (no rewrite),
	 * a new path (internal rewrite) or an answer array.
	 */
	static function rewrite(array $hc, $root, $rel, array &$parsed)
	{
		$set = null;
		foreach ((array) ($hc['rewrite'] ?? array()) as $s) {
			$dir = trim((string) ($s['dir'] ?? ''), '/');
			if (!self::under($rel, $dir)) continue;
			if ($set === null or strlen($dir) > strlen(trim((string) $set['dir'], '/'))) $set = $s + array('dir' => '');
		}
		if ($set === null) return null;
		$dir = trim((string) $set['dir'], '/');
		$local = $dir === '' ? $rel : (string) substr($rel, strlen($dir) + 1);
		$base = isset($set['base']) ? '/' . trim((string) $set['base'], '/') : '/' . $dir;
		foreach ((array) ($set['rules'] ?? array()) as $rule) {
			$flags = (array) ($rule['flags'] ?? array());
			$re = '#' . str_replace('#', '\#', (string) $rule['pattern']) . '#' . (!empty($flags['NC']) ? 'i' : '');
			$neg = !empty($rule['negate']);
			$hit = @preg_match($re, $local, $m);
			if ($hit === false) continue;
			if ((bool) $hit === $neg) continue;
			if ($neg) $m = array($local);
			$cm = array();
			if (!self::conds((array) ($rule['conds'] ?? array()), $root, $rel, $parsed, $m, $cm)) continue;
			$target = (string) ($rule['target'] ?? '-');
			if (!empty($flags['F'])) return self::answer(403, 'Forbidden');
			if (!empty($flags['G'])) return self::answer(410, 'Gone');
			// [R=4xx/5xx]: Apache drops the substitution and answers that status (`RewriteRule x - [R=404,L]`, the
			// usual way to hide a file). Taken as "no redirect" before, the request went on: a script it was
			// meant to hide ran.
			if (!empty($flags['R']) and (int) $flags['R'] >= 400 and (int) $flags['R'] <= 599) {
				return self::answer((int) $flags['R'], self::reason((int) $flags['R']));
			}
			if ($target === '-') {
				if (!empty($flags['L']) or !empty($flags['END'])) return null;
				continue;
			}
			$sub = self::expand($target, $m, $cm, $parsed, $root, $rel);
			$q = '';
			if (($p = strpos($sub, '?')) !== false) {
				$q = (string) substr($sub, $p + 1);
				$sub = substr($sub, 0, $p);
				if (!empty($flags['QSA']) and (string) ($parsed['query'] ?? '') !== '') $q .= ($q === '' ? '' : '&') . $parsed['query'];
			} else {
				$q = (string) ($parsed['query'] ?? '');
			}
			$abs = (bool) preg_match('#^https?://#i', $sub);
			if (!empty($flags['R']) or $abs) {
				$code = !empty($flags['R']) && (int) $flags['R'] >= 300 ? (int) $flags['R'] : 302;
				$loc = $abs ? $sub : rtrim($base, '/') . '/' . ltrim($sub, '/');
				if (!$abs and $sub !== '' and $sub[0] === '/') $loc = $sub;
				if ($q !== '') $loc .= '?' . $q;
				return array('status' => $code, 'headers' => array('Location' => $loc, 'Content-Type' => 'text/html; charset=utf-8'),
					'body' => '<a href="' . htmlspecialchars($loc, ENT_QUOTES) . '">Moved</a>');
			}
			$parsed['query'] = $q;
			$newPath = ($sub !== '' and $sub[0] === '/') ? $sub : rtrim($base, '/') . '/' . $sub;
			if (trim($newPath, '/') === trim($rel, '/')) return null;   // same path: nothing more to do
			return $newPath;
		}
		return null;
	}

	/** RewriteCond chain with [OR]; $cm gets the last matching condition's groups (%1 ...). */
	static function conds(array $conds, $root, $rel, array $parsed, array $m, &$cm)
	{
		$result = true;
		$orOpen = false;
		$orHit = false;
		foreach ($conds as $c) {
			$val = self::expand((string) $c['test'], $m, $cm, $parsed, $root, $rel);
			$ok = false;
			$groups = array();
			if (!empty($c['file'])) {
				$ok = $c['file'] === '-f' ? is_file($val) : ($c['file'] === '-d' ? is_dir($val) : ($c['file'] === '-s' ? (is_file($val) && filesize($val) > 0) : file_exists($val)));
			} else {
				$re = '#' . str_replace('#', '\#', (string) $c['pattern']) . '#' . (!empty($c['nocase']) ? 'i' : '');
				$ok = (bool) @preg_match($re, $val, $groups);
			}
			if (!empty($c['negate'])) $ok = !$ok;
			if ($ok and $groups) $cm = $groups;
			if (!empty($c['or'])) {
				$orOpen = true;
				$orHit = $orHit || $ok;
				continue;
			}
			if ($orOpen) {
				$ok = $orHit || $ok;
				$orOpen = false;
				$orHit = false;
			}
			$result = $result && $ok;
			if (!$result) return false;
		}
		if ($orOpen) $result = $result && $orHit;
		return $result;
	}

	/** $n, %n and the %{VAR} this engine knows. */
	static function expand($s, array $m, array $cm, array $parsed, $root, $rel)
	{
		$h = (array) ($parsed['headers'] ?? array());
		$https = !empty($parsed['_https']) || strtolower((string) ($h['x-forwarded-proto'] ?? '')) === 'https';
		$vars = array(
			'REQUEST_FILENAME' => $root . '/' . $rel,
			'REQUEST_URI' => (string) ($parsed['path'] ?? '/'),
			'QUERY_STRING' => (string) ($parsed['query'] ?? ''),
			'HTTP_HOST' => (string) ($h['host'] ?? ''),
			'HTTP_USER_AGENT' => (string) ($h['user-agent'] ?? ''),
			'HTTP_REFERER' => (string) ($h['referer'] ?? ''),
			'HTTP_COOKIE' => (string) ($h['cookie'] ?? ''),
			'REQUEST_METHOD' => (string) ($parsed['method'] ?? 'GET'),
			'HTTPS' => $https ? 'on' : 'off',
			'DOCUMENT_ROOT' => $root,
		);
		$s = str_replace('%{ENV:REDIRECT_STATUS}', empty($parsed['_rewriteRound']) ? '' : '200', $s);
		$s = preg_replace_callback('/%\{HTTP:([A-Za-z0-9-]+)\}/', function ($x) use ($h) {
			return (string) ($h[strtolower($x[1])] ?? '');
		}, $s);
		$s = preg_replace_callback('/%\{([A-Z_]+)\}/', function ($x) use ($vars) {
			return $vars[$x[1]] ?? '';
		}, $s);
		$s = preg_replace_callback('/\$(\d)/', function ($x) use ($m) { return (string) ($m[(int) $x[1]] ?? ''); }, $s);
		$s = preg_replace_callback('/%(\d)/', function ($x) use ($cm) { return (string) ($cm[(int) $x[1]] ?? ''); }, $s);
		return $s;
	}

	/** The current request's host entry (set by apply()), for its error documents. */
	static $current = null;

	/**
	 * An answer of the server's own: the host's error document for that status when it names one
	 * (Q.webserver.hosts.<host>.errorDocuments: {"401": "/var/www/vhosts/example.com/error_docs/unauthorized.html"},
	 * what Apache's ErrorDocument sends), else a short plain text.
	 */
	static function answer($status, $text)
	{
		$doc = self::errorDocument($status);
		if ($doc !== null) {
			return array('status' => $status, 'headers' => array('Content-Type' => 'text/html'), 'body' => $doc);
		}
		return array('status' => $status, 'headers' => array('Content-Type' => 'text/plain; charset=utf-8'), 'body' => "$status $text\n");
	}

	/** The reason phrase of an HTTP status this class answers with. */
	static function reason($status)
	{
		$r = array(400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed',
			410 => 'Gone', 429 => 'Too Many Requests', 500 => 'Internal Server Error', 501 => 'Not Implemented', 503 => 'Service Unavailable');
		return $r[(int) $status] ?? 'Error';
	}

	/** The body of the current host's error document for $status, or null. */
	static function errorDocument($status)
	{
		$docs = is_array(self::$current) ? (self::$current['errorDocuments'] ?? null) : null;
		if (!is_array($docs)) return null;
		$file = $docs[(string) (int) $status] ?? null;
		if (!is_string($file) or $file === '' or !is_file($file)) return null;
		$body = @file_get_contents($file);
		return $body === false ? null : $body;
	}

	/**
	 * The virtual host's rules (Apache's mod_rewrite in server context): each pattern is matched against the whole
	 * URL path, leading slash included. Returns null (nothing changed), a new path (internal rewrite, which then
	 * goes through access control and the per-directory rules) or an answer array.
	 */
	static function serverRewrite(array $rules, $root, $path, array &$parsed)
	{
		$rel = (string) self::relPath($path);
		foreach ($rules as $rule) {
			$flags = (array) ($rule['flags'] ?? array());
			$re = '#' . str_replace('#', '\#', (string) $rule['pattern']) . '#' . (!empty($flags['NC']) ? 'i' : '');
			$neg = !empty($rule['negate']);
			$hit = @preg_match($re, $path, $m);
			if ($hit === false) continue;
			if ((bool) $hit === $neg) continue;
			if ($neg) $m = array($path);
			$cm = array();
			if (!self::conds((array) ($rule['conds'] ?? array()), $root, $rel, $parsed, $m, $cm)) continue;
			$target = (string) ($rule['target'] ?? '-');
			if (!empty($flags['F'])) return self::answer(403, 'Forbidden');
			if (!empty($flags['G'])) return self::answer(410, 'Gone');
			// [R=4xx/5xx]: Apache drops the substitution and answers that status (`RewriteRule x - [R=404,L]`, the
			// usual way to hide a file). Taken as "no redirect" before, the request went on: a script it was
			// meant to hide ran.
			if (!empty($flags['R']) and (int) $flags['R'] >= 400 and (int) $flags['R'] <= 599) {
				return self::answer((int) $flags['R'], self::reason((int) $flags['R']));
			}
			if ($target === '-') {
				if (!empty($flags['L']) or !empty($flags['END'])) return null;
				continue;
			}
			$sub = self::expand($target, $m, $cm, $parsed, $root, $rel);
			if (($p = strpos($sub, '?')) !== false) {
				$q = (string) substr($sub, $p + 1);
				$sub = substr($sub, 0, $p);
				if (!empty($flags['QSA']) and (string) ($parsed['query'] ?? '') !== '') $q .= ($q === '' ? '' : '&') . $parsed['query'];
			} else {
				$q = (string) ($parsed['query'] ?? '');
			}
			$abs = (bool) preg_match('#^https?://#i', $sub);
			if (!empty($flags['R']) or $abs) {
				$code = !empty($flags['R']) && (int) $flags['R'] >= 300 ? (int) $flags['R'] : 302;
				$loc = $abs ? $sub : '/' . ltrim($sub, '/');
				if ($q !== '') $loc .= '?' . $q;
				return array('status' => $code, 'headers' => array('Location' => $loc, 'Content-Type' => 'text/html; charset=utf-8'),
					'body' => '<a href="' . htmlspecialchars($loc, ENT_QUOTES) . '">Moved</a>');
			}
			$parsed['query'] = $q;
			// In server context a substitution is a URL path (Apache also takes a file path; vcc refuses those).
			return '/' . ltrim($sub, '/');
		}
		return null;
	}
}
