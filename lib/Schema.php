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
 * Plugin tables, their migrations, and the plugin's registration records.
 *
 * Tables are created through api_plugin_db_table_create() so Cacti tracks them
 * in plugin_db_changes and drops them on uninstall. The schema version is kept
 * in its own setting because api_plugin_install() writes plugin_config.version
 * before the plugin's install function runs, so that column cannot tell us
 * whether the tables exist.
 *
 * Migrations run once each, in order, and the stored schema version moves
 * past a migration only after it succeeds (REQ-LIFE-002). Every migration is
 * written to be safe to repeat, so one interrupted part way is completed by
 * the next run (REQ-LIFE-003). No migration calls a provider (REQ-LIFE-006).
 */
final class Schema {
	public const VERSION = 2;

	public const HOOKS = [
		'config_arrays'        => 'cloud_config_arrays',
		'config_settings'      => 'cloud_config_settings',
		'draw_navigation_text' => 'cloud_draw_navigation_text',
		'poller_bottom'        => 'cloud_poller_bottom',
	];

	private const PLUGIN  = 'cloud';
	private const SETTING = 'cloud_schema_version';

	public function __construct(private readonly \PDO|false $connection) {
	}

	public function storedVersion(): int {
		$value = db_fetch_cell_prepared(
			'SELECT value FROM settings WHERE name = ?',
			[self::SETTING],
			'',
			true,
			$this->connection
		);

		return is_numeric($value) ? (int) $value : 0;
	}

	public function isCurrent(): bool {
		return $this->storedVersion() === self::VERSION;
	}

	/**
	 * Apply every pending migration, or stop at the first that fails.
	 *
	 * @param int $through the last migration to run; tests pass a lower
	 *                     number to leave an upgrade interrupted
	 */
	public function migrate(int $through = self::VERSION): bool {
		$from = $this->storedVersion();

		if ($from > self::VERSION) {
			Log::error(sprintf('Schema version %d is newer than this code (%d); refusing to downgrade', $from, self::VERSION));

			return false;
		}

		foreach ($this->migrations() as $version => $migration) {
			if ($version <= $from || $version > $through) {
				continue;
			}

			if (!$migration()) {
				Log::error(sprintf('Schema migration %d failed; schema left at version %d', $version, $this->storedVersion()));

				return false;
			}

			db_execute_prepared(
				'REPLACE INTO settings (name, value) VALUES (?, ?)',
				[self::SETTING, (string) $version],
				true,
				$this->connection
			);

			Log::info(sprintf('Schema migrated to version %d', $version));
		}

		return true;
	}

	public function recordedPluginVersion(): string {
		return (string) db_fetch_cell_prepared(
			'SELECT version FROM plugin_config WHERE directory = ?',
			[self::PLUGIN],
			'',
			true,
			$this->connection
		);
	}

	public function recordPluginVersion(string $version, string $author, string $homepage): void {
		db_execute_prepared(
			'UPDATE plugin_config SET version = ?, author = ?, webpage = ? WHERE directory = ?',
			[$version, $author, $homepage, self::PLUGIN],
			true,
			$this->connection
		);
	}

	public function hooksRegistered(): bool {
		$placeholders = implode(', ', array_fill(0, count(self::HOOKS), '?'));

		$hooks  = (int) db_fetch_cell_prepared(
			"SELECT COUNT(DISTINCT hook) FROM plugin_hooks WHERE name = ? AND hook IN ($placeholders)",
			array_merge([self::PLUGIN], array_keys(self::HOOKS)),
			'',
			true,
			$this->connection
		);
		$realms = (int) db_fetch_cell_prepared(
			'SELECT COUNT(*) FROM plugin_realms WHERE plugin = ?',
			[self::PLUGIN],
			'',
			true,
			$this->connection
		);

		return $hooks === count(self::HOOKS) && $realms === count(Pages::realms());
	}

	/**
	 * Stop Cacti from dropping the plugin tables, or the columns migrations
	 * added to them, on uninstall, and keep the schema version so a
	 * reinstall reuses them.
	 */
	public function untrackTables(): void {
		db_execute_prepared(
			"DELETE FROM plugin_db_changes WHERE plugin = ? AND method IN ('create', 'addcolumn')",
			[self::PLUGIN],
			true,
			$this->connection
		);
	}

	public function forgetVersion(): void {
		db_execute_prepared('DELETE FROM settings WHERE name = ?', [self::SETTING], true, $this->connection);
	}

	/**
	 * @return array<int, \Closure(): bool> schema version => migration
	 */
	private function migrations(): array {
		return [
			1 => fn (): bool => $this->createTables(self::tables()),
			// A fresh install already has the column from tables().
			2 => fn (): bool => $this->addColumn('plugin_cloud_health', self::retryAtColumn()),
		];
	}

	/**
	 * Track every existing plugin table for uninstall again.
	 *
	 * Core records a table only when it creates one, so tables kept through an
	 * uninstall would otherwise never be dropped by a later one. The row is
	 * the one api_plugin_db_table_create() writes; the table has no unique
	 * key, so an existing row is checked for rather than replaced.
	 */
	public function trackTables(): void {
		foreach (array_keys(self::tables()) as $table) {
			if ($this->tableExists($table)) {
				db_execute_prepared(
					"INSERT INTO plugin_db_changes (plugin, `table`, `column`, `method`) SELECT ?, ?, '', 'create' FROM DUAL
						WHERE NOT EXISTS (SELECT 1 FROM plugin_db_changes WHERE plugin = ? AND `table` = ? AND `method` = 'create')",
					[self::PLUGIN, $table, self::PLUGIN, $table],
					true,
					$this->connection
				);
			}
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $tables
	 */
	private function createTables(array $tables): bool {
		foreach ($tables as $table => $definition) {
			api_plugin_db_table_create(self::PLUGIN, $table, $definition);

			if (!$this->tableExists($table)) {
				Log::error(sprintf('Unable to create table %s', $table));

				return false;
			}
		}

		return true;
	}

	/**
	 * @param array{name: string, type: string, unsigned: bool, NULL: bool, default: string} $column
	 */
	private function addColumn(string $table, array $column): bool {
		api_plugin_db_add_column(self::PLUGIN, $table, $column);

		if ((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column['name']], '', true, $this->connection) === 0) {
			Log::error(sprintf('Unable to add column %s.%s', $table, $column['name']));

			return false;
		}

		return true;
	}

	/**
	 * When a throttled account may be collected again (REQ-BUDGET-003).
	 *
	 * @return array{name: string, type: string, unsigned: bool, NULL: bool, default: string}
	 */
	private static function retryAtColumn(): array {
		return ['name' => 'retry_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'];
	}

	private function tableExists(string $table): bool {
		return db_fetch_cell_prepared(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
			[$table],
			'',
			true,
			$this->connection
		) > 0;
	}

	/**
	 * Table definitions in api_plugin_db_table_create() format.
	 *
	 * State values are stored as varchar enum values, never list positions.
	 * Times are Unix seconds. No table holds a secret in the clear.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function tables(): array {
		$innodb   = [
			'type' => 'InnoDB',
			'charset' => 'utf8mb4',
			'collate' => 'utf8mb4_unicode_ci',
			'row_format' => 'Dynamic'
		];
		$id       = static fn (string $name): array => [
			'name' => $name,
			'type' => 'int(10)',
			'unsigned' => true,
			'NULL' => false
		];
		$time     = static fn (string $name): array => [
			'name' => $name,
			'type' => 'int(10)',
			'unsigned' => true,
			'NULL' => false,
			'default' => '0'
		];
		$text     = static fn (string $name, int $length, string $default = ''): array => [
			'name' => $name,
			'type' => "varchar($length)",
			'NULL' => false,
			'default' => $default
		];

		return [
			'plugin_cloud_account' => $innodb + [
				'comment' => 'Cloud provider accounts',
				'columns' => [
					$id('id') + ['auto_increment' => true],
					$text('provider', 16),
					$text('name', 100),
					$id('poller_id') + ['default' => '1'],
					// Provider scope fields as a JSON object, validated per provider before storage.
					$text('scope', 2048, '{}'),
					$text('credential_source', 16),
					$text('credential_file', 512),
					$id('collect_interval') + ['default' => '300'],
					$id('discover_interval') + ['default' => '3600'],
					$id('request_share') + ['default' => '50'],
					['name' => 'monthly_ceiling', 'type' => 'double', 'NULL' => true],
					$text('admin_state', 16, 'enabled'),
					$text('verify_status', 32, 'never'),
					$time('verified_at'),
					$time('created_at'),
					$time('updated_at'),
				],
				'primary'     => 'id',
				'unique_keys' => [['name' => 'name', 'columns' => ['name']]],
				'keys'        => [['name' => 'poller_id', 'columns' => ['poller_id']]],
			],
			// Vault entries: AES-256-GCM with the account bound in the additional data.
			'plugin_cloud_secret' => $innodb + [
				'comment' => 'Encrypted cloud credentials',
				'columns' => [
					$id('account_id'),
					$text('purpose', 32, 'credential'),
					['name' => 'nonce', 'type' => 'varbinary(12)', 'NULL' => false, 'default' => ''],
					['name' => 'ciphertext', 'type' => 'mediumblob', 'NULL' => false],
					['name' => 'tag', 'type' => 'varbinary(16)', 'NULL' => false, 'default' => ''],
					$text('key_id', 64),
					$time('expires_at'),
					$time('updated_at'),
				],
				'primary' => 'account_id`,`purpose',
			],
			'plugin_cloud_resource' => $innodb + [
				'comment' => 'Cloud resources found by discovery',
				'columns' => [
					$id('id') + ['auto_increment' => true],
					$id('account_id'),
					$text('type', 64),
					$text('provider_id', 255),
					$text('name', 255),
					$text('region', 64),
					$text('lifecycle', 16, 'active'),
					$id('missing_count') + ['default' => '0'],
					$time('first_seen'),
					$time('last_seen'),
				],
				'primary'     => 'id',
				'unique_keys' => [['name' => 'account_type_resource', 'columns' => ['account_id', 'type', 'provider_id']]],
			],
			// Which Cacti data source reads which resource and metric (ADR 0005).
			'plugin_cloud_series' => $innodb + [
				'comment' => 'Cacti data sources mapped to cloud resources and metrics',
				'columns' => [
					$id('local_data_id'),
					$id('account_id'),
					$id('resource_id'),
					$text('metric', 128),
					$time('created_at'),
				],
				'primary' => 'local_data_id`,`metric',
				'keys'    => [['name' => 'account_resource', 'columns' => ['account_id', 'resource_id']]],
			],
			// Newest complete datapoint per resource and metric. Freshness is
			// derived at read time, so a stale row cannot look current.
			'plugin_cloud_observation' => $innodb + [
				'comment' => 'Latest cloud datapoint per resource and metric',
				'columns' => [
					$id('resource_id'),
					$text('metric', 128),
					['name' => 'value', 'type' => 'double', 'NULL' => true],
					$time('observed_at'),
					$time('fetched_at'),
					$text('status', 16, 'never'),
				],
				'primary' => 'resource_id`,`metric',
			],
			'plugin_cloud_lease' => $innodb + [
				'comment' => 'Per-account collector leases',
				'columns' => [
					$id('account_id'),
					$text('holder', 64),
					$time('acquired_at'),
					$time('expires_at'),
				],
				'primary' => 'account_id',
			],
			'plugin_cloud_health' => $innodb + [
				'comment' => 'Collector and discovery status per account',
				'columns' => [
					$id('account_id'),
					$time('run_started'),
					$time('run_finished'),
					['name' => 'run_duration', 'type' => 'double', 'NULL' => false, 'default' => '0'],
					$text('collection', 16, 'never'),
					$text('error_class', 16),
					$id('requests') + ['default' => '0'],
					$id('series_total') + ['default' => '0'],
					$id('series_read') + ['default' => '0'],
					$time('discovered_at'),
					$text('discovery', 16, 'never'),
					$text('credential', 16, 'unknown'),
					self::retryAtColumn(),
				],
				'primary' => 'account_id',
			],
			// Account changes: who, when, and which fields, never their secret values.
			'plugin_cloud_audit' => $innodb + [
				'comment' => 'Cloud account change log',
				'columns' => [
					$id('id') + ['auto_increment' => true],
					$id('account_id'),
					$id('user_id') + ['default' => '0'],
					$time('changed_at'),
					$text('action', 16),
					$text('fields', 1024),
				],
				'primary' => 'id',
				'keys'    => [['name' => 'account_id', 'columns' => ['account_id', 'changed_at']]],
			],
		];
	}
}
