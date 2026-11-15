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

use Somethingwithproof\Cloud\SeriesRepository;

/*
 * Series sync against the database stubs: account 4's scope device is host
 * 7, and its one recorded series reads do_cpu_pct from data source 5.
 */

/**
 * @param list<array<string, mixed>>|false $sources the scope device's data sources
 */
function cloud_series_world(array|false $sources): void {
	$GLOBALS['__cloud_db_exec'] = [];
	$GLOBALS['__cloud_db_rows'] = [
		'FROM plugin_cloud_device' => [['host_id' => 7]],
		'FROM data_local'          => $sources,
		'FROM plugin_cloud_series' => [['local_data_id' => 5, 'metric' => 'do_cpu_pct']],
	];
}

/**
 * @return list<string> the mutating statements the sync ran
 */
function cloud_series_writes(): array {
	return array_values(array_filter(
		array_map(static fn (array $exec): string => strtok(trim($exec['sql']), ' '), $GLOBALS['__cloud_db_exec']),
		static fn (string $verb): bool => in_array($verb, ['DELETE', 'INSERT'], true)
	));
}

it('replaces the series whose data sources changed', function () {
	cloud_series_world([['local_data_id' => 6, 'metric' => 'do_load_1', 'resource_id' => 11]]);

	expect((new SeriesRepository(false))->sync(4))->toBeTrue()
		->and(cloud_series_writes())->toBe(['DELETE', 'INSERT']);
});

it('deletes nothing when the scope device\'s data sources cannot be read', function () {
	cloud_series_world(false);

	expect((new SeriesRepository(false))->sync(4))->toBeFalse()
		->and(cloud_series_writes())->toBe([]);
});
