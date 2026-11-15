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
 * Spike prototype of the Signature Version 4 signer (issue #23). A pure
 * function of its inputs, so the AWS test suite can check every step:
 * canonical request, string to sign, and signature.
 */

final class AwsSigV4 {
	/**
	 * @param list<array{0: string, 1: string}> $headers name and value pairs in request order
	 * @return array{canonical: string, stringToSign: string, signature: string, authorization: string, addHeaders: array<string, string>}
	 */
	public static function sign(
		string $method,
		string $path,
		string $query,
		array $headers,
		string $body,
		string $accessKey,
		string $secret,
		?string $token,
		string $region,
		string $service,
		DateTimeImmutable $time,
		bool $normalize = true,
		bool $signBody = false,
		bool $omitToken = false,
	): array {
		$amzDate = $time->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
		$day = substr($amzDate, 0, 8);
		$payloadHash = hash('sha256', $body);

		$add = array('X-Amz-Date' => $amzDate);
		if ($signBody) {
			$add['X-Amz-Content-Sha256'] = $payloadHash;
		}
		// With omitToken the token is attached after signing, as STS
		// presigning does for some services; otherwise it is signed.
		if ($token !== null && !$omitToken) {
			$add['X-Amz-Security-Token'] = $token;
		}
		foreach ($add as $name => $value) {
			$headers[] = array($name, $value);
		}

		$canonicalHeaders = array();
		foreach ($headers as [$name, $value]) {
			$name = strtolower($name);
			// Folded lines join with a space; runs of spaces collapse to one.
			$value = trim((string) preg_replace('/\s+/', ' ', $value));
			$canonicalHeaders[$name][] = $value;
		}
		ksort($canonicalHeaders, SORT_STRING);
		$headerBlock = '';
		foreach ($canonicalHeaders as $name => $values) {
			$headerBlock .= $name . ':' . implode(',', $values) . "\n";
		}
		$signedHeaders = implode(';', array_keys($canonicalHeaders));

		$canonical = implode("\n", array(
			$method,
			self::canonicalPath($path, $normalize),
			self::canonicalQuery($query),
			$headerBlock,
			$signedHeaders,
			$payloadHash,
		));

		$scope = "$day/$region/$service/aws4_request";
		$stringToSign = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonical);

		$key = 'AWS4' . $secret;
		foreach (array($day, $region, $service, 'aws4_request') as $part) {
			$key = hash_hmac('sha256', $part, $key, true);
		}
		$signature = hash_hmac('sha256', $stringToSign, $key);

		if ($token !== null && $omitToken) {
			$add['X-Amz-Security-Token'] = $token;
		}

		return array(
			'canonical'     => $canonical,
			'stringToSign'  => $stringToSign,
			'signature'     => $signature,
			'authorization' => "AWS4-HMAC-SHA256 Credential=$accessKey/$scope, SignedHeaders=$signedHeaders, Signature=$signature",
			'addHeaders'    => $add,
		);
	}

	/**
	 * Each segment is percent-encoded once with RFC 3986 unreserved
	 * characters left alone, which rawurlencode() does. Normalizing removes
	 * dot segments and empty segments, as every service but S3 expects.
	 */
	private static function canonicalPath(string $path, bool $normalize): string {
		$segments = explode('/', $path);
		if ($normalize) {
			$out = array();
			foreach ($segments as $s) {
				if ($s === '' || $s === '.') {
					continue;
				}
				if ($s === '..') {
					array_pop($out);
					continue;
				}
				$out[] = $s;
			}
			$last = end($segments);
			$trailing = $out !== array() && ($last === '' || $last === '.' || $last === '..');
			$segments = array_merge(array(''), $out, $trailing ? array('') : array());
			if ($out === array()) {
				return '/';
			}
		}

		return implode('/', array_map(static fn (string $s): string => rawurlencode(rawurldecode($s)), $segments));
	}

	private static function canonicalQuery(string $query): string {
		if ($query === '') {
			return '';
		}
		$pairs = array();
		foreach (explode('&', $query) as $pair) {
			[$k, $v] = explode('=', $pair, 2) + array('', '');
			$pairs[] = array(rawurlencode(rawurldecode($k)), rawurlencode(rawurldecode($v)));
		}
		usort($pairs, static fn (array $a, array $b): int => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));

		return implode('&', array_map(static fn (array $p): string => $p[0] . '=' . $p[1], $pairs));
	}
}
