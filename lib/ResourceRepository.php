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
 * Resources found by discovery, with their lifecycle (REQ-STATE-003): active
 * when the last discovery listed them, missing after a discovery without
 * them, deleted after DELETE_AFTER such discoveries. Each write names the
 * caller's lease in the statement itself, so a run that lost its lease
 * between checks cannot overwrite the newer holder's inventory.
 */
final class ResourceRepository {
	public const DELETE_AFTER = 3;

	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

	public function sync(int $accountId, Inventory $inventory, LeaseRepository $leases, string $holder): bool {
		$now  = $this->clock->now();
		$seen = [];

		foreach ($inventory->resources as $resource) {
			if (!$leases->holds($accountId, $holder)) {
				return false;
			}

			db_execute_prepared('INSERT INTO plugin_cloud_resource
					(account_id, type, provider_id, name, region, lifecycle, missing_count, first_seen, last_seen)
				SELECT ?, ?, ?, ?, ?, ?, 0, ?, ? FROM plugin_cloud_lease l
					WHERE l.account_id = ? AND l.holder = ? AND l.expires_at >= ?
				ON DUPLICATE KEY UPDATE name = VALUES(name), region = VALUES(region), lifecycle = VALUES(lifecycle),
					missing_count = 0, last_seen = VALUES(last_seen)', [
				$accountId, $resource->type, $resource->providerId, $resource->name, $resource->region,
				Lifecycle::Active->value, $now, $now, $accountId, $holder, $now,
			], true, $this->connection);

			$seen[] = $resource->type . "\0" . $resource->providerId;
		}

		if (!$leases->holds($accountId, $holder)) {
			return false;
		}

		foreach (Row::rows(db_fetch_assoc_prepared(
			'SELECT id, type, provider_id, missing_count FROM plugin_cloud_resource WHERE account_id = ? AND lifecycle != ?',
			[$accountId, Lifecycle::Deleted->value],
			true,
			$this->connection
		)) as $row) {
			if (in_array(Row::string($row, 'type') . "\0" . Row::string($row, 'provider_id'), $seen, true)) {
				continue;
			}

			$missing = Row::int($row, 'missing_count', 0, 0) + 1;

			db_execute_prepared('UPDATE plugin_cloud_resource r INNER JOIN plugin_cloud_lease l
					ON l.account_id = r.account_id AND l.holder = ? AND l.expires_at >= ?
				SET r.missing_count = ?, r.lifecycle = ? WHERE r.id = ?', [
				$holder, $now, $missing,
				($missing >= self::DELETE_AFTER ? Lifecycle::Deleted : Lifecycle::Missing)->value,
				Row::int($row, 'id'),
			], true, $this->connection);
		}

		return true;
	}

	/**
	 * Resources a scope device serves: every one discovery has not retired,
	 * so a missing resource keeps its index and reads as unknown.
	 *
	 * @return list<array{id: int, providerId: string, name: string, firstSeen: int}>
	 */
	public function served(int $accountId, string $type): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared(
			'SELECT id, provider_id, name, first_seen FROM plugin_cloud_resource
				WHERE account_id = ? AND type = ? AND lifecycle != ? ORDER BY provider_id',
			[$accountId, $type, Lifecycle::Deleted->value],
			true,
			$this->connection
		)) as $row) {
			$out[] = [
				'id'         => Row::int($row, 'id'),
				'providerId' => Row::string($row, 'provider_id'),
				'name'       => Row::string($row, 'name'),
				'firstSeen'  => Row::int($row, 'first_seen'),
			];
		}

		return $out;
	}

	/**
	 * @return array<string, int> lifecycle => count
	 */
	public function counts(int $accountId): array {
		$counts = [];

		foreach (Row::rows(db_fetch_assoc_prepared(
			'SELECT lifecycle, COUNT(*) AS n FROM plugin_cloud_resource WHERE account_id = ? GROUP BY lifecycle',
			[$accountId],
			true,
			$this->connection
		)) as $row) {
			$counts[Row::string($row, 'lifecycle')] = Row::int($row, 'n');
		}

		return $counts;
	}
}
