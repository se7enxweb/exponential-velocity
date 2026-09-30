#!/usr/bin/env php
<?php
/**
 * qbixserver.php has moved to sbin/qbixserver.php (docs/layout.md, "Programs").
 *
 * This file keeps the former path working for everything that names it:
 * systemd units, init scripts, Composer's vendor/bin link, pkill patterns,
 * and the start records of servers started from this path (a restart starts
 * them again from here, the way they were started).
 *
 * It runs the new file in this same process, so the command line (argv), the
 * process id, standard input and output and the exit status are exactly the
 * new file's. The only difference is one line on standard error, written only
 * when standard error is a terminal; QBIX_MOVED_QUIET=1 turns it off.
 */
if (getenv('QBIX_MOVED_QUIET') === false && defined('STDERR') && function_exists('stream_isatty') && @stream_isatty(STDERR)) {
	fwrite(STDERR, "qbixserver.php: moved to sbin/qbixserver.php; this path keeps working (QBIX_MOVED_QUIET=1 hides this line)\n");
}
require __DIR__ . '/sbin/qbixserver.php';
