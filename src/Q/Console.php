<?php
/**
 * @module Q
 */
/**
 * A small command console in the manner of Symfony's, with no library behind
 * it: named commands grouped by namespace (server:start), aliases, unique
 * abbreviations (se:st), `list` and `help`, --option=value / --flag / -f
 * parsing, and a process exit code from every command.
 *
 *   Q_Console::add('server:status', 'Report whether the server runs',
 *       function ($args, $opts) { ...; return 0; },
 *       array('pid' => 'Pid file'), array('status'));
 *   exit(Q_Console::run($argv, 'qbixconsole'));
 *
 * A handler receives the positional arguments (after the command name) and
 * the options, and returns an int (0 = success). Output goes through out()
 * and err(), which colour only when writing to a terminal.
 *
 * @class Q_Console
 * @static
 */
class Q_Console
{
	/** @var array name => array(description, handler, options, aliases, usage) */
	private static $commands = array();

	/** @var string the program name shown in usage lines */
	public static $program = 'qbixconsole';

	/** @var string a one-line title shown above `list` */
	public static $title = 'Qbix console';

	/**
	 * Register a command.
	 * @method add
	 * @static
	 * @param {string} $name namespace:command, or a plain name
	 * @param {string} $description one line
	 * @param {callable} $handler function(array $args, array $opts): int
	 * @param {array} $options option name => description
	 * @param {array} $aliases other names for it
	 * @param {string} $usage the arguments part of its usage line
	 */
	static function add($name, $description, $handler, array $options = array(), array $aliases = array(), $usage = '')
	{
		self::$commands[$name] = array(
			'description' => $description, 'handler' => $handler,
			'options' => $options, 'aliases' => $aliases, 'usage' => $usage,
		);
	}


	/**
	 * Every registered command, without its handler: name => description,
	 * options, aliases, usage. For tools that list or complete commands.
	 * @method all
	 * @static
	 * @return {array}
	 */
	static function all()
	{
		$out = array();
		foreach (self::$commands as $name => $c) {
			unset($c['handler']);
			$out[$name] = $c;
		}
		return $out;
	}
	/** Whether a command is registered under this exact name. */
	static function has($name)
	{
		return isset(self::$commands[$name]);
	}

	/**
	 * Split argv into positional arguments and options, GNU or BSD style:
	 *
	 *   --name=value   --name value    GNU long option (value options only
	 *                                   take the next argument)
	 *   -name=value    -name value     BSD single-dash long option, for any
	 *                                   name longer than one letter that is
	 *                                   a known option
	 *   --flag  -flag  --no-flag       flags (true / true / false)
	 *   -abc                            bundled one-letter flags; one given twice
 *                                   or more counts (-vv is v => 2)
	 *   --                              ends options; the rest is positional
	 *
	 * @method parse
	 * @static
	 * @param {array} $argv without the program name
	 * @param {array} $valued names of options that take a value
	 * @param {array} $known names of every known option (values and flags);
	 *   a single-dash word is a long option only if it is one of these
	 * @return {array} array($positional, $options)
	 */
	static function parse(array $argv, ?array $valued = null, ?array $known = null)
	{
		if ($valued === null) $valued = self::valueOptions();
		if ($known === null) $known = self::knownOptions();
		$positional = array(); $options = array(); $done = false;
		$argv = array_values($argv);
		for ($i = 0, $n = count($argv); $i < $n; $i++) {
			$arg = (string) $argv[$i];
			if ($done or $arg === '' or $arg[0] !== '-' or $arg === '-') { $positional[] = $arg; continue; }
			if ($arg === '--') { $done = true; continue; }
			$long = strncmp($arg, '--', 2) === 0;
			$body = substr($arg, $long ? 2 : 1);
			$eq = strpos($body, '=');
			$name = $eq === false ? $body : substr($body, 0, $eq);
			// A single dash is a long option only for a known word (BSD style,
			// -config /path); otherwise it is bundled letters (-abc).
			if (!$long and (strlen($name) === 1 or !in_array($name, $known, true))) {
				if ($eq !== false and strlen($name) === 1) { $options[$name] = substr($body, $eq + 1); continue; }
				// A letter given twice or more counts: -vv is v => 2, as verbosity reads it.
				foreach (str_split($body) as $flag) {
					$options[$flag] = isset($options[$flag]) && $options[$flag] !== false
						? (is_int($options[$flag]) ? $options[$flag] + 1 : 2) : true;
				}
				continue;
			}
			if ($eq !== false) { $options[$name] = substr($body, $eq + 1); continue; }
			if (in_array($name, $valued, true) and $i + 1 < $n
				and ((string) $argv[$i + 1] === '' or ((string) $argv[$i + 1])[0] !== '-')) {
				$options[$name] = (string) $argv[++$i];
				continue;
			}
			if (strncmp($name, 'no-', 3) === 0 and !in_array($name, $known, true)) { $options[substr($name, 3)] = false; continue; }
			$options[$name] = true;
		}
		return array($positional, $options);
	}

	/**
	 * Names of options that take a value, across every command: an option is
	 * declared as a flag by giving its description as array(description, false).
	 */
	static function valueOptions()
	{
		$out = array();
		foreach (self::$commands as $c) {
			foreach ($c['options'] as $name => $d) {
				if (!(is_array($d) and isset($d[1]) and $d[1] === false)) $out[$name] = true;
			}
		}
		return array_keys($out);
	}

	/** Names of every declared option, and help. */
	static function knownOptions()
	{
		$out = array('help' => true);
		foreach (self::$commands as $c) {
			foreach ($c['options'] as $name => $d) $out[$name] = true;
		}
		return array_keys($out);
	}

	/**
	 * Find a command by name, alias, or an abbreviation that matches one
	 * command only, segment by segment (se:st -> server:start).
	 *
	 * @method find
	 * @static
	 * @param {string} $name
	 * @return {string|array|null} the command's name, a list of candidates when ambiguous, or null
	 */
	static function find($name)
	{
		if (isset(self::$commands[$name])) return $name;
		foreach (self::$commands as $cmd => $c) {
			if (in_array($name, $c['aliases'], true)) return $cmd;
		}
		$want = explode(':', $name);
		$hits = array();
		foreach (self::$commands as $cmd => $c) {
			$parts = explode(':', $cmd);
			if (count($parts) !== count($want)) continue;
			$ok = true;
			foreach ($want as $i => $w) {
				if ($w === '' or strncmp($parts[$i], $w, strlen($w)) !== 0) { $ok = false; break; }
			}
			if ($ok) $hits[] = $cmd;
		}
		sort($hits);
		if (count($hits) === 1) return $hits[0];
		return $hits ? $hits : null;
	}

	/**
	 * Run the console on argv; returns the exit code.
	 * @method run
	 * @static
	 * @param {array} $argv including the program name
	 * @return {integer}
	 */
	static function run(array $argv)
	{
		array_shift($argv);
		list($args, $opts) = self::parse($argv);
		$name = array_shift($args);
		if ($name === null or $name === 'list') {
			self::listCommands($args[0] ?? null);
			return 0;
		}
		if ($name === 'help') {
			$name = array_shift($args);
			if ($name === null) { self::listCommands(); return 0; }
			$opts['help'] = true;
		}
		$found = self::find($name);
		if ($found === null) {
			self::err("Command \"$name\" is not defined. Run `" . self::$program . " list`.");
			return 1;
		}
		if (is_array($found)) {
			self::err("Command \"$name\" is ambiguous: " . implode(', ', $found) . '.');
			return 1;
		}
		if (!empty($opts['help']) or !empty($opts['h'])) {
			self::help($found);
			return 0;
		}
		try {
			$code = call_user_func(self::$commands[$found]['handler'], $args, $opts);
		} catch (\Throwable $e) {
			self::err(get_class($e) . ': ' . $e->getMessage());
			return 1;
		}
		return is_int($code) ? $code : 0;
	}

	/** Print the commands, grouped by namespace, optionally one namespace only. */
	static function listCommands($namespace = null)
	{
		self::out(self::style(self::$title, 'title'));
		self::out('');
		self::out(self::style('Usage:', 'head'));
		self::out('  ' . self::$program . ' <command> [options] [arguments]');
		self::out('');
		self::out(self::style('Available commands:', 'head'));
		$names = array_keys(self::$commands);
		sort($names);
		$width = $names ? max(array_map('strlen', $names)) + 2 : 10;
		$group = null;
		foreach ($names as $n) {
			$ns = strpos($n, ':') !== false ? substr($n, 0, strpos($n, ':')) : '';
			if ($namespace !== null and $ns !== $namespace) continue;
			if ($ns !== $group) { $group = $ns; if ($ns !== '') self::out(' ' . self::style($ns, 'head')); }
			$c = self::$commands[$n];
			$alias = $c['aliases'] ? ' [' . implode('|', $c['aliases']) . ']' : '';
			self::out('  ' . self::style(str_pad($n, $width), 'name') . $c['description'] . $alias);
		}
	}

	/** Print one command's usage, options and aliases. */
	static function help($name)
	{
		$c = self::$commands[$name];
		self::out(self::style('Description:', 'head'));
		self::out('  ' . $c['description']);
		self::out('');
		self::out(self::style('Usage:', 'head'));
		self::out('  ' . self::$program . ' ' . $name . ($c['options'] ? ' [options]' : '') . ($c['usage'] !== '' ? ' ' . $c['usage'] : ''));
		if ($c['aliases']) self::out('  aliases: ' . implode(', ', $c['aliases']));
		if ($c['options']) {
			self::out('');
			self::out(self::style('Options:', 'head'));
			$w = max(array_map('strlen', array_keys($c['options']))) + 4;
			foreach ($c['options'] as $o => $d) {
				$flag = is_array($d) && isset($d[1]) && $d[1] === false;
				$text = is_array($d) ? $d[0] : $d;
				self::out('  ' . self::style(str_pad('--' . $o . ($flag ? '' : '=V'), $w + 2), 'name') . $text);
			}
			self::out('');
			self::out('  Values: --name=V, --name V, -name=V or -name V. Flags: --name, -name, --no-name.');
		}
	}

	static function out($line = '')
	{
		fwrite(STDOUT, $line . "\n");
	}

	static function err($line)
	{
		fwrite(STDERR, self::style($line, 'error', STDERR) . "\n");
	}

	/** Colour for a terminal, plain text otherwise. */
	static function style($text, $kind, $stream = null)
	{
		$stream = $stream ?: STDOUT;
		$forced = getenv('QBIX_FORCE_COLOR') === '1';
		if (getenv('NO_COLOR') !== false or (!$forced and (!function_exists('stream_isatty') or !@stream_isatty($stream)))) return $text;
		$codes = array('title' => '1', 'head' => '33', 'name' => '32', 'error' => '37;41', 'ok' => '32', 'warn' => '33');
		return "\033[" . ($codes[$kind] ?? '0') . 'm' . $text . "\033[0m";
	}
}
