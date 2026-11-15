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
 * Spike prototype of the HTTPS stream wrapper transport (issue #24).
 * Not plugin code: it exists to measure what the wrapper can and cannot
 * enforce. The URL allowlist is out of scope here; plugin_cloudflare's
 * OutboundPolicy runs before any of this in the real transport.
 */

final class TransportError extends RuntimeException {
	public function __construct(public readonly string $kind, string $detail = '') {
		parent::__construct($kind . ($detail !== '' ? ': ' . $detail : ''));
	}
}

final class StreamTransport {
	public function __construct(
		private readonly string $caFile,
		private readonly int $maxBytes = 5242880,
	) {
	}

	/**
	 * @param array<string, string> $headers
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public function send(string $method, string $url, array $headers, ?string $body, float $connectTimeout, float $totalTimeout): array {
		// allow_url_fopen is PHP_INI_SYSTEM, so the plugin can detect it but
		// not change it at runtime.
		if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
			throw new TransportError('allow_url_fopen_off');
		}

		$deadline = microtime(true) + $totalTimeout;
		$lines = array('Connection: close');
		foreach ($headers as $name => $value) {
			if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1 || preg_match('/[\r\n\x00]/', $value) === 1) {
				throw new TransportError('header_rejected');
			}
			$lines[] = $name . ': ' . $value;
		}

		$ctx = stream_context_create(array(
			'http' => array(
				'method'           => $method,
				'header'           => $lines,
				'content'          => $body ?? '',
				'follow_location'  => 0,
				'ignore_errors'    => true,
				'protocol_version' => 1.1,
				// The wrapper uses one timeout for connect, handshake and each
				// read; the spike measures what that means for total time.
				'timeout'          => $connectTimeout,
			),
			'ssl' => array(
				'verify_peer'         => true,
				'verify_peer_name'    => true,
				'allow_self_signed'   => false,
				'cafile'              => $this->caFile,
				'SNI_enabled'         => true,
				'disable_compression' => true,
			),
		));

		// fopen() reports the TLS or socket cause in an earlier warning and
		// ends with a generic "operation failed", so collect all of them.
		$warnings = array();
		set_error_handler(function (int $no, string $message) use (&$warnings): bool {
			$warnings[] = $message;

			return true;
		});
		try {
			$fp = fopen($url, 'rb', false, $ctx);
		} finally {
			restore_error_handler();
		}
		if ($fp === false) {
			$message = implode(' | ', $warnings);
			throw new TransportError(self::classify($message), self::clean($message));
		}

		try {
			$meta = stream_get_meta_data($fp);
			[$status, $responseHeaders] = self::parseHeaders($meta['wrapper_data'] ?? array());

			if ($status >= 300 && $status < 400) {
				throw new TransportError('redirect_refused', (string) $status);
			}
			if (isset($responseHeaders['content-length']) && (int) $responseHeaders['content-length'] > $this->maxBytes) {
				throw new TransportError('too_large', 'content-length ' . $responseHeaders['content-length']);
			}

			$out = '';
			while (!feof($fp)) {
				$left = $deadline - microtime(true);
				if ($left <= 0) {
					throw new TransportError('total_timeout', strlen($out) . ' bytes read');
				}
				stream_set_timeout($fp, (int) $left, (int) (fmod($left, 1) * 1e6));
				$chunk = fread($fp, 8192);
				if (stream_get_meta_data($fp)['timed_out']) {
					throw new TransportError('total_timeout', strlen($out) . ' bytes read');
				}
				if ($chunk === false) {
					throw new TransportError('read_failed');
				}
				$out .= $chunk;
				if (strlen($out) > $this->maxBytes) {
					throw new TransportError('too_large', 'over ' . $this->maxBytes . ' bytes');
				}
			}

			return array('status' => $status, 'headers' => $responseHeaders, 'body' => $out);
		} finally {
			fclose($fp);
		}
	}

	/**
	 * wrapper_data holds every status line and header block received, so a
	 * 100 Continue must not leak headers into the final response.
	 *
	 * @param list<string> $raw
	 * @return array{0: int, 1: array<string, string>}
	 */
	private static function parseHeaders(array $raw): array {
		$status = 0;
		$headers = array();
		foreach ($raw as $line) {
			if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $m) === 1) {
				$status = (int) $m[1];
				$headers = array();
			} elseif (($pos = strpos($line, ':')) !== false) {
				$name = strtolower(trim(substr($line, 0, $pos)));
				$value = trim(substr($line, $pos + 1));
				if ($name === 'content-length' && isset($headers[$name]) && $headers[$name] !== $value) {
					throw new TransportError('malformed', 'conflicting content-length');
				}
				$headers[$name] = $value;
			}
		}

		return array($status, $headers);
	}

	private static function classify(string $message): string {
		return match (true) {
			str_contains($message, 'certificate verify failed') => 'tls_verify',
			str_contains($message, 'did not match expected') => 'tls_name',
			str_contains($message, 'timed out') => 'connect_timeout',
			str_contains($message, 'Connection refused') => 'connect_refused',
			str_contains($message, 'allow_url_fopen') => 'allow_url_fopen_off',
			default => 'open_failed',
		};
	}

	private static function clean(string $message): string {
		return substr((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $message), 0, 400);
	}
}
