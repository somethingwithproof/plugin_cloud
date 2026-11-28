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
 * The observation cache (REQ-STATE-001): newest complete datapoint per
 * resource and metric, its provider timestamp, and when it was fetched.
 *
 * Each write is one statement that inserts only while the writer's lease is
 * unexpired, so a collector that lost its lease cannot overwrite a newer
 * run's values. An older datapoint never replaces a newer one.
 */
final class ObservationRepository {
	/**
	 * Construct an observation repository on the given database connection.
	 *
	 * @param \PDO|false $connection Cacti PDO handle or false for the default connection
	 */
	public function __construct(private readonly \PDO|false $connection) {
	}

	/**
	 * Persist a datapoint while the collector still holds an unexpired lease.
	 *
	 * @param int         $accountId   cloud account ID
	 * @param string      $holder      active lease holder identifier
	 * @param int         $now         current Unix timestamp
	 * @param Observation $observation datapoint to store
	 * @return void
	 */
	public function write(int $accountId, string $holder, int $now, Observation $observation): void {
		db_execute_prepared('INSERT INTO plugin_cloud_observation
				(resource_id, metric, value, observed_at, fetched_at, status)
			SELECT ?, ?, ?, ?, ?, ? FROM plugin_cloud_lease l WHERE l.account_id = ? AND l.holder = ? AND l.expires_at >= ?
			ON DUPLICATE KEY UPDATE
				value = IF(VALUES(observed_at) >= observed_at, VALUES(value), value),
				status = IF(VALUES(observed_at) >= observed_at, VALUES(status), status),
				fetched_at = IF(VALUES(observed_at) >= observed_at, VALUES(fetched_at), fetched_at),
				observed_at = GREATEST(observed_at, VALUES(observed_at))', [
			$observation->resourceId, $observation->metric, $observation->value, $observation->observedAt,
			$observation->fetchedAt, $observation->value === null ? 'unknown' : 'ok',
			$accountId, $holder, $now,
		], true, $this->connection);
	}

	/**
	 * Record why series were not read this run, such as budget, keeping the
	 * last datapoint so freshness still ages from it.
	 *
	 * @param list<MetricRequest> $requests
	 */
	public function markUnread(int $accountId, string $holder, int $now, array $requests, string $reason): void {
		foreach ($requests as $request) {
			db_execute_prepared('INSERT INTO plugin_cloud_observation
					(resource_id, metric, value, observed_at, fetched_at, status)
				SELECT ?, ?, NULL, 0, ?, ? FROM plugin_cloud_lease l WHERE l.account_id = ? AND l.holder = ? AND l.expires_at >= ?
				ON DUPLICATE KEY UPDATE status = VALUES(status)', [
				$request->resourceId, $request->metric, $now, $reason, $accountId, $holder, $now,
			], true, $this->connection);
		}
	}

	/**
	 * Look up the cached observation for one resource and metric.
	 *
	 * @param int    $resourceId internal resource ID
	 * @param string $metric     metric name
	 * @return Observation|null cached observation, or null when absent
	 */
	public function find(int $resourceId, string $metric): ?Observation {
		$row = db_fetch_row_prepared(
			'SELECT * FROM plugin_cloud_observation WHERE resource_id = ? AND metric = ?',
			[$resourceId, $metric],
			true,
			$this->connection
		);

		return is_array($row) ? Observation::fromRow($row) : null;
	}

	/**
	 * @return array<string, array{observation: Observation, status: string}> metric => entry
	 */
	public function forResource(int $resourceId): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared(
			'SELECT * FROM plugin_cloud_observation WHERE resource_id = ?',
			[$resourceId],
			true,
			$this->connection
		)) as $row) {
			$observation = Observation::fromRow($row);

			if ($observation !== null) {
				$out[$observation->metric] = ['observation' => $observation, 'status' => Row::string($row, 'status')];
			}
		}

		return $out;
	}

	/**
	 * @return list<array{observation: Observation, status: string}>
	 */
	public function forAccount(int $accountId): array {
		$out = [];

		foreach (Row::rows(db_fetch_assoc_prepared(
			'SELECT o.* FROM plugin_cloud_observation o INNER JOIN plugin_cloud_resource r ON r.id = o.resource_id
				WHERE r.account_id = ?',
			[$accountId],
			true,
			$this->connection
		)) as $row) {
			$observation = Observation::fromRow($row);

			if ($observation !== null) {
				$out[] = ['observation' => $observation, 'status' => Row::string($row, 'status')];
			}
		}

		return $out;
	}
}
