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
 * The last collector and discovery result for one account, as stored in
 * plugin_cloud_health.
 */
final readonly class AccountHealth {
	public function __construct(
		public int $accountId,
		public int $runStarted = 0,
		public int $runFinished = 0,
		public float $runDuration = 0.0,
		public CollectionState $collection = CollectionState::Never,
		public ?ErrorClass $error = null,
		public int $requests = 0,
		public int $seriesTotal = 0,
		public int $seriesRead = 0,
		public int $discoveredAt = 0,
		public CollectionState $discovery = CollectionState::Never,
	) {
	}

	/**
	 * @param array<array-key, mixed> $row
	 */
	public static function fromRow(array $row): ?self {
		$id = Row::int($row, 'account_id', 0, 1);

		if ($id === 0) {
			return null;
		}

		$error = Row::string($row, 'error_class');

		return new self(
			accountId: $id,
			runStarted: Row::int($row, 'run_started', 0, 0),
			runFinished: Row::int($row, 'run_finished', 0, 0),
			runDuration: max(0.0, Row::float($row, 'run_duration') ?? 0.0),
			collection: CollectionState::tryFrom(Row::string($row, 'collection')) ?? CollectionState::Never,
			error: $error === '' ? null : ErrorClass::tryFrom($error),
			requests: Row::int($row, 'requests', 0, 0),
			seriesTotal: Row::int($row, 'series_total', 0, 0),
			seriesRead: Row::int($row, 'series_read', 0, 0),
			discoveredAt: Row::int($row, 'discovered_at', 0, 0),
			discovery: CollectionState::tryFrom(Row::string($row, 'discovery')) ?? CollectionState::Never,
		);
	}
}
