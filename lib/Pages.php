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
 * Page files, realms, menu entries, and breadcrumbs.
 *
 * Cacti's auth.php denies any page that is not listed in a realm, so every
 * page script the plugin ships must appear in exactly one realm below
 * (REQ-UI-001). Pages that change accounts, credentials, or Cacti objects are
 * Cloud Admin pages (REQ-UI-002).
 */
final class Pages {
	/**
	 * Page files protected by the Cloud Operator realm.
	 */
	public const OPERATOR = ['cloud_operator.php'];

	/**
	 * Page files protected by the Cloud View realm.
	 */
	public const VIEW  = ['cloud_health.php'];

	/**
	 * Page files protected by the Cloud Admin realm.
	 */
	public const ADMIN = ['cloud_accounts.php'];

	/**
	 * @return list<array{name: string, files: list<string>}>
	 */
	public static function realms(): array {
		return [
			['name' => __('Cloud Operator', 'cloud'), 'files' => self::OPERATOR],
			['name' => __('Cloud View', 'cloud'), 'files' => self::VIEW],
			['name' => __('Cloud Admin', 'cloud'), 'files' => self::ADMIN],
		];
	}

	/**
	 * @return list<string>
	 */
	public static function files(): array {
		return array_merge(self::VIEW, self::ADMIN, self::OPERATOR);
	}

	/**
	 * Console > Management entries.
	 *
	 * @return array<string, string>
	 */
	public static function menu(): array {
		return [
			'cloud_operator.php' => __('Cloud Operations', 'cloud'),
			'cloud_accounts.php' => __('Cloud Accounts', 'cloud'),
			'cloud_health.php'   => __('Cloud Health', 'cloud'),
		];
	}

	/**
	 * Pages whose load re-checks the schema, matching how maintained plugins
	 * apply upgrades in a core that never calls plugin_*_upgrade() itself.
	 *
	 * @return list<string>
	 */
	public static function upgradeTriggers(): array {
		return array_merge(['plugins.php'], self::files());
	}

	/**
	 * @return array<string, array{title: string, mapping: string, url: string, level: string}>
	 */
	public static function navigation(): array {
		$nav = [];

		foreach (self::menu() as $file => $title) {
			$nav[$file . ':'] = [
				'title'   => $title,
				'mapping' => 'index.php:',
				'url'     => $file,
				'level'   => '1',
			];
		}

		return $nav;
	}
}
