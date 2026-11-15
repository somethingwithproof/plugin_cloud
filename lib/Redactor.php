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
	private const MASK = '[redacted]';

	/** @var list<string> */
	private array $known = [];

	public function add(Secret $secret): void {
		$value = $secret->reveal();

		// Short values would match ordinary text and hide the error itself.
		if (strlen($value) < 8) {
			return;
		}

		foreach ([$value, rawurlencode($value), urlencode($value), substr((string) json_encode($value), 1, -1)] as $form) {
			if ($form !== '' && !in_array($form, $this->known, true)) {
				$this->known[] = $form;
			}
		}

		// Longest first, so a secret containing another is masked whole.
		usort($this->known, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
	}

	public function clean(string $text): string {
		if ($this->known !== []) {
			$text = str_replace($this->known, self::MASK, $text);
		}

		$patterns = [
			'/\b(authorization|proxy-authorization|x-amz-security-token|x-goog-iam-authorization-token|cookie|set-cookie)\s*:\s*[^\r\n]*/i' => '$1: ' . self::MASK,
			'/\b(bearer|basic)\s+[A-Za-z0-9._~+\/=-]+/i'                                                                                   => '$1 ' . self::MASK,
			'/\b(X-Amz-Signature|X-Amz-Credential|X-Amz-Security-Token|signature|access_token|refresh_token|client_secret|assertion)=[^&\s"]*/i' => '$1=' . self::MASK,
			'/"(access_token|refresh_token|id_token|client_secret|private_key|secret_access_key|session_token)"\s*:\s*"(?:[^"\\\\]|\\\\.)*"/i' => '"$1":"' . self::MASK . '"',
			'/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(-----END [A-Z ]*PRIVATE KEY-----|$)/s'                                               => self::MASK,
		];

		return (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
	}
}
