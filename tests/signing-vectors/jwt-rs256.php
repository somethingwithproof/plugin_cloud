<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_cloud, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_cloud
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

/*
 * Checks the RS256 signing that the Google service account token source
 * needs (issue #23) against RFC 7515 Appendix A.2:
 *   php jwt-rs256.php <rfc7515.txt from rfc-editor.org>
 * RSASSA-PKCS1-v1_5 is deterministic, so openssl_sign() must reproduce the
 * RFC's signature exactly, and openssl_verify() must accept it with the
 * public half. The values are read from the RFC text, not copied here.
 */

$text = file_get_contents($argv[1] ?? '');
$start = strpos($text, "A.2.  Example JWS Using RSASSA-PKCS1-v1_5 SHA-256\n\nA.2.1.");
$end = strpos($text, 'A.3.  Example JWS Using ECDSA', $start);
if ($start === false || $end === false) {
	fwrite(STDERR, "appendix A.2 not found\n");
	exit(2);
}
// Drop page footers and headers so wrapped values join cleanly.
$a2 = preg_replace('/\n[^\n]*\[Page \d+\]\n\x0c\nRFC 7515[^\n]*\n/', "\n", substr($text, $start, $end - $start));

/** Joins a value the RFC wraps across indented lines. */
function joined(string $s): string {
	return (string) preg_replace('/\s+/', '', $s);
}

preg_match('/\{"kty":"RSA",(.*?)\n\s*\}/s', $a2, $m);
$jwk = json_decode('{"kty":"RSA",' . joined($m[1]) . '}', true, 4, JSON_THROW_ON_ERROR);
preg_match('/(eyJhbGciOiJSUzI1NiJ9\s+\.\s+eyJ[\w\s-]+?)\n\n/s', $a2, $m);
$signingInput = joined($m[1]);
preg_match(
	'/BASE64URL\(JWS Signature\) produces this\s+value \(with line breaks for display purposes ' .
		'only\):\s+([\w\s-]+?)\n\n/s',
	$a2,
	$m
);
$expected = joined($m[1]);

$b64 = static fn (string $s): string => base64_decode(strtr($s, '-_', '+/'), true);
$b64url = static fn (string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

$key = openssl_pkey_new(array('rsa' => array(
	'n'    => $b64($jwk['n']),
	'e'    => $b64($jwk['e']),
	'd'    => $b64($jwk['d']),
	'p'    => $b64($jwk['p']),
	'q'    => $b64($jwk['q']),
	'dmp1' => $b64($jwk['dp']),
	'dmq1' => $b64($jwk['dq']),
	'iqmp' => $b64($jwk['qi']),
)));
if ($key === false) {
	fwrite(STDERR, "could not build the key: " . openssl_error_string() . "\n");
	exit(1);
}

openssl_sign($signingInput, $sig, $key, OPENSSL_ALGO_SHA256);
$public = openssl_pkey_get_public(openssl_pkey_get_details($key)['key']);

$checks = array(
	'signature equals RFC 7515 A.2'   => $b64url($sig) === $expected,
	'openssl_verify accepts RFC value' => openssl_verify(
		$signingInput,
		$b64($expected),
		$public,
		OPENSSL_ALGO_SHA256
	) === 1,
	'openssl_verify rejects a changed input' => openssl_verify(
		$signingInput . 'x',
		$b64($expected),
		$public,
		OPENSSL_ALGO_SHA256
	) === 0,
);
$fail = 0;
foreach ($checks as $name => $ok) {
	echo ($ok ? 'pass ' : 'FAIL ') . $name . "\n";
	$fail += $ok ? 0 : 1;
}
printf(
	"signing input %d bytes, key %d bits, OpenSSL %s\n",
	strlen($signingInput),
	openssl_pkey_get_details($key)['bits'],
	OPENSSL_VERSION_TEXT
);
exit($fail === 0 ? 0 : 1);
