<?php

/**
 * The output buffer a persistent process captures a script's response in.
 *
 * A script's output has to be collected rather than written to stdout, and the
 * buffer that collects it has to survive the script: applications end their own
 * buffers with loops such as `while (@ob_end_clean());`, which would take a
 * normal buffer -- and the response in it -- along with them.
 *
 * That used to be done with ob_start(null, 0, 0) on every request. Flags 0 make
 * a buffer impossible to remove, which protects it, but also impossible to
 * clean or remove *afterwards*: the @ob_clean() meant to empty it failed
 * silently, and the next request stacked another one on top. One buffer, with
 * that request's whole page in it, was left behind per request for the life of
 * the process -- measured at ~2 MB a request on an Exponential install, a
 * worker at 1.1 GB after 600 requests. The response still came out right,
 * because ob_get_contents() read whichever buffer was on top, which is why
 * nothing looked wrong.
 *
 * Here there is one buffer per process, opened on first use and kept for the
 * process's life:
 *
 *   - It is cleanable and flushable but not removable, so an application's
 *     end-of-buffer loop stops at it, and ob_clean() still discards what the
 *     script printed, as it would anywhere. (PHP declines to end it without
 *     cleaning it, so output printed before such a loop is kept -- as it was
 *     with the buffer this replaced. Making it removable would instead send
 *     everything after the loop to the process's real stdout.)
 *   - Its handler moves flushed output out of the buffer into a string, so the
 *     buffer itself is empty between requests and nothing accumulates in it.
 *   - Buffers the script leaves open are flushed down into it at the end, as
 *     PHP does at the end of a request; the response is everything the script
 *     printed, not whichever buffer happened to be on top.
 *
 * balanced() is the invariant a caller can check after every request: exactly
 * one buffer, ours, and empty. A process that fails it has lost track of its
 * output and should be replaced rather than trusted with the next request.
 *
 * @class Q_WebServer_Capture
 * @static
 */
class Q_WebServer_Capture
{
	/** Output flushed out of the buffer during this request. */
	private static $captured = '';

	/** ob_get_level() of our buffer; 0 until it has been opened. */
	private static $level = 0;

	/**
	 * Called after every flush of our buffer while it is set: a callable that
	 * may take() what has been captured so far and send it on. The worker
	 * pool sets it for the length of a request, so a response that streams
	 * (Server-Sent Events, a long download produced piece by piece) leaves on
	 * each ob_flush() or flush() instead of when the script ends. It runs
	 * inside the output handler, so it must not use output buffering itself.
	 *
	 * @property $onFlush
	 * @type {callable|null}
	 */
	public static $onFlush = null;

	/**
	 * Output handler. Flushed output is kept; cleaned output is discarded, as
	 * ob_clean() means. Either way the buffer is left empty.
	 *
	 * @method handler
	 * @static
	 * @param {string} $buffer
	 * @param {integer} $phase PHP_OUTPUT_HANDLER_* bits
	 * @return {string} always empty: nothing goes to the real stdout
	 */
	static function handler($buffer, $phase)
	{
		if (!($phase & PHP_OUTPUT_HANDLER_CLEAN)) {
			self::$captured .= $buffer;
			if (self::$onFlush !== null and ($phase & PHP_OUTPUT_HANDLER_FLUSH)) {
				call_user_func(self::$onFlush);
			}
		}
		return '';
	}

	/**
	 * Hand over what has been captured so far and forget it, for output that
	 * leaves before the script ends. Only flushed output is here: what is
	 * still in the buffer itself stays there.
	 *
	 * @method take
	 * @static
	 * @return {string}
	 */
	static function take()
	{
		$out = self::$captured;
		self::$captured = '';
		return $out;
	}

	/**
	 * The level of our buffer, 0 before it is opened.
	 * @method level
	 * @static
	 * @return {integer}
	 */
	static function level()
	{
		return self::$level;
	}

	/**
	 * Start capturing a request.
	 *
	 * Opens the buffer the first time. On later requests it is reused, and
	 * anything a previous request left behind -- buffers above ours, unflushed
	 * content -- is discarded first, so one request's output can never become
	 * part of the next one's.
	 *
	 * @method begin
	 * @static
	 */
	static function begin()
	{
		if (self::$level > 0 and ob_get_level() >= self::$level) {
			self::dropAbove();
			@ob_clean();
		} else {
			ob_start(array(__CLASS__, 'handler'), 0,
				PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_FLUSHABLE);
			self::$level = ob_get_level();
		}
		self::$captured = '';
	}

	/**
	 * Finish capturing and return everything the script printed.
	 *
	 * Buffers the script left open are flushed down into ours first, then ours
	 * is flushed into the captured string, leaving it empty.
	 *
	 * @method end
	 * @static
	 * @return {string}
	 */
	static function end()
	{
		if (self::$level === 0) return '';
		$guard = 0;
		while (ob_get_level() > self::$level and $guard++ < 256) {
			if (!@ob_end_flush()) {
				// A buffer that will not be ended. Take what it holds and
				// stop; balanced() reports the process as unhealthy.
				self::$captured .= (string) ob_get_contents();
				@ob_clean();
				break;
			}
		}
		if (ob_get_level() >= self::$level) {
			@ob_flush();
		}
		$out = self::$captured;
		self::$captured = '';
		return $out;
	}

	/**
	 * Throw away everything the request printed so far -- for replacing the
	 * output with an error. Capturing continues.
	 *
	 * @method discard
	 * @static
	 */
	static function discard()
	{
		if (self::$level === 0) return;
		self::dropAbove();
		@ob_clean();
		self::$captured = '';
	}

	/**
	 * Whether the process is back where a request should find it: our buffer
	 * alone on the stack, and empty.
	 *
	 * @method balanced
	 * @static
	 * @return {boolean}
	 */
	static function balanced()
	{
		return self::$level > 0
			and ob_get_level() === self::$level
			and ob_get_length() === 0
			and self::$captured === '';
	}

	/**
	 * Whether capturing has started in this process.
	 * @method active
	 * @static
	 * @return {boolean}
	 */
	static function active()
	{
		return self::$level > 0 and ob_get_level() >= self::$level;
	}

	private static function dropAbove()
	{
		$guard = 0;
		while (ob_get_level() > self::$level and $guard++ < 256) {
			if (!@ob_end_clean()) break;
		}
	}
}
