<?php

/**
 * Answers with the $_POST and $_FILES that PHP's own SAPI built from the
 * request, serialized, each uploaded file's temporary name replaced by its
 * contents. tests/unit-multipart-form-fields.php runs it under `php -S` as the
 * reference its parser is compared against, and normalizes its own result
 * the same way.
 */

function multipartEchoContents($tmp)
{
	if (is_array($tmp)) return array_map('multipartEchoContents', $tmp);
	return $tmp === '' ? '' : 'contents:' . file_get_contents($tmp);
}

$files = $_FILES;
foreach ($files as $field => $entry) {
	if (is_array($entry) && array_key_exists('tmp_name', $entry)) {
		$files[$field]['tmp_name'] = multipartEchoContents($entry['tmp_name']);
	}
}

header('Content-Type: application/octet-stream');
echo serialize(array('post' => $_POST, 'files' => $files));
