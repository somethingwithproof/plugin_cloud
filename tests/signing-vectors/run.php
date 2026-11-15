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
 * Checks AwsSigV4 against the AWS Signature Version 4 test suite:
 *   php run.php <suite v4 directory>
 * The suite is not copied into this repository; see
 * docs/research/signing-vectors.md for the pinned source and license.
 * Each vector is checked at three steps so a failure names the stage.
 */

require __DIR__ . '/AwsSigV4.php';

$dir = $argv[1] ?? '';
$contextFiles = is_dir($dir) ? glob(rtrim($dir, '/') . '/*/context.json') : false;
// An empty or wrong directory must not pass on the IAM example alone.
if ($contextFiles === false || count($contextFiles) < 30) {
	fwrite(STDERR, "expected the suite's v4 directory with its test cases: $dir\n");
	exit(2);
}
$pass = 0;
$fail = 0;

foreach ($contextFiles as $contextFile) {
	$case = basename(dirname($contextFile));
	$base = dirname($contextFile);
	$ctx = json_decode(file_get_contents($contextFile), true, 8, JSON_THROW_ON_ERROR);

	// request.txt is an HTTP/1.1 request with LF line ends; a header line
	// starting with whitespace continues the previous one.
	[$head, $body] = explode("\n\n", file_get_contents("$base/request.txt"), 2) + array('', '');
	$lines = explode("\n", $head);
	[$method, $target] = explode(' ', array_shift($lines));
	$headers = array();
	foreach ($lines as $line) {
		if ($line === '') {
			continue;
		}
		if ($line[0] === ' ' || $line[0] === "\t") {
			$headers[count($headers) - 1][1] .= ' ' . trim($line);
			continue;
		}
		[$n, $v] = explode(':', $line, 2);
		$headers[] = array($n, $v);
	}
	// The method and path are separated by spaces, and get-space puts a
	// space inside the path, so the target is everything up to " HTTP/".
	$target = substr(explode("\n", file_get_contents("$base/request.txt"))[0], strlen($method) + 1, -strlen(' HTTP/1.1'));
	[$path, $query] = explode('?', $target, 2) + array('', '');

	$r = AwsSigV4::sign(
		$method,
		$path,
		$query,
		$headers,
		$body,
		$ctx['credentials']['access_key_id'],
		$ctx['credentials']['secret_access_key'],
		$ctx['credentials']['token'] ?? null,
		$ctx['region'],
		$ctx['service'],
		new DateTimeImmutable($ctx['timestamp']),
		$ctx['normalize'] ?? true,
		$ctx['sign_body'] ?? false,
		$ctx['omit_session_token'] ?? false,
	);

	$checks = array(
		'canonical'      => array($r['canonical'], file_get_contents("$base/header-canonical-request.txt")),
		'string-to-sign' => array($r['stringToSign'], file_get_contents("$base/header-string-to-sign.txt")),
		'signature'      => array($r['signature'], trim(file_get_contents("$base/header-signature.txt"))),
	);
	$failed = array_keys(array_filter($checks, static fn (array $c): bool => $c[0] !== $c[1]));
	if ($failed === array()) {
		$pass++;
		echo "pass $case\n";
	} else {
		$fail++;
		echo "FAIL $case at " . implode(', ', $failed) . "\n";
	}
}

// The signing-key example from the IAM User Guide, independent of the suite.
$key = 'AWS4wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';
foreach (array('20120215', 'us-east-1', 'iam', 'aws4_request') as $part) {
	$key = hash_hmac('sha256', $part, $key, true);
}
$iam = bin2hex($key) === 'f4780e2d9f65fa895f9c67b32ce1baf0b0d8a43505a000a1a9e090d414db404d';
echo ($iam ? 'pass' : 'FAIL') . " iam-user-guide-signing-key\n";
$iam ? $pass++ : $fail++;

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
