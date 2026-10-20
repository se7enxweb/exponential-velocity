<?php
/**
 * Snapshots and restores static properties on all user-defined classes.
 *
 * This lets workers handle multiple requests in a loop WITHOUT state leaks:
 * the worker takes a snapshot after preloading, then restores it between
 * requests. ReflectionProperty handles are cached at snapshot time so
 * restoreStatics() does only setValue() calls — no Reflection lookups.
 *
 * Compare: pcntl_fork() costs ~8ms. Snapshot restore is ~160× cheaper,
 * and achieves the same state isolation for static properties.
 *
 * What it DOES reset:
 *   - All static properties on all user-defined classes
 *   - $GLOBALS (except superglobals)
 *   - Superglobals ($_GET, $_POST, $_COOKIE, $_SERVER, $_REQUEST, $_FILES)
 *
 * What it does NOT reset:
 *   - Resources (DB connections, file handles) — these must be managed by
 *     the application, same as fpm's max_requests recycling
 *   - Closures that captured references to statics (rare, and unfixable
 *     without garbage-collecting the closure itself)
 *   - C-level extension state (same limitation as fpm)
 *
 * @class Q_WebServer_Snapshot
 */
class Q_WebServer_Snapshot
{
	/** @var array class => [property => value] */
	private static $snapshot = array();
	/** @var array class => [property => ReflectionProperty] — cached handles */
	private static $reflectors = array();
	/** @var array Global variables snapshot */
	private static $globals = array();
	/** @var boolean Whether a snapshot has been taken */
	private static $taken = false;

	/**
	 * Take a snapshot of all static properties. Call ONCE after preloading,
	 * before the worker starts handling requests.
	 *
	 * Caches ReflectionProperty handles alongside values. Once a class is
	 * loaded, its property slots never move, so the cached handle is safe
	 * to reuse indefinitely. Only false negatives are possible (a new class
	 * not yet cached), never false positives (stale handle pointing to the
	 * wrong slot).
	 */
	static function take()
	{
		self::$snapshot = array();
		self::$reflectors = array();
		$count = 0;
		foreach (get_declared_classes() as $cls) {
			$ref = new \ReflectionClass($cls);
			if ($ref->isInternal()) continue;
			$props = $ref->getProperties(\ReflectionProperty::IS_STATIC);
			if (empty($props)) continue;
			self::$snapshot[$cls] = array();
			self::$reflectors[$cls] = array();
			foreach ($props as $prop) {
				// Reflection reaches private and protected members by itself
				// from PHP 8.1, and PHP 8.5 deprecates setAccessible(), so it
				// is called on PHP 8.0 only (reach()).
				self::reach($prop);
				try {
					$val = $prop->getValue(null);
					if (is_resource($val)) continue;
					$name = $prop->getName();
					self::$snapshot[$cls][$name] =
						is_object($val) ? clone $val : $val;
					self::$reflectors[$cls][$name] = $prop;
					$count++;
				} catch (\Throwable $e) { /* uninitialized typed property */ }
			}
			if (empty(self::$snapshot[$cls])) {
				unset(self::$snapshot[$cls]);
				unset(self::$reflectors[$cls]);
			}
		}

		// Snapshot globals (excluding superglobals and our own state)
		$skip = array('_GET','_POST','_COOKIE','_SERVER','_REQUEST',
			'_FILES','_ENV','_SESSION','GLOBALS','argv','argc');
		self::$globals = array();
		foreach ($GLOBALS as $k => $v) {
			if (in_array($k, $skip, true)) continue;
			if (is_resource($v)) continue;
			self::$globals[$k] = is_object($v) ? clone $v : $v;
		}

		self::$taken = true;
		return $count;
	}

	/**
	 * Restore all static properties and globals to the snapshot.
	 * Call between requests in the worker loop.
	 */
	static function restore()
	{
		if (!self::$taken) return;

		// Restore static properties via cached handles
		foreach (self::$snapshot as $cls => $props) {
			foreach ($props as $name => $val) {
				self::$reflectors[$cls][$name]->setValue(
					null, is_object($val) ? clone $val : $val
				);
			}
		}

		// Restore globals
		$skip = array('_GET','_POST','_COOKIE','_SERVER','_REQUEST',
			'_FILES','_ENV','_SESSION','GLOBALS','argv','argc');
		foreach (array_keys($GLOBALS) as $k) {
			if (in_array($k, $skip, true)) continue;
			if (!array_key_exists($k, self::$globals)) {
				unset($GLOBALS[$k]);
			}
		}
		foreach (self::$globals as $k => $v) {
			$GLOBALS[$k] = is_object($v) ? clone $v : $v;
		}
	}

	/**
	 * How many properties are being tracked.
	 */
	static function count()
	{
		$n = 0;
		foreach (self::$snapshot as $props) $n += count($props);
		return $n;
	}

	/**
	 * Expose the class snapshot for callers that need statics-only restore.
	 */
	static function getClassSnapshot() { return self::$snapshot; }

	/**
	 * Makes a private or protected property readable and writable through
	 * reflection on PHP 8.0, where reflection needs setAccessible() for it.
	 * PHP 8.1 and later reach every member without it (and 8.5 deprecates
	 * the call), so there it does nothing.
	 *
	 * @method reach
	 * @static
	 * @param {ReflectionProperty} $prop
	 * @return {ReflectionProperty} the same property
	 */
	static function reach(\ReflectionProperty $prop)
	{
		if (PHP_VERSION_ID < 80100) $prop->setAccessible(true);
		return $prop;
	}

	/** @var array class => [prop => array(known, value)]: declared defaults read from source on PHP 8.0 */
	private static $declared = array();
	/** @var array Classes declared since the snapshot whose defaults PHP 8.0 could not tell */
	private static $unknown = array();

	/**
	 * The value a static property is declared with, as a process that never
	 * touched it would have it.
	 *
	 * PHP 8.1 and later answer this with getDefaultValue(). PHP 8.0 keeps a
	 * user class's static values in the same table as its declared defaults
	 * (unless the opcode cache made the class immutable), so there
	 * getDefaultValue() returns whatever the property holds now: a class
	 * first loaded during a request would be "reset" to that request's
	 * values, and the next request in the worker would start with them.
	 * Exponential's siteaccess, kept in such a static, then stayed the first
	 * request's for every later one. On 8.0 the declaration is therefore read
	 * from the class's source and evaluated as the constant expression it is,
	 * in the class's scope and namespace.
	 *
	 * @method declaredDefault
	 * @static
	 * @param {ReflectionProperty} $prop a static property
	 * @param {boolean} [&$known] set to false when the default cannot be told
	 * @return {mixed}
	 */
	static function declaredDefault(\ReflectionProperty $prop, &$known = null)
	{
		if (PHP_VERSION_ID >= 80100) {
			$known = $prop->hasDefaultValue();
			return $known ? $prop->getDefaultValue() : null;
		}
		$d = self::fromSource($prop);
		$known = $d !== null && $d[0] && $d[2];
		return $known ? $d[1] : null;
	}

	/**
	 * Whether a static property is declared with a default: what
	 * hasDefaultValue() says, except on PHP 8.0, where a typed static
	 * declared without one reports true as soon as something has been
	 * assigned to it; there the declaration is read (declaredDefault()).
	 *
	 * @method hasDeclaredDefault
	 * @static
	 * @param {ReflectionProperty} $prop a static property
	 * @return {boolean}
	 */
	static function hasDeclaredDefault(\ReflectionProperty $prop)
	{
		if (PHP_VERSION_ID >= 80100) return $prop->hasDefaultValue();
		$d = self::fromSource($prop);
		return $d === null ? $prop->hasDefaultValue() : $d[2];
	}

	/** array(known, value, hasDefault) of a static from its source, or null when not found. PHP 8.0 only. */
	private static function fromSource(\ReflectionProperty $prop)
	{
		$class = $prop->getDeclaringClass();
		$cn = $class->getName();
		if (!isset(self::$declared[$cn])) {
			// Read once per class: every static it declares, from its source and
			// its traits'. A class from eval() has no source to read.
			self::$declared[$cn] = self::readDeclared($class);
		}
		return self::$declared[$cn][$prop->getName()] ?? null;
	}

	/**
	 * Finds every "static ... $name [= expr]" in the class's own source and
	 * in its traits', and evaluates each expr. PHP 8.0 only.
	 *
	 * @return {array} name => array(known, value)
	 */
	private static function readDeclared(\ReflectionClass $class)
	{
		$found = array();
		$places = array($class);
		foreach ($class->getTraits() as $t) $places[] = $t;
		foreach ($places as $c) {
			$file = $c->getFileName();
			if (!$file || !is_file($file)) continue;
			$code = @file_get_contents($file);
			if ($code === false) continue;
			$tokens = token_get_all($code);
			$n = count($tokens);
			$short = $c->getShortName();
			$open = function ($t) {
				return $t === '{' or (is_array($t) and ($t[0] === T_CURLY_OPEN or $t[0] === T_DOLLAR_OPEN_CURLY_BRACES));
			};
			// The namespace and imports in force, and the class keyword itself.
			$ns = ''; $uses = array(); $depth = 0; $nsDepth = 0; $at = -1;
			for ($i = 0; $i < $n; $i++) {
				$t = $tokens[$i];
				if ($open($t)) { $depth++; continue; }
				if ($t === '}') { $depth--; continue; }
				if (!is_array($t)) continue;
				if ($t[0] === T_NAMESPACE and $depth === 0) {
					$ns = '';
					for ($j = $i + 1; $j < $n and $tokens[$j] !== ';' and $tokens[$j] !== '{'; $j++) {
						if (is_array($tokens[$j]) and $tokens[$j][0] !== T_WHITESPACE) $ns .= $tokens[$j][1];
					}
					$nsDepth = ($j < $n and $tokens[$j] === '{') ? 1 : 0;
					$uses = array();
				} elseif ($t[0] === T_USE and $depth === $nsDepth) {
					$stmt = '';
					for ($j = $i; $j < $n and $tokens[$j] !== ';' and $tokens[$j] !== '('; $j++) {
						$stmt .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
					}
					if ($j < $n and $tokens[$j] === ';') { $uses[] = $stmt . ';'; $i = $j; }
				} elseif (in_array($t[0], array(T_CLASS, T_TRAIT), true) and $t[2] >= $c->getStartLine() and $t[2] <= $c->getEndLine()) {
					for ($j = $i + 1; $j < $n and is_array($tokens[$j]) and $tokens[$j][0] === T_WHITESPACE; $j++);
					if ($j < $n and is_array($tokens[$j]) and $tokens[$j][0] === T_STRING and $tokens[$j][1] === $short) { $at = $j; break; }
				}
			}
			if ($at < 0) continue;
			for ($i = $at; $i < $n and $tokens[$i] !== '{'; $i++);
			$depth = 0;
			for (; $i < $n; $i++) {
				$t = $tokens[$i];
				if ($open($t)) { $depth++; continue; }
				if ($t === '}') { if (--$depth === 0) break; continue; }
				if ($depth !== 1 or !is_array($t) or $t[0] !== T_STATIC) continue;
				// "static" at the top of the class body: a property list or a method.
				$typed = false;
				for ($j = $i + 1; $j < $n; $j++) {
					$u = $tokens[$j];
					if (is_array($u) and $u[0] === T_FUNCTION) continue 2;
					if (is_array($u) and $u[0] === T_VARIABLE) break;
					if ($u === ';' or $u === '{' or $u === '(') continue 2;
					// A type before the name: anything but whitespace, comments and modifiers.
					if ($u === '?' or (is_array($u) and !in_array($u[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT,
						T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_VAR), true))) $typed = true;
				}
				// $j is at the first variable; walk the list "$a = x, $b, $c = y;".
				while ($j < $n) {
					$var = $tokens[$j];
					$expr = null;
					for ($k = $j + 1; $k < $n and is_array($tokens[$k]) and in_array($tokens[$k][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true); $k++);
					if ($k < $n and $tokens[$k] === '=') {
						$expr = array(); $nest = 0;
						for ($k++; $k < $n; $k++) {
							$u = $tokens[$k];
							if (($u === ',' or $u === ';') and $nest === 0) break;
							if ($u === '(' or $u === '[' or $open($u)) $nest++;
							if ($u === ')' or $u === ']' or $u === '}') $nest--;
							$expr[] = $u;
						}
					}
					$name = substr($var[1], 1);
					if (!isset($found[$name])) {
						// array(known, value, hasDefault): a typed static declared
						// without a value has no default; an untyped one is null.
						$found[$name] = $expr === null
							? array(true, null, !$typed)
							: array_merge(self::evaluate($expr, $c, $class->getName(), $ns, $uses, $file), array(true));
					}
					if ($k >= $n or $tokens[$k] !== ',') break;
					for ($j = $k + 1; $j < $n and !(is_array($tokens[$j]) and $tokens[$j][0] === T_VARIABLE); $j++);
				}
			}
		}
		return $found;
	}

	/** Evaluates a declared default's tokens in the class's scope. @return {array} array(known, value) */
	private static function evaluate($expr, \ReflectionClass $where, $scope, $ns, array $uses, $file)
	{
		$text = '';
		foreach ($expr as $u) {
			if (!is_array($u)) { $text .= $u; continue; }
			switch ($u[0]) {
				case T_DIR: $text .= var_export(dirname($file), true); break;
				case T_FILE: $text .= var_export($file, true); break;
				case T_LINE: $text .= (string) $u[2]; break;
				case T_CLASS_C: $text .= var_export($where->getName(), true); break;
				case T_NS_C: $text .= var_export($ns, true); break;
				case T_COMMENT: case T_DOC_COMMENT: break;
				default: $text .= $u[1];
			}
		}
		$code = ($ns !== '' ? "namespace $ns;\n" : '') . implode("\n", $uses) . "\nreturn " . $text . ";\n";
		try {
			$value = \Closure::bind(function ($code) { return eval($code); }, null, $scope)($code);
			return array(true, $value);
		} catch (\Throwable $e) {
			return array(false, null);
		}
	}

	/**
	 * Static properties of classes declared since the snapshot whose
	 * declared defaults PHP 8.0 could not tell, as "Class::$prop". A worker
	 * that has any cannot put them back, so it is replaced after the request.
	 *
	 * @method unknownDefaults
	 * @static
	 * @return {array}
	 */
	static function unknownDefaults() { return self::$unknown; }

	/**
	 * Restore ONLY class statics, not globals.
	 * Uses cached ReflectionProperty handles — no Reflection lookups per call.
	 */
	/**
	 * Classes whose statics are the process's own state, not a request's, and
	 * are therefore never put back to the snapshot.
	 *
	 *   Snapshot -- restoring it would undo updateNewClasses() by reverting
	 *               $snapshot and $reflectors.
	 *   Compat   -- manages its own state through shutdown() and init().
	 *   Capture  -- holds which output buffer is the capture buffer. Put back
	 *               to the parent's value (none), every request would open
	 *               another, and one would be left behind per request: the
	 *               leak Capture exists to end.
	 *   CompatFileWrapper -- remembers stats for the current request. Put
	 *               back, every request would start with the parent's stats
	 *               from before the fork, and report files as older than they
	 *               are.
	 *
	 * @property $processState
	 * @type array
	 */
	protected static $processState = array(
		'Q_WebServer_Snapshot' => true,
		'Q_WebServer_Compat' => true,
		'Q_WebServer_Capture' => true,
		'Q_WebServer_CompatFileWrapper' => true,
	);

	static function restoreStatics()
	{
		if (!self::$taken) return;
		foreach (self::$snapshot as $cls => $props) {
			if (isset(self::$processState[$cls])) continue;
			foreach ($props as $name => $val) {
				$prop = self::$reflectors[$cls][$name];
				// A closure in a static is behaviour installed once, never
				// request data. Composer's ClassLoader keeps its include helper
				// there and builds it behind a null check, so putting the
				// declared null back left the loader calling null on the next
				// request.
				//
				// Only closures. Skipping the class entirely, or sparing every
				// object, both left an application's request state standing:
				// Exponential keeps the requested route in an object static, so
				// every later request came back with the first one's page.
				try {
					if ($prop->getValue(null) instanceof \Closure) continue;
				} catch (\Throwable $ignore) { /* uninitialized typed property */ }
				$prop->setValue(null, is_object($val) ? clone $val : $val);
			}
		}
	}

	/**
	 * Detect classes declared AFTER the initial snapshot and add their
	 * statics to the snapshot using the declaration default values.
	 *
	 * Also populates the $reflectors cache for the new class so
	 * restoreStatics() can use cached handles on subsequent requests.
	 *
	 * Cost: ~0.01ms per new class (one ReflectionClass + property scan).
	 * Already-tracked classes are skipped in O(1) via isset().
	 */
	static function updateNewClasses()
	{
		if (!self::$taken) return;
		foreach (get_declared_classes() as $cls) {
			if (isset(self::$snapshot[$cls])) continue;
			$ref = new \ReflectionClass($cls);
			if ($ref->isInternal()) continue;
			$props = $ref->getProperties(\ReflectionProperty::IS_STATIC);
			if (empty($props)) continue;
			self::$snapshot[$cls] = array();
			self::$reflectors[$cls] = array();
			foreach ($props as $prop) {
				// Reflection reaches private and protected members by itself
				// from PHP 8.1, and PHP 8.5 deprecates setAccessible(), so it
				// is called on PHP 8.0 only (reach()).
				self::reach($prop);
				try {
					if (self::hasDeclaredDefault($prop)) {
						$val = self::declaredDefault($prop, $known);
						if (!$known) {
							self::$unknown[] = $cls . '::$' . $prop->getName();
							continue;
						}
					} else {
						$val = $prop->getValue(null);
					}
					if (is_resource($val)) continue;
					$name = $prop->getName();
					self::$snapshot[$cls][$name] =
						is_object($val) ? clone $val : $val;
					self::$reflectors[$cls][$name] = $prop;
				} catch (\Throwable $e) { /* uninitialized typed property */ }
			}
			if (empty(self::$snapshot[$cls])) {
				unset(self::$snapshot[$cls]);
				unset(self::$reflectors[$cls]);
			}
		}
	}
}
