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

use Somethingwithproof\Cloud\DeviceRepository;
use Somethingwithproof\Cloud\FixedClock;
use Somethingwithproof\Cloud\ScopeReindex;

/*
 * Reindex methods across a scope device edit, against the database stubs:
 * host 7 is a scope device whose two data queries, 9 and 10, use index
 * count (2) and verify all fields (3).
 */

/**
 * @param list<array<string, mixed>> $queries host_snmp_query rows of host 7
 */
function cloud_reindex_queries(array $queries): void {
	$GLOBALS['__cloud_db_rows'] = ['FROM host_snmp_query' => $queries];
}

/**
 * @return array{0: ScopeReindex, 1: \ArrayObject<int, list<int>>}
 */
function cloud_reindex_keeper(): array {
	$changes = new ArrayObject();

	return [
		new ScopeReindex(
			new DeviceRepository(false, new FixedClock(1_800_000_000)),
			static function (int $host, int $query, int $method) use ($changes): void {
				$changes[] = [$host, $query, $method];
			}
		),
		$changes,
	];
}

it('puts back the reindex methods a scope device save reset, and only those', function () {
	[$keeper, $changes] = cloud_reindex_keeper();

	cloud_reindex_queries([
		['snmp_query_id' => 9, 'reindex_method' => 2],
		['snmp_query_id' => 10, 'reindex_method' => 3],
	]);
	$keeper->remember(7);

	cloud_reindex_queries([
		['snmp_query_id' => 9, 'reindex_method' => 0],
		['snmp_query_id' => 10, 'reindex_method' => 3],
	]);
	$keeper->restore(7);

	expect($changes->getArrayCopy())->toBe([[7, 9, 2]]);

	// Each save is restored once.
	$keeper->restore(7);
	expect($changes->getArrayCopy())->toBe([[7, 9, 2]]);
});

it('leaves a device alone that is no scope device, or that was just created', function () {
	[$keeper, $changes] = cloud_reindex_keeper();

	cloud_reindex_queries([]);
	$keeper->remember(8);
	cloud_reindex_queries([['snmp_query_id' => 9, 'reindex_method' => 0]]);
	$keeper->restore(8);
	$keeper->restore(9);

	expect($changes->getArrayCopy())->toBe([]);
});
