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
 * Where an account's credential comes from (ADR 0007).
 */
enum CredentialSource: string {
	case Vault    = 'vault';
	case File     = 'file';
	case Instance = 'instance';

	public function label(): string {
		return match ($this) {
			self::Vault    => __('Stored in the plugin vault', 'cloud'),
			self::File     => __('Credential file on the poller', 'cloud'),
			self::Instance => __('Instance identity of the poller', 'cloud'),
		};
	}
}
