#!/usr/bin/env php
<?php

/**
 * Panel logs API filtering: filter, method, status.
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Log.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel.php';

$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

$base = sys_get_temp_dir() . '/qbix-panel-logs-' . getmypid();
@mkdir($base, 0755, true);
$log = $base . '/access.log';
$lines = array(
	'192.168.1.1 - - [24/Sep/2026:18:00:00 +0000] "GET /foo HTTP/1.1" 200 123 "-" "curl" 1.2ms',
	'192.168.1.2 - - [24/Sep/2026:18:00:01 +0000] "POST /bar HTTP/1.1" 500 456 "-" "curl" 3.4ms',
	'192.168.1.3 - - [24/Sep/2026:18:00:02 +0000] "GET /baz HTTP/2.0" 404 78 "-" "curl" 0.5ms',
);
file_put_contents($log, implode("\n", $lines) . "\n");

Q_WebServer_Log::$accessPath = $log;

$parsed = function ($query) { return array('query' => $query, 'path' => '/Q/api/logs'); };

$r = Q_WebServer_Panel::apiLogs($parsed('type=access&lines=50'));
check('logs returns all lines', $r['lines'], $lines);

$r = Q_WebServer_Panel::apiLogs($parsed('type=access&lines=50&method=GET'));
check('filter by GET', count($r['lines']), 2);

$r = Q_WebServer_Panel::apiLogs($parsed('type=access&lines=50&status=500'));
check('filter by status 500', count($r['lines']), 1);
check('filter by status 500 returns the POST line', strpos($r['lines'][0], 'POST /bar') !== false, true);

$r = Q_WebServer_Panel::apiLogs($parsed('type=access&lines=50&filter=/foo'));
check('filter by text /foo', count($r['lines']), 1);

$r = Q_WebServer_Panel::apiLogs($parsed('type=access&lines=50&method=GET&status=404'));
check('filter by GET and 404', count($r['lines']), 1);

$r = Q_WebServer_Panel::apiLogs($parsed('type=access&lines=50&status=5xx'));
check('status class 5xx', count($r['lines']), 1);
$r = Q_WebServer_Panel::apiLogs($parsed('type=access&lines=50&status=4'));
check('status class 4', count($r['lines']), 1);
$r = Q_WebServer_Panel::apiLogs($parsed('type=access&lines=50&status=abc'));
check('a bad status is refused with 400', array($r['status'] ?? null, isset($r['error'])), array(400, true));
$r = Q_WebServer_Panel::apiLogs($parsed('type=access&lines=50&method=' . rawurlencode('GET;rm')));
check('a bad method is refused with 400', $r['status'] ?? null, 400);

// A search reaches further back than the lines it returns.
$r = Q_WebServer_Panel::apiLogs($parsed('type=access&lines=1&filter=/foo'));
check('lines=1 still finds the oldest match', array(count($r['lines']), strpos($r['lines'][0] ?? '', 'GET /foo') !== false, $r['matched']), array(1, true, 1));

// A long log: only its end is read, and no fragment of a line is returned.
$big = array();
for ($i = 0; $i < 20000; $i++) {
	$big[] = sprintf('10.0.%d.%d - - [24/Sep/2026:18:%02d:%02d +0000] "GET /page/%d HTTP/1.1" %d 1000 "-" "ua" 1.0ms',
		intdiv($i, 250) % 250, $i % 250, intdiv($i, 60) % 60, $i % 60, $i, ($i % 97) ? 200 : 503);
}
file_put_contents($log, implode("\n", $big) . "\n");
$r = Q_WebServer_Panel::apiLogs($parsed('type=access&lines=5'));
check('a long log returns its last lines', end($r['lines']), end($big));
check('...read from the end, not whole', $r['complete'], false);
$r = Q_WebServer_Panel::apiLogs($parsed('type=access&lines=500&status=503'));
$whole = true;
foreach ($r['lines'] as $l) if (!preg_match('/^10\.0\.\d+\.\d+ /', $l)) $whole = false;
check('...filtered lines are whole lines', $whole, true);
check('...and all 503', count(array_filter($r['lines'], function ($l) { return strpos($l, '" 503 ') !== false; })), count($r['lines']));

// Tail cursor: after a byte offset returns only new lines, without fragments.
// Use a fresh small log; the earlier long-log test replaced the file.
file_put_contents($log, implode("\n", $lines) . "\n");
Q_WebServer_Log::$accessPath = $log;
$r = Q_WebServer_Panel::apiLogs($parsed('type=access&lines=50&after=0'));
check('after=0 reads from the start', $r['complete'], true);
check('after=0 returns all lines', count($r['lines']), 3);
$afterOffset = strlen($lines[0]) + 1;
$r = Q_WebServer_Panel::apiLogs($parsed('type=access&lines=50&after=' . $afterOffset));
check('after a line boundary returns the remaining lines', count($r['lines']), 2);
check('...and the first line is complete', strpos($r['lines'][0], 'POST /bar') !== false, true);
$size = filesize($log);
$r = Q_WebServer_Panel::apiLogs($parsed('type=access&lines=50&after=' . $size));
check('after the current end returns no new lines', count($r['lines']), 0);
check('...and is not marked rotated', $r['rotated'], false);

// Append a line and fetch from the previous end: only the new line arrives.
$extra = '192.168.1.4 - - [24/Sep/2026:18:00:03 +0000] "GET /qux HTTP/1.1" 301 99 "-" "curl" 0.8ms';
file_put_contents($log, $extra . "\n", FILE_APPEND);
$r = Q_WebServer_Panel::apiLogs($parsed('type=access&lines=50&after=' . $size));
check('after the previous end returns the appended line', count($r['lines']), 1);
check('...and it is the new line', strpos($r['lines'][0], 'GET /qux') !== false, true);

// A rotated log (after beyond the current size) is reported so the client can reset.
file_put_contents($log, "fresh start\n");
$r = Q_WebServer_Panel::apiLogs($parsed('type=access&lines=50&after=' . $size));
check('after a rotated file is marked rotated', $r['rotated'], true);
check('...and still returns the new file\'s lines', count($r['lines']), 1);

// View parameters in the panel path.
check('view params: tab', Q_WebServer_Panel::parseViewParams('/Q/panel/(tab)/logs'), array('tab' => 'logs'));
check('view params: several, decoded', Q_WebServer_Panel::parseViewParams('/Q/panel/(tab)/logs/(filter)/%2Ffoo%20bar'), array('tab' => 'logs', 'filter' => '/foo bar'));
check('view params: none', Q_WebServer_Panel::parseViewParams('/Q/panel'), array());
check('view params: a name without parentheses is ignored', Q_WebServer_Panel::parseViewParams('/Q/panel/tab/logs'), array());
check('view params: not the panel', Q_WebServer_Panel::parseViewParams('/Q/dashboard/(tab)/x'), array());

// Whatever the path holds, the page's inline data cannot close its <script>.
$html = Q_WebServer_Panel::panelHtml('localhost', 'ws://localhost/Q/ws', 'panel',
	Q_WebServer_Panel::parseViewParams('/Q/panel/(tab)/' . rawurlencode('</script><script>alert(1)</script>')));
check('view params cannot break out of the page script', strpos($html, '<script>alert(1)') === false, true);

// /Q/panel/(tab)/logs opens on the Logs tab in the HTML itself: no script
// has to switch away from Apps, so the first paint is already the right tab.
$pg = Q_WebServer_Panel::panelHtml('localhost', 'ws://localhost/Q/ws', 'panel', array('tab' => 'logs'));
check('tab in the path: its section is shown', strpos($pg, 'id="tab-logs" class="content"') !== false, true);
check('...the default section is hidden', strpos($pg, 'id="tab-apps" class="content hidden"') !== false, true);
check('...its tab is the active one', strpos($pg, 'class="tab active" data-tab="logs"') !== false, true);
check('...and only one tab is active', substr_count($pg, 'class="tab active"'), 1);
check('...and only one section is shown', preg_match_all('/id="tab-[a-z0-9_-]+" class="content"/', $pg), 1);
// The tab markup alone: the inline view-parameter data differs by design.
$tabs = function ($html) { preg_match_all('/class="tab[^"]*" data-tab="[^"]*"|id="tab-[^"]*" class="[^"]*"/', $html, $m); return $m[0]; };
$def = $tabs(Q_WebServer_Panel::panelHtml('localhost', 'ws://localhost/Q/ws', 'panel', array()));
foreach (array('nosuchtab', 'apps', '"><script>', '') as $bad) {
	check('tab ' . var_export($bad, true) . ' leaves the tabs as they are',
		$tabs(Q_WebServer_Panel::panelHtml('localhost', 'ws://localhost/Q/ws', 'panel', array('tab' => $bad))) === $def, true);
}

unlink($log);
rmdir($base);

if ($fail) { printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
