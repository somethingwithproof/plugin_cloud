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

use Somethingwithproof\Cloud\Account;
use Somethingwithproof\Cloud\DeviceRepository;
use Somethingwithproof\Cloud\FixedClock;
use Somethingwithproof\Cloud\ScopeDevices;
use Somethingwithproof\Cloud\TemplateRepository;

/*
 * Scope device creation against the database stubs, with Cacti's device
 * API replaced by closures that record their calls. Account 4 has no live
 * scope device; Cacti saves its new one as host 42.
 */

function cloud_scope_world(): void {
	$GLOBALS['__cloud_db_exec'] = [];
	$GLOBALS['__cloud_db_fail'] = [];
	$GLOBALS['__cloud_log']     = [];
	$GLOBALS['__cloud_db_rows'] = [
		'FROM plugin_cloud_device' => [],
		'FROM `host_template`'     => [['id' => 3]],
		'FROM `snmp_query`'        => [['id' => 9]],
		'GET_LOCK'                 => [['locked' => 1]],
	];
	$GLOBALS['__cloud_scope'] = ['saved' => [], 'reindexed' => [], 'indexed' => [], 'cleared' => [], 'removed' => []];
}

function cloud_scope_devices_under_test(): ScopeDevices {
	return new ScopeDevices(
		new DeviceRepository(false, new FixedClock(1_800_000_000)),
		new TemplateRepository(false),
		static function (Account $account, int $template): int {
			$GLOBALS['__cloud_scope']['saved'][] = $template;

			return 42;
		},
		static function (int $host, int $query, int $method): void {
			$GLOBALS['__cloud_scope']['reindexed'][] = [$host, $query, $method];
		},
		static function (int $host, int $query): bool {
			$GLOBALS['__cloud_scope']['indexed'][] = [$host, $query];

			return $GLOBALS['__cloud_scope']['index_ok'] ?? true;
		},
		static function (int $host, int $query): void {
			$GLOBALS['__cloud_scope']['cleared'][] = [$host, $query];
		},
		static function (int $host): void {
			$GLOBALS['__cloud_scope']['removed'][] = $host;
		}
	);
}

function cloud_scope_account(): Account {
	$account = Account::fromRow([
		'id'                => 4,
		'provider'          => 'digitalocean',
		'name'              => 'Ops',
		'credential_source' => 'vault',
		'admin_state'       => 'enabled',
	]);

	expect($account)->not->toBeNull();

	return $account;
}

it('links the device it saved and indexes its data queries, reindexing on an index count change', function () {
	cloud_scope_world();

	expect(cloud_scope_devices_under_test()->create(cloud_scope_account()))->toBe(42)
		->and($GLOBALS['__cloud_scope']['saved'])->toBe([3])
		->and($GLOBALS['__cloud_scope']['reindexed'])->toBe([[42, 9, 2], [42, 9, 2]])
		->and($GLOBALS['__cloud_scope']['removed'])->toBe([]);
});

it('removes the device it saved when the link is not recorded, and reports failure', function () {
	cloud_scope_world();
	$GLOBALS['__cloud_db_fail'] = ['INSERT INTO plugin_cloud_device'];

	expect(cloud_scope_devices_under_test()->create(cloud_scope_account()))->toBe(0)
		->and($GLOBALS['__cloud_scope']['removed'])->toBe([42])
		->and($GLOBALS['__cloud_scope']['reindexed'])->toBe([])
		->and(implode("\n", array_column($GLOBALS['__cloud_log'], 'message')))->toContain('ERROR: Account 4');

	$GLOBALS['__cloud_db_fail'] = [];
});

it('creates nothing while another request holds the account\'s creation lock', function () {
	cloud_scope_world();
	$GLOBALS['__cloud_db_rows']['GET_LOCK'] = [['locked' => 0]];

	expect(cloud_scope_devices_under_test()->create(cloud_scope_account()))->toBe(0)
		->and($GLOBALS['__cloud_scope']['saved'])->toBe([])
		->and(array_column($GLOBALS['__cloud_db_exec'], 'sql'))->not->toContain('DO RELEASE_LOCK(?)');
});

it('returns the device another request created while it waited for the lock', function () {
	cloud_scope_world();
	$lookups = 0;

	$GLOBALS['__cloud_db_rows']['FROM plugin_cloud_device'] = static function () use (&$lookups): array {
		return ++$lookups === 1 ? [] : [['host_id' => 77]];
	};

	expect(cloud_scope_devices_under_test()->create(cloud_scope_account()))->toBe(77)
		->and($GLOBALS['__cloud_scope']['saved'])->toBe([])
		->and(array_column($GLOBALS['__cloud_db_exec'], 'sql'))->toContain('DO RELEASE_LOCK(?)');
});

/**
 * Host 42 is account 4's device, with the Droplet query 9 and the load
 * balancer query 10, and Cacti has indexed Droplets 101 and 102.
 */
function cloud_scope_indexed_world(): void {
	cloud_scope_world();
	$ids = [9, 10];
	$GLOBALS['__cloud_db_rows']['FROM `snmp_query`'] = static function () use (&$ids): array {
		$id    = array_shift($ids) ?? 0;
		$ids[] = $id;

		return [['id' => $id]];
	};
	$GLOBALS['__cloud_db_rows']['FROM host_snmp_cache'] = [
		['snmp_index' => '101', 'field_value' => 'web-1'],
		['snmp_index' => '102', 'field_value' => 'web-2'],
	];
	$GLOBALS['__cloud_db_rows']['FROM host_snmp_query'] = [
		['snmp_query_id' => 9, 'reindex_method' => 2],
		['snmp_query_id' => 10, 'reindex_method' => 2],
	];
	$GLOBALS['__cloud_db_rows']['FROM plugin_cloud_device'] = [['host_id' => 42]];
}

it('indexes the account\'s device again when Cacti\'s index is stale, under the account\'s lock', function () {
	cloud_scope_indexed_world();

	expect(cloud_scope_devices_under_test()->refresh(4, ['droplet' => ['102' => 'web-2', '101' => 'web-1'], 'load_balancer' => []]))->toBeTrue()
		->and($GLOBALS['__cloud_scope']['indexed'])->toBe([]);

	expect(cloud_scope_devices_under_test()->refresh(4, ['droplet' => ['101' => 'web-1'], 'load_balancer' => ['lb-1' => 'front']]))->toBeTrue()
		->and($GLOBALS['__cloud_scope']['indexed'])->toBe([[42, 9], [42, 10]])
		->and($GLOBALS['__cloud_scope']['cleared'])->toBe([])
		->and(array_column($GLOBALS['__cloud_db_exec'], 'sql'))->toContain('DO RELEASE_LOCK(?)');
});

it('empties Cacti\'s index of a data query whose resources are all gone, rather than running it', function () {
	cloud_scope_indexed_world();

	expect(cloud_scope_devices_under_test()->refresh(4, ['droplet' => [], 'load_balancer' => ['lb-1' => 'front']]))->toBeTrue()
		->and($GLOBALS['__cloud_scope']['cleared'])->toBe([[42, 9]])
		->and($GLOBALS['__cloud_scope']['indexed'])->toBe([[42, 10]]);
});

it('reports a data query that failed to run, so the next discovery retries it', function () {
	cloud_scope_indexed_world();
	$GLOBALS['__cloud_scope']['index_ok'] = false;

	expect(cloud_scope_devices_under_test()->refresh(4, ['droplet' => ['103' => 'web-3'], 'load_balancer' => []]))->toBeFalse()
		->and(implode("\n", array_column($GLOBALS['__cloud_log'], 'message')))->toContain('data query 9 failed');
});

it('indexes nothing while the account\'s device is being created, or when it has none', function () {
	cloud_scope_world();
	$GLOBALS['__cloud_db_rows']['FROM host_snmp_cache'] = [];

	expect(cloud_scope_devices_under_test()->refresh(4, ['droplet' => ['101' => 'web-1'], 'load_balancer' => []]))->toBeTrue()
		->and($GLOBALS['__cloud_scope']['indexed'])->toBe([]);

	$GLOBALS['__cloud_db_rows']['FROM plugin_cloud_device'] = [['host_id' => 42]];
	$GLOBALS['__cloud_db_rows']['GET_LOCK']                 = [['locked' => 0]];

	expect(cloud_scope_devices_under_test()->refresh(4, ['droplet' => ['101' => 'web-1'], 'load_balancer' => []]))->toBeFalse()
		->and($GLOBALS['__cloud_scope']['indexed'])->toBe([]);
});
