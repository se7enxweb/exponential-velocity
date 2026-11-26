<?php

/**
 * The start-up banner names the event loop the server runs.
 *
 * It guessed from which classes existed and named "PHP 8.6 Io\Poll" for a
 * PHP that had it, although Io\Poll is opt-in and such a server runs
 * stream_select or Revolt unless asked. It now asks Q_Evented::label(), which
 * follows the same choice as driver() without creating a driver. Asserted:
 *
 *   - with QBIX_EVENT_LOOP=select the label is stream_select, and no driver
 *     was created to find that out;
 *   - an unavailable forced loop is labelled as what auto picks;
 *   - the label agrees with the driver that is then created;
 *   - the banner in sbin/qbixserver.php uses label() and no class_exists guess.
 *
 *   php tests/unit-banner-event-loop.php
 */

require __DIR__ . '/../src/Q.php';

$pass = 0;
$fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

class Banner_Probe extends Q_Evented
{
	static function created() { return self::$driver !== null; }
	static function reset() { self::$driver = null; }
}

putenv('QBIX_EVENT_LOOP=select');
check('forced select is labelled stream_select', Q_Evented::label(), 'stream_select');
check('...and labelling it created no driver', Banner_Probe::created(), false);

if (!Q_Evented_IoPoll::available()) {
	putenv('QBIX_EVENT_LOOP=iopoll');
	$auto = class_exists('Revolt\\EventLoop') ? 'Revolt' : 'stream_select';
	check('an unavailable Io\\Poll is not named', Q_Evented::label(), $auto);
}

putenv('QBIX_EVENT_LOOP');
$label = Q_Evented::label();
$labels = array('iopoll' => 'epoll/kqueue (Io\\Poll)', 'revolt' => 'Revolt', 'select' => 'stream_select');
check('the label agrees with the driver created afterwards', $labels[Q_Evented::backend()] ?? null, $label);
Banner_Probe::reset();

$src = file_get_contents(__DIR__ . '/../sbin/qbixserver.php');
check('the banner asks Q_Evented::label()', strpos($src, '$evLoop = Q_Evented::label();') !== false, true);
check('...and does not guess from class_exists', strpos($src, "class_exists('Io\\\\Poll'"), false);

echo ($fail ? 'FAIL' : 'PASS') . "  $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
