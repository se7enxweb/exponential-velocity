#!/usr/bin/env php
<?php
/**
 * qbixctl.php has moved to sbin/qbixctl.php (docs/layout.md, "Programs").
 *
 * This file keeps the former path working for everything that names it:
 * scripts, cron jobs and the programs that drive the server with it.
 *
 * It runs the new file in this same process, so the command line (argv), the
 * process id, standard input and output and the exit status are exactly the
 * new file's. The only difference is one line on standard error, written only
 * when standard error is a terminal; QBIX_MOVED_QUIET=1 turns it off.
 */
if (getenv('QBIX_MOVED_QUIET') === false && defined('STDERR') && function_exists('stream_isatty') && @stream_isatty(STDERR)) {
	fwrite(STDERR, "qbixctl.php: moved to sbin/qbixctl.php; this path keeps working (QBIX_MOVED_QUIET=1 hides this line)\n");
}
require __DIR__ . '/sbin/qbixctl.php';
