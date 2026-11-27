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

namespace Somethingwithproof\Cloud;

/**
 * Pure-PHP AWS Signature Version 4 (AWS4-HMAC-SHA256) request signer.
 *
 * Every signing step is a pure function of its inputs so official AWS SigV4
 * test vectors verify the canonical request, string to sign, derived key, and
 * final signature independently.
 */
final class AwsSigV4 {
	/**
	 * Derive the binary SigV4 signing key for a date, region, and service.
	 */
	public static function signingKey(
		#[\SensitiveParameter] string $secret,
		string $day,
		string $region,
		string $service
	): string {
		$key = 'AWS4' . $secret;

		foreach ([$day, $region, $service, 'aws4_request'] as $part) {
			$key = hash_hmac('sha256', $part, $key, true);
		}

		return $key;
	}

	/**
	 * Compute the SigV4 canonical request, string to sign, signature, and
	 * Authorization header value.
	 *
	 * @param list<array{0: string, 1: string}> $headers name and value pairs in request order
	 * @return array{
	 *     canonical: string,
	 *     stringToSign: string,
	 *     signature: string,
	 *     authorization: string,
	 *     addHeaders: array<string, string>
	 * }
	 */
	public static function sign(
		string $method,
		string $path,
		string $query,
		array $headers,
		string $body,
		string $accessKey,
		#[\SensitiveParameter] string $secret,
		#[\SensitiveParameter] ?string $token,
		string $region,
		string $service,
		\DateTimeImmutable $time,
		bool $normalize = true,
		bool $signBody = false,
		bool $omitToken = false,
	): array {
		$amzDate     = $time->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
		$day         = substr($amzDate, 0, 8);
		$payloadHash = hash('sha256', $body);

		$add = ['X-Amz-Date' => $amzDate];

		if ($signBody) {
			$add['X-Amz-Content-Sha256'] = $payloadHash;
		}

		if ($token !== null && $token !== '' && !$omitToken) {
			$add['X-Amz-Security-Token'] = $token;
		}

		foreach ($add as $name => $value) {
			$headers[] = [$name, $value];
		}

		/** @var array<string, list<string>> $canonicalHeaders */
		$canonicalHeaders = [];

		foreach ($headers as [$name, $value]) {
			$lower                      = strtolower($name);
			$clean                      = trim((string) preg_replace('/\s+/', ' ', $value));
			$canonicalHeaders[$lower][] = $clean;
		}

		ksort($canonicalHeaders, SORT_STRING);
		$headerBlock = '';

		foreach ($canonicalHeaders as $name => $values) {
			$headerBlock .= $name . ':' . implode(',', $values) . "\n";
		}

		$signedHeaders = implode(';', array_keys($canonicalHeaders));

		$canonical = implode("\n", [
			$method,
			self::canonicalPath($path, $normalize),
			self::canonicalQuery($query),
			$headerBlock,
			$signedHeaders,
			$payloadHash,
		]);

		$scope        = "$day/$region/$service/aws4_request";
		$stringToSign = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonical);
		$key          = self::signingKey($secret, $day, $region, $service);
		$signature    = hash_hmac('sha256', $stringToSign, $key);

		if ($token !== null && $token !== '' && $omitToken) {
			$add['X-Amz-Security-Token'] = $token;
		}

		return [
			'canonical'     => $canonical,
			'stringToSign'  => $stringToSign,
			'signature'     => $signature,
			'authorization' => "AWS4-HMAC-SHA256 Credential=$accessKey/$scope, "
				. "SignedHeaders=$signedHeaders, Signature=$signature",
			'addHeaders'    => $add,
		];
	}

	/**
	 * Sign an HttpRequest in place and return a new HttpRequest carrying the
	 * required SigV4 headers.
	 */
	public static function signRequest(
		HttpRequest $request,
		string $accessKey,
		#[\SensitiveParameter] string $secret,
		#[\SensitiveParameter] ?string $token,
		string $region,
		string $service,
		\DateTimeImmutable $time,
		bool $signBody = true,
	): HttpRequest {
		$parts = parse_url($request->url);
		$host  = is_array($parts) && isset($parts['host']) ? $parts['host'] : '';
		$port  = is_array($parts) && isset($parts['port']) ? $parts['port'] : null;
		$path  = is_array($parts) && isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
		$query = is_array($parts) && isset($parts['query']) ? $parts['query'] : '';

		$hostHeader = $port !== null && $port !== 443 ? $host . ':' . $port : $host;
		$pairs      = [['Host', $hostHeader]];

		foreach ($request->headers as $name => $value) {
			if (strcasecmp($name, 'Host') === 0) {
				continue;
			}

			$pairs[] = [$name, $value];
		}

		$signed  = self::sign(
			$request->method,
			$path,
			$query,
			$pairs,
			$request->body ?? '',
			$accessKey,
			$secret,
			$token,
			$region,
			$service,
			$time,
			true,
			$signBody
		);
		$headers                  = $request->headers + $signed['addHeaders'];
		$headers['Authorization'] = $signed['authorization'];

		return new HttpRequest($request->method, $request->url, $headers, $request->body);
	}

	private static function canonicalPath(string $path, bool $normalize): string {
		$segments = explode('/', $path);

		if ($normalize) {
			$out = [];

			foreach ($segments as $segment) {
				if ($segment === '' || $segment === '.') {
					continue;
				}

				if ($segment === '..') {
					array_pop($out);

					continue;
				}

				$out[] = $segment;
			}

			$last     = end($segments);
			$trailing = $out !== [] && ($last === '' || $last === '.' || $last === '..');

			if ($out === []) {
				return '/';
			}

			$segments = array_merge([''], $out, $trailing ? [''] : []);
		}

		return implode('/', array_map(static fn (string $s): string => rawurlencode(rawurldecode($s)), $segments));
	}

	private static function canonicalQuery(string $query): string {
		if ($query === '') {
			return '';
		}

		$pairs = [];

		foreach (explode('&', $query) as $pair) {
			$parts   = explode('=', $pair, 2);
			$k       = $parts[0];
			$v       = $parts[1] ?? '';
			$pairs[] = [rawurlencode(rawurldecode($k)), rawurlencode(rawurldecode($v))];
		}

		usort($pairs, static fn (array $a, array $b): int => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));

		return implode('&', array_map(static fn (array $p): string => $p[0] . '=' . $p[1], $pairs));
	}
}
