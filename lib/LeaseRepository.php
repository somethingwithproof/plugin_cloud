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
 * Per-account collector leases (REQ-COLL-004). A lease is taken only when no
 * unexpired one is held by another run, in one statement, so two collectors
 * cannot both believe they own an account.
 */
final class LeaseRepository {
	/**
	 * Construct a lease repository bound to a database connection and clock.
	 *
	 * @param \PDO|false $connection Cacti PDO handle or false for the default connection
	 * @param Clock      $clock      wall clock for lease expiration checks
	 */
	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

	/**
	 * Attempt to acquire or renew an account's collector lease.
	 *
	 * @param int    $accountId cloud account ID
	 * @param string $holder    unique collector run identifier
	 * @param int    $seconds   lease duration in seconds
	 * @return bool true when this holder now owns an active lease
	 */
	public function acquire(int $accountId, string $holder, int $seconds): bool {
		$now = $this->clock->now();

		db_execute_prepared(
			'INSERT IGNORE INTO plugin_cloud_lease (account_id, holder, acquired_at, expires_at) VALUES (?, ?, 0, 0)',
			[$accountId, ''],
			true,
			$this->connection
		);
		db_execute_prepared(
			'UPDATE plugin_cloud_lease SET holder = ?, acquired_at = ?, expires_at = ?
				WHERE account_id = ? AND (holder = ? OR expires_at < ?)',
			[$holder, $now, $now + $seconds, $accountId, $holder, $now],
			true,
			$this->connection
		);

		return $this->holds($accountId, $holder);
	}

	/**
	 * Check whether the specified holder currently holds an unexpired lease.
	 *
	 * @param int    $accountId cloud account ID
	 * @param string $holder    unique collector run identifier
	 * @return bool true when the lease is active for this holder
	 */
	public function holds(int $accountId, string $holder): bool {
		return (int) db_fetch_cell_prepared(
			'SELECT COUNT(*) FROM plugin_cloud_lease WHERE account_id = ? AND holder = ? AND expires_at >= ?',
			[$accountId, $holder, $this->clock->now()],
			'',
			true,
			$this->connection
		) === 1;
	}

	/**
	 * holds(), as a locking read for use inside a transaction: the lease row
	 * stays locked until the transaction ends, so no other run can take the
	 * lease over between this check and the caller's writes. The row is
	 * selected by its key alone, so it is locked whoever holds it. Unlogged,
	 * so core does not retry it after a deadlock outside the transaction;
	 * a failed read counts as not held.
	 */
	public function holdsForUpdate(int $accountId, string $holder): bool {
		return (int) db_fetch_cell_prepared(
			'SELECT holder = ? AND expires_at >= ? FROM plugin_cloud_lease WHERE account_id = ? FOR UPDATE',
			[$holder, $this->clock->now(), $accountId],
			'',
			false,
			$this->connection
		) === 1;
	}

	/**
	 * When a run last took this account's lease, or 0. Written as an attempt
	 * starts and kept after release, so it records attempts whose results
	 * were never written.
	 */
	public function lastAcquired(int $accountId): int {
		return (int) db_fetch_cell_prepared(
			'SELECT acquired_at FROM plugin_cloud_lease WHERE account_id = ?',
			[$accountId],
			'',
			true,
			$this->connection
		);
	}

	/**
	 * Release a lease owned by the given holder by expiring it immediately.
	 *
	 * @param int    $accountId cloud account ID
	 * @param string $holder    unique collector run identifier
	 * @return void
	 */
	public function release(int $accountId, string $holder): void {
		db_execute_prepared(
			'UPDATE plugin_cloud_lease SET expires_at = 0 WHERE account_id = ? AND holder = ?',
			[$accountId, $holder],
			true,
			$this->connection
		);
	}
}
