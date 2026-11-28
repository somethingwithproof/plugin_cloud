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
 * HTTPS over stream_socket_client() with its own HTTP/1.1 reader (ADR 0009),
 * from the milestone 1 spike. One request per connection, certificate and
 * host name verification on, no redirects followed, the connect and TLS
 * handshake inside one total deadline, at most 64 KiB of headers, a body cap,
 * strict Content-Length, and strict chunked decoding: PHP's dechunk filter
 * passes a malformed chunk through as data.
 */
final class SocketTransport implements HttpTransport {
	/**
	 * Maximum combined HTTP response header size in bytes (64 KiB).
	 */
	private const MAX_HEADER_BYTES = 65536;

	/**
	 * @param ?string $caFile a CA bundle for the test binding only; production
	 *                        uses OpenSSL's configured trust store
	 */
	public function __construct(
		private readonly OutboundPolicy $policy,
		private readonly float $connectTimeout = 5.0,
		private readonly float $totalTimeout = 30.0,
		private readonly int $maxBytes = 5242880,
		private readonly ?string $caFile = null,
	) {
	}

	/**
	 * Execute one outbound HTTP/1.1 request over stream sockets under OutboundPolicy.
	 *
	 * @param HttpRequest $request outbound HTTP request
	 * @return HttpResponse validated HTTP response
	 * @throws TransportError on policy refusal, TLS failure, timeout, framing error, or size cap
	 */
	public function send(HttpRequest $request): HttpResponse {
		// The policy is checked before anything resolves or connects.
		$url      = $this->policy->check($request->url);
		$method   = $request->method;
		$headers  = $request->headers;
		$body     = $request->body;
		$deadline = microtime(true) + $this->totalTimeout;
		$host     = $url['host'];
		$port     = $url['port'];
		$target   = $url['target'];
		$tls      = $url['tls'];

		if (preg_match('/^[A-Z]{3,7}$/', $method) !== 1) {
			throw new TransportError('header_rejected', 'method');
		}

		// A CR or LF in a name or value would let a caller inject headers or a
		// second request.
		$defaultPort = $tls ? 443 : 80;
		$request     = "$method $target HTTP/1.1\r\nHost: " . $host . ($port === $defaultPort ? '' : ":$port")
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

		if ($tls) {
			$ssl = [
				'verify_peer'         => true,
				'verify_peer_name'    => true,
				'allow_self_signed'   => false,
				'peer_name'           => $host,
				'SNI_enabled'         => true,
				'disable_compression' => true,
			];

			if ($this->caFile !== null) {
				$ssl['cafile'] = $this->caFile;
			}

			$ctx    = stream_context_create(['ssl' => $ssl]);
			$remote = "tls://$host:$port";
		} else {
			$ctx    = stream_context_create([]);
			$remote = "tcp://$host:$port";
		}

		// The connect and TLS handshake share the total budget. Checked before
		// the warning collector is installed, so a throw cannot leave it behind.
		$connectBudget = min($this->connectTimeout, $deadline - microtime(true));
		if ($connectBudget <= 0) {
			throw new TransportError('total_timeout');
		}

		$warnings = array();
		set_error_handler(function (int $no, string $message) use (&$warnings): bool {
			$warnings[] = $message;

			return true;
		});
		try {
			$fp = stream_socket_client($remote, $errno, $errstr, $connectBudget, STREAM_CLIENT_CONNECT, $ctx);
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

			// These responses end with their headers whatever Content-Length
			// says (RFC 9112 section 6.3).
			if ($method === 'HEAD' || $status === 204 || $status === 304) {
				return new HttpResponse($status, $responseHeaders, '');
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

			return new HttpResponse($status, $responseHeaders, $out);
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
			// At most eight hex digits, so the value always fits an int.
			$size = (int) hexdec($m[1]);
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

	/**
	 * Map a PHP socket/TLS warning message into a fixed transport error kind.
	 *
	 * @param string $message raw stream error message
	 * @return string fixed TransportError kind
	 */
	private static function classify(string $message): string {
		return match (true) {
			str_contains($message, 'certificate verify failed') => 'tls_verify',
			str_contains($message, 'did not match expected') => 'tls_name',
			str_contains($message, 'timed out') => 'connect_timeout',
			str_contains($message, 'refused') => 'connect_refused',
			default => 'open_failed',
		};
	}

	/**
	 * Strip control characters and truncate local socket error text.
	 *
	 * @param string $message raw socket diagnostic string
	 * @return string sanitized bounded diagnostic detail
	 */
	private static function clean(string $message): string {
		return substr((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $message), 0, 400);
	}
}
