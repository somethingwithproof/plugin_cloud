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
 * The database side of the shipped Cacti templates: the import lock, the
 * checksum of the last imported package, and the imported objects, found by
 * the fixed hashes tests/bin/generate-templates.php gives them (ADR 0010).
 */
final class TemplateRepository {
	private const SETTING = 'cloud_templates_sha256';
	private const LOCK    = 'plugin_cloud_templates';

	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Two processes importing at once would create every object twice. The
	 * lock is per connection, so it ends with the request at the latest.
	 */
	public function lock(int $timeout): bool {
		$locked = db_fetch_cell_prepared('SELECT GET_LOCK(?, ?)', [self::LOCK, $timeout], '', true, $this->connection);

		return (string) $locked === '1';
	}

	public function unlock(): void {
		db_execute_prepared('DO RELEASE_LOCK(?)', [self::LOCK], true, $this->connection);
	}

	public function recordedChecksum(): string {
		return (string) db_fetch_cell_prepared(
			'SELECT value FROM settings WHERE name = ?',
			[self::SETTING],
			'',
			true,
			$this->connection
		);
	}

	public function recordChecksum(string $sha256): void {
		db_execute_prepared(
			'REPLACE INTO settings (name, value) VALUES (?, ?)',
			[self::SETTING, $sha256],
			true,
			$this->connection
		);
	}

	public function defaultProfile(): int {
		$id = db_fetch_cell_prepared(
			"SELECT id FROM data_source_profiles WHERE `default` = 'on'",
			[],
			'',
			true,
			$this->connection
		);

		return is_numeric($id) ? (int) $id : 0;
	}

	/**
	 * Every object the package defines, as table => keys. A missing one means
	 * the import failed or an administrator deleted it.
	 *
	 * @return array<string, list<string>>
	 */
	public static function objects(): array {
		$objects = [
			'host_template'    => ['host_template/digitalocean'],
			'snmp_query'       => [],
			'data_template'    => [],
			'graph_templates'  => [],
			'snmp_query_graph' => [],
		];

		foreach ([DigitalOceanCatalog::DROPLET, DigitalOceanCatalog::LOAD_BALANCER] as $type) {
			$objects['snmp_query'][] = 'data_query/' . $type;
		}

		foreach (array_keys(DigitalOceanCatalog::GRAPHS) as $key) {
			$objects['data_template'][]    = 'data_template/' . $key;
			$objects['graph_templates'][]  = 'graph_template/' . $key;
			$objects['snmp_query_graph'][] = 'data_query_graph/' . $key;
		}

		return $objects;
	}

	/**
	 * Cacti identifies template objects by 32 hex digits; a truncated SHA-256
	 * of a fixed key gives each object a stable one. Not a security control.
	 */
	public static function hash(string $key): string {
		return substr(hash('sha256', 'plugin_cloud/' . $key), 0, 32);
	}

	/**
	 * The id of one package object, or null when it is not imported.
	 */
	public function id(string $table, string $key): ?int {
		if (!in_array($key, self::objects()[$table] ?? [], true)) {
			return null;
		}

		// $table is a key of objects(), so never input.
		$id = (int) db_fetch_cell_prepared(
			'SELECT id FROM `' . $table . '` WHERE hash = ?',
			[self::hash($key)],
			'',
			true,
			$this->connection
		);

		return $id > 0 ? $id : null;
	}

	/**
	 * @return list<string> the keys of package objects not in the database
	 */
	public function missing(): array {
		$missing = [];

		// The table names come from objects(), never from input.
		foreach (self::objects() as $table => $keys) {
			$hashes = array_map(self::hash(...), $keys);
			$found  = db_fetch_assoc_prepared(
				'SELECT hash FROM `' . $table . '` WHERE hash IN (' . implode(', ', array_fill(0, count($hashes), '?')) . ')',
				$hashes,
				true,
				$this->connection
			);
			$have   = array_column(is_array($found) ? $found : [], 'hash');

			foreach ($keys as $i => $key) {
				if (!in_array($hashes[$i], $have, true)) {
					$missing[] = $key;
				}
			}
		}

		return $missing;
	}
}
