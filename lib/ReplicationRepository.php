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
 * Queries poller-scoped cloud metadata for remote data collector replication.
 *
 * Only configuration rows owned by the target remote poller are selected,
 * keeping raw vault key material strictly local to each poller. Encrypted
 * vault ciphertext rows are included only for vault-backed accounts owned by
 * that poller.
 */
final class ReplicationRepository {
	/**
	 * Construct a replication repository bound to a Cacti database connection.
	 *
	 * @param \PDO|false $connection Cacti PDO handle or false for the default connection
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * @return list<array<array-key, mixed>>|null null when the query failed
	 */
	public function accountsForPoller(int $pollerId): ?array {
		$rows = db_fetch_assoc_prepared(
			'SELECT * FROM plugin_cloud_account WHERE poller_id = ? ORDER BY id',
			[$pollerId],
			true,
			$this->connection
		);

		return is_array($rows) ? Row::rows($rows) : null;
	}

	/**
	 * @return list<array<array-key, mixed>>|null null when the query failed
	 */
	public function devicesForPoller(int $pollerId): ?array {
		$rows = db_fetch_assoc_prepared(
			'SELECT d.* FROM plugin_cloud_device d
				INNER JOIN plugin_cloud_account a ON a.id = d.account_id
				WHERE a.poller_id = ?
				ORDER BY d.host_id',
			[$pollerId],
			true,
			$this->connection
		);

		return is_array($rows) ? Row::rows($rows) : null;
	}

	/**
	 * @return list<array<array-key, mixed>>|null null when the query failed
	 */
	public function seriesForPoller(int $pollerId): ?array {
		$rows = db_fetch_assoc_prepared(
			'SELECT s.* FROM plugin_cloud_series s
				INNER JOIN plugin_cloud_account a ON a.id = s.account_id
				WHERE a.poller_id = ?
				ORDER BY s.local_data_id, s.metric',
			[$pollerId],
			true,
			$this->connection
		);

		return is_array($rows) ? Row::rows($rows) : null;
	}

	/**
	 * Encrypted ciphertext rows ONLY for vault-backed accounts owned by $pollerId.
	 * Raw vault key material is never stored in the database or replicated.
	 *
	 * @return list<array<array-key, mixed>>|null null when the query failed
	 */
	public function encryptedSecretsForPoller(int $pollerId): ?array {
		$rows = db_fetch_assoc_prepared(
			'SELECT s.account_id, s.purpose, s.nonce, s.ciphertext, s.tag, s.key_id, s.expires_at, s.updated_at
				FROM plugin_cloud_secret s
				INNER JOIN plugin_cloud_account a ON a.id = s.account_id
				WHERE a.poller_id = ? AND a.credential_source = ?
				ORDER BY s.account_id, s.purpose',
			[$pollerId, CredentialSource::Vault->value],
			true,
			$this->connection
		);

		return is_array($rows) ? Row::rows($rows) : null;
	}
}
