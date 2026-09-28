<?php
/**
 * @module Q
 */

/**
 * What the certificate sources share: reading secrets without putting them
 * in the configuration, keeping an imported pair, finding tools.
 *
 * @class Q_WebServer_Certificate_Source_Base
 */
abstract class Q_WebServer_Certificate_Source_Base implements Q_WebServer_Certificate_Source
{
	function watched(array $config)
	{
		return array();
	}

	function tick(array $config)
	{
	}

	/**
	 * A secret from the configuration: $name itself, or read from the file
	 * named by "{$name}File", or from the environment variable named by
	 * "{$name}Env" -- so a password need not sit in a configuration file.
	 * @method secret
	 * @static
	 * @return {string|null}
	 */
	static function secret(array $config, $name)
	{
		if (isset($config[$name]) and $config[$name] !== '') return (string) $config[$name];
		if (!empty($config[$name . 'File']) and is_readable($config[$name . 'File'])) {
			return rtrim((string) file_get_contents($config[$name . 'File']), "\r\n");
		}
		if (!empty($config[$name . 'Env']) and getenv($config[$name . 'Env']) !== false) {
			return (string) getenv($config[$name . 'Env']);
		}
		return null;
	}

	/**
	 * Keep an imported pair where the server reads it:
	 * <ssl dir>/imported/<mode>.pem and .key (0600), written atomically.
	 * @return {array|null} array(cert file, key file)
	 */
	protected function keep(Q_WebServer_Certificate $c, &$why = '')
	{
		$dir = Q_WebServer_Certificate_Store::defaultDir() . DIRECTORY_SEPARATOR . 'imported';
		if (!is_dir($dir) and !@mkdir($dir, 0700, true) and !is_dir($dir)) { $why = "could not create $dir"; return null; }
		$cert = $dir . DIRECTORY_SEPARATOR . $this->name() . '.pem';
		$key = $dir . DIRECTORY_SEPARATOR . $this->name() . '.key';
		$old = Q_WebServer_Certificate::fromFiles($cert, $key);
		if ($old and $old->certPem === $c->certPem and $old->keyPem === $c->keyPem) return array($cert, $key);
		if (!Q_WebServer_Certificate_Store::writeAtomic($key, $c->keyPem, 0600)
			or !Q_WebServer_Certificate_Store::writeAtomic($cert, $c->certPem, 0644)) {
			$why = "could not write $dir";
			return null;
		}
		return array($cert, $key);
	}

	/**
	 * The first of these tools found, by absolute path, so it works from a
	 * service manager that starts the server with no PATH.
	 * @method tool
	 * @static
	 * @param {array} $names e.g. array('bsdtar', 'tar')
	 * @return {string|null}
	 */
	static function tool(array $names)
	{
		foreach ($names as $n) {
			foreach (array('/usr/bin', '/bin', '/usr/local/bin', '/opt/homebrew/bin', '/usr/sbin', '/sbin') as $d) {
				if (@is_executable("$d/$n")) return "$d/$n";
			}
		}
		return null;
	}

	/**
	 * Run a command given as an argument list (no shell), with optional
	 * extra environment. Returns its exit code; output by reference.
	 * @method run
	 * @static
	 */
	static function run(array $cmd, &$output = '', ?array $env = null, $cwd = null)
	{
		$p = @proc_open($cmd, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
			$pipes, $cwd, $env === null ? null : $env + (getenv() ?: array()));
		if (!is_resource($p)) { $output = 'could not run ' . $cmd[0]; return -1; }
		$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return proc_close($p);
	}
}
