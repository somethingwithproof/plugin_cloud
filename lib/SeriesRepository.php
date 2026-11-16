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
 * The series to collect: plugin data sources whose Cacti data source is
 * active, on active resources (REQ-COLL-005, ADR 0005). A data source the
 * plugin did not create has no row here and is never read.
 */
final class SeriesRepository {
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Rebuild an account's series from the Cacti data sources on its scope
	 * device (ADR 0010): one row per data source and catalog metric whose
	 * data query index is one of the account's resources. Age fields have
	 * no row, since they read nothing. Cacti's tables are only read.
	 *
	 * An account without a live scope device keeps its rows, and so does one
	 * whose data sources could not be read: a failed query is not an empty
	 * device.
	 *
	 * A row is the data source, metric, resource and account together, so
	 * a data source an operator moved to another resource index, or to this
	 * account's device from another account's, replaces its old row rather
	 * than keeping it. Rows of other accounts whose data source is now on
	 * this device are stale and go too; their own collector may never run
	 * again to remove them.
	 *
	 * The rows change in one transaction that first locks the caller's lease
	 * and checks it is still held, so a run that lost its lease while it read
	 * cannot replace a newer holder's rows from its older snapshot.
	 *
	 * Core's database wrapper retries a statement that hit a deadlock or a
	 * lock wait timeout, but InnoDB has by then rolled the transaction back,
	 * so the retry would run on its own, outside the lease's lock. Core
	 * retries only statements it logs, so every statement in the transaction
	 * runs unlogged and fails instead, and the transaction is checked to be
	 * still open before each one. Any failure rolls back and stops; the next
	 * collector run syncs again.
	 *
	 * @return bool whether the series were rebuilt
	 */
	public function sync(int $accountId, LeaseRepository $leases, string $holder): bool {
		$host = (int) db_fetch_cell_prepared(
			'SELECT d.host_id FROM plugin_cloud_device d INNER JOIN host h ON h.id = d.host_id
				WHERE d.account_id = ? AND h.external_id = CONCAT(?, d.account_id)',
			[$accountId, DeviceRepository::EXTERNAL_PREFIX],
			'',
			true,
			$this->connection
		);

		if ($host <= 0) {
			return false;
		}

		$wanted = [];

		foreach ([DigitalOceanCatalog::DROPLET, DigitalOceanCatalog::LOAD_BALANCER] as $type) {
			// Cacti's tables and the plugin's can differ in collation; ids match exactly.
			$rows = db_fetch_assoc_prepared(
				'SELECT dl.id AS local_data_id, dtr.data_source_name AS metric, r.id AS resource_id
					FROM data_local dl
					INNER JOIN snmp_query q ON q.id = dl.snmp_query_id AND q.hash = ?
					INNER JOIN data_template_rrd dtr ON dtr.local_data_id = dl.id
					INNER JOIN plugin_cloud_resource r ON r.account_id = ? AND r.type = ?
						AND BINARY r.provider_id = BINARY dl.snmp_index
					WHERE dl.host_id = ?',
				[TemplateRepository::hash('data_query/' . $type), $accountId, $type, $host],
				true,
				$this->connection
			);

			if (!is_array($rows)) {
				Log::warning(sprintf('Account %d: scope device data sources unreadable; series left unchanged', $accountId));

				return false;
			}

			foreach (Row::rows($rows) as $row) {
				$metric = Row::string($row, 'metric');

				if ((DigitalOceanCatalog::metric($metric)['type'] ?? null) === $type) {
					$series = [Row::int($row, 'local_data_id'), $metric, Row::int($row, 'resource_id'), $accountId];

					$wanted[implode('/', $series)] = $series;
				}
			}
		}

		if (!db_begin_transaction($this->connection)) {
			return false;
		}

		if (!$leases->holdsForUpdate($accountId, $holder) || !$this->inTransaction()) {
			$this->rollback();

			return false;
		}

		$existing = [];
		$rows     = db_fetch_assoc_prepared(
			'SELECT s.local_data_id, s.metric, s.resource_id, s.account_id FROM plugin_cloud_series s
				LEFT JOIN data_local dl ON dl.id = s.local_data_id
				WHERE s.account_id = ? OR dl.host_id = ?',
			[$accountId, $host],
			false,
			$this->connection
		);

		if (!is_array($rows) || !$this->inTransaction()) {
			return $this->abandon($accountId);
		}

		foreach (Row::rows($rows) as $row) {
			$series = [
				Row::int($row, 'local_data_id'),
				Row::string($row, 'metric'),
				Row::int($row, 'resource_id'),
				Row::int($row, 'account_id'),
			];

			$existing[implode('/', $series)] = $series;
		}

		$writes = [];

		// Deletes first: a replaced row holds the data source and metric key
		// its replacement needs.
		foreach (array_diff_key($existing, $wanted) as [$local, $metric, $resource, $account]) {
			$writes[] = [
				'DELETE FROM plugin_cloud_series
					WHERE local_data_id = ? AND metric = ? AND resource_id = ? AND account_id = ?',
				[$local, $metric, $resource, $account],
			];
		}

		foreach (array_diff_key($wanted, $existing) as [$local, $metric, $resource]) {
			$writes[] = [
				'INSERT INTO plugin_cloud_series (local_data_id, account_id, resource_id, metric, created_at)
					VALUES (?, ?, ?, ?, UNIX_TIMESTAMP())',
				[$local, $accountId, $resource, $metric],
			];
		}

		foreach ($writes as [$sql, $params]) {
			if (!$this->inTransaction() || db_execute_prepared($sql, $params, false, $this->connection) === false) {
				return $this->abandon($accountId);
			}
		}

		return $this->inTransaction() && db_commit_transaction($this->connection) === true;
	}

	private function abandon(int $accountId): bool {
		$this->rollback();
		Log::warning(sprintf('Account %d: series sync rolled back; the next run retries it', $accountId));

		return false;
	}

	/**
	 * InnoDB can end the transaction under any statement.
	 *
	 * @phpstan-impure
	 */
	private function inTransaction(): bool {
		return (int) db_fetch_cell_prepared('SELECT @@in_transaction', [], '', true, $this->connection) === 1;
	}

	private function rollback(): void {
		if ($this->inTransaction()) {
			db_rollback_transaction($this->connection);
		}
	}

	/**
	 * @return list<MetricRequest>
	 */
	public function enabledFor(int $accountId): array {
		$requests = [];
		$rows     = db_fetch_assoc_prepared("SELECT DISTINCT s.resource_id, r.type, r.provider_id, s.metric
			FROM plugin_cloud_series s
			INNER JOIN plugin_cloud_resource r ON r.id = s.resource_id AND r.account_id = s.account_id
			INNER JOIN data_template_data d ON d.local_data_id = s.local_data_id
			WHERE s.account_id = ? AND r.lifecycle = ? AND d.active = 'on'
			ORDER BY s.resource_id, s.metric", [$accountId, Lifecycle::Active->value], true, $this->connection);

		foreach (Row::rows($rows) as $row) {
			$requests[] = new MetricRequest(
				Row::int($row, 'resource_id'),
				Row::string($row, 'type'),
				Row::string($row, 'provider_id'),
				Row::string($row, 'metric')
			);
		}

		return $requests;
	}
}
