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
 * Spike prototype of a stream_socket_client() transport (issue #24), built
 * after the stream wrapper could not bound total time while headers
 * arrive. It owns the HTTP/1.1 framing, including a strict chunked decoder:
 * PHP's "dechunk" filter passes a malformed chunk through as data.
 */

require_once __DIR__ . '/StreamTransport.php';

final class SocketTransport {
	private const MAX_HEADER_BYTES = 65536;

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
		$deadline = microtime(true) + $totalTimeout;
		$parts = parse_url($url);
		if (($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
			throw new TransportError('url_rejected');
		}
		$host = $parts['host'];
		$port = $parts['port'] ?? 443;
		$target = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

		// A CR or LF in a name or value would let a caller inject headers or a
		// second request.
		$request = "$method $target HTTP/1.1\r\nHost: " . $host . ($port === 443 ? '' : ":$port")
			. "\r\nConnection: close\r\n";
		foreach ($headers as $name => $value) {
			if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1 || preg_match('/[\r\n\x00]/', $value) === 1) {
				throw new TransportError('header_rejected');
			}
			$request .= "$name: $value\r\n";
		}
		if ($body !== null || in_array($method, array('POST', 'PUT', 'PATCH'), true)) {
			$request .= 'Content-Length: ' . strlen($body ?? '') . "\r\n";
		}
		$request .= "\r\n" . ($body ?? '');

		$ctx = stream_context_create(array('ssl' => array(
			'verify_peer'         => true,
			'verify_peer_name'    => true,
			'allow_self_signed'   => false,
			'cafile'              => $this->caFile,
			'peer_name'           => $host,
			'SNI_enabled'         => true,
			'disable_compression' => true,
		)));

		// The connect and TLS handshake share the total budget. Checked before
		// the warning collector is installed, so a throw cannot leave it behind.
		$connectBudget = min($connectTimeout, $deadline - microtime(true));
		if ($connectBudget <= 0) {
			throw new TransportError('total_timeout');
		}

		$warnings = array();
		set_error_handler(function (int $no, string $message) use (&$warnings): bool {
			$warnings[] = $message;

			return true;
		});
		try {
			$fp = stream_socket_client("tls://$host:$port", $errno, $errstr, $connectBudget, STREAM_CLIENT_CONNECT, $ctx);
		} finally {
			restore_error_handler();
		}
		if ($fp === false) {
			$message = implode(' | ', array_merge($warnings, array("$errno $errstr")));
			throw new TransportError(self::classify($message), self::clean($message));
		}

		try {
			// A peer that stops reading must not hold the write past the deadline.
			for ($off = 0; $off < strlen($request); $off += $n) {
				$left = $deadline - microtime(true);
				if ($left <= 0) {
					throw new TransportError('total_timeout');
				}
				stream_set_timeout($fp, (int) $left, (int) (fmod($left, 1) * 1e6));
				$n = @fwrite($fp, substr($request, $off, 65536));
				if (stream_get_meta_data($fp)['timed_out']) {
					throw new TransportError('total_timeout');
				}
				if ($n === false || $n === 0) {
					throw new TransportError('write_failed');
				}
			}

			// Informational (1xx) responses come before the final one and are
			// skipped; their bytes count towards the same header limit.
			$raw = '';
			$headBytes = 0;
			while (true) {
				$headEnd = strpos($raw, "\r\n\r\n");
				while ($headEnd === false) {
					if ($headBytes + strlen($raw) > self::MAX_HEADER_BYTES) {
						throw new TransportError('headers_too_large');
					}
					if (feof($fp)) {
						throw new TransportError('malformed', 'connection closed in headers');
					}
					$raw .= $this->readSome($fp, $deadline);
					$headEnd = strpos($raw, "\r\n\r\n");
				}
				$headBytes += $headEnd + 4;
				if ($headBytes > self::MAX_HEADER_BYTES) {
					throw new TransportError('headers_too_large');
				}
				[$status, $responseHeaders] = self::parseHead(substr($raw, 0, $headEnd));
				$rest = substr($raw, $headEnd + 4);
				if ($status === 101) {
					// No request asks for an upgrade.
					throw new TransportError('malformed', 'unexpected 101');
				}
				if ($status >= 200) {
					break;
				}
				$raw = $rest;
			}

			if ($status >= 300 && $status < 400) {
				throw new TransportError('redirect_refused', (string) $status);
			}

			$chunked = str_contains(strtolower($responseHeaders['transfer-encoding'] ?? ''), 'chunked');
			$length = null;
			if (!$chunked && isset($responseHeaders['content-length'])) {
				if (preg_match('/^\d{1,12}$/', $responseHeaders['content-length']) !== 1) {
					throw new TransportError('malformed', 'content-length');
				}
				$length = (int) $responseHeaders['content-length'];
				if ($length > $this->maxBytes) {
					throw new TransportError('too_large', 'content-length ' . $length);
				}
			}

			// The raw (still chunked) body is capped too, so chunk framing
			// cannot be used to stream an unbounded amount through the filter.
			while (!feof($fp) && ($length === null || strlen($rest) < $length)) {
				$rest .= $this->readSome($fp, $deadline);
				if (strlen($rest) > $this->maxBytes + ($chunked ? 65536 : 0)) {
					throw new TransportError('too_large', 'over ' . $this->maxBytes . ' bytes');
				}
			}
			if ($length !== null && strlen($rest) < $length) {
				throw new TransportError('malformed', 'body shorter than content-length');
			}
			$out = $chunked ? self::dechunk($rest) : ($length === null ? $rest : substr($rest, 0, $length));
			if (strlen($out) > $this->maxBytes) {
				throw new TransportError('too_large', 'over ' . $this->maxBytes . ' bytes');
			}

			return array('status' => $status, 'headers' => $responseHeaders, 'body' => $out);
		} finally {
			fclose($fp);
		}
	}

	/**
	 * fread() on a socket returns whatever has arrived, so checking the
	 * deadline between reads bounds a server that sends one byte at a time.
	 *
	 * @param resource $fp
	 */
	private function readSome($fp, float $deadline): string {
		$left = $deadline - microtime(true);
		if ($left <= 0) {
			throw new TransportError('total_timeout');
		}
		stream_set_timeout($fp, (int) $left, (int) (fmod($left, 1) * 1e6));
		// A peer that closes without TLS close_notify raises a warning on
		// some builds; the caller sees EOF either way.
		$chunk = @fread($fp, 8192);
		if (stream_get_meta_data($fp)['timed_out']) {
			throw new TransportError('total_timeout');
		}

		return $chunk === false ? '' : $chunk;
	}

	/**
	 * @return array{0: int, 1: array<string, string>}
	 */
	private static function parseHead(string $head): array {
		$lines = explode("\r\n", $head);
		if (preg_match('#^HTTP/1\.[01] (\d{3})(?: |$)#', array_shift($lines), $m) !== 1) {
			throw new TransportError('malformed', 'status line');
		}
		$headers = array();
		foreach ($lines as $line) {
			$pos = strpos($line, ':');
			if ($pos === false || $pos === 0 || $line[0] === ' ' || $line[0] === "\t") {
				throw new TransportError('malformed', 'header line');
			}
			$name = strtolower(substr($line, 0, $pos));
			$value = trim(substr($line, $pos + 1), " \t");
			// Two Content-Length values that disagree are a framing attack.
			if ($name === 'content-length' && isset($headers[$name]) && $headers[$name] !== $value) {
				throw new TransportError('malformed', 'conflicting content-length');
			}
			$headers[$name] = $value;
		}

		return array((int) $m[1], $headers);
	}

	/**
	 * Strict RFC 9112 chunked decoding. Trailer fields are checked for
	 * shape and ignored; the empty line that ends them is required.
	 */
	private static function dechunk(string $raw): string {
		$out = '';
		$pos = 0;
		while (true) {
			$eol = strpos($raw, "\r\n", $pos);
			if ($eol === false || preg_match(
				'/^([0-9A-Fa-f]{1,8})(?:;[^\r\n]*)?$/',
				substr($raw, $pos, $eol - $pos),
				$m
			) !== 1) {
				throw new TransportError('malformed', 'chunk size');
			}
			$size = hexdec($m[1]);
			$pos = $eol + 2;
			if ($size === 0) {
				while (true) {
					$eol = strpos($raw, "\r\n", $pos);
					if ($eol === false) {
						throw new TransportError('malformed', 'chunked terminator');
					}
					$line = substr($raw, $pos, $eol - $pos);
					$pos = $eol + 2;
					if ($line === '') {
						return $out;
					}
					if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+:/', $line) !== 1) {
						throw new TransportError('malformed', 'trailer line');
					}
				}
			}
			if (strlen($raw) < $pos + $size + 2 || substr($raw, $pos + $size, 2) !== "\r\n") {
				throw new TransportError('malformed', 'chunk data');
			}
			$out .= substr($raw, $pos, $size);
			$pos += $size + 2;
		}
	}

	private static function classify(string $message): string {
		return match (true) {
			str_contains($message, 'certificate verify failed') => 'tls_verify',
			str_contains($message, 'did not match expected') => 'tls_name',
			str_contains($message, 'timed out') => 'connect_timeout',
			str_contains($message, 'refused') => 'connect_refused',
			default => 'open_failed',
		};
	}

	private static function clean(string $message): string {
		return substr((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $message), 0, 400);
	}
}
