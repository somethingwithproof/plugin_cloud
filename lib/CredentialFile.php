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
 * A credential stored in a file on the poller instead of the vault.
 */
final class CredentialFile {
	/**
	 * Maximum permitted credential file size in bytes.
	 */
	public const MAX_BYTES = 16384;

	/**
	 * Report whether a credential file path satisfies structural safety checks.
	 *
	 * @param string $path candidate filesystem path
	 * @return bool true when absolute, bounded, and free of traversal/control characters
	 */
	public static function pathAcceptable(string $path): bool {
		return SecureFile::pathAcceptable($path);
	}

	/**
	 * Whether $path names a file inside $directory, the credential directory a
	 * full administrator set on Cacti's Settings page. Cloud Admin chooses an
	 * account's file, so without this it could name any file the Cacti user
	 * can read and have it sent to the provider. An empty or root directory
	 * allows nothing. Only directories are resolved; the file itself is not
	 * touched, and SecureFile refuses a link at the last component.
	 */
	public static function within(string $path, string $directory): bool {
		if (!SecureFile::pathAcceptable($path) || !SecureFile::pathAcceptable($directory)) {
			return false;
		}

		$root   = realpath($directory);
		$parent = realpath(dirname($path));

		return $root !== false && $root !== '/' && $parent !== false
			&& str_starts_with($parent . '/', rtrim($root, '/') . '/');
	}

	/**
	 * @throws CredentialError
	 */
	public static function check(string $path, string $directory): void {
		self::guard($path, $directory);
		SecureFile::check($path, self::MAX_BYTES);
	}

	/**
	 * @throws CredentialError
	 */
	public static function read(string $path, string $directory): Secret {
		self::guard($path, $directory);

		$content = trim(SecureFile::read($path, self::MAX_BYTES));

		if ($content === '') {
			throw CredentialError::because('the credential file is empty');
		}

		return new Secret($content);
	}

	/**
	 * Refuse a path outside the directory before opening it, so the reason
	 * says nothing about files there.
	 *
	 * @throws CredentialError
	 */
	private static function guard(string $path, string $directory): void {
		if (!self::within($path, $directory)) {
			throw CredentialError::because('the file is not in the credential directory set in the Cloud settings');
		}
	}
}
