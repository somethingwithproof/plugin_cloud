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
	public const MAX_BYTES = 16384;

	public static function pathAcceptable(string $path): bool {
		return SecureFile::pathAcceptable($path);
	}

	/**
	 * @throws CredentialError
	 */
	public static function read(string $path): Secret {
		$content = trim(SecureFile::read($path, self::MAX_BYTES));

		if ($content === '') {
			throw CredentialError::because('the credential file is empty');
		}

		return new Secret($content);
	}
}
