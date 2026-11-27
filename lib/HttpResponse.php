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
 * Immutable HTTP response value object returned by HttpTransport.
 */
final readonly class HttpResponse {
	/**
	 * Construct an HTTP response snapshot.
	 *
	 * @param int                   $status  HTTP status code
	 * @param array<string, string> $headers lower-case header names mapped to values
	 * @param string                $body    raw response body
	 */
	public function __construct(
		public int $status,
		public array $headers,
		public string $body,
	) {
	}

	/**
	 * Look up a response header value case-insensitively.
	 *
	 * @param string $name header name
	 * @return string header value, or empty string when absent
	 */
	public function header(string $name): string {
		return $this->headers[strtolower($name)] ?? '';
	}
}
