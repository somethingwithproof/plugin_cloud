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

use Somethingwithproof\Cloud\FixedClock;
use Somethingwithproof\Cloud\LeaseRepository;
use Somethingwithproof\Cloud\SeriesRepository;

/*
 * Series sync against the database stubs: account 4's scope device is host
 * 7, its one recorded series reads do_cpu_pct of resource 11 from data
 * source 5, and run-1 holds its lease unless a test says otherwise.
 */

/**
 * @param list<array<string, mixed>>|false $sources  the scope device's data sources
 * @param list<array<string, mixed>>|null  $recorded series rows of the account or its device
 */
function cloud_series_world(array|false $sources, bool $held = true, ?array $recorded = null): void {
	$GLOBALS['__cloud_db_exec'] = [];
	$GLOBALS['__cloud_db_rows'] = [
		'FROM plugin_cloud_device' => [['host_id' => 7]],
		'FROM data_local'          => $sources,
		'FROM plugin_cloud_lease'  => [['held' => $held ? 1 : 0]],
		'FROM plugin_cloud_series' => $recorded
			?? [['local_data_id' => 5, 'metric' => 'do_cpu_pct', 'resource_id' => 11, 'account_id' => 4]],
	];
}

/**
 * @return list<array{0: string, 1: list<mixed>}> each mutating statement's verb and parameters
 */
function cloud_series_mutations(): array {
	$out = [];

	foreach ($GLOBALS['__cloud_db_exec'] as $exec) {
		$verb = strtok(trim($exec['sql']), ' ');

		if (in_array($verb, ['DELETE', 'INSERT'], true)) {
			$out[] = [$verb, $exec['params']];
		}
	}

	return $out;
}

function cloud_series_sync(): bool {
	return (new SeriesRepository(false))->sync(4, new LeaseRepository(false, new FixedClock(1_800_000_000)), 'run-1');
}

/**
 * @return list<string> the transaction and mutating statements the sync ran
 */
function cloud_series_writes(): array {
	return array_values(array_filter(
		array_map(static fn (array $exec): string => strtok(trim($exec['sql']), ' '), $GLOBALS['__cloud_db_exec']),
		static fn (string $verb): bool => in_array($verb, ['BEGIN', 'DELETE', 'INSERT', 'COMMIT', 'ROLLBACK'], true)
	));
}

it('replaces the series whose data sources changed', function () {
	cloud_series_world([['local_data_id' => 6, 'metric' => 'do_load_1', 'resource_id' => 11]]);

	expect(cloud_series_sync())->toBeTrue()
		->and(cloud_series_writes())->toBe(['BEGIN', 'DELETE', 'INSERT', 'COMMIT']);
});

it('deletes nothing when the scope device\'s data sources cannot be read', function () {
	cloud_series_world(false);

	expect(cloud_series_sync())->toBeFalse()
		->and(cloud_series_writes())->toBe([]);
});

it('changes nothing once another run holds the lease', function () {
	cloud_series_world([['local_data_id' => 6, 'metric' => 'do_load_1', 'resource_id' => 11]], false);

	expect(cloud_series_sync())->toBeFalse()
		->and(cloud_series_writes())->toBe(['BEGIN', 'ROLLBACK']);
});

it('rolls back when a write leaves the transaction, as a deadlock retry does', function () {
	cloud_series_world([['local_data_id' => 6, 'metric' => 'do_load_1', 'resource_id' => 11]]);
	// The delete runs, but InnoDB has ended the transaction by then.
	$GLOBALS['__cloud_in_tx'] = [0, 0];

	expect(cloud_series_sync())->toBeFalse()
		->and(cloud_series_writes())->toBe(['BEGIN', 'DELETE']);

	$GLOBALS['__cloud_in_tx'] = [];
});

it('keeps a series whose data source, resource and account are unchanged', function () {
	cloud_series_world([['local_data_id' => 5, 'metric' => 'do_cpu_pct', 'resource_id' => 11]]);

	expect(cloud_series_sync())->toBeTrue()
		->and(cloud_series_writes())->toBe(['BEGIN', 'COMMIT']);
});

it('replaces a series whose data source an operator moved to another resource', function () {
	cloud_series_world([['local_data_id' => 5, 'metric' => 'do_cpu_pct', 'resource_id' => 12]]);

	expect(cloud_series_sync())->toBeTrue()
		->and(cloud_series_mutations())->toBe([
			['DELETE', [5, 'do_cpu_pct', 11, 4]],
			['INSERT', [5, 4, 12, 'do_cpu_pct']],
		]);
});

it('replaces another account\'s series whose data source is now on this account\'s device', function () {
	cloud_series_world(
		[['local_data_id' => 5, 'metric' => 'do_cpu_pct', 'resource_id' => 11]],
		true,
		[['local_data_id' => 5, 'metric' => 'do_cpu_pct', 'resource_id' => 30, 'account_id' => 3]]
	);

	expect(cloud_series_sync())->toBeTrue()
		->and(cloud_series_mutations())->toBe([
			['DELETE', [5, 'do_cpu_pct', 30, 3]],
			['INSERT', [5, 4, 11, 'do_cpu_pct']],
		]);
});
