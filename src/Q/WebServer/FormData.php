<?php

/**
 * Builds $_POST and $_FILES from the fields of a multipart/form-data body the
 * way PHP's own SAPI does.
 *
 * A field name is not a key. PHP reads "a[b][c]" as a path, "a[]" as an
 * append, turns spaces and dots in the top-level name into underscores, stops
 * at max_input_nesting_level, and treats an unmatched "[" as part of the name.
 * The multipart parser used to understand one level of brackets only, so
 * "Attributes[0][id]" landed in $_POST under that literal string and the
 * application found no "Attributes" at all -- while the same field sent
 * urlencoded, which goes through parse_str(), arrived intact.
 *
 * Rather than a second implementation of those rules, both builders hand the
 * names to parse_str() -- the same code PHP registers every request variable
 * with -- carrying only a placeholder for each value, and then put the real
 * values in place. Binary values never pass through the query-string encoding,
 * and $_FILES keeps its integer error and size.
 *
 * @class Q_WebServer_FormData
 * @static
 */
class Q_WebServer_FormData
{
	/**
	 * Build the $_POST array from ordinary form fields.
	 *
	 * @method post
	 * @static
	 * @param {array} $fields List of array(name, value), in body order
	 * @return {array}
	 */
	static function post(array $fields)
	{
		$names = array();
		$values = array();
		foreach ($fields as $field) {
			$names[] = (string) $field[0];
			$values[] = $field[1];
		}
		return self::build($names, $values);
	}

	/**
	 * Build the $_FILES array from file fields.
	 *
	 * PHP gives a field named "f[a][b]" the layout $_FILES['f']['name']['a']['b'],
	 * $_FILES['f']['tmp_name']['a']['b'] and so on: each property is inserted
	 * after the top-level name. A name whose brackets are not balanced, or
	 * with anything but another "[" after a "]", is not registered at all,
	 * which is what PHP does with it too.
	 *
	 * @method files
	 * @static
	 * @param {array} $files List of array(name, entry), where entry maps each
	 *   property (name, full_path, type, tmp_name, error, size) to its value
	 * @return {array}
	 */
	static function files(array $files)
	{
		$names = array();
		$values = array();
		foreach ($files as $file) {
			$name = (string) $file[0];
			if (!self::fileNameIsWellFormed($name)) continue;
			$open = strpos($name, '[');
			if ($open !== false && substr($name, -1) === ']') {
				$base = substr($name, 0, $open);
				$rest = substr($name, $open);
			} else {
				$base = $name;
				$rest = '';
			}
			foreach ($file[1] as $prop => $value) {
				$names[] = $base . '[' . $prop . ']' . $rest;
				$values[] = $value;
			}
		}
		return self::build($names, $values);
	}

	/**
	 * One $_FILES entry, with the properties in PHP's order. full_path is
	 * there from PHP 8.1, as it is in PHP's own.
	 *
	 * @method entry
	 * @static
	 * @param {string} $name File name without the path
	 * @param {string} $fullPath File name as the client sent it
	 * @param {string} $type
	 * @param {string} $tmpName Empty when the file was not kept
	 * @param {integer} $error One of the UPLOAD_ERR_* constants
	 * @param {integer} $size
	 * @return {array}
	 */
	static function entry($name, $fullPath, $type, $tmpName, $error, $size)
	{
		$entry = array('name' => $name);
		if (PHP_VERSION_ID >= 80100) $entry['full_path'] = $fullPath;
		$entry['type'] = $type;
		$entry['tmp_name'] = $tmpName;
		$entry['error'] = $error;
		$entry['size'] = $size;
		return $entry;
	}

	/**
	 * PHP's rule for an uploaded file's field name: every "]" is either the
	 * last character or followed by "[", and the brackets balance.
	 *
	 * @method fileNameIsWellFormed
	 * @static
	 * @param {string} $name
	 * @return {boolean}
	 */
	static function fileNameIsWellFormed($name)
	{
		$depth = 0;
		$len = strlen($name);
		for ($i = 0; $i < $len; $i++) {
			if ($name[$i] === '[') {
				$depth++;
			} elseif ($name[$i] === ']') {
				$depth--;
				if ($i + 1 < $len && $name[$i + 1] !== '[') return false;
			}
			if ($depth < 0) return false;
		}
		return $depth === 0;
	}

	/**
	 * The file name PHP reports: what follows the last "/" or "\". Some
	 * browsers send the whole path the file was picked from; that stays in
	 * full_path.
	 *
	 * @method baseName
	 * @static
	 * @param {string} $filename
	 * @return {string}
	 */
	static function baseName($filename)
	{
		$slash = strrpos($filename, '/');
		$backslash = strrpos($filename, '\\');
		if ($slash === false && $backslash === false) return $filename;
		return substr($filename, max((int) $slash, (int) $backslash) + 1);
	}

	/**
	 * Register every name with parse_str() and replace each placeholder with
	 * its value.
	 *
	 * @method build
	 * @static
	 * @private
	 * @param {array} $names
	 * @param {array} $values Same keys as $names
	 * @return {array}
	 */
	private static function build(array $names, array $values)
	{
		if (!$names) return array();
		$query = '';
		foreach ($names as $i => $name) {
			if ($query !== '') $query .= '&';
			$query .= rawurlencode($name) . '=' . $i;
		}
		$result = array();
		// A name nested beyond max_input_nesting_level is dropped, as PHP
		// drops it; its warning would otherwise land in the response body.
		@parse_str($query, $result);
		array_walk_recursive($result, function (&$leaf) use ($values) {
			$leaf = $values[(int) $leaf];
		});
		return $result;
	}
}
