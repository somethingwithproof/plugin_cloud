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
 * The newest complete datapoint of one series. A null value is unknown,
 * never zero.
 */
final readonly class Observation {
	public function __construct(
		public int $resourceId,
		public string $metric,
		public ?float $value,
		public int $observedAt,
		public int $fetchedAt,
	) {
	}

	/**
	 * @param array<array-key, mixed> $row
	 */
	public static function fromRow(array $row): ?self {
		$resource = Row::int($row, 'resource_id', 0, 1);
		$metric   = Row::string($row, 'metric');

		if ($resource === 0 || $metric === '') {
			return null;
		}

		return new self($resource, $metric, Row::float($row, 'value'), Row::int($row, 'observed_at', 0, 0), Row::int($row, 'fetched_at', 0, 0));
	}
}
