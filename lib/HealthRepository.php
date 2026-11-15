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

final class HealthRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

	public function find(int $accountId): AccountHealth {
		$row = db_fetch_row_prepared('SELECT * FROM plugin_cloud_health WHERE account_id = ?', [$accountId], true, $this->connection);

		return (is_array($row) ? AccountHealth::fromRow($row) : null) ?? new AccountHealth($accountId);
	}

	public function save(AccountHealth $health): void {
		db_execute_prepared('REPLACE INTO plugin_cloud_health (account_id, run_started, run_finished, run_duration, collection, error_class, requests, series_total, series_read, discovered_at, discovery, credential) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
			$health->accountId, $health->runStarted, $health->runFinished, $health->runDuration, $health->collection->value, $health->error->value ?? '',
			$health->requests, $health->seriesTotal, $health->seriesRead, $health->discoveredAt, $health->discovery->value, $health->error === ErrorClass::Auth ? 'invalid' : ($health->error === ErrorClass::Permission ? 'permission' : 'unknown'),
		], true, $this->connection);
	}
}
