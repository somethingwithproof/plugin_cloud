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
 * Plugin settings, read once per request from Cacti's settings table.
 *
 * Per-account values (intervals, ceilings, credential sources) live on the
 * account row; these are the few that apply to the whole plugin.
 */
final readonly class Settings {
	public const NAMES = [
		'cloud_vault_key_file',
		'cloud_collector_budget',
		'cloud_uninstall_keep_tables',
	];

	public const DEFAULT_KEY_FILE = '/etc/cacti/cloud-vault.key';
	public const DEFAULT_BUDGET   = 240;

	public function __construct(
		public string $vaultKeyFile,
		public int $collectorBudget,
		public bool $keepTablesOnUninstall,
	) {
	}

	/**
	 * A setting that was never saved reads as its default. Keeping tables is
	 * the default, so an uninstall drops accounts and credentials only after
	 * an administrator has cleared the box (REQ-LIFE-005).
	 *
	 * @param array<string, mixed> $row setting name => stored value, absent when never saved
	 */
	public static function fromRow(array $row): self {
		$keyFile = Row::string($row, 'cloud_vault_key_file');

		return new self(
			vaultKeyFile: $keyFile === '' ? self::DEFAULT_KEY_FILE : $keyFile,
			collectorBudget: min(Row::int($row, 'cloud_collector_budget', self::DEFAULT_BUDGET, 10), 3600),
			keepTablesOnUninstall: array_key_exists('cloud_uninstall_keep_tables', $row) ? Row::bool(
				$row,
				'cloud_uninstall_keep_tables'
			) : true,
		);
	}

	/**
	 * Settings tab definition for the config_settings hook (REQ-UI-003).
	 *
	 * The key file is a plain text box, not a Cacti filepath field: it lives
	 * on the poller that runs the collector, which need not be the server
	 * rendering this form, and the health page checks it there.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function form(): array {
		return [
			'cloud_header_vault' => [
				'friendly_name' => __('Credential Vault', 'cloud'),
				'method'        => 'spacer',
			],
			'cloud_vault_key_file' => [
				'friendly_name' => __('Vault Key File', 'cloud'),
				'description'   => __(
					'Absolute path to the 32-byte key that encrypts stored credentials. Create it yourself, outside the web root, readable only by the web server and poller user, in a directory that only root and that user can write (the same applies to credential files); the plugin never creates or writes it. Back it up with the database: without it, stored credentials must be entered again.',
					'cloud'
				),
				'method'        => 'textbox',
				'default'       => self::DEFAULT_KEY_FILE,
				'max_length'    => '255',
				'size'          => '60',
			],
			'cloud_header_collector' => [
				'friendly_name' => __('Collector', 'cloud'),
				'method'        => 'spacer',
			],
			'cloud_collector_budget' => [
				'friendly_name' => __('Collector Time Budget', 'cloud'),
				'description'   => __(
					'Seconds one collector run may spend before it stops starting new work. Keep it below the poller interval; unfinished accounts resume on the next run.',
					'cloud'
				),
				'method'        => 'textbox',
				'default'       => (string) self::DEFAULT_BUDGET,
				'filter'        => FILTER_VALIDATE_INT,
				'options'       => ['options' => ['min_range' => 10, 'max_range' => 3600]],
				'max_length'    => '4',
				'size'          => '4',
			],
			'cloud_header_uninstall' => [
				'friendly_name' => __('Uninstall', 'cloud'),
				'method'        => 'spacer',
			],
			'cloud_uninstall_keep_tables' => [
				'friendly_name' => __('Keep Plugin Tables on Uninstall', 'cloud'),
				'description'   => __(
					'When checked, uninstalling the plugin keeps its accounts, encrypted credentials, and cache. Clear it to have the uninstall drop them. Cloud resources are never touched either way.',
					'cloud'
				),
				'method'        => 'checkbox',
				'default'       => 'on',
			],
		];
	}
}
