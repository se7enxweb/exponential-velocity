<?php
/**
 * The documentation and the command line agree.
 *
 * Forward: every option qbixserver.php accepts, every qbixconsole command
 * (and alias) and every option a command lists in its help is named somewhere
 * in README.md or docs/*.md.
 *
 * Backward: every qbixserver / qbixctl / qbixconsole invocation shown in the
 * documentation (in a fenced block or an inline code span) uses a command and
 * options that exist.
 *
 * What exists is read from the programs themselves: `qbixserver.php --help`,
 * the option lists its argument parser declares, `qbixconsole.php list` and
 * `qbixconsole.php help <command>`. No server is started and nothing is
 * written, so this runs with the unit suite.
 *
 * Usage: php tests/unit-docs-cli.php [--verbose]
 */

$root = dirname(__DIR__);
$verbose = in_array('--verbose', $argv, true);
$php = escapeshellarg(PHP_BINARY);
$fail = array();
$checks = 0;

function run_cli($cmd)
{
	$out = array();
	exec($cmd . ' 2>&1', $out);
	return implode("\n", $out);
}

// ── What exists ────────────────────────────────────────────────────────────

$serverSrc = file_get_contents("$root/sbin/qbixserver.php");
$serverOpts = array();   // name => 'valued' | 'flag'
if (preg_match('/qbix_normalize_argv\(\$argv,\s*array\((.*?)\),\s*array\((.*?)\)\);/s', $serverSrc, $m)) {
	preg_match_all("/'([a-z0-9-]+)'/", $m[1], $v);
	preg_match_all("/'([a-z0-9-]+)'/", $m[2], $f);
	foreach ($v[1] as $n) $serverOpts[$n] = 'valued';
	foreach ($f[1] as $n) $serverOpts[$n] = 'flag';
} else {
	$fail[] = 'cannot find the option lists in sbin/qbixserver.php (qbix_normalize_argv)';
}
$serverHelp = run_cli("$php " . escapeshellarg("$root/sbin/qbixserver.php") . ' --help');
preg_match_all('/^\s{2}(?:-[a-z], )?--([a-z0-9-]+)/m', $serverHelp, $hm);
$helpOpts = array_unique($hm[1]);
foreach ($helpOpts as $n) {
	$checks++;
	if (!isset($serverOpts[$n]) && !in_array($n, array('help', 'version'), true)) {
		$fail[] = "qbixserver.php --help lists --$n, which the argument parser does not declare";
	}
}
// Accepted outside the declared lists (handled before or beside the parser).
$serverExtra = array('help', 'version', 'about', 'copyright', 'qshell', 'qconsole', 'key', 'signer', 'm', 'output');

$consoleBin = "$php " . escapeshellarg("$root/sbin/qbixconsole.php");
$list = run_cli("$consoleBin list");
$commands = array();     // name => array of option names
$aliases = array();      // alias => command
foreach (explode("\n", $list) as $line) {
	if (!preg_match('/^\s{2}([a-z0-9]+:[a-z0-9:-]+)\s+(.*)$/', $line, $lm)) continue;
	$commands[$lm[1]] = array();
	if (preg_match('/\[([a-z0-9|-]+)\]\s*$/', $lm[2], $am)) {
		foreach (explode('|', $am[1]) as $a) $aliases[$a] = $lm[1];
	}
}
if (count($commands) < 10) $fail[] = 'qbixconsole.php list gave ' . count($commands) . ' commands; expected the full list';
foreach (array_keys($commands) as $c) {
	$h = run_cli("$consoleBin help " . escapeshellarg($c));
	preg_match_all('/^\s{2}--([a-z0-9-]+)/m', $h, $om);
	$commands[$c] = array_values(array_unique($om[1]));
}
// qbixctl's own verbs (the apachectl ones); everything else it hands to the console.
$ctlVerbs = array('start', 'stop', 'restart', 'graceful', 'status', 'reload', 'configtest', 'help', 'list');

// ── The documentation ──────────────────────────────────────────────────────

$docFiles = array_merge(array("$root/README.md"), glob("$root/docs/*.md") ?: array());
$all = '';
$codeLines = array();    // array(file, line no, text)
foreach ($docFiles as $file) {
	$text = file_get_contents($file);
	$all .= "\n" . $text;
	$inFence = false;
	foreach (explode("\n", $text) as $i => $line) {
		if (preg_match('/^\s*(```|~~~)/', $line)) { $inFence = !$inFence; continue; }
		$rel = substr($file, strlen($root) + 1);
		if ($inFence) {
			$codeLines[] = array($rel, $i + 1, $line);
		} else {
			preg_match_all('/`([^`]+)`/', $line, $cm);
			foreach ($cm[1] as $span) $codeLines[] = array($rel, $i + 1, $span);
		}
	}
}
// A unique abbreviation, segment by segment, as the console accepts it (ser:stat).
function abbreviation($commands, $word)
{
	if (strpos($word, ':') === false) return null;
	$parts = explode(':', $word);
	$hits = array();
	foreach (array_keys($commands) as $c) {
		$cp = explode(':', $c);
		if (count($cp) !== count($parts)) continue;
		$ok = true;
		foreach ($parts as $i => $p) if (strpos($cp[$i], $p) !== 0) { $ok = false; break; }
		if ($ok) $hits[] = $c;
	}
	return count($hits) === 1 ? $hits[0] : null;
}
function documented($all, $opt)
{
	return (bool) preg_match('/(?<![\w-])--' . preg_quote($opt, '/') . '(?![\w-])/', $all);
}

// ── Forward: what exists is documented ─────────────────────────────────────

foreach (array_keys($serverOpts) as $n) {
	$checks++;
	if (!documented($all, $n)) $fail[] = "qbixserver option --$n is not documented in README.md or docs/";
}
foreach ($commands as $c => $opts) {
	$checks++;
	$names = array_merge(array($c), array_keys($aliases, $c, true));
	$found = false;
	foreach ($names as $n) {
		if (preg_match('/(?<![\w:-])' . preg_quote($n, '/') . '(?![\w:-])/', $all)) { $found = true; break; }
	}
	if (!$found) $fail[] = "console command $c is not documented";
	foreach ($opts as $o) {
		if ($o === 'help') continue;
		$checks++;
		if (!documented($all, $o)) $fail[] = "option --$o of $c is not documented";
	}
}

// ── Backward: what is documented exists ────────────────────────────────────

// An optional path in front of the name (a URL is not a command, so no "scheme:").
$prog = '(?:(?:[A-Za-z]:)?[\w\\\\\/.-]*[\\\\\/])?';
foreach ($codeLines as $cl) {
	list($file, $no, $line) = $cl;
	// One shell command at a time: stop at comments, pipes and chains.
	foreach (preg_split('/\s(?:#|\|\|?|&&|;)\s|^\s*#/', $line) as $cmd) {
		if (preg_match('/(?:^|[\s"\'(])' . $prog . 'qbixserver(?:\.php|\.phar|-[a-z0-9_.-]+(?:\.exe)?)?(\s.*)?$/i', $cmd, $m)) {
			$args = isset($m[1]) ? preg_split('/\s+/', trim($m[1])) : array();
			foreach ($args as $a) {
				// After --, or after --qshell/--qconsole, the words belong to another program.
				if ($a === '--' || $a === '--qshell' || $a === '--qconsole') break;
				if (!preg_match('/^--?([a-z][a-z0-9-]*)(?:[=\[]|$)/', $a, $om)) continue;
				$n = $om[1];
				$base = preg_replace('/^no-/', '', $n);
				$checks++;
				if (isset($serverOpts[$n]) || in_array($n, $serverExtra, true)
					|| (strpos($n, 'no-') === 0 && isset($serverOpts[$base]))
					|| in_array($n, array('t', 'v', 'q', 'h'), true)) continue;
				$fail[] = "$file:$no: qbixserver $a -- no such option";
			}
		}
		// Two or more spaces after the name: a listing of programs and what they do, not a command.
		if (preg_match('/(?:^|[\s"\'(])' . $prog . '(qbixconsole|qbixctl)(?:\.php)?\s(?! )([a-z0-9:-]+)(.*)$/i', $cmd, $m)) {
			$tool = $m[1];
			$word = $m[2];
			if ($word[0] === '-') continue;   // -t, -S and options alone
			$checks++;
			$c = isset($commands[$word]) ? $word : (isset($aliases[$word]) ? $aliases[$word] : abbreviation($commands, $word));
			if ($c === null) {
				if ($tool === 'qbixctl' && in_array($word, $ctlVerbs, true)) continue;
				if (in_array($word, array('help', 'list'), true)) continue;
				// A placeholder such as <command> or a word of prose after the name.
				if (strpos($m[0], '<') !== false && strpos($word, ':') === false) continue;
				$fail[] = "$file:$no: $tool $word -- no such command";
				continue;
			}
			foreach (preg_split('/\s+/', trim($m[3])) as $a) {
				if ($a === '--') break;
				if (!preg_match('/^--([a-z][a-z0-9-]*)(?:=|$)/', $a, $om)) continue;
				$n = preg_replace('/^no-/', '', $om[1]);
				$checks++;
				if ($n === 'help' || in_array($n, $commands[$c], true)) continue;
				// server:start passes options after -- to qbixserver; accept those too.
				if ($c === 'server:start' && isset($serverOpts[$n])) continue;
				$fail[] = "$file:$no: $tool $word $a -- $c has no such option";
			}
		}
	}
}

// ── Report ─────────────────────────────────────────────────────────────────

if ($verbose) {
	echo count($serverOpts) . " server options, " . count($commands) . " console commands, "
		. count($codeLines) . " code lines read\n";
}
foreach (array_unique($fail) as $f) echo "  FAIL $f\n";
if ($fail) {
	echo '  FAIL - ' . count(array_unique($fail)) . " of $checks check(s)\n";
	exit(1);
}
echo "  PASS - $checks case(s)\n";
exit(0);
