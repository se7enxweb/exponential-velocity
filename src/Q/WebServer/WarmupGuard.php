<?php
/**
 * Puts the parent back to where it was before a warm-up that failed.
 *
 * The pool takes its snapshot of statics and globals after the warm-up, and
 * every worker restores that snapshot before each request. A warm-up that
 * throws halfway through a render stops before its own clean-up, so the
 * snapshot held the render's request: its address, its route, its visitor and
 * its half-written output buffers. Every worker then started every request as
 * that one, and answered other addresses with its page -- a signed-in admin
 * asking for the dashboard got the public front page, rendered anonymously.
 *
 * capture() is called before the warm-up and restore() only when it failed. A
 * failed warm-up is then what the pool already promised: workers that warm
 * themselves lazily, from a parent whose state is the one it had before.
 *
 * What restore() puts back:
 *   - output buffers: every one the warm-up opened is discarded;
 *   - superglobals and $GLOBALS: to the values captured, and the globals the
 *     warm-up added are removed;
 *   - statics of every class declared during the warm-up: to their declared
 *     defaults, as a process that never ran it would have them. Classes that
 *     existed before keep their statics; the warm-up has no business in the
 *     server's own state, and the snapshot and per-request restore take care
 *     of the rest.
 *
 * The classes themselves stay loaded; that part of a warm-up is harmless and
 * is what makes the workers' first requests cheaper even after a failure.
 *
 * @class Q_WebServer_WarmupGuard
 */
class Q_WebServer_WarmupGuard
{
	/** @var array Superglobals copied by capture() */
	protected static $superglobals = array();
	/** @var array $GLOBALS (other than superglobals) copied by capture() */
	protected static $globals = array();
	/** @var array Class names declared at capture() */
	protected static $classes = array();
	/** @var int Output buffer level at capture() */
	protected static $obLevel = 0;

	/** @var array The superglobals, which are copied and put back by value */
	protected static $superglobalNames = array('_GET', '_POST', '_COOKIE',
		'_SERVER', '_REQUEST', '_FILES', '_ENV', '_SESSION');

	/**
	 * Remember the state a failed warm-up must leave behind.
	 */
	static function capture()
	{
		self::$superglobals = array();
		foreach (self::$superglobalNames as $name) {
			if (isset($GLOBALS[$name]) && is_array($GLOBALS[$name])) {
				self::$superglobals[$name] = $GLOBALS[$name];
			}
		}
		self::$globals = array();
		foreach ($GLOBALS as $key => $value) {
			if ($key === 'GLOBALS' || in_array($key, self::$superglobalNames, true)) {
				continue;
			}
			self::$globals[$key] = $value;
		}
		self::$classes = array_flip(get_declared_classes());
		self::$obLevel = ob_get_level();
	}

	/**
	 * Undo what a failed warm-up left. Returns counts of what was undone, for
	 * the log: buffers, globals, classes.
	 *
	 * @return array
	 */
	static function restore()
	{
		$done = array('buffers' => 0, 'globals' => 0, 'classes' => 0);

		while (ob_get_level() > self::$obLevel) {
			if (!@ob_end_clean()) {
				break;
			}
			$done['buffers']++;
		}

		foreach (array_keys($GLOBALS) as $key) {
			if ($key === 'GLOBALS' || in_array($key, self::$superglobalNames, true)) {
				continue;
			}
			if (!array_key_exists($key, self::$globals)) {
				unset($GLOBALS[$key]);
				$done['globals']++;
			}
		}
		foreach (self::$globals as $key => $value) {
			$GLOBALS[$key] = $value;
		}
		foreach (self::$superglobalNames as $name) {
			if (array_key_exists($name, self::$superglobals)) {
				$GLOBALS[$name] = self::$superglobals[$name];
			}
		}

		foreach (get_declared_classes() as $cls) {
			if (isset(self::$classes[$cls])) {
				continue;
			}
			try {
				$ref = new \ReflectionClass($cls);
				if ($ref->isInternal()) {
					continue;
				}
				$reset = false;
				foreach ($ref->getProperties(\ReflectionProperty::IS_STATIC) as $prop) {
					if ($prop->getDeclaringClass()->getName() !== $cls || !$prop->hasDefaultValue()) {
						continue;
					}
					// A closure in a static is behaviour installed once, never
					// request data -- the same rule as the snapshot's restore.
					// Composer's ClassLoader, declared by the warm-up's
					// autoload.php, builds its include helper there behind a
					// null check in its constructor; put back to null, every
					// class it loaded afterwards failed with "Value of type
					// null is not callable".
					try {
						if ($prop->getValue(null) instanceof \Closure) {
							continue;
						}
					} catch (\Throwable $ignore) {
						// Uninitialized typed property: nothing to keep.
					}
					$prop->setValue(null, $prop->getDefaultValue());
					$reset = true;
				}
				if ($reset) {
					$done['classes']++;
				}
			} catch (\Throwable $e) {
				// A typed property without a usable default: left as it is.
			}
		}

		// Whatever the warm-up opened and only it referenced -- a database
		// connection among them -- is released now, in the parent, instead of
		// being inherited by every worker.
		gc_collect_cycles();

		self::$superglobals = self::$globals = self::$classes = array();
		return $done;
	}
}
