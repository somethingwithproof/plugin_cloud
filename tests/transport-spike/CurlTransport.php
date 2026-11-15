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
 * Spike prototype of the ext-curl option (issue #24), with the options
 * plugin_cloudflare's CurlTransport sets, so all three candidates meet the
 * same cases.
 */

require_once __DIR__ . '/StreamTransport.php';

final class CurlTransport {
	public function __construct(
		private readonly string $caFile,
		private readonly int $maxBytes = 5242880,
	) {
	}

	/**
	 * @param array<string, string> $headers
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public function send(
		string $method,
		string $url,
		array $headers,
		?string $body,
		float $connectTimeout,
		float $totalTimeout
	): array {
		if (!extension_loaded('curl')) {
			throw new TransportError('curl_missing');
		}
		$lines = array();
		foreach ($headers as $name => $value) {
			if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1 || preg_match('/[\r\n\x00]/', $value) === 1) {
				throw new TransportError('header_rejected');
			}
			$lines[] = "$name: $value";
		}

		$out = '';
		$responseHeaders = array();
		$headerBytes = 0;
		$failure = null;
		$maxBytes = $this->maxBytes;
		$ch = curl_init();
		curl_setopt_array($ch, array(
			CURLOPT_URL               => $url,
			CURLOPT_CUSTOMREQUEST     => $method,
			CURLOPT_HTTPHEADER        => $lines,
			CURLOPT_FOLLOWLOCATION    => false,
			CURLOPT_SSL_VERIFYPEER    => true,
			CURLOPT_SSL_VERIFYHOST    => 2,
			CURLOPT_CAINFO            => $this->caFile,
			CURLOPT_CONNECTTIMEOUT_MS => (int) ($connectTimeout * 1000),
			CURLOPT_TIMEOUT_MS        => (int) ($totalTimeout * 1000),
			CURLOPT_NOPROXY           => '*',
			CURLOPT_NOSIGNAL          => true,
			CURLOPT_PROTOCOLS         => CURLPROTO_HTTPS,
			CURLOPT_HTTP_VERSION      => CURL_HTTP_VERSION_1_1,
			CURLOPT_HEADERFUNCTION    => function (
				$ch,
				string $line
			) use (&$responseHeaders, &$headerBytes, &$failure, $maxBytes): int {
				$headerBytes += strlen($line);
				if ($headerBytes > 65536) {
					$failure = 'headers_too_large';

					return 0;
				}
				if (strncmp($line, 'HTTP/', 5) === 0) {
					$responseHeaders = array();
				} elseif (($pos = strpos($line, ':')) !== false) {
					$name = strtolower(trim(substr($line, 0, $pos)));
					$responseHeaders[$name] = trim(substr($line, $pos + 1));
					// Refuse a declared oversize body before any of it is read.
					if ($name === 'content-length' && (int) $responseHeaders[$name] > $maxBytes) {
						$failure = 'too_large';

						return 0;
					}
				}

				return strlen($line);
			},
			CURLOPT_WRITEFUNCTION     => function ($ch, string $chunk) use (&$out, &$failure, $maxBytes): int {
				if (strlen($out) + strlen($chunk) > $maxBytes) {
					$failure = 'too_large';

					return 0;
				}
				$out .= $chunk;

				return strlen($chunk);
			},
		));
		if ($body !== null || in_array($method, array('POST', 'PUT', 'PATCH'), true)) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?? '');
		}

		$ok = curl_exec($ch);
		$errno = curl_errno($ch);
		$error = curl_error($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

		if ($failure !== null) {
			throw new TransportError($failure);
		}
		if ($ok === false || $errno !== 0) {
			$kind = match ($errno) {
				60, 77 => 'tls_verify',
				51     => 'tls_name',
				// 28 is both; libcurl names the phase in the message.
				28     => str_starts_with($error, 'Connection timed out')
					|| str_starts_with($error, 'Connection timeout')
					|| str_contains($error, 'SSL connection timeout') ? 'connect_timeout' : 'total_timeout',
				7      => 'connect_refused',
				default => 'curl_' . $errno,
			};
			if (
				$kind === 'tls_verify'
				&& (str_contains($error, 'does not match') || str_contains($error, 'subject name matches'))
			) {
				$kind = 'tls_name';
			}
			throw new TransportError($kind, substr($error, 0, 400));
		}
		if ($status >= 300 && $status < 400) {
			throw new TransportError('redirect_refused', (string) $status);
		}

		return array('status' => $status, 'headers' => $responseHeaders, 'body' => $out);
	}
}
