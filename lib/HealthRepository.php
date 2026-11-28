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
 * Persistence layer for per-account collector and discovery health rows.
 */
final class HealthRepository {
	/**
	 * Construct a health repository on the given database connection.
	 *
	 * @param \PDO|false $connection Cacti PDO handle or false for the default connection
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Load the latest health record for one account, or an empty default snapshot.
	 *
	 * @param int $accountId cloud account ID
	 * @return AccountHealth stored or default health record
	 */
	public function find(int $accountId): AccountHealth {
		$row = db_fetch_row_prepared(
			'SELECT * FROM plugin_cloud_health WHERE account_id = ?',
			[$accountId],
			true,
			$this->connection
		);

		return (is_array($row) ? AccountHealth::fromRow($row) : null) ?? new AccountHealth($accountId);
	}

	/**
	 * Written only while $holder still holds the account's lease, checked in
	 * the same statement, so a run that lost its lease leaves the newer
	 * holder's health in place.
	 */
	public function save(AccountHealth $health, string $holder, int $now): void {
		db_execute_prepared('INSERT INTO plugin_cloud_health (account_id, run_started, run_finished, run_duration, collection,
				error_class, requests, series_total, series_read, discovered_at, discovery, credential, retry_at)
			SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ? FROM plugin_cloud_lease l
				WHERE l.account_id = ? AND l.holder = ? AND l.expires_at >= ?
			ON DUPLICATE KEY UPDATE run_started = VALUES(run_started), run_finished = VALUES(run_finished),
				run_duration = VALUES(run_duration), collection = VALUES(collection),
				error_class = VALUES(error_class), requests = VALUES(requests), series_total = VALUES(series_total),
				series_read = VALUES(series_read),
				discovered_at = VALUES(discovered_at), discovery = VALUES(discovery), credential = VALUES(credential),
				retry_at = VALUES(retry_at)', [
			$health->accountId, $health->runStarted, $health->runFinished, $health->runDuration,
			$health->collection->value, $health->error->value ?? '',
			$health->requests, $health->seriesTotal, $health->seriesRead, $health->discoveredAt, $health->discovery->value,
			$health->error === ErrorClass::Auth
				? 'invalid'
				: ($health->error === ErrorClass::Permission ? 'permission' : 'unknown'),
			$health->retryAt,
			$health->accountId, $holder, $now,
		], true, $this->connection);
	}
}
