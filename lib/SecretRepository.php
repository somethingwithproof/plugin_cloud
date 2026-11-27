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
 * Encrypted vault rows. Only ciphertext passes through here.
 */
final class SecretRepository {
	/**
	 * Vault entry purpose identifier for primary account credentials.
	 */
	public const CREDENTIAL = 'credential';

	/**
	 * Construct a secret repository bound to a database connection and clock.
	 *
	 * @param \PDO|false $connection Cacti PDO handle or false for the default connection
	 * @param Clock      $clock      wall clock for update timestamps
	 */
	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

	/**
	 * Load an encrypted vault entry for an account and purpose.
	 *
	 * @param int    $accountId cloud account ID
	 * @param string $purpose   entry purpose identifier
	 * @return VaultEntry|null stored ciphertext entry, or null when absent or malformed
	 */
	public function load(int $accountId, string $purpose): ?VaultEntry {
		$row = db_fetch_row_prepared(
			'SELECT nonce, ciphertext, tag, key_id, expires_at FROM plugin_cloud_secret WHERE account_id = ? AND purpose = ?',
			[$accountId, $purpose],
			true,
			$this->connection
		);

		return is_array($row) ? VaultEntry::fromRow($row) : null;
	}

	/**
	 * Store or replace an encrypted vault entry for an account and purpose.
	 *
	 * @param int        $accountId cloud account ID
	 * @param string     $purpose   entry purpose identifier
	 * @param VaultEntry $entry     encrypted payload
	 * @return bool true when the database write succeeded
	 */
	public function store(int $accountId, string $purpose, VaultEntry $entry): bool {
		return (bool) db_execute_prepared(
			'REPLACE INTO plugin_cloud_secret (account_id, purpose, nonce, ciphertext, tag, key_id, expires_at, updated_at)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
			[
				$accountId,
				$purpose,
				$entry->nonce,
				$entry->ciphertext,
				$entry->tag,
				$entry->keyId,
				$entry->expiresAt,
				$this->clock->now()
			],
			true,
			$this->connection
		);
	}

	/**
	 * Report whether a vault row exists for an account and purpose.
	 *
	 * @param int    $accountId cloud account ID
	 * @param string $purpose   entry purpose identifier
	 * @return bool true when at least one row exists
	 */
	public function has(int $accountId, string $purpose): bool {
		return (int) db_fetch_cell_prepared(
			'SELECT COUNT(*) FROM plugin_cloud_secret WHERE account_id = ? AND purpose = ?',
			[$accountId, $purpose],
			'',
			true,
			$this->connection
		) > 0;
	}

	/**
	 * Delete a stored vault entry for an account and purpose.
	 *
	 * @param int    $accountId cloud account ID
	 * @param string $purpose   entry purpose identifier
	 * @return bool true when the delete statement succeeded
	 */
	public function delete(int $accountId, string $purpose): bool {
		return db_execute_prepared(
			'DELETE FROM plugin_cloud_secret WHERE account_id = ? AND purpose = ?',
			[$accountId, $purpose],
			true,
			$this->connection
		) !== false;
	}

	/**
	 * Deletes the row only while the account does not keep its credential in
	 * the vault. The check and the delete are one statement, so a concurrent
	 * save that switches the account to the vault cannot lose its credential
	 * to a cleanup that began before the switch.
	 */
	public function deleteUnlessVault(int $accountId, string $purpose): bool {
		return db_execute_prepared(
			'DELETE s FROM plugin_cloud_secret s
				INNER JOIN plugin_cloud_account a ON a.id = s.account_id
				WHERE s.account_id = ? AND s.purpose = ? AND a.credential_source <> ?',
			[$accountId, $purpose, CredentialSource::Vault->value],
			true,
			$this->connection
		) !== false;
	}
}
