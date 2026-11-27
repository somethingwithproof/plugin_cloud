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
 * One provider read operation a driver may call (ADR 0002). Requests are
 * matched on method, host, path, query, and for reads made through POST on
 * the body, so a write operation on the same path is refused.
 *
 * A request with a query string matches only an operation that lists a
 * query pattern, and a form body or query with a repeated parameter name
 * never matches: providers differ on which copy they act on, so a pattern
 * could approve one copy while the provider runs the other.
 */
final readonly class Operation {
	/**
	 * Construct an allowlisted read-only provider HTTP operation pattern.
	 *
	 * @param string      $name         operation identifier
	 * @param string      $method       HTTP method (GET or read-only POST)
	 * @param string      $host         exact hostname or PCRE pattern prefixed with '#'
	 * @param string      $pathPattern  PCRE pattern for the request path
	 * @param string|null $bodyPattern  optional PCRE pattern for read-only POST bodies
	 * @param string|null $queryPattern optional PCRE pattern for query strings
	 */
	public function __construct(
		public string $name,
		public string $method,
		public string $host,
		public string $pathPattern,
		public ?string $bodyPattern = null,
		public ?string $queryPattern = null,
	) {
	}

	/**
	 * Return whether the given outbound request satisfies this operation's allowlist rules.
	 *
	 * @param HttpRequest $request candidate outbound request
	 * @return bool true when method, host, path, query, and body match
	 */
	public function matches(HttpRequest $request): bool {
		$parts = parse_url($request->url);

		if (!is_array($parts) || $request->method !== $this->method) {
			return false;
		}

		$host   = $parts['host'] ?? '';
		$hostOk = str_starts_with($this->host, '#')
			? preg_match($this->host, $host) === 1
			: $host === $this->host;

		if (!$hostOk) {
			return false;
		}

		if (preg_match($this->pathPattern, $parts['path'] ?? '/') !== 1) {
			return false;
		}

		$query = $parts['query'] ?? '';

		if (
			$query !== ''
			&& ($this->queryPattern === null || self::repeatsName($query) || preg_match($this->queryPattern, $query) !== 1)
		) {
			return false;
		}

		$body = $request->body ?? '';

		if ($this->bodyPattern === null) {
			return $body === '';
		}

		$types = [];

		foreach ($request->headers as $name => $value) {
			if (strcasecmp((string) $name, 'Content-Type') === 0) {
				$types[] = strtolower(trim($value));
			}
		}

		// Content-Type is a single field (RFC 9110 section 8.3); two copies
		// leave it open which one the provider reads.
		if (count($types) > 1) {
			return false;
		}

		$type = $types[0] ?? '';

		$form = str_starts_with($type, 'application/x-www-form-urlencoded');

		return !($form && self::repeatsName($body)) && preg_match($this->bodyPattern, $body) === 1;
	}

	/**
	 * Detect repeated parameter names in a URL-encoded query string or form body.
	 *
	 * @param string $encoded query string or form-encoded payload
	 * @return bool true when any decoded parameter name appears more than once
	 */
	private static function repeatsName(string $encoded): bool {
		$names = [];

		foreach (explode('&', $encoded) as $pair) {
			$name = strtolower(rawurldecode(explode('=', $pair, 2)[0]));

			if (isset($names[$name])) {
				return true;
			}

			$names[$name] = true;
		}

		return false;
	}
}
