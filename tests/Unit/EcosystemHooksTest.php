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

use Somethingwithproof\Cloud\Abilities;
use Somethingwithproof\Cloud\Account;
use Somethingwithproof\Cloud\AccountRepository;
use Somethingwithproof\Cloud\CredentialError;
use Somethingwithproof\Cloud\DoctorChecks;
use Somethingwithproof\Cloud\Drivers;
use Somethingwithproof\Cloud\FixedClock;
use Somethingwithproof\Cloud\HealthRepository;
use Somethingwithproof\Cloud\ResourceRepository;
use Somethingwithproof\Cloud\Schema;
use Somethingwithproof\Cloud\Settings;

beforeEach(function () {
	$GLOBALS['__cloud_db_rows'] = [];
	$GLOBALS['__cloud_db_exec'] = [];
});

afterEach(function () {
	$GLOBALS['__cloud_db_rows'] = [];
	$GLOBALS['__cloud_db_exec'] = [];
});

it('registers strictly read-only MCP abilities gated on Cloud View and returning zero secrets', function () {
	$clock   = new FixedClock(1731542400);
	$account = Account::fromRow([
		'id'                => 7,
		'provider'          => 'aws',
		'name'              => 'aws-prod',
		'poller_id'         => 1,
		'scope'             => json_encode(['account_id' => '123456789012', 'regions' => 'us-east-1']),
		'credential_source' => 'file',
		'credential_file'   => '/etc/cacti/aws-secret.json',
		'admin_state'       => 'enabled',
		'verify_status'     => 'ok',
	]);

	$GLOBALS['__cloud_db_rows'] = [
		'FROM plugin_cloud_resource WHERE account_id = ?' => [
			[
				'id'          => 11,
				'account_id'  => 7,
				'type'        => 'ec2_instance',
				'provider_id' => 'us-east-1/i-0123456789abcdef0',
				'name'        => 'web-1',
				'region'      => 'us-east-1',
				'lifecycle'   => 'active',
				'first_seen'  => 1731500000,
				'last_seen'   => 1731542000,
			],
		],
		'FROM plugin_cloud_health' => [
			[
				'account_id'   => 7,
				'collection'   => 'ok',
				'discovery'    => 'ok',
				'series_read'  => 5,
				'series_total' => 5,
				'requests'     => 1,
			],
		],
	];

	$allowed = true;
	$hub     = new Abilities(
		static function () use (&$allowed): bool {
			return $allowed;
		},
		static fn (): array => [$account],
		new ResourceRepository(false, $clock),
		new HealthRepository(false),
		$clock
	);

	$defs = $hub->definitions();
	expect(array_keys($defs))->toBe(['cloud-list-accounts', 'cloud-list-resources', 'cloud-get-health']);

	foreach ($defs as $def) {
		expect($def['read_only'])->toBeTrue();
	}

	$accOut = $defs['cloud-list-accounts']['callback']([]);
	$resOut = $defs['cloud-list-resources']['callback'](['account_id' => 7]);
	$hltOut = $defs['cloud-get-health']['callback']([]);

	$serialized = (string) json_encode([$accOut, $resOut, $hltOut]);

	expect($accOut['accounts'][0]['name'])->toBe('aws-prod')
		->and($resOut['resources'][0]['provider_id'])->toBe('us-east-1/i-0123456789abcdef0')
		->and($hltOut['accounts'][0]['collection'])->toBe('ok')
		->and($serialized)->not->toContain('/etc/cacti/aws-secret.json')
		->and($serialized)->not->toContain('credential_file');

	// Denied when Cloud View realm is missing.
	$allowed = false;
	expect($defs['cloud-list-accounts']['permission_callback']())->toBeFalse()
		->and($defs['cloud-list-accounts']['callback']([]))->toBe(['error' => 'permission_denied', 'accounts' => []])
		->and($defs['cloud-list-resources']['callback']([]))->toBe(['error' => 'permission_denied', 'resources' => []])
		->and($defs['cloud-get-health']['callback']([]))->toBe(['error' => 'permission_denied', 'accounts' => []]);
});

it('runs all five Doctor diagnostic checks across healthy and degraded states', function () {
	$clock = new FixedClock(1731542400);
	$GLOBALS['__cloud_db_rows'] = [
		'FROM settings WHERE name = ?' => [['value' => (string) Schema::VERSION]],
		'FROM plugin_cloud_account ORDER BY name' => [
			[
				'id'                => 1,
				'provider'          => 'aws',
				'name'              => 'aws-prod',
				'poller_id'         => 1,
				'credential_source' => 'vault',
				'collect_interval'  => 300,
				'admin_state'       => 'enabled',
			],
		],
		'FROM plugin_cloud_health' => [
			[
				'account_id'  => 1,
				'run_started' => 1731542300,
				'collection'  => 'ok',
			],
		],
	];

	$doctor = new DoctorChecks(
		new Schema(false),
		Settings::fromRow([]),
		new AccountRepository(false, $clock),
		new HealthRepository(false),
		Drivers::production(),
		$clock,
		300,
		static function (string $path): void {
		}
	);

	$checks = $doctor->checks();
	expect(array_column($checks, 'id'))->toBe([
		'cloud_schema',
		'cloud_vault_key',
		'cloud_drivers',
		'cloud_accounts_health',
		'cloud_poller_intervals',
	]);

	foreach ($checks as $check) {
		expect($check['callback']()['status'])->toBe('ok', $check['id']);
	}

	// Degraded state: unreadable vault key, throttled account, misaligned interval.
	$GLOBALS['__cloud_db_rows']['FROM plugin_cloud_account ORDER BY name'][0]['collect_interval'] = 450;
	$GLOBALS['__cloud_db_rows']['FROM plugin_cloud_health'][0]['retry_at']                       = 1731543000;

	$degraded = new DoctorChecks(
		new Schema(false),
		Settings::fromRow([]),
		new AccountRepository(false, $clock),
		new HealthRepository(false),
		Drivers::production(),
		$clock,
		300,
		static function (string $path): void {
			throw CredentialError::because('key file missing');
		}
	);

	expect($degraded->checkVaultKey()['status'])->toBe('error')
		->and($degraded->checkAccountHealth()['status'])->toBe('warning')
		->and($degraded->checkIntervals()['status'])->toBe('warning');
});

it('wires ecosystem registration hooks in setup.php cleanly', function () {
	$abilities = cloud_abilities_register([]);
	$checks    = cloud_doctor_register_checks([]);
	$providers = cloud_casc_register_providers([]);

	expect(array_keys($abilities))->toBe(['cloud-list-accounts', 'cloud-list-resources', 'cloud-get-health'])
		->and(array_column($checks, 'id'))->toContain('cloud_schema', 'cloud_vault_key', 'cloud_drivers')
		->and($providers)->toHaveKey('cloud')
		->and($providers['cloud']['formats'])->toBe(['yaml', 'xml']);
});
