#!/usr/bin/env php
<?php
/**
 * packaging/nfpm/render.php: the deb and rpm package is exponential-velocity,
 * installs under its own name everywhere, takes the place of its former name
 * qbix-webserver (deb Replaces/Breaks/Provides, rpm Obsoletes/Provides via
 * nfpm's replaces), and carries the scripts that move the old package's
 * settings, state and service across -- posttrans on rpm, where the obsoleted
 * package is removed after %post.
 *
 *   php tests/unit-nfpm-render.php
 */
$pass = 0;
$fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what,
		var_export($got, true), var_export($want, true));
}
$root = dirname(__DIR__);
$render = function ($distro) use ($root) {
	$out = array();
	exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/packaging/nfpm/render.php") . ' '
		. escapeshellarg($distro) . ' 0.0.4.99 2>/dev/null', $out, $rc);
	return $rc === 0 ? json_decode(implode("\n", $out), true) : null;
};

foreach (array('debian-12', 'ubuntu-24.04', 'el-9', 'el-10') as $distro) {
	$s = $render($distro);
	check("$distro renders", is_array($s), true);
	if (!is_array($s)) continue;
	$deb = strncmp($distro, 'el-', 3) !== 0;
	check("$distro is named exponential-velocity", $s['name'], 'exponential-velocity');
	check("$distro replaces the former name", $s['replaces'] ?? null, array('qbix-webserver'));
	check("$distro provides the former name", $s['provides'] ?? null, array('qbix-webserver'));
	check("$distro runs the takeover scripts", array($s['scripts']['preinstall'] ?? null, $s['scripts']['postinstall'] ?? null),
		array('packaging/nfpm/preinstall.sh', 'packaging/nfpm/postinstall.sh'));
	if ($deb) {
		check("$distro breaks the former package (so dpkg may take its files)", $s['deb']['breaks'] ?? null, array('qbix-webserver'));
	} else {
		check("$distro finishes the takeover in posttrans", $s['rpm']['scripts']['posttrans'] ?? null, 'packaging/nfpm/posttrans.sh');
	}
	$dst = array_column($s['contents'], 'dst');
	$unit = array_values(array_filter($dst, function ($d) { return substr($d, -8) === '.service'; }));
	check("$distro ships the unit under the new name", count($unit) === 1 && basename($unit[0]) === 'exponential-velocity.service', true);
	check("$distro ships its settings under the new name", in_array('/etc/default/exponential-velocity', $dst, true), true);
	check("$distro installs the phar under /usr/share/exponential-velocity", in_array('/usr/share/exponential-velocity/sbin/qbixserver.phar', $dst, true), true);
	check("$distro ships nothing under the former name", count(array_filter($dst, function ($d) { return strpos($d, 'qbix-webserver') !== false; })), 0);
	$byDst = array();
	foreach ($s['contents'] as $c) $byDst[$c['dst']] = $c;
	foreach ($s['contents'] as $c) {
		if (($c['type'] ?? '') === 'symlink') {
			// A link's src is its target, relative to the link: it must be
			// something the package itself installs.
			$parts = array();
			foreach (explode('/', dirname($c['dst']) . '/' . $c['src']) as $p) {
				if ($p === '..') array_pop($parts); elseif ($p !== '' && $p !== '.') $parts[] = $p;
			}
			$target = '/' . implode('/', $parts);
			if (!isset($byDst[$target])) { check("$distro link {$c['dst']} points into the package ($target)", false, true); break; }
			continue;
		}
		if (isset($c['src']) && !is_file("$root/{$c['src']}")) { check("$distro source exists: {$c['src']}", false, true); break; }
	}

	// Programs: the daemon and its administration commands in /usr/sbin,
	// links at their former paths in /usr/bin (docs/layout.md, "Programs").
	foreach (array('qbixserver', 'qbixctl', 'qbixconsole') as $t) {
		$sbin = $byDst["/usr/sbin/$t"] ?? array();
		check("$distro installs /usr/sbin/$t from packaging/sbin/$t, executable",
			array($sbin['src'] ?? null, $sbin['type'] ?? '', $sbin['file_info']['mode'] ?? null), array("packaging/sbin/$t", '', 0755));
		$bin = $byDst["/usr/bin/$t"] ?? array();
		check("$distro keeps /usr/bin/$t as a link to ../sbin/$t", array($bin['type'] ?? null, $bin['src'] ?? null), array('symlink', "../sbin/$t"));
	}
	check("$distro puts no regular file in /usr/bin", count(array_filter($s['contents'], function ($c) {
		return strncmp($c['dst'], '/usr/bin/', 9) === 0 && ($c['type'] ?? '') !== 'symlink';
	})), 0);
	// The engine's tree under /usr/share/exponential-velocity, in its own layout.
	$share = '/usr/share/exponential-velocity';
	foreach (array('sbin/qbixserver.phar', 'sbin/qbixserver.php', 'sbin/qbixctl.php', 'sbin/qbixconsole.php', 'bin/qshell.php',
		'qbixserver.php', 'qbixctl.php', 'qbixconsole.php', 'qshell.php', 'src/Q.php') as $f) {
		check("$distro installs $share/$f", isset($byDst["$share/$f"]) && ($byDst["$share/$f"]['type'] ?? '') === '', true);
	}
	$pharLink = $byDst["$share/bin/qbixserver.phar"] ?? array();
	check("$distro keeps the phar's former path, $share/bin/qbixserver.phar, as a link to ../sbin/qbixserver.phar",
		array($pharLink['type'] ?? null, $pharLink['src'] ?? null), array('symlink', '../sbin/qbixserver.phar'));
	check("$distro leaves the uwebserver benchmark binary out", count(array_filter($dst, function ($d) { return strpos($d, 'uwebserver') !== false; })), 0);
}

// The unit starts the daemon at its /usr/sbin path.
$unitText = file_get_contents("$root/packaging/systemd/exponential-velocity.service");
check('the unit starts /usr/sbin/qbixserver', (bool) preg_match('#^ExecStart=/usr/sbin/qbixserver #m', $unitText), true);
check('the unit reloads through /usr/sbin/qbixserver', (bool) preg_match('#^ExecReload=/usr/sbin/qbixserver #m', $unitText), true);
// The wrappers the package installs parse, and the old packaging/bin names still lead to them.
foreach (array('qbixserver', 'qbixctl', 'qbixconsole') as $t) {
	exec('sh -n ' . escapeshellarg("$root/packaging/sbin/$t") . ' 2>&1', $o, $rc);
	check("packaging/sbin/$t parses", $rc, 0);
	check("packaging/bin/$t (the former path) leads to packaging/sbin/$t",
		realpath("$root/packaging/bin/$t"), realpath("$root/packaging/sbin/$t"));
}

// The scripts it names exist and are shell scripts sh can parse.
foreach (array('preinstall', 'postinstall', 'posttrans', 'preremove') as $n) {
	$f = "$root/packaging/nfpm/$n.sh";
	check("$n.sh exists", is_file($f), true);
	exec('sh -n ' . escapeshellarg($f) . ' 2>&1', $o, $rc);
	check("$n.sh parses", $rc, 0);
}
// An upgrade of the package itself must not stop and disable its service.
$pre = file_get_contents("$root/packaging/nfpm/preremove.sh");
check('preremove stops the service only when removing', (bool) preg_match('/remove\|purge\|0\)/', $pre), true);

echo $fail ? "  FAIL - $fail of " . ($pass + $fail) . " case(s)\n" : "  PASS - $pass case(s)\n";
exit($fail ? 1 : 0);
