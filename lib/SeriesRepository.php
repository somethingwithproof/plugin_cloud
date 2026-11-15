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
	 * @return bool whether the series were rebuilt
	 */
	public function sync(int $accountId): bool {
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
					$wanted[Row::int($row, 'local_data_id') . '/' . $metric] = [
						Row::int($row, 'local_data_id'),
						$metric,
						Row::int($row, 'resource_id'),
					];
				}
			}
		}

		$existing = [];
		$rows     = db_fetch_assoc_prepared(
			'SELECT local_data_id, metric FROM plugin_cloud_series WHERE account_id = ?',
			[$accountId],
			true,
			$this->connection
		);

		if (!is_array($rows)) {
			return false;
		}

		foreach (Row::rows($rows) as $row) {
			$existing[Row::int($row, 'local_data_id') . '/' . Row::string($row, 'metric')] = [
				Row::int($row, 'local_data_id'),
				Row::string($row, 'metric'),
			];
		}

		foreach (array_diff_key($existing, $wanted) as [$local, $metric]) {
			db_execute_prepared(
				'DELETE FROM plugin_cloud_series WHERE account_id = ? AND local_data_id = ? AND metric = ?',
				[$accountId, $local, $metric],
				true,
				$this->connection
			);
		}

		foreach (array_diff_key($wanted, $existing) as [$local, $metric, $resource]) {
			db_execute_prepared(
				'INSERT IGNORE INTO plugin_cloud_series (local_data_id, account_id, resource_id, metric, created_at)
					VALUES (?, ?, ?, ?, UNIX_TIMESTAMP())',
				[$local, $accountId, $resource, $metric],
				true,
				$this->connection
			);
		}

		return true;
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
