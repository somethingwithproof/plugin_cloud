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
 * Accounts and their audit log. Every change writes an audit row naming the
 * user, the time, and the changed columns, never their values, so no secret
 * or credential path history accumulates there (REQ-ACCT-005).
 */
final class AccountRepository {
	public function __construct(private readonly \PDO|false $connection, private readonly Clock $clock) {
	}

	public function find(int $id): ?Account {
		$row = db_fetch_row_prepared('SELECT * FROM plugin_cloud_account WHERE id = ?', [$id], true, $this->connection);

		return is_array($row) ? Account::fromRow($row) : null;
	}

	/**
	 * @return list<Account>
	 */
	public function all(): array {
		return $this->accounts(Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_cloud_account ORDER BY name', [], true, $this->connection)));
	}

	/**
	 * Enabled accounts owned by one poller, for that poller's collector.
	 *
	 * @return list<Account>
	 */
	public function enabledForPoller(int $pollerId): array {
		return $this->accounts(Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_cloud_account WHERE poller_id = ? AND admin_state = ? ORDER BY id', [$pollerId, AdminState::Enabled->value], true, $this->connection)));
	}

	public function nameTaken(string $name, int $exceptId): bool {
		return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_cloud_account WHERE name = ? AND id != ?', [$name, $exceptId], '', true, $this->connection) > 0;
	}

	/**
	 * Insert or update, returning the account id, or 0 when the write failed.
	 */
	public function save(Account $account, int $userId): int {
		$before = $account->id > 0 ? $this->find($account->id) : null;
		$row    = $account->toRow();
		$now    = $this->clock->now();

		if ($account->id > 0 && $before === null) {
			return 0;
		}

		if ($before === null) {
			$columns = array_keys($row);
			$ok      = db_execute_prepared('INSERT INTO plugin_cloud_account (' . implode(', ', $columns) . ', created_at, updated_at) VALUES (' . implode(', ', array_fill(0, count($columns) + 2, '?')) . ')', array_merge(array_values($row), [$now, $now]), true, $this->connection);
			$id      = $ok ? (int) db_fetch_insert_id($this->connection) : 0;
			$action  = 'create';
		} else {
			$set    = implode(', ', array_map(static fn (string $column): string => "$column = ?", array_keys($row)));
			$ok     = db_execute_prepared("UPDATE plugin_cloud_account SET $set, updated_at = ? WHERE id = ?", array_merge(array_values($row), [$now, $account->id]), true, $this->connection);
			$id     = $ok ? $account->id : 0;
			$action = 'update';
		}

		$changed = $account->changedFields($before);

		if ($id > 0 && $changed !== []) {
			$this->audit($id, $userId, $action, $changed);
		}

		return $id;
	}

	/**
	 * Remove the account and everything collected for it. Its Cacti devices
	 * and graphs are left to the administrator.
	 */
	private const DELETE_ATTEMPTS = 3;

	public function delete(int $id, int $userId): bool {
		if ($this->find($id) === null) {
			return false;
		}

		// All or nothing: an account must not lose its credential or data
		// while its row stays. InnoDB rolls back the whole transaction on a
		// deadlock, and Cacti then retries the failed statement on its own,
		// outside any transaction. So the transaction is checked after every
		// statement, and if it has ended the whole delete runs again. Every
		// statement is idempotent, and the account row goes last, so an
		// interrupted attempt leaves an account that can be deleted again.
		$statements = [
			['DELETE FROM plugin_cloud_secret WHERE account_id = ?', [$id]],
			['DELETE FROM plugin_cloud_series WHERE account_id = ?', [$id]],
			['DELETE FROM plugin_cloud_lease WHERE account_id = ?', [$id]],
			['DELETE FROM plugin_cloud_health WHERE account_id = ?', [$id]],
			['DELETE o FROM plugin_cloud_observation o INNER JOIN plugin_cloud_resource r ON r.id = o.resource_id WHERE r.account_id = ?', [$id]],
			['DELETE FROM plugin_cloud_resource WHERE account_id = ?', [$id]],
			['DELETE FROM plugin_cloud_account WHERE id = ?', [$id]],
		];

		for ($attempt = 0; $attempt < self::DELETE_ATTEMPTS; $attempt++) {
			if (!db_begin_transaction($this->connection)) {
				return false;
			}

			$intact = true;

			foreach ($statements as [$sql, $params]) {
				if (!db_execute_prepared($sql, $params, true, $this->connection) || !$this->inTransaction()) {
					$intact = false;

					break;
				}
			}

			if ($intact && db_commit_transaction($this->connection)) {
				$this->audit($id, $userId, 'delete', []);

				return true;
			}

			if ($this->inTransaction()) {
				db_rollback_transaction($this->connection);
			}
		}

		return false;
	}

	public function recordVerify(int $id, VerifyStatus $status, int $userId): void {
		db_execute_prepared('UPDATE plugin_cloud_account SET verify_status = ?, verified_at = ? WHERE id = ?', [$status->value, $this->clock->now(), $id], true, $this->connection);

		$this->audit($id, $userId, 'verify', ['verify_status']);
	}

	/**
	 * A credential replacement is an account change too; only the fact is logged.
	 */
	public function recordCredentialChange(int $id, int $userId): void {
		$this->audit($id, $userId, 'credential', ['credential']);
	}

	/**
	 * @return list<array{account_id: int, user_id: int, changed_at: int, action: string, fields: list<string>}>
	 */
	public function auditLog(int $accountId, int $limit = 50): array {
		$entries = [];

		foreach (Row::rows(db_fetch_assoc_prepared('SELECT * FROM plugin_cloud_audit WHERE account_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit), [$accountId], true, $this->connection)) as $row) {
			$fields = Row::string($row, 'fields');

			$entries[] = [
				'account_id' => Row::int($row, 'account_id'),
				'user_id'    => Row::int($row, 'user_id'),
				'changed_at' => Row::int($row, 'changed_at'),
				'action'     => Row::string($row, 'action'),
				'fields'     => $fields === '' ? [] : explode(',', $fields),
			];
		}

		return $entries;
	}

	private function inTransaction(): bool {
		return (int) db_fetch_cell_prepared('SELECT @@in_transaction', [], '', true, $this->connection) === 1;
	}

	/**
	 * @param list<string> $fields
	 */
	private function audit(int $accountId, int $userId, string $action, array $fields): void {
		db_execute_prepared('INSERT INTO plugin_cloud_audit (account_id, user_id, changed_at, action, fields) VALUES (?, ?, ?, ?, ?)', [$accountId, $userId, $this->clock->now(), $action, implode(',', $fields)], true, $this->connection);
	}

	/**
	 * @param list<array<array-key, mixed>> $rows
	 * @return list<Account>
	 */
	private function accounts(array $rows): array {
		$accounts = [];

		foreach ($rows as $row) {
			$account = Account::fromRow($row);

			if ($account === null) {
				Log::warning(sprintf('Account row %d has an unknown provider or credential source and was skipped', Row::int($row, 'id')));

				continue;
			}

			$accounts[] = $account;
		}

		return $accounts;
	}
}
