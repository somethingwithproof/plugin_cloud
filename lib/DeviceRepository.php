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
 * Which Cacti device is each account's scope device (ADR 0010). One device
 * per account: the script server serves an account's resources only to its
 * own device (REQ-SS-004).
 */
final class DeviceRepository {
	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

	public function link(int $hostId, int $accountId): void {
		db_execute_prepared(
			'INSERT INTO plugin_cloud_device (host_id, account_id, created_at) VALUES (?, ?, ?)',
			[$hostId, $accountId, $this->clock->now()],
			true,
			$this->connection
		);
	}

	/**
	 * Forget a device Cacti deleted. Its graphs and data sources go with it.
	 */
	public function unlink(int $hostId): void {
		db_execute_prepared('DELETE FROM plugin_cloud_device WHERE host_id = ?', [$hostId], true, $this->connection);
	}

	public function accountFor(int $hostId): ?int {
		$id = (int) db_fetch_cell_prepared(
			'SELECT account_id FROM plugin_cloud_device WHERE host_id = ?',
			[$hostId],
			'',
			true,
			$this->connection
		);

		return $id > 0 ? $id : null;
	}

	public function hostFor(int $accountId): ?int {
		$id = (int) db_fetch_cell_prepared(
			'SELECT host_id FROM plugin_cloud_device WHERE account_id = ?',
			[$accountId],
			'',
			true,
			$this->connection
		);

		return $id > 0 ? $id : null;
	}
}
