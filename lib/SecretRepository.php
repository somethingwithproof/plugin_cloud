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
	public const CREDENTIAL = 'credential';

	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

	public function load(int $accountId, string $purpose): ?VaultEntry {
		$row = db_fetch_row_prepared('SELECT nonce, ciphertext, tag, key_id, expires_at FROM plugin_cloud_secret WHERE account_id = ? AND purpose = ?', [$accountId, $purpose], true, $this->connection);

		return is_array($row) ? VaultEntry::fromRow($row) : null;
	}

	public function store(int $accountId, string $purpose, VaultEntry $entry): bool {
		return (bool) db_execute_prepared('REPLACE INTO plugin_cloud_secret (account_id, purpose, nonce, ciphertext, tag, key_id, expires_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$accountId, $purpose, $entry->nonce, $entry->ciphertext, $entry->tag, $entry->keyId, $entry->expiresAt, $this->clock->now()], true, $this->connection);
	}

	public function has(int $accountId, string $purpose): bool {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_cloud_secret WHERE account_id = ? AND purpose = ?', [$accountId, $purpose], '', true, $this->connection) > 0;
	}

	public function delete(int $accountId, string $purpose): void {
		db_execute_prepared('DELETE FROM plugin_cloud_secret WHERE account_id = ? AND purpose = ?', [$accountId, $purpose], true, $this->connection);
	}
}
