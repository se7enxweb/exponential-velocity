#!/usr/bin/env php
<?php
/**
 * qshell.php has moved to bin/qshell.php (docs/layout.md, "Programs").
 *
 * This file keeps the former path working for everything that names it:
 * scripts and terminals that start the shell by this path.
 *
 * It runs the new file in this same process, so the command line (argv), the
 * process id, standard input and output and the exit status are exactly the
 * new file's. The only difference is one line on standard error, written only
 * when standard error is a terminal; QBIX_MOVED_QUIET=1 turns it off.
 */
if (getenv('QBIX_MOVED_QUIET') === false && defined('STDERR') && function_exists('stream_isatty') && @stream_isatty(STDERR)) {
	fwrite(STDERR, "qshell.php: moved to bin/qshell.php; this path keeps working (QBIX_MOVED_QUIET=1 hides this line)\n");
}
require __DIR__ . '/bin/qshell.php';
