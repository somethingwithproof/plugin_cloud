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
 * Which URLs a request may go to: HTTPS only, to an exact host on the list,
 * on an allowed port, with a URL that cannot be read two ways.
 *
 * The checks run before any name is resolved or socket opened. Hosts come
 * from fixed per-provider tables and validated scope fields, never from a
 * provider response or a setting. Ports other than 443 exist only for the
 * test binding of the transport.
 */
final readonly class OutboundPolicy {
	/**
	 * @param list<string> $hosts exact lower-case host names
	 * @param list<int>    $ports
	 */
	public function __construct(
		public array $hosts,
		public array $ports = [443],
	) {
	}

	/**
	 * @return array{host: string, port: int, target: string}
	 * @throws TransportError
	 */
	public function check(string $url): array {
		// Control bytes, spaces, non-ASCII, and backslashes are read
		// differently by different URL parsers.
		if ($url === '' || strlen($url) > 8192 || preg_match('/[^\x21-\x7e]|\\\\/', $url) === 1) {
			throw new TransportError('url_rejected', 'characters');
		}

		if (preg_match('#^https://([a-z0-9.-]+)(?::([0-9]{1,5}))?(/[^?\#]*)?(\?[^\#]*)?$#', $url, $m) !== 1) {
			throw new TransportError('url_rejected', 'form');
		}

		$host = $m[1];
		$port = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 443;

		if (!in_array($host, $this->hosts, true)) {
			throw new TransportError('host_refused', $host);
		}

		if (!in_array($port, $this->ports, true)) {
			throw new TransportError('url_rejected', 'port');
		}

		$path = isset($m[3]) && $m[3] !== '' ? $m[3] : '/';

		if (preg_match('#(^|/)\.\.?(/|$)#', $path) === 1) {
			throw new TransportError('url_rejected', 'dot segment');
		}

		return ['host' => $host, 'port' => $port, 'target' => $path . ($m[4] ?? '')];
	}
}
