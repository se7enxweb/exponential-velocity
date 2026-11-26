<?php
/**
 * Every relative link in the documentation resolves.
 *
 * Reads README.md, the other top-level *.md files and docs/*.md, and checks
 * each Markdown link that is not a URL: the file (or directory) must exist in
 * the repository, and a #fragment must name a heading of the target page, made
 * into an anchor the way GitHub does it. Links into the GitHub web interface
 * that only work on github.com (../../actions/...) are skipped. No network.
 *
 * Usage: php tests/unit-docs-links.php
 */

$root = dirname(__DIR__);
$files = array_merge(glob("$root/*.md") ?: array(), glob("$root/docs/*.md") ?: array());
$fail = array();
$checks = 0;
$anchorCache = array();

function github_anchor($heading)
{
	$h = strtolower(trim($heading));
	$h = preg_replace('/<[^>]+>/', '', $h);                  // inline HTML
	$h = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $h);  // links keep their text
	$h = preg_replace('/[^\p{L}\p{M}\p{N}\p{Pc} -]/u', '', $h);       // punctuation, emoji
	return str_replace(' ', '-', $h);
}

function anchors_of($file, &$cache)
{
	if (isset($cache[$file])) return $cache[$file];
	$seen = array();
	$out = array();
	$inFence = false;
	foreach (explode("\n", (string) file_get_contents($file)) as $line) {
		if (preg_match('/^\s*(```|~~~)/', $line)) { $inFence = !$inFence; continue; }
		if ($inFence) continue;
		if (preg_match('/^#{1,6}\s+(.*?)\s*#*\s*$/', $line, $m)) {
			$a = github_anchor($m[1]);
			$n = isset($seen[$a]) ? $seen[$a] : 0;
			$out[$n ? "$a-$n" : $a] = true;
			$seen[$a] = $n + 1;
		}
		// Explicit anchors: <a name="x"> or id="x".
		if (preg_match_all('/<a\s+(?:name|id)="([^"]+)"/i', $line, $m)) {
			foreach ($m[1] as $a) $out[$a] = true;
		}
	}
	return $cache[$file] = $out;
}

foreach ($files as $file) {
	$rel = substr($file, strlen($root) + 1);
	$inFence = false;
	foreach (explode("\n", file_get_contents($file)) as $i => $line) {
		if (preg_match('/^\s*(```|~~~)/', $line)) { $inFence = !$inFence; continue; }
		if ($inFence) continue;
		$line = preg_replace('/`[^`]*`/', '', $line);   // code spans are not links
		if (!preg_match_all('/\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)/', $line, $m)) continue;
		foreach ($m[1] as $target) {
			if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $target)) continue;      // http:, mailto:, ...
			if (preg_match('#^(\.\./)+(actions|issues|pulls|releases|wiki)\b#', $target)) continue;
			$checks++;
			$path = $target;
			$frag = '';
			if (($p = strpos($target, '#')) !== false) {
				$path = substr($target, 0, $p);
				$frag = urldecode(substr($target, $p + 1));
			}
			$dest = $path === '' ? $file : dirname($file) . '/' . urldecode($path);
			if (!file_exists($dest)) {
				$fail[] = "$rel:" . ($i + 1) . ": $target -- no such file";
				continue;
			}
			if ($frag !== '' && is_file($dest) && substr($dest, -3) === '.md') {
				$anchors = anchors_of($dest, $anchorCache);
				if (!isset($anchors[strtolower($frag)]) && !isset($anchors[$frag])) {
					$fail[] = "$rel:" . ($i + 1) . ": $target -- no heading for #$frag";
				}
			}
		}
	}
}

foreach ($fail as $f) echo "  FAIL $f\n";
if ($fail) {
	echo '  FAIL - ' . count($fail) . " of $checks link(s)\n";
	exit(1);
}
echo "  PASS - $checks link(s)\n";
exit(0);
