<?php
/**
 * @module Q
 */
/**
 * The ext:* console commands over Q_WebServer_Extensions: list what a
 * variant carries, check this PHP against the standard set, plan and run a
 * static build, and say how to install what is missing.
 *
 *   qbixctl ext:list --variant=standard --platform=linux-x86_64 --php=8.3 --format=spc
 *   qbixctl ext:check                      exit 0 all present, 1 required missing, 2 recommended missing
 *   qbixctl ext:plan --variant=full --platform=windows-x64
 *   qbixctl ext:install-hint intl redis    (none given: whatever ext:check finds missing)
 *   qbixctl ext:build --variant=standard --php=8.4 --dry-run
 *
 * @class Q_WebServer_ExtensionsCtl
 * @static
 */
class Q_WebServer_ExtensionsCtl
{
	/** Register the commands with Q_Console. */
	static function register()
	{
		$C = 'Q_Console';
		$sel = array(
			'variant' => 'mini, lite, standard (default), full or source',
			'platform' => 'linux-x86_64, linux-aarch64, macos-arm64, windows-x64 (default: this machine)',
			'php' => 'PHP version, e.g. 8.3 (default: the manifest\'s default for builds, this PHP for checks)',
			'with' => 'Extra extensions, comma-separated (e.g. xdebug,imagick)',
			'manifest' => 'Another manifest file (default: build/extensions.json)',
		);
		$C::add('ext:list', 'List the PHP extensions a variant carries on a platform', function ($a, $o) {
			$r = self::resolveFrom($o, true);
			if ($r === null) return 1;
			$m = Q_WebServer_Extensions::manifest();
			switch ($o['format'] ?? 'table') {
				case 'spc': Q_Console::out(implode(',', $r['spc'])); return 0;
				case 'libs': Q_Console::out(implode(',', $r['libs'])); return 0;
				case 'json':
					$r['purposes'] = array();
					foreach ($r['include'] as $n) $r['purposes'][$n] = $m['extensions'][$n]['purpose'];
					Q_Console::out(json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
					return 0;
				case 'csv':
					Q_Console::out('name,tier,spc');
					foreach ($r['include'] as $n) Q_Console::out($n . ',' . $m['extensions'][$n]['tier'] . ',' . ($m['extensions'][$n]['spc'] ?? ''));
					return 0;
				case 'table':
					Q_Console::out(sprintf('%s on %s, PHP %s: %d extensions', $r['variant'], $r['platform'], $r['php'], count($r['include'])));
					foreach ($r['include'] as $n) {
						Q_Console::out(sprintf('  %-16s %-12s %s', $n, $m['extensions'][$n]['tier'], $m['extensions'][$n]['purpose']));
					}
					return 0;
			}
			Q_Console::err('unknown --format; one of table, json, spc, libs, csv');
			return 1;
		}, $sel + array('format' => 'table (default), json, spc (for spc build), libs (for --with-libs), csv'));

		$C::add('ext:plan', 'Show what a build of a variant includes, what it leaves out, and why', function ($a, $o) {
			$r = self::resolveFrom($o, true);
			if ($r === null) return 1;
			$m = Q_WebServer_Extensions::manifest();
			Q_Console::out(sprintf('%s on %s (%s), PHP %s', $r['variant'], $r['platform'],
				$m['platforms'][$r['platform']]['label'] ?? 'no static build for this platform', $r['php']));
			Q_Console::out('  ' . $m['variants'][$r['variant']]['description']);
			$by = array();
			foreach ($r['include'] as $n) $by[$m['extensions'][$n]['tier']][] = $n;
			foreach ($by as $tier => $names) Q_Console::out(sprintf('  %-12s %d: %s', $tier, count($names), implode(' ', $names)));
			if ($r['exclude']) {
				Q_Console::out('  left out:');
				foreach ($r['exclude'] as $n => $why) Q_Console::out(sprintf('    %-16s %s', $n, $why));
			}
			if (isset($m['php']['notes'][$r['php']])) Q_Console::out('  PHP ' . $r['php'] . ': ' . $m['php']['notes'][$r['php']]);
			if ($r['libs']) Q_Console::out('  with libraries: ' . implode(', ', $r['libs']));
			foreach (self::buildCommands($r, $o, true) as $cmd) Q_Console::out('  $ ' . $cmd);
			return 0;
		}, $sel + array('spc' => 'The spc binary (default: spc on PATH, or ./spc)'));

		$C::add('ext:check', 'Check this PHP against the standard set of extensions', function ($a, $o) {
			if (isset($o['manifest'])) { Q_WebServer_Extensions::$manifestPath = (string) $o['manifest']; Q_WebServer_Extensions::reset(); }
			try {
				$c = Q_WebServer_Extensions::check(isset($o['variant']) ? (string) $o['variant'] : null);
			} catch (Throwable $e) {
				Q_Console::err($e->getMessage());
				return 1;
			}
			$tier = isset($o['tier']) ? (string) $o['tier'] : null;
			$code = $c['missing_required'] ? 1 : ($c['missing_recommended'] || $c['incomplete'] ? 2 : 0);
			if (($o['format'] ?? 'table') === 'json') {
				$c['exit'] = $code;
				Q_Console::out(json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
				return $code;
			}
			Q_Console::out(sprintf('PHP %s on %s%s: provides the \'%s\' set; checked against \'%s\'', $c['php'], $c['platform'],
				Q_WebServer_Extensions::isStaticBuild() ? ' (static build)' : '', $c['variant_detected'], $c['variant']));
			foreach ($c['rows'] as $row) {
				if ($tier !== null && $row['tier'] !== $tier) continue;
				$mark = $row['loaded'] ? ($row['missingCapabilities'] ? '~' : 'ok') : '--';
				$extra = $row['missingCapabilities'] ? ' (without ' . implode(', ', $row['missingCapabilities']) . ')' : '';
				Q_Console::out(sprintf('  %-3s %-16s %-12s %s%s', $mark, $row['name'], $row['tier'], $row['purpose'], $extra));
			}
			$missing = array_merge($c['missing_required'], $c['missing_recommended']);
			if ($missing) {
				Q_Console::out('');
				Q_Console::out('missing: ' . implode(', ', $missing));
				self::printHints(Q_WebServer_Extensions::installHints($missing));
			} else {
				Q_Console::out('');
				Q_Console::out('nothing missing' . ($c['incomplete'] ? ', but some are built without features (marked ~)' : ''));
			}
			return $code;
		}, array('variant' => $sel['variant'], 'tier' => 'Show one tier only: server, required, recommended or extra',
			'format' => 'table (default) or json', 'manifest' => $sel['manifest']));

		$C::add('ext:install-hint', 'Show how to install extensions (or database add-ons) on this machine', function ($a, $o) {
			if (isset($o['manifest'])) { Q_WebServer_Extensions::$manifestPath = (string) $o['manifest']; Q_WebServer_Extensions::reset(); }
			$names = $a;
			if (!$names) {
				$c = Q_WebServer_Extensions::check();
				$names = array_merge($c['missing_required'], $c['missing_recommended']);
				if (!$names) { Q_Console::out('nothing missing from the standard set'); return 0; }
			}
			$h = Q_WebServer_Extensions::installHints($names, isset($o['manager']) ? (string) $o['manager'] : null,
				isset($o['php']) ? (string) $o['php'] : null);
			self::printHints($h);
			return 0;
		}, array('manager' => 'apt, dnf, dnf-scl, plesk, apk, pkg, brew or windows (default: detected)',
			'php' => 'PHP version the packages are for (default: this PHP)', 'manifest' => $sel['manifest']), array(), '[extension...]');

		$C::add('ext:build', 'Build a static PHP and server binary for a variant with static-php-cli (or the source kit)', function ($a, $o) {
			$r = self::resolveFrom($o, true);
			if ($r === null) return 1;
			$dry = !empty($o['dry-run']);
			if ($r['kit']) return self::buildKit($r, $o, $dry);
			if ($r['platform'] !== Q_WebServer_Extensions::platform()) {
				Q_Console::err('static-php-cli builds for the machine it runs on (' . Q_WebServer_Extensions::platform() . '), not ' . $r['platform']);
				if (!$dry) return 1;
			}
			foreach (self::buildCommands($r, $o, false) as $cmd) {
				Q_Console::out('$ ' . $cmd);
				if ($dry) continue;
				passthru($cmd, $code);
				if ($code !== 0) { Q_Console::err("failed (exit $code): $cmd"); return $code; }
			}
			if (!$dry) Q_Console::out('built ' . self::artifactPath($r, $o));
			return 0;
		}, $sel + array('spc' => 'The spc binary (default: spc on PATH, or ./spc)',
			'out' => 'Where the binary or kit goes (default: dist)',
			'dry-run' => array('Print the commands instead of running them', false)));
	}

	/** Resolve the variant the options name, or print why not and return null. */
	private static function resolveFrom(array $o, $forBuild)
	{
		if (isset($o['manifest'])) { Q_WebServer_Extensions::$manifestPath = (string) $o['manifest']; Q_WebServer_Extensions::reset(); }
		try {
			$m = Q_WebServer_Extensions::manifest();
			$platform = (isset($o['platform']) && $o['platform'] !== 'auto') ? (string) $o['platform'] : Q_WebServer_Extensions::platform();
			$php = isset($o['php']) ? (string) $o['php'] : ($forBuild ? $m['php']['default'] : Q_WebServer_Extensions::phpVersion());
			if ($forBuild && !in_array($php, $m['php']['versions'], true)) {
				Q_Console::err("PHP $php is not one of the versions built: " . implode(', ', $m['php']['versions']));
				return null;
			}
			$with = isset($o['with']) ? array_values(array_filter(array_map('trim', explode(',', (string) $o['with'])))) : array();
			return Q_WebServer_Extensions::resolve(isset($o['variant']) ? (string) $o['variant'] : $m['defaultVariant'], $platform, $php, $with);
		} catch (Throwable $e) {
			Q_Console::err($e->getMessage());
			return null;
		}
	}

	/** The spc binary: --spc, else spc on PATH, else ./spc. */
	private static function spcBinary(array $o)
	{
		if (isset($o['spc'])) return (string) $o['spc'];
		$which = PHP_OS_FAMILY === 'Windows' ? 'where spc 2>NUL' : 'command -v spc 2>/dev/null';
		$found = trim((string) @shell_exec($which));
		return $found !== '' ? strtok($found, "\n") : './spc';
	}

	/** Where ext:build writes the binary for a resolved variant. */
	static function artifactPath(array $r, array $o)
	{
		$out = rtrim((string) ($o['out'] ?? 'dist'), '/\\');
		$exe = strpos($r['platform'], 'windows-') === 0 ? '.exe' : '';
		return $out . '/qbixserver-' . $r['platform'] . '-php' . $r['php'] . '-' . $r['variant'] . $exe;
	}

	/**
	 * The commands that build a variant: fetch sources, build PHP (CLI and
	 * micro), rebuild the phar, and combine the two into one binary.
	 * @method buildCommands
	 * @static
	 * @param {array} $r a resolve() result
	 * @param {array} $o the command's options (spc, out)
	 * @param {boolean} $short only the two spc commands, for ext:plan
	 * @return {array}
	 */
	static function buildCommands(array $r, array $o, $short)
	{
		$spc = self::spcBinary($o);
		$list = implode(',', $r['spc']);
		$q = function ($s) { return escapeshellarg($s); };
		$cmds = array(
			$q($spc) . ' download --for-extensions=' . $q($list) . ' --with-php=' . $q($r['php']) . ' --prefer-pre-built',
			$q($spc) . ' build ' . $q($list) . ' --build-cli --build-micro' . ($r['libs'] ? ' --with-libs=' . $q(implode(',', $r['libs'])) : ''),
		);
		if ($short) return $cmds;
		$root = dirname(__DIR__, 3);
		$out = self::artifactPath($r, $o);
		$mkdir = PHP_OS_FAMILY === 'Windows' ? 'mkdir ' . $q(dirname($out)) . ' 2>NUL & ' : 'mkdir -p ' . $q(dirname($out)) . ' && ';
		// A full checkout rebuilds the phar from its sources first; the source
		// kit has no designs/ or web/ to rebuild from, and ships the phar.
		if (is_dir($root . '/designs') && is_file($root . '/build-phar.php')) {
			$cmds[] = $mkdir . $q(PHP_BINARY) . ' -d phar.readonly=0 ' . $q($root . '/build-phar.php');
		} else {
			$cmds[] = rtrim($mkdir, ' &');
		}
		$cmds[] = $q($spc) . ' micro:combine ' . $q($root . '/sbin/qbixserver.phar') . ' -O ' . $q($out);
		return $cmds;
	}

	/**
	 * The source variant: a kit to build any variant later, anywhere -- the
	 * phar, the manifest and schema, the console with the sources it needs,
	 * the requirements docs, and BUILD.md with the recipe for every variant.
	 */
	private static function buildKit(array $r, array $o, $dry)
	{
		$root = dirname(__DIR__, 3);
		$out = rtrim((string) ($o['out'] ?? 'dist'), '/\\');
		$name = 'qbixserver-source-kit';
		$files = array('sbin/qbixserver.phar', 'build/extensions.json', 'build/extensions.schema.json',
			'sbin/qbixconsole.php', 'sbin/qbixctl.php', 'qbixconsole.php', 'qbixctl.php', 'docs/requirements.md', 'docs/extensions.md', 'LICENSE');
		$m = Q_WebServer_Extensions::manifest();
		$recipe = "# Building the server from this kit\n\n"
			. "Requires PHP " . $m['php']['minimum'] . "+ and static-php-cli " . ($m['spc']['version'] ?? '') . " (https://static-php.dev).\n"
			. "Each variant is built on the machine it is for. List, plan and build with the console:\n\n"
			. "    php sbin/qbixctl.php ext:list --variant=standard\n    php sbin/qbixctl.php ext:plan --variant=full\n"
			. "    php sbin/qbixctl.php ext:build --variant=standard --php=" . $m['php']['default'] . " --spc=/path/to/spc\n\n"
			. "Variants:\n\n";
		foreach ($m['variants'] as $v => $d) $recipe .= "- **$v**: {$d['description']}\n";
		$recipe .= "\nPHP versions: " . implode(', ', $m['php']['versions']) . ". See docs/requirements.md for every extension and docs/extensions.md for the commands.\n";
		$target = "$out/$name.tar.gz";
		Q_Console::out("kit: $target");
		foreach ($files as $f) Q_Console::out("  + $f");
		Q_Console::out('  + src/ (the console needs it)');
		Q_Console::out('  + BUILD.md');
		if ($dry) return 0;
		if (!is_dir($out) && !@mkdir($out, 0755, true)) { Q_Console::err("cannot create $out"); return 1; }
		$tar = "$out/$name.tar";
		@unlink($tar); @unlink($target);
		try {
			$p = new PharData($tar);
			foreach ($files as $f) if (is_file("$root/$f")) $p->addFile("$root/$f", "$name/$f");
			$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/src", FilesystemIterator::SKIP_DOTS));
			foreach ($it as $file) $p->addFile($file->getPathname(), "$name/" . substr($file->getPathname(), strlen($root) + 1));
			$p->addFromString("$name/BUILD.md", $recipe);
			$p->compress(Phar::GZ);
			unset($p);
			@unlink($tar);
		} catch (Throwable $e) {
			Q_Console::err('could not write the kit: ' . $e->getMessage());
			return 1;
		}
		Q_Console::out("built $target");
		return 0;
	}

	/** Print install hints: the commands, then the notes. */
	private static function printHints(array $h)
	{
		Q_Console::out('to install' . ($h['manager'] ? ' (' . $h['manager'] . ')' : '') . ':');
		foreach ($h['commands'] as $c) Q_Console::out('  ' . $c);
		foreach ($h['notes'] as $n) Q_Console::out('  note: ' . $n);
		if (!$h['commands'] && !$h['notes']) Q_Console::out('  (no hint for this system; see docs/extensions.md)');
	}
}
