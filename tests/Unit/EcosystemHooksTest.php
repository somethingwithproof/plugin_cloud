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
use Somethingwithproof\Cloud\RealmRepository;
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

it('registers strictly read-only MCP abilities via registrar object with canonical schemas and user-ID permissions', function () {
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
		'GROUP BY lifecycle' => [
			['lifecycle' => 'active', 'n' => 1],
		],
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
		'FROM plugin_realms WHERE plugin = ?' => [
			['id' => 21, 'file' => 'cloud_health.php'],
			['id' => 22, 'file' => 'cloud_accounts.php'],
		],
		'FROM user_auth_realm' => [['realm_id' => 121]],
	];

	$allowed = true;
	$hub     = new Abilities(
		static function (int $userId) use (&$allowed): bool {
			return $allowed && $userId > 0;
		},
		static fn (int $userId): array => [$account],
		new ResourceRepository(false, $clock),
		new HealthRepository(false),
		$clock
	);

	$defs = $hub->definitions();
	expect(array_keys($defs))->toBe([Abilities::LIST_ACCOUNTS, Abilities::LIST_RESOURCES, Abilities::GET_HEALTH]);

	$assertSchema = static function (array $schema) use (&$assertSchema): void {
		$type = is_array($schema['type'] ?? null) ? $schema['type'][0] : ($schema['type'] ?? '');

		if ($type === 'object') {
			expect($schema['additionalProperties'] ?? null)->toBeFalse()
				->and($schema)->toHaveKey('properties');

			foreach ($schema['properties'] as $prop) {
				$assertSchema($prop);
			}
		} elseif ($type === 'array') {
			expect($schema['items'] ?? null)->toBeArray()
				->and($schema['maxItems'] ?? null)->toBeInt()->toBeGreaterThan(0);
			$assertSchema($schema['items']);
		}
	};

	$expectedKeys = ['label', 'description', 'category', 'input_schema', 'output_schema', 'annotations', 'permission', 'execute'];

	foreach ($defs as $def) {
		expect(array_keys($def))->toBe($expectedKeys)
			->and($def['annotations'])->toBe(['readOnly' => true, 'destructive' => false, 'idempotent' => true]);
		$assertSchema($def['input_schema']);
		$assertSchema($def['output_schema']);
	}

	$accOut = $defs[Abilities::LIST_ACCOUNTS]['execute'](7, new \stdClass());
	$resOut = $defs[Abilities::LIST_RESOURCES]['execute'](7, (object) ['account_id' => 7]);
	$hltOut = $defs[Abilities::GET_HEALTH]['execute'](7, new \stdClass());

	$serialized = (string) json_encode([$accOut, $resOut, $hltOut]);

	expect($accOut['accounts'][0]['name'])->toBe('aws-prod')
		->and($resOut['resources'][0]['provider_id'])->toBe('us-east-1/i-0123456789abcdef0')
		->and($hltOut['accounts'][0]['collection'])->toBe('ok')
		->and($hltOut['accounts'][0]['resources'])->toBe(['active' => 1, 'missing' => 0, 'deleted' => 0])
		->and($serialized)->not->toContain('/etc/cacti/aws-secret.json')
		->and($serialized)->not->toContain('credential_file');

	// Denied when Cloud View realm is missing.
	$allowed = false;
	expect($defs[Abilities::LIST_ACCOUNTS]['permission'](7))->toBeFalse()
		->and($defs[Abilities::LIST_ACCOUNTS]['execute'](7, new \stdClass()))->toBe(['accounts' => []])
		->and($defs[Abilities::LIST_RESOURCES]['execute'](7, new \stdClass()))->toBe(['resources' => []])
		->and($defs[Abilities::GET_HEALTH]['execute'](7, new \stdClass()))->toBe(['checked_at' => 1731542400, 'accounts' => []]);

	// Verify setup.php hook calls $registrar->register($id, $def) and uses DB-backed RealmRepository.
	$registrar = new class() {
		/** @var array<string, string> */
		public array $categories = [];
		/** @var array<string, array<string, mixed>> */
		public array $abilities = [];

		public function registerCategory(string $id, string $label): void {
			$this->categories[$id] = $label;
		}

		/** @param array<string, mixed> $def */
		public function register(string $id, array $def): void {
			$this->abilities[$id] = $def;
		}
	};

	cloud_abilities_register($registrar);
	expect($registrar->categories)->toHaveKey('cloud', 'Cloud')
		->and(array_keys($registrar->abilities))->toBe([Abilities::LIST_ACCOUNTS, Abilities::LIST_RESOURCES, Abilities::GET_HEALTH])
		->and(($registrar->abilities[Abilities::LIST_ACCOUNTS]['permission'])(7))->toBeTrue()
		->and(($registrar->abilities[Abilities::LIST_ACCOUNTS]['permission'])(0))->toBeFalse();

	$realms = new RealmRepository(false);
	expect($realms->userHasAnyFile(7, ['cloud_health.php']))->toBeTrue()
		->and($realms->userHasAnyFile(7, ['unknown.php']))->toBeFalse()
		->and($realms->userHasAnyFile(0, ['cloud_health.php']))->toBeFalse();
	$GLOBALS['__cloud_db_rows']['FROM user_auth_realm'] = [];
	expect($realms->userHasAnyFile(7, ['cloud_health.php']))->toBeFalse();
});

it('runs all five Doctor diagnostic checks across healthy and degraded states using canonical statuses', function () {
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
	expect(array_is_list($checks))->toBeTrue()
		->and(array_column($checks, 'id'))->toBe([
			'cloud.schema',
			'cloud.vault_key',
			'cloud.drivers',
			'cloud.accounts_health',
			'cloud.poller_intervals',
		]);

	foreach ($checks as $check) {
		expect($check)->toHaveKeys(['id', 'plugin', 'category', 'title', 'status', 'message', 'details', 'remediation'])
			->and($check['plugin'])->toBe('cloud')
			->and($check['status'])->toBe('ok', $check['id'])
			->and($check['message'])->not->toBe('');
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

	expect($degraded->checkVaultKey()['status'])->toBe('fail')
		->and($degraded->checkAccountHealth()['status'])->toBe('warn')
		->and($degraded->checkIntervals()['status'])->toBe('warn');
});

it('wires canonical ecosystem registration hooks in setup.php cleanly', function () {
	$checks    = cloud_doctor_register_checks(['mode' => 'run', 'plugin' => 'cloud']);
	$debugInfo = cloud_doctor_register_debug_info(['plugin' => 'cloud']);
	$resources = cloud_api_register_resources([]);

	expect(array_is_list($checks))->toBeTrue()
		->and($checks)->not->toHaveKey('mode')
		->and($checks)->not->toHaveKey('plugin')
		->and(array_column($checks, 'id'))->toBe([
			'cloud.schema',
			'cloud.vault_key',
			'cloud.drivers',
			'cloud.accounts_health',
			'cloud.poller_intervals',
		])
		->and(array_is_list($debugInfo))->toBeTrue()
		->and($debugInfo)->toHaveCount(1)
		->and($debugInfo[0]['id'])->toBe('cloud.overview')
		->and($debugInfo[0]['plugin'])->toBe('cloud')
		->and(array_is_list($resources))->toBeTrue()
		->and(array_column($resources, 'collection'))->toBe([
			ApiResources::COLLECTION_ACCOUNTS,
			ApiResources::COLLECTION_RESOURCES,
			ApiResources::COLLECTION_HEALTH,
		]);

	$cascRegistrar = new class() {
		/** @var array<string, mixed>|null */
		public ?array $registered = null;

		/** @param array<string, mixed> $descriptor */
		public function register(array $descriptor): void {
			$this->registered = $descriptor;
		}
	};

	cloud_casc_register_providers($cascRegistrar);
	expect($cascRegistrar->registered)->toBeArray()
		->and($cascRegistrar->registered['slug'])->toBe('cloud')
		->and($cascRegistrar->registered['schemaVersion'])->toBe('1.0')
		->and(is_callable($cascRegistrar->registered['export']))->toBeTrue()
		->and(is_callable($cascRegistrar->registered['validate']))->toBeTrue()
		->and(is_callable($cascRegistrar->registered['plan']))->toBeTrue()
		->and(is_callable($cascRegistrar->registered['apply']))->toBeTrue();

	$unwrapped = cloud_casc_register_providers([]);
	expect($unwrapped['slug'])->toBe('cloud')
		->and($unwrapped['schemaVersion'])->toBe('1.0');
});

it('produces a redacted Doctor debugInfo snapshot with scalar fields and zero secrets', function () {
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

	expect($info['id'])->toBe('cloud.overview')
		->and($info['plugin'])->toBe('cloud')
		->and($info['fields']['plugin_version']['value'])->toBe('0.1.0-dev')
		->and($info['fields']['schema_version']['value'])->toBe(Schema::VERSION)
		->and($info['fields']['schema_current']['value'])->toBeTrue()
		->and($info['fields']['collector_budget']['value'])->toBe(180)
		->and($info['fields']['vault_key_status']['value'])->toBe('ok')
		->and($info['fields']['accounts_total']['value'])->toBe(2)
		->and($info['fields']['accounts_enabled']['value'])->toBe(1)
		->and($info['fields']['accounts_disabled']['value'])->toBe(1)
		->and($info['fields']['accounts_by_provider']['value'])->toBe('aws=1, gcp=1')
		->and($info['fields']['drivers_missing']['value'])->toBe('none')
		->and($serialized)->not->toContain('/etc/cacti/vault-secret-key.bin')
		->and($serialized)->not->toContain('/etc/cacti/secret-creds')
		->and($serialized)->not->toContain('super-secret-aws.json')
		->and($serialized)->not->toContain('gcp-private-key.json');

	foreach ($info['fields'] as $field) {
		expect($field['value'] === null || is_scalar($field['value']))->toBeTrue();
	}
});

it('registers canonical Google AIP resource descriptors with strict permission gating and zero secrets', function () {
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
		static function (int $userId) use (&$allowed): bool {
			return $allowed;
		},
		static fn (int $userId): array => [$account],
		new ResourceRepository(false, $clock),
		new HealthRepository(false),
		$clock
	);

	$descriptors = $api->descriptors();
	expect(array_is_list($descriptors))->toBeTrue()
		->and(array_column($descriptors, 'collection'))->toBe([
			'cloudAccounts',
			'cloudResources',
			'cloudHealth',
		]);

	foreach ($descriptors as $descriptor) {
		expect($descriptor['plugin'])->toBe('cloud')
			->and($descriptor['realm_read'])->toBe('cloud_accounts.php')
			->and(preg_match('/^[a-z][A-Za-z0-9]{2,63}$/', $descriptor['collection']))->toBe(1)
			->and(array_keys($descriptor['handlers']))->toBe(['list', 'get']);
	}

	$accounts  = $descriptors[0]['handlers']['list'](['user_id' => 1]);
	$oneAcc    = $descriptors[0]['handlers']['get']('9', ['user_id' => 1]);
	$resources = $descriptors[1]['handlers']['list'](['user_id' => 1, 'query' => ['account_id' => 9]]);
	$oneRes    = $descriptors[1]['handlers']['get']('42', ['user_id' => 1]);
	$healthAll = $descriptors[2]['handlers']['list'](['user_id' => 1]);
	$oneHealth = $descriptors[2]['handlers']['get']('9', ['user_id' => 1]);

	$serialized = (string) json_encode([$accounts, $oneAcc, $resources, $oneRes, $healthAll, $oneHealth]);

	expect($accounts)->toHaveCount(1)
		->and($oneAcc['name'])->toBe('cloudAccounts/9')
		->and($oneRes['name'])->toBe('cloudResources/42')
		->and($oneHealth['name'])->toBe('cloudHealth/9')
		->and($oneHealth['collection'])->toBe('ok')
		->and($serialized)->not->toContain('azure-client-secret.json')
		->and($serialized)->not->toContain('credential_file');

	// Denied when Cloud View permission is revoked.
	$allowed = false;
	expect($descriptors[0]['handlers']['list'](['user_id' => 1]))->toBe([])
		->and($descriptors[0]['handlers']['get']('9', ['user_id' => 1]))->toBeNull()
		->and($descriptors[1]['handlers']['list'](['user_id' => 1]))->toBe([])
		->and($descriptors[1]['handlers']['get']('42', ['user_id' => 1]))->toBeNull()
		->and($descriptors[2]['handlers']['list'](['user_id' => 1]))->toBe([])
		->and($descriptors[2]['handlers']['get']('9', ['user_id' => 1]))->toBeNull();
});
