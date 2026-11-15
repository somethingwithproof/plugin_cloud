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
	$GLOBALS['__cloud_scope'] = ['saved' => [], 'reindexed' => [], 'removed' => []];
}

function cloud_scope_devices_under_test(): ScopeDevices {
	return new ScopeDevices(
		new DeviceRepository(false, new FixedClock(1_800_000_000)),
		new TemplateRepository(false),
		static function (Account $account, int $template): int {
			$GLOBALS['__cloud_scope']['saved'][] = $template;

			return 42;
		},
		static function (int $host, int $query): void {
			$GLOBALS['__cloud_scope']['reindexed'][] = [$host, $query];
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

it('links the device it saved and indexes its data queries', function () {
	cloud_scope_world();

	expect(cloud_scope_devices_under_test()->create(cloud_scope_account()))->toBe(42)
		->and($GLOBALS['__cloud_scope']['saved'])->toBe([3])
		->and($GLOBALS['__cloud_scope']['reindexed'])->toBe([[42, 9], [42, 9]])
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
