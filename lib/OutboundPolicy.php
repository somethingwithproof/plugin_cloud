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
	private const METADATA_HOSTS = ['169.254.169.254', 'metadata.google.internal'];

	/**
	 * Build a link-local cloud instance metadata URL. Cloud metadata endpoints
	 * (AWS IMDSv2, Azure IMDS, GCE metadata) are reachable only over plain HTTP
	 * on port 80 and are strictly gated to CredentialSource::Instance accounts.
	 *
	 * @throws TransportError
	 */
	public static function metadataUrl(string $host, string $pathAndQuery): string {
		if (!in_array($host, self::METADATA_HOSTS, true) || !str_starts_with($pathAndQuery, '/')) {
			throw new TransportError('host_refused', $host);
		}

		$scheme = 'http';

		return $scheme . '://' . $host . $pathAndQuery;
	}

	/**
	 * @param list<string> $hosts          exact lower-case HTTPS host names
	 * @param list<int>    $ports          allowed HTTPS ports
	 * @param list<string> $plainHttpHosts exact lower-case link-local metadata hosts allowed over HTTP port 80
	 */
	public function __construct(
		public array $hosts,
		public array $ports = [443],
		public array $plainHttpHosts = [],
	) {
	}

	/**
	 * @return array{host: string, port: int, target: string, tls: bool}
	 * @throws TransportError
	 */
	public function check(string $url): array {
		// Control bytes, spaces, non-ASCII, and backslashes are read
		// differently by different URL parsers.
		if ($url === '' || strlen($url) > 8192 || preg_match('/[^\x21-\x7e]|\\\\/', $url) === 1) {
			throw new TransportError('url_rejected', 'characters');
		}

		if (preg_match('#^(https?)://([a-z0-9.-]+)(?::([0-9]{1,5}))?(/[^?\#]*)?(\?[^\#]*)?$#', $url, $m) !== 1) {
			throw new TransportError('url_rejected', 'form');
		}

		$scheme = $m[1];
		$host   = $m[2];
		$tls    = $scheme === 'https';

		if ($tls) {
			$port = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 443;

			if (!in_array($host, $this->hosts, true)) {
				throw new TransportError('host_refused', $host);
			}

			if (!in_array($port, $this->ports, true)) {
				throw new TransportError('url_rejected', 'port');
			}
		} else {
			$port = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 80;

			if (!in_array($host, $this->plainHttpHosts, true)) {
				throw new TransportError('url_rejected', 'form');
			}

			if ($port !== 80) {
				throw new TransportError('url_rejected', 'port');
			}
		}

		$path = isset($m[4]) && $m[4] !== '' ? $m[4] : '/';

		// An encoded dot, slash, or backslash lets a server see a different
		// path from the one checked here and in the operation list.
		if (preg_match('/%(2e|2f|5c|00)/i', $path) === 1) {
			throw new TransportError('url_rejected', 'encoded path separator');
		}

		if (preg_match('#(^|/)\.\.?(/|$)#', $path) === 1) {
			throw new TransportError('url_rejected', 'dot segment');
		}

		return ['host' => $host, 'port' => $port, 'target' => $path . ($m[5] ?? ''), 'tls' => $tls];
	}
}
