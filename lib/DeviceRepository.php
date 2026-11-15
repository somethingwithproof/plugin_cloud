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
 *
 * A link counts only while its host exists and still carries the
 * external_id the plugin gave it. Cacti skips a disabled plugin's hooks, so
 * a device deleted then leaves its link behind, and a reused host id must
 * not inherit the account.
 */
final class DeviceRepository {
	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

	public const EXTERNAL_PREFIX = 'cloud:';

	/**
	 * Replaces any earlier link of the account, which can only be stale when
	 * the caller found no live one.
	 *
	 * @return bool whether the link was recorded; without it the device
	 *              serves nothing
	 */
	public function link(int $hostId, int $accountId): bool {
		return db_execute_prepared('DELETE FROM plugin_cloud_device WHERE account_id = ? OR host_id = ?', [
			$accountId,
			$hostId,
		], true, $this->connection) !== false
			&& db_execute_prepared(
				'INSERT INTO plugin_cloud_device (host_id, account_id, created_at) VALUES (?, ?, ?)',
				[$hostId, $accountId, $this->clock->now()],
				true,
				$this->connection
			) !== false;
	}

	/**
	 * Forget a device Cacti deleted. Its graphs and data sources go with it.
	 */
	public function unlink(int $hostId): void {
		db_execute_prepared('DELETE FROM plugin_cloud_device WHERE host_id = ?', [$hostId], true, $this->connection);
	}

	public function accountFor(int $hostId): ?int {
		$id = (int) db_fetch_cell_prepared(
			'SELECT d.account_id FROM plugin_cloud_device d INNER JOIN host h ON h.id = d.host_id
				WHERE d.host_id = ? AND h.external_id = CONCAT(?, d.account_id)',
			[$hostId, self::EXTERNAL_PREFIX],
			'',
			true,
			$this->connection
		);

		return $id > 0 ? $id : null;
	}

	public function hostFor(int $accountId): ?int {
		$id = (int) db_fetch_cell_prepared(
			'SELECT d.host_id FROM plugin_cloud_device d INNER JOIN host h ON h.id = d.host_id
				WHERE d.account_id = ? AND h.external_id = CONCAT(?, d.account_id)',
			[$accountId, self::EXTERNAL_PREFIX],
			'',
			true,
			$this->connection
		);

		return $id > 0 ? $id : null;
	}
}
