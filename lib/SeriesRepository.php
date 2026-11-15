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
			$requests[] = new MetricRequest(Row::int($row, 'resource_id'), Row::string($row, 'type'), Row::string($row, 'provider_id'), Row::string($row, 'metric'));
		}

		return $requests;
	}
}
