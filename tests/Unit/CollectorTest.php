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
use Somethingwithproof\Cloud\AccountHealth;
use Somethingwithproof\Cloud\AccountRepository;
use Somethingwithproof\Cloud\CloudResource;
use Somethingwithproof\Cloud\CollectResult;
use Somethingwithproof\Cloud\CollectionState;
use Somethingwithproof\Cloud\Collector;
use Somethingwithproof\Cloud\CredentialStore;
use Somethingwithproof\Cloud\DeviceRepository;
use Somethingwithproof\Cloud\DigitalOceanCatalog;
use Somethingwithproof\Cloud\DriverError;
use Somethingwithproof\Cloud\Drivers;
use Somethingwithproof\Cloud\ErrorClass;
use Somethingwithproof\Cloud\FixedClock;
use Somethingwithproof\Cloud\HealthRepository;
use Somethingwithproof\Cloud\HttpRequest;
use Somethingwithproof\Cloud\HttpResponse;
use Somethingwithproof\Cloud\HttpTransport;
use Somethingwithproof\Cloud\Inventory;
use Somethingwithproof\Cloud\LeaseRepository;
use Somethingwithproof\Cloud\Log;
use Somethingwithproof\Cloud\MetricRequest;
use Somethingwithproof\Cloud\Observation;
use Somethingwithproof\Cloud\ObservationRepository;
use Somethingwithproof\Cloud\OutboundPolicy;
use Somethingwithproof\Cloud\Provider;
use Somethingwithproof\Cloud\ProviderDriver;
use Somethingwithproof\Cloud\Redactor;
use Somethingwithproof\Cloud\RefusingTransport;
use Somethingwithproof\Cloud\ResourceRepository;
use Somethingwithproof\Cloud\Secret;
use Somethingwithproof\Cloud\SecretRepository;
use Somethingwithproof\Cloud\SeriesRepository;
use Somethingwithproof\Cloud\Settings;
use Somethingwithproof\Cloud\SettingsRepository;
use Somethingwithproof\Cloud\SystemClock;
use Somethingwithproof\Cloud\TransportError;
use Somethingwithproof\Cloud\UsageEstimate;
use Somethingwithproof\Cloud\Vault;
use Somethingwithproof\Cloud\VaultKey;
use Somethingwithproof\Cloud\Verifier;
use Somethingwithproof\Cloud\VerifyStatus;

beforeEach(function () {
	$GLOBALS['__cloud_db_rows'] = [];
	$GLOBALS['__cloud_db_exec'] = [];
	$GLOBALS['__cloud_db_fail'] = [];
	$GLOBALS['__cloud_log']     = [];
});

afterEach(function () {
	$GLOBALS['__cloud_db_rows'] = [];
	$GLOBALS['__cloud_db_exec'] = [];
	$GLOBALS['__cloud_db_fail'] = [];
	$GLOBALS['__cloud_log']     = [];
});

/**
 * Build a minimal fake ProviderDriver for collector and verifier unit tests.
 */
function cloud_fake_driver(
	?\Closure $onDiscover = null,
	?\Closure $onCollect = null,
	?\Closure $onVerify = null,
	Provider $provider = Provider::DigitalOcean
): ProviderDriver {
	return new class($onDiscover, $onCollect, $onVerify, $provider) implements ProviderDriver {
		public function __construct(
			private readonly ?\Closure $onDiscover,
			private readonly ?\Closure $onCollect,
			private readonly ?\Closure $onVerify,
			private readonly Provider $provider,
		) {
		}

		public function provider(): Provider {
			return $this->provider;
		}

		public function endpoints(Account $account): OutboundPolicy {
			return new OutboundPolicy(['api.example.invalid']);
		}

		public function operations(): array {
			return [];
		}

		public function verify(Account $account, Secret $credential): VerifyStatus {
			return $this->onVerify !== null ? ($this->onVerify)($account, $credential) : VerifyStatus::Ok;
		}

		public function discover(Account $account, Secret $credential): Inventory {
			if ($this->onDiscover !== null) {
				return ($this->onDiscover)($account, $credential);
			}

			return new Inventory([new CloudResource(DigitalOceanCatalog::DROPLET, '101', 'web-1', 'nyc3')], 1);
		}

		public function collect(Account $account, Secret $credential, array $requests): CollectResult {
			if ($this->onCollect !== null) {
				return ($this->onCollect)($account, $credential, $requests);
			}

			$obs = [];

			foreach ($requests as $req) {
				$obs[] = new Observation($req->resourceId, $req->metric, 42.5, 1731542300, 1731542400);
			}

			return new CollectResult($obs, count($requests));
		}

		public function estimate(array $requests, int $intervalSeconds): UsageEstimate {
			return new UsageEstimate(count($requests), count($requests) * 100, count($requests) * 0.5);
		}
	};
}

/**
 * Create a temporary 32-byte hex vault key file and encrypted VaultEntry for tests.
 *
 * @return array{keyFile: string, row: array<string, mixed>}
 */
function cloud_temp_vault_secret(int $accountId, string $plaintext): array {
	$keyBytes = random_bytes(32);
	$keyFile  = sys_get_temp_dir() . '/cloud-unit-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.key';
	file_put_contents($keyFile, bin2hex($keyBytes));
	chmod($keyFile, 0600);

	$entry = (new Vault(VaultKey::fromBytes($keyBytes)))->encrypt(
		new Secret($plaintext),
		$accountId,
		SecretRepository::CREDENTIAL
	);

	return [
		'keyFile' => $keyFile,
		'row'     => [
			'nonce'      => $entry->nonce,
			'ciphertext' => $entry->ciphertext,
			'tag'        => $entry->tag,
			'key_id'     => $entry->keyId,
			'expires_at' => 0,
		],
	];
}

it('runs full discovery and collection for a leased account and records ok health', function () {
	$clock = new FixedClock(1731542400);
	$vault = cloud_temp_vault_secret(1, 'dop_v1_secret_token');

	try {
		$GLOBALS['__cloud_db_rows'] = [
			'FROM plugin_cloud_account WHERE poller_id = ? AND admin_state = ?' => [
				[
					'id'                => 1,
					'provider'          => 'digitalocean',
					'name'              => 'do-main',
					'poller_id'         => 1,
					'credential_source' => 'vault',
					'collect_interval'  => 300,
					'discover_interval' => 3600,
					'request_share'     => 50,
					'admin_state'       => 'enabled',
				],
			],
			'SELECT acquired_at FROM plugin_cloud_lease' => [['acquired_at' => 0]],
			'SELECT COUNT(*) FROM plugin_cloud_lease'    => [['c' => 1]],
			'FROM plugin_cloud_health WHERE account_id = ?' => [],
			'FROM plugin_cloud_secret WHERE account_id = ?' => [$vault['row']],
			'FROM plugin_cloud_resource WHERE account_id = ? AND lifecycle != ?' => [
				['id' => 10, 'type' => 'droplet', 'provider_id' => '999', 'missing_count' => 2],
			],
			'FROM plugin_cloud_series s' => [
				['resource_id' => 11, 'type' => 'droplet', 'provider_id' => '101', 'metric' => 'do_bw_pub_in'],
			],
		];

		$drivers = new Drivers(static fn (OutboundPolicy $p): HttpTransport => new RefusingTransport());
		$drivers->register(Provider::DigitalOcean, static fn (HttpTransport $http): ProviderDriver => cloud_fake_driver());

		$collector = new Collector(
			new AccountRepository(false, $clock),
			$drivers,
			new CredentialStore(new SecretRepository(false, $clock), $vault['keyFile'], ''),
			new LeaseRepository(false, $clock),
			new ResourceRepository(false, $clock),
			new SeriesRepository(false),
			new ObservationRepository(false),
			new HealthRepository(false),
			new Redactor(),
			$clock,
			'poller1-test'
		);

		$summary = $collector->run(1, 240);

		expect($summary)->toBe(['accounts' => 1, 'skipped' => 0, 'stopped_early' => false])
			->and(Collector::dueAfter(300))->toBe(270)
			->and(Collector::dueAfter(60))->toBe(54);

		$sqls = array_column($GLOBALS['__cloud_db_exec'], 'sql');
		expect(implode("\n", $sqls))->toContain('INSERT INTO plugin_cloud_resource')
			->and(implode("\n", $sqls))->toContain('UPDATE plugin_cloud_resource r')
			->and(implode("\n", $sqls))->toContain('INSERT INTO plugin_cloud_observation')
			->and(implode("\n", $sqls))->toContain('INSERT INTO plugin_cloud_health')
			->and(implode("\n", $sqls))->toContain('UPDATE plugin_cloud_lease SET expires_at = 0');
	} finally {
		@unlink($vault['keyFile']);
	}
});

it('handles skipped leases, budget exhaustion, retry-after pauses, missing drivers, and bad credentials', function () {
	$clock = new FixedClock(1731542400);

	// Case 1: budget already spent (budgetSeconds = 0) stops early before leasing.
	$GLOBALS['__cloud_db_rows'] = [
		'FROM plugin_cloud_account WHERE poller_id = ? AND admin_state = ?' => [
			[
				'id'                => 1,
				'provider'          => 'digitalocean',
				'name'              => 'a1',
				'poller_id'         => 1,
				'credential_source' => 'vault',
				'admin_state'       => 'enabled',
			],
		],
	];

	$emptyDrivers = new Drivers(static fn (OutboundPolicy $p): HttpTransport => new RefusingTransport());
	$collector    = new Collector(
		new AccountRepository(false, $clock),
		$emptyDrivers,
		new CredentialStore(new SecretRepository(false, $clock), '/nonexistent/key', ''),
		new LeaseRepository(false, $clock),
		new ResourceRepository(false, $clock),
		new SeriesRepository(false),
		new ObservationRepository(false),
		new HealthRepository(false),
		new Redactor(),
		$clock,
		'poller1-test'
	);

	expect($collector->run(1, 0))->toBe(['accounts' => 0, 'skipped' => 0, 'stopped_early' => true]);

	// Case 2: lease not acquired increments skipped.
	$GLOBALS['__cloud_db_rows']['SELECT COUNT(*) FROM plugin_cloud_lease'] = [['c' => 0]];
	expect($collector->run(1, 60))->toBe(['accounts' => 0, 'skipped' => 1, 'stopped_early' => false]);

	// Case 3: retry_at in future skips work cleanly without calling driver.
	$GLOBALS['__cloud_db_rows']['SELECT COUNT(*) FROM plugin_cloud_lease']     = [['c' => 1]];
	$GLOBALS['__cloud_db_rows']['FROM plugin_cloud_health WHERE account_id = ?'] = [
		['account_id' => 1, 'retry_at' => 1731543000],
	];
	expect($collector->run(1, 60))->toBe(['accounts' => 1, 'skipped' => 0, 'stopped_early' => false]);

	// Case 4: not yet due (run_started recent) returns early.
	$GLOBALS['__cloud_db_rows']['FROM plugin_cloud_health WHERE account_id = ?'] = [
		['account_id' => 1, 'run_started' => 1731542350, 'retry_at' => 0],
	];
	expect($collector->run(1, 60))->toBe(['accounts' => 1, 'skipped' => 0, 'stopped_early' => false]);

	// Case 5: missing driver records Failed/Config health.
	$GLOBALS['__cloud_db_rows']['FROM plugin_cloud_health WHERE account_id = ?'] = [];
	$GLOBALS['__cloud_db_exec']                                                  = [];
	expect($collector->run(1, 60))->toBe(['accounts' => 1, 'skipped' => 0, 'stopped_early' => false]);
	$healthInsert = array_values(array_filter(
		$GLOBALS['__cloud_db_exec'],
		static fn (array $e): bool => str_contains($e['sql'], 'INSERT INTO plugin_cloud_health')
	));
	expect($healthInsert)->toHaveCount(1)
		->and($healthInsert[0]['params'][4])->toBe(CollectionState::Failed->value)
		->and($healthInsert[0]['params'][5])->toBe(ErrorClass::Config->value);

	// Case 6: driver present, missing credential records Failed/Config health and logs warning.
	$drivers = new Drivers(static fn (OutboundPolicy $p): HttpTransport => new RefusingTransport());
	$drivers->register(Provider::DigitalOcean, static fn (HttpTransport $http): ProviderDriver => cloud_fake_driver());
	$collectorWithDriver = new Collector(
		new AccountRepository(false, $clock),
		$drivers,
		new CredentialStore(new SecretRepository(false, $clock), '/nonexistent/key', ''),
		new LeaseRepository(false, $clock),
		new ResourceRepository(false, $clock),
		new SeriesRepository(false),
		new ObservationRepository(false),
		new HealthRepository(false),
		new Redactor(),
		$clock,
		'poller1-test'
	);

	$GLOBALS['__cloud_db_exec'] = [];
	expect($collectorWithDriver->run(1, 60))->toBe(['accounts' => 1, 'skipped' => 0, 'stopped_early' => false]);
	expect(array_column($GLOBALS['__cloud_log'], 'message'))->toContain('WARNING: Account 1: credential unavailable: no credential is stored for this account');
});

it('handles discovery throttling, budget capping, and partial collection errors in Collector', function () {
	$clock = new FixedClock(1731542400);
	$vault = cloud_temp_vault_secret(1, 'dop_v1_token');

	try {
		$GLOBALS['__cloud_db_rows'] = [
			'FROM plugin_cloud_account WHERE poller_id = ? AND admin_state = ?' => [
				[
					'id'                => 1,
					'provider'          => 'digitalocean',
					'name'              => 'do-capped',
					'poller_id'         => 1,
					'credential_source' => 'vault',
					'collect_interval'  => 300,
					'discover_interval' => 3600,
					'request_share'     => 1,
					'monthly_ceiling'   => 0.6,
					'admin_state'       => 'enabled',
				],
			],
			'SELECT COUNT(*) FROM plugin_cloud_lease' => [['c' => 1]],
			'FROM plugin_cloud_secret WHERE account_id = ?' => [$vault['row']],
			'FROM plugin_cloud_series s' => [
				['resource_id' => 11, 'type' => 'droplet', 'provider_id' => '101', 'metric' => 'do_bw_pub_in'],
				['resource_id' => 11, 'type' => 'droplet', 'provider_id' => '101', 'metric' => 'do_bw_pub_out'],
			],
		];

		// Discovery throws Throttled with Retry-After => skips collect and records retry_at.
		$throttledDrivers = new Drivers(static fn (OutboundPolicy $p): HttpTransport => new RefusingTransport());
		$throttledDrivers->register(
			Provider::DigitalOcean,
			static fn (HttpTransport $http): ProviderDriver => cloud_fake_driver(
				onDiscover: static function (): Inventory {
					throw new DriverError(ErrorClass::Throttled, 'rate limited', 120);
				}
			)
		);

		$collector = new Collector(
			new AccountRepository(false, $clock),
			$throttledDrivers,
			new CredentialStore(new SecretRepository(false, $clock), $vault['keyFile'], ''),
			new LeaseRepository(false, $clock),
			new ResourceRepository(false, $clock),
			new SeriesRepository(false),
			new ObservationRepository(false),
			new HealthRepository(false),
			new Redactor(),
			$clock,
			'poller1-test'
		);

		$collector->run(1, 60);

		$sqls = implode("\n", array_column($GLOBALS['__cloud_db_exec'], 'sql'));
		expect($sqls)->toContain('INSERT INTO plugin_cloud_observation')
			->and($sqls)->toContain('INSERT INTO plugin_cloud_health');

		$healthWrites = array_values(array_filter(
			$GLOBALS['__cloud_db_exec'],
			static fn (array $e): bool => str_contains($e['sql'], 'INSERT INTO plugin_cloud_health')
		));
		expect($healthWrites[0]['params'][4])->toBe(CollectionState::Failed->value)
			->and($healthWrites[0]['params'][5])->toBe(ErrorClass::Throttled->value)
			->and($healthWrites[0]['params'][12])->toBe(1731542520);
	} finally {
		@unlink($vault['keyFile']);
	}
});

it('exercises SettingsRepository, Verifier, Log, SystemClock, RefusingTransport, and repository helpers', function () {
	$clock = new SystemClock();
	expect($clock->now())->toBeGreaterThan(1700000000);

	expect(static fn () => (new RefusingTransport())->send(new HttpRequest('GET', 'https://api.example.invalid/')))
		->toThrow(TransportError::class);

	Log::debug('d');
	Log::info('i');
	Log::warning('w');
	Log::error('e');
	expect(count($GLOBALS['__cloud_log']))->toBe(4);

	$GLOBALS['__cloud_db_rows'] = [
		'FROM settings WHERE name IN' => [
			['name' => 'cloud_vault_key_file', 'value' => '/etc/cacti/custom.key'],
			['name' => 'cloud_collector_budget', 'value' => '180'],
			['name' => 'cloud_uninstall_keep_tables', 'value' => ''],
			['name' => 'cloud_credential_dir', 'value' => '/etc/cacti/creds'],
		],
	];
	$settings = (new SettingsRepository(false))->load();
	expect($settings->vaultKeyFile)->toBe('/etc/cacti/custom.key')
		->and($settings->collectorBudget)->toBe(180)
		->and($settings->keepTablesOnUninstall)->toBeFalse()
		->and($settings->credentialDir)->toBe('/etc/cacti/creds');

	// Verifier paths: missing driver, CredentialError, DriverError, TransportError, unexpected Throwable, and Ok.
	$account = Account::fromRow([
		'id'                => 5,
		'provider'          => 'aws',
		'name'              => 'aws-1',
		'poller_id'         => 1,
		'credential_source' => 'instance',
		'admin_state'       => 'enabled',
	]);
	$accounts = new AccountRepository(false, new FixedClock(100));
	$creds    = new CredentialStore(new SecretRepository(false, new FixedClock(100)), '/nonexistent/key', '');

	$noDriver = new Verifier(new Drivers(static fn (OutboundPolicy $p): HttpTransport => new RefusingTransport()), $creds, $accounts);
	expect($noDriver->verify($account, 1))->toBe(VerifyStatus::Config);

	$cases = [
		[static fn () => VerifyStatus::Ok, VerifyStatus::Ok],
		[static function (): VerifyStatus {
			throw new DriverError(ErrorClass::Permission, 'denied');
		}, VerifyStatus::Permission],
		[static function (): VerifyStatus {
			throw new TransportError('connect_timeout', 'timeout');
		}, VerifyStatus::Unreachable],
		[static function (): VerifyStatus {
			throw new \RuntimeException('unexpected');
		}, VerifyStatus::Config],
	];

	foreach ($cases as [$cb, $expected]) {
		$d = new Drivers(static fn (OutboundPolicy $p): HttpTransport => new RefusingTransport());
		$d->register(Provider::Aws, static fn (HttpTransport $http): ProviderDriver => cloud_fake_driver(onVerify: $cb, provider: Provider::Aws));
		$v = new Verifier($d, $creds, $accounts);
		expect($v->verify($account, 1))->toBe($expected);
	}

	// DeviceRepository, HealthRepository, and ObservationRepository direct helpers.
	$GLOBALS['__cloud_db_rows'] = [
		'SELECT * FROM plugin_cloud_health WHERE account_id = ?' => [
			['account_id' => 5, 'collection' => 'ok', 'discovery' => 'ok', 'requests' => 3],
		],
		'SELECT * FROM plugin_cloud_observation WHERE resource_id = ? AND metric = ?' => [
			['resource_id' => 9, 'metric' => 'cpu', 'value' => '12.5', 'observed_at' => 100, 'fetched_at' => 105],
		],
		'FROM plugin_cloud_observation o INNER JOIN' => [
			['resource_id' => 9, 'metric' => 'cpu', 'value' => '12.5', 'observed_at' => 100, 'fetched_at' => 105, 'status' => 'ok'],
		],
		'SELECT lifecycle, COUNT(*) AS n FROM plugin_cloud_resource' => [
			['lifecycle' => 'active', 'n' => 4],
		],
	];
	$health = (new HealthRepository(false))->find(5);
	expect($health->collection)->toBe(CollectionState::Ok)
		->and($health->requests)->toBe(3);

	$obsRepo = new ObservationRepository(false);
	expect($obsRepo->find(9, 'cpu')?->value)->toBe(12.5)
		->and($obsRepo->forAccount(5))->toHaveCount(1);

	$resRepo = new ResourceRepository(false, new FixedClock(100));
	expect($resRepo->counts(5))->toBe(['active' => 4]);

	$devRepo = new DeviceRepository(false, new FixedClock(100));
	$devRepo->unlink(42);
	expect(implode("\n", array_column($GLOBALS['__cloud_db_exec'], 'sql')))->toContain('DELETE FROM plugin_cloud_device WHERE host_id = ?');
});
