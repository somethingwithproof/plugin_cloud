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
use Somethingwithproof\Cloud\ApiResources;
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
	$debugInfo = cloud_doctor_register_debug_info([]);
	$resources = cloud_api_register_resources([]);
	$providers = cloud_casc_register_providers([]);

	expect(array_keys($abilities))->toBe(['cloud-list-accounts', 'cloud-list-resources', 'cloud-get-health'])
		->and(array_column($checks, 'id'))->toContain('cloud_schema', 'cloud_vault_key', 'cloud_drivers')
		->and($debugInfo)->toHaveKey('cloud')
		->and($debugInfo['cloud']['id'])->toBe('cloud')
		->and(array_keys($resources))->toBe([
			ApiResources::TYPE_ACCOUNT,
			ApiResources::TYPE_RESOURCE,
			ApiResources::TYPE_HEALTH,
		])
		->and($providers)->toHaveKey('cloud')
		->and($providers['cloud']['formats'])->toBe(['yaml', 'xml']);
});

it('produces a redacted Doctor debugInfo snapshot standalone with zero secrets', function () {
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
				'credential_file'   => '/etc/cacti/super-secret-aws.json',
				'collect_interval'  => 300,
				'admin_state'       => 'enabled',
			],
			[
				'id'                => 2,
				'provider'          => 'gcp',
				'name'              => 'gcp-stage',
				'poller_id'         => 1,
				'credential_source' => 'file',
				'credential_file'   => '/etc/cacti/gcp-private-key.json',
				'collect_interval'  => 300,
				'admin_state'       => 'disabled',
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

	$settings = Settings::fromRow([
		'cloud_vault_key_file'   => '/etc/cacti/vault-secret-key.bin',
		'cloud_credential_dir'   => '/etc/cacti/secret-creds',
		'cloud_collector_budget' => '180',
	]);

	$doctor = new DoctorChecks(
		new Schema(false),
		$settings,
		new AccountRepository(false, $clock),
		new HealthRepository(false),
		Drivers::production(),
		$clock,
		300,
		static function (string $path): void {
		}
	);

	$info       = $doctor->debugInfo('0.1.0-dev');
	$serialized = (string) json_encode($info);

	expect($info['id'])->toBe('cloud')
		->and($info['fields']['version']['value'])->toBe('0.1.0-dev')
		->and($info['fields']['schema_version']['value'])->toBe([
			'stored'   => Schema::VERSION,
			'expected' => Schema::VERSION,
			'current'  => true,
		])
		->and($info['fields']['settings']['value'])->toMatchArray([
			'collector_budget'          => 180,
			'keep_tables_on_uninstall'  => true,
			'vault_key_configured'      => true,
			'vault_key_status'          => 'ok',
			'credential_dir_configured' => true,
			'poller_interval'           => 300,
		])
		->and($info['fields']['accounts']['value'])->toBe([
			'total'                => 2,
			'enabled'              => 1,
			'disabled'             => 1,
			'by_provider'          => ['aws' => 1, 'gcp' => 1],
			'by_credential_source' => ['file' => 1, 'vault' => 1],
		])
		->and($info['fields']['drivers']['value']['missing'])->toBe([])
		->and($serialized)->not->toContain('/etc/cacti/vault-secret-key.bin')
		->and($serialized)->not->toContain('/etc/cacti/secret-creds')
		->and($serialized)->not->toContain('super-secret-aws.json')
		->and($serialized)->not->toContain('gcp-private-key.json');
});

it('registers standalone Google AIP resource descriptors with strict permission gating and zero secrets', function () {
	$clock   = new FixedClock(1731542400);
	$account = Account::fromRow([
		'id'                => 9,
		'provider'          => 'azure',
		'name'              => 'azure-core',
		'poller_id'         => 1,
		'scope'             => json_encode(['tenant' => '0f8fad5b-d9cb-469f-a165-70867728950e', 'subscription' => '7c9e6679-7425-40de-944b-e07fc1f90ae7']),
		'credential_source' => 'file',
		'credential_file'   => '/etc/cacti/azure-client-secret.json',
		'admin_state'       => 'enabled',
		'verify_status'     => 'ok',
	]);

	$GLOBALS['__cloud_db_rows'] = [
		'FROM plugin_cloud_resource WHERE account_id = ?' => [
			[
				'id'          => 42,
				'account_id'  => 9,
				'type'        => 'azure_vm',
				'provider_id' => 'vm-prod-01',
				'name'        => 'app-node-1',
				'region'      => 'eastus',
				'lifecycle'   => 'active',
				'first_seen'  => 1731500000,
				'last_seen'   => 1731542100,
			],
		],
		'FROM plugin_cloud_health' => [
			[
				'account_id'   => 9,
				'collection'   => 'ok',
				'discovery'    => 'ok',
				'series_read'  => 3,
				'series_total' => 3,
				'requests'     => 2,
			],
		],
	];

	$allowed = true;
	$api     = new ApiResources(
		static function () use (&$allowed): bool {
			return $allowed;
		},
		static fn (): array => [$account],
		new ResourceRepository(false, $clock),
		new HealthRepository(false),
		$clock
	);

	$descriptors = $api->descriptors();
	expect(array_keys($descriptors))->toBe([
		'cloud.cacti.net/Account',
		'cloud.cacti.net/Resource',
		'cloud.cacti.net/Health',
	]);

	foreach ($descriptors as $descriptor) {
		expect($descriptor['read_only'])->toBeTrue()
			->and($descriptor['operations'])->toBe(['list', 'get'])
			->and($descriptor['permission_callback']())->toBeTrue();
	}

	$accounts  = $descriptors['cloud.cacti.net/Account']['callbacks']['list']([]);
	$oneAcc    = $descriptors['cloud.cacti.net/Account']['callbacks']['get'](['account' => 9]);
	$resources = $descriptors['cloud.cacti.net/Resource']['callbacks']['list'](['account_id' => 9]);
	$oneRes    = $descriptors['cloud.cacti.net/Resource']['callbacks']['get'](['account_id' => 9, 'resource_id' => 42]);
	$healthAll = $descriptors['cloud.cacti.net/Health']['callbacks']['list']([]);
	$oneHealth = $descriptors['cloud.cacti.net/Health']['callbacks']['get'](['account_id' => 9]);

	$serialized = (string) json_encode([$accounts, $oneAcc, $resources, $oneRes, $healthAll, $oneHealth]);

	expect($accounts)->toHaveCount(1)
		->and($oneAcc['name'])->toBe('accounts/9')
		->and($oneRes['name'])->toBe('accounts/9/resources/42')
		->and($oneHealth['name'])->toBe('accounts/9/health')
		->and($oneHealth['collection'])->toBe('ok')
		->and($serialized)->not->toContain('azure-client-secret.json')
		->and($serialized)->not->toContain('credential_file');

	// Denied when Cloud View permission is revoked.
	$allowed = false;
	expect($descriptors['cloud.cacti.net/Account']['permission_callback']())->toBeFalse()
		->and($descriptors['cloud.cacti.net/Account']['callbacks']['list']([]))->toBe([])
		->and($descriptors['cloud.cacti.net/Account']['callbacks']['get'](['account_id' => 9]))->toBeNull()
		->and($descriptors['cloud.cacti.net/Resource']['callbacks']['list']([]))->toBe([])
		->and($descriptors['cloud.cacti.net/Resource']['callbacks']['get'](['resource_id' => 42]))->toBeNull()
		->and($descriptors['cloud.cacti.net/Health']['callbacks']['list']([]))->toBe([])
		->and($descriptors['cloud.cacti.net/Health']['callbacks']['get'](['account_id' => 9]))->toBeNull();
});
