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
	public function __construct(
		public string $name,
		public string $method,
		public string $host,
		public string $pathPattern,
		public ?string $bodyPattern = null,
		public ?string $queryPattern = null,
	) {
	}

	public function matches(HttpRequest $request): bool {
		$parts = parse_url($request->url);

		if (!is_array($parts) || ($parts['host'] ?? '') !== $this->host || $request->method !== $this->method) {
			return false;
		}

		if (preg_match($this->pathPattern, $parts['path'] ?? '/') !== 1) {
			return false;
		}

		$query = $parts['query'] ?? '';

		if ($query !== '' && ($this->queryPattern === null || self::repeatsName($query) || preg_match($this->queryPattern, $query) !== 1)) {
			return false;
		}

		$body = $request->body ?? '';

		if ($this->bodyPattern === null) {
			return $body === '';
		}

		$form = str_starts_with(strtolower($request->headers['Content-Type'] ?? $request->headers['content-type'] ?? ''), 'application/x-www-form-urlencoded');

		return !($form && self::repeatsName($body)) && preg_match($this->bodyPattern, $body) === 1;
	}

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
