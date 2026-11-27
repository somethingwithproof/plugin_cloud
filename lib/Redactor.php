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
 * Removes secrets from text before it is logged or stored (REQ-CRED-005).
 *
 * Known secrets are replaced wherever they appear, along with their URL and
 * JSON escaped forms. Authorization, cookie, and signature values are removed
 * by pattern, since a signed request header holds material derived from a
 * secret that is not itself on the known list.
 */
final class Redactor {
	/**
	 * Replacement placeholder substituted for redacted secret material.
	 */
	private const MASK = '[redacted]';

	/** @var array<string, int> form => byte length */
	private array $known = [];

	/**
	 * Register a secret value and its URL/JSON encodings for exact redaction.
	 *
	 * @param Secret $secret sensitive value to mask
	 * @return void
	 */
	public function add(Secret $secret): void {
		$value = $secret->reveal();

		// Every accepted credential is masked, however short: a readable
		// error is worth less than a leaked secret.
		if ($value === '') {
			return;
		}

		foreach ([$value, rawurlencode($value), urlencode($value), substr((string) json_encode($value), 1, -1)] as $form) {
			if ($form !== '' && !isset($this->known[$form])) {
				$this->known[$form] = strlen($form);
			}
		}
	}

	/**
	 * Each pattern masks its named group "v", or the whole match without one.
	 */
	private const PATTERNS = [
		'/\b(?:authorization|proxy-authorization|x-amz-security-token|x-goog-iam-authorization-token|'
			. 'cookie|set-cookie)\s*:\s*(?<v>[^\r\n]*)/i',
		'/\b(?:bearer|basic)\s+(?<v>[A-Za-z0-9._~+\/=-]+)/i',
		'/\b(?:X-Amz-Signature|X-Amz-Credential|X-Amz-Security-Token|signature|access_token|refresh_token|'
			. 'client_secret|assertion)=(?<v>[^&\s"]*)/i',
		'/"(?:access_token|refresh_token|id_token|client_secret|private_key|secret_access_key|session_token)"'
			. '\s*:\s*"(?<v>(?:[^"\\\\]|\\\\.)*)"/i',
		'/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|$)/s',
	];

	/**
	 * Mask all registered secrets and recognized credential patterns in a string.
	 *
	 * @param string $text untrusted log or error text
	 * @return string redacted text safe for logging
	 */
	public function clean(string $text): string {
		// Every span is found in the original text and masked once at the
		// end. Applied one after another, a pass could hide material from
		// the next: a known secret inside a header name would stop the
		// header pattern matching, and a pattern masking part of a known
		// secret would stop that secret matching whole.
		$spans = [];

		foreach (self::PATTERNS as $pattern) {
			if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
				return self::MASK;
			}

			foreach ($matches as $match) {
				// An unmatched optional group reports offset -1.
				$found   = isset($match['v']) && $match['v'][1] >= 0 ? $match['v'] : $match[0];
				$spans[] = [$found[1], $found[1] + strlen($found[0])];
			}
		}

		foreach ($this->known as $form => $length) {
			for ($at = strpos($text, $form); $at !== false; $at = strpos($text, $form, $at + 1)) {
				$spans[] = [$at, $at + $length];
			}
		}

		if ($spans === []) {
			return $text;
		}

		sort($spans);

		/** @var list<array{int, int}> $merged */
		$merged = [];

		foreach ($spans as [$start, $stop]) {
			$last = count($merged) - 1;

			if ($last >= 0 && $start <= $merged[$last][1]) {
				$merged[$last] = [$merged[$last][0], max($merged[$last][1], $stop)];
			} else {
				$merged[] = [$start, $stop];
			}
		}

		$clean = '';
		$from  = 0;

		foreach ($merged as [$start, $stop]) {
			$clean .= substr($text, $from, $start - $from) . self::MASK;
			$from   = $stop;
		}

		return $clean . substr($text, $from);
	}
}
