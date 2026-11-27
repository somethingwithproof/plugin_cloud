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
 * Diagnostic checks registered via the doctor_register_checks and
 * doctor_register_debug_info hooks.
 *
 * Checks schema currency, vault key health, provider driver readiness,
 * stale/failed/throttled accounts, and poller/collection interval alignment.
 * Never exposes secret values or raw key contents.
 */
final class DoctorChecks {
	/**
	 * Build the Doctor diagnostic runner for Cloud checks and debug snapshots.
	 *
	 * @param Schema                 $schema         schema version reader
	 * @param Settings               $settings       active plugin settings
	 * @param AccountRepository      $accounts       configured accounts store
	 * @param HealthRepository       $health         collector health store
	 * @param Drivers                $drivers        registered provider drivers
	 * @param Clock                  $clock          clock for staleness and backoff checks
	 * @param int                    $pollerInterval configured Cacti poller interval in seconds
	 * @param \Closure(string): void $keyCheck       throws CredentialError when the vault key is unusable
	 */
	public function __construct(
		private readonly Schema $schema,
		private readonly Settings $settings,
		private readonly AccountRepository $accounts,
		private readonly HealthRepository $health,
		private readonly Drivers $drivers,
		private readonly Clock $clock,
		private readonly int $pollerInterval,
		private readonly \Closure $keyCheck,
	) {
	}

	/**
	 * Evaluate and return canonical Doctor check descriptors.
	 *
	 * @return list<array{
	 *     id: string,
	 *     plugin: string,
	 *     category: string,
	 *     title: string,
	 *     status: string,
	 *     message: string,
	 *     details: array<string, mixed>,
	 *     remediation: string
	 * }>
	 */
	public function checks(): array {
		$schema    = $this->checkSchema();
		$vault     = $this->checkVaultKey();
		$drivers   = $this->checkDrivers();
		$health    = $this->checkAccountHealth();
		$intervals = $this->checkIntervals();

		return [
			[
				'id'          => 'cloud.schema',
				'plugin'      => 'cloud',
				'category'    => 'cloud',
				'title'       => 'Cloud Plugin Schema Currency',
				'status'      => $schema['status'],
				'message'     => $schema['message'],
				'details'     => $schema['details'],
				'remediation' => $schema['remediation'],
			],
			[
				'id'          => 'cloud.vault_key',
				'plugin'      => 'cloud',
				'category'    => 'cloud',
				'title'       => 'Cloud Vault Key Health',
				'status'      => $vault['status'],
				'message'     => $vault['message'],
				'details'     => $vault['details'],
				'remediation' => $vault['remediation'],
			],
			[
				'id'          => 'cloud.drivers',
				'plugin'      => 'cloud',
				'category'    => 'cloud',
				'title'       => 'Cloud Provider Driver Readiness',
				'status'      => $drivers['status'],
				'message'     => $drivers['message'],
				'details'     => $drivers['details'],
				'remediation' => $drivers['remediation'],
			],
			[
				'id'          => 'cloud.accounts_health',
				'plugin'      => 'cloud',
				'category'    => 'cloud',
				'title'       => 'Cloud Account Collection Health',
				'status'      => $health['status'],
				'message'     => $health['message'],
				'details'     => $health['details'],
				'remediation' => $health['remediation'],
			],
			[
				'id'          => 'cloud.poller_intervals',
				'plugin'      => 'cloud',
				'category'    => 'cloud',
				'title'       => 'Cloud Poller and Collection Interval Alignment',
				'status'      => $intervals['status'],
				'message'     => $intervals['message'],
				'details'     => $intervals['details'],
				'remediation' => $intervals['remediation'],
			],
		];
	}

	/**
	 * Check whether the stored Cloud plugin schema version matches Schema::VERSION.
	 *
	 * @return array{status: string, message: string, details: array<string, mixed>, remediation: string}
	 */
	public function checkSchema(): array {
		$stored = $this->schema->storedVersion();

		if ($stored === Schema::VERSION) {
			return [
				'status'      => 'ok',
				'message'     => 'Cloud plugin schema is current (version ' . Schema::VERSION . ').',
				'details'     => ['stored_version' => $stored, 'expected_version' => Schema::VERSION],
				'remediation' => '',
			];
		}

		return [
			'status'      => 'fail',
			'message'     => sprintf('Cloud plugin schema version %d does not match expected version %d.', $stored, Schema::VERSION),
			'details'     => ['stored_version' => $stored, 'expected_version' => Schema::VERSION],
			'remediation' => 'Run plugin_cloud upgrade from Cacti Plugin Management to bring the schema current.',
		];
	}

	/**
	 * Check whether the configured vault key file passes permission and size checks.
	 *
	 * @return array{status: string, message: string, details: array<string, mixed>, remediation: string}
	 */
	public function checkVaultKey(): array {
		$vaultAccounts = 0;

		foreach ($this->accounts->all() as $account) {
			if ($account->enabled() && $account->credentialSource === CredentialSource::Vault) {
				$vaultAccounts++;
			}
		}

		try {
			($this->keyCheck)($this->settings->vaultKeyFile);

			return [
				'status'      => 'ok',
				'message'     => 'Vault key file is readable and passes permission and size checks.',
				'details'     => ['vault_accounts' => $vaultAccounts],
				'remediation' => '',
			];
		} catch (CredentialError $e) {
			return [
				'status'      => $vaultAccounts > 0 ? 'fail' : 'warn',
				'message'     => 'Vault key file check failed: ' . $e->getMessage(),
				'details'     => ['vault_accounts' => $vaultAccounts],
				'remediation' => 'Provision a readable 32-byte Cloud vault key file with 0600 permissions.',
			];
		}
	}

	/**
	 * Check that production drivers are registered for every released provider.
	 *
	 * @return array{status: string, message: string, details: array<string, mixed>, remediation: string}
	 */
	public function checkDrivers(): array {
		$missing = [];
		$ready   = [];

		foreach (Provider::released() as $provider) {
			$probe  = new Account(
				id: 1,
				provider: $provider,
				name: 'probe',
				pollerId: Account::MAIN_POLLER,
				scope: [],
				credentialSource: CredentialSource::Vault,
				credentialFile: '',
				collectInterval: AccountInput::DEFAULT_COLLECT,
				discoverInterval: AccountInput::DEFAULT_DISCOVER,
				requestShare: AccountInput::DEFAULT_SHARE,
				monthlyCeiling: null,
				adminState: AdminState::Enabled
			);
			$driver = $this->drivers->for($probe);

			if ($driver === null) {
				$missing[] = $provider->value;
			} else {
				$ready[] = $provider->value;
			}
		}

		if ($missing !== []) {
			return [
				'status'      => 'fail',
				'message'     => 'Missing production drivers for released providers: ' . implode(', ', $missing),
				'details'     => ['ready' => $ready, 'missing' => $missing],
				'remediation' => 'Verify plugin_cloud installation integrity and PHP extension requirements.',
			];
		}

		return [
			'status'      => 'ok',
			'message'     => 'All released provider drivers are registered (' . implode(', ', $ready) . ').',
			'details'     => ['ready' => $ready, 'missing' => []],
			'remediation' => '',
		];
	}

	/**
	 * Evaluate failed, throttled, and stale collection status across enabled accounts.
	 *
	 * @return array{status: string, message: string, details: array<string, mixed>, remediation: string}
	 */
	public function checkAccountHealth(): array {
		$now       = $this->clock->now();
		$throttled = [];
		$failed    = [];
		$stale     = [];
		$enabled   = 0;

		foreach ($this->accounts->all() as $account) {
			if (!$account->enabled()) {
				continue;
			}

			$enabled++;
			$h = $this->health->find($account->id);

			if ($h->retryAt > $now || $h->error === ErrorClass::Throttled) {
				$throttled[] = $account->name;
			} elseif ($h->collection === CollectionState::Failed) {
				$failed[] = $account->name;
			} elseif ($h->runStarted > 0 && ($now - $h->runStarted) > ($account->collectInterval * 3)) {
				$stale[] = $account->name;
			}
		}

		if ($failed !== []) {
			return [
				'status'      => 'fail',
				'message'     => 'Failed cloud accounts: ' . implode(', ', $failed),
				'details'     => ['enabled' => $enabled, 'failed' => $failed, 'throttled' => $throttled, 'stale' => $stale],
				'remediation' => 'Inspect Cloud Health and Cacti logs for authentication or connectivity errors.',
			];
		}

		if ($throttled !== [] || $stale !== []) {
			$parts = [];

			if ($throttled !== []) {
				$parts[] = 'throttled: ' . implode(', ', $throttled);
			}

			if ($stale !== []) {
				$parts[] = 'stale: ' . implode(', ', $stale);
			}

			return [
				'status'      => 'warn',
				'message'     => 'Degraded cloud accounts (' . implode('; ', $parts) . ').',
				'details'     => ['enabled' => $enabled, 'failed' => [], 'throttled' => $throttled, 'stale' => $stale],
				'remediation' => 'Verify poller_cloud.php scheduling and review provider API rate limits.',
			];
		}

		return [
			'status'      => 'ok',
			'message'     => sprintf('%d enabled cloud account(s) healthy.', $enabled),
			'details'     => ['enabled' => $enabled, 'failed' => [], 'throttled' => [], 'stale' => []],
			'remediation' => '',
		];
	}

	/**
	 * Verify enabled accounts' collection intervals align with Cacti's poller interval.
	 *
	 * @return array{status: string, message: string, details: array<string, mixed>, remediation: string}
	 */
	public function checkIntervals(): array {
		$misaligned = [];

		if ($this->pollerInterval > 0) {
			foreach ($this->accounts->all() as $account) {
				if (!$account->enabled()) {
					continue;
				}

				$effective = max(1, (int) ceil(Collector::dueAfter($account->collectInterval) / $this->pollerInterval))
					* $this->pollerInterval;

				if ($effective !== $account->collectInterval) {
					$misaligned[] = sprintf(
						'%s (configured %ds, effective %ds)',
						$account->name,
						$account->collectInterval,
						$effective
					);
				}
			}
		}

		if ($misaligned !== []) {
			return [
				'status'      => 'warn',
				'message'     => 'Collection intervals misaligned with poller interval: ' . implode(', ', $misaligned),
				'details'     => ['poller_interval' => $this->pollerInterval, 'misaligned' => $misaligned],
				'remediation' => 'Set each cloud account collect_interval to a multiple of the Cacti poller interval.',
			];
		}

		return [
			'status'      => 'ok',
			'message'     => 'All enabled cloud account collection intervals align with the poller interval.',
			'details'     => ['poller_interval' => $this->pollerInterval, 'misaligned' => []],
			'remediation' => '',
		];
	}

	/**
	 * Return a redacted diagnostic snapshot section for doctor_register_debug_info.
	 *
	 * Reports plugin version, schema currency, non-secret settings metadata,
	 * account/provider/driver counts, and aggregate collection health using scalar
	 * field values without ever reading or emitting raw vault key material or
	 * credential values.
	 *
	 * @param string $pluginVersion plugin version reported by INFO
	 * @return array{
	 *     id: string,
	 *     plugin: string,
	 *     label: string,
	 *     private: bool,
	 *     fields: array<string, array{label: string, value: scalar|null}>
	 * }
	 */
	public function debugInfo(string $pluginVersion = ''): array {
		$accounts        = $this->accounts->all();
		$enabled         = 0;
		$disabled        = 0;
		$byProvider      = [];
		$byCredentialSrc = [];
		$healthSummary   = [
			'ok'        => 0,
			'partial'   => 0,
			'budget'    => 0,
			'failed'    => 0,
			'throttled' => 0,
			'never'     => 0,
		];
		$now             = $this->clock->now();

		foreach ($accounts as $account) {
			if ($account->enabled()) {
				$enabled++;
			} else {
				$disabled++;
			}

			$provider                 = $account->provider->value;
			$byProvider[$provider]    = ($byProvider[$provider] ?? 0) + 1;
			$source                   = $account->credentialSource->value;
			$byCredentialSrc[$source] = ($byCredentialSrc[$source] ?? 0) + 1;
			$h                        = $this->health->find($account->id);

			if ($h->retryAt > $now || $h->error === ErrorClass::Throttled) {
				$healthSummary['throttled']++;
			} else {
				$stateKey = $h->collection->value;

				if (isset($healthSummary[$stateKey])) {
					$healthSummary[$stateKey]++;
				}
			}
		}

		ksort($byProvider);
		ksort($byCredentialSrc);

		$driverCheck = $this->checkDrivers();
		$vaultCheck  = $this->checkVaultKey();
		$stored      = $this->schema->storedVersion();

		$readyDrivers   = [];
		$missingDrivers = [];

		if (is_array($driverCheck['details']['ready'] ?? null)) {
			foreach ($driverCheck['details']['ready'] as $item) {
				if (is_scalar($item)) {
					$readyDrivers[] = (string) $item;
				}
			}
		}

		if (is_array($driverCheck['details']['missing'] ?? null)) {
			foreach ($driverCheck['details']['missing'] as $item) {
				if (is_scalar($item)) {
					$missingDrivers[] = (string) $item;
				}
			}
		}

		return [
			'id'      => 'cloud.overview',
			'plugin'  => 'cloud',
			'label'   => 'Cloud Plugin',
			'private' => false,
			'fields'  => [
				'plugin_version'              => ['label' => 'Plugin Version', 'value' => $pluginVersion],
				'schema_version'              => ['label' => 'Schema Version', 'value' => $stored],
				'schema_version_expected'     => ['label' => 'Expected Schema Version', 'value' => Schema::VERSION],
				'schema_current'              => ['label' => 'Schema Current', 'value' => $stored === Schema::VERSION],
				'collector_budget'            => ['label' => 'Collector Budget (s)', 'value' => $this->settings->collectorBudget],
				'keep_tables_on_uninstall'    => ['label' => 'Keep Tables On Uninstall', 'value' => $this->settings->keepTablesOnUninstall],
				'vault_key_configured'        => ['label' => 'Vault Key Configured', 'value' => $this->settings->vaultKeyFile !== ''],
				'vault_key_status'            => ['label' => 'Vault Key Status', 'value' => $vaultCheck['status']],
				'credential_dir_configured'   => ['label' => 'Credential Directory Configured', 'value' => $this->settings->credentialDir !== ''],
				'poller_interval'             => ['label' => 'Poller Interval (s)', 'value' => $this->pollerInterval],
				'accounts_total'              => ['label' => 'Accounts Total', 'value' => count($accounts)],
				'accounts_enabled'            => ['label' => 'Accounts Enabled', 'value' => $enabled],
				'accounts_disabled'           => ['label' => 'Accounts Disabled', 'value' => $disabled],
				'accounts_by_provider'        => ['label' => 'Accounts By Provider', 'value' => self::formatCountMap($byProvider)],
				'accounts_by_credential_type' => ['label' => 'Accounts By Credential Source', 'value' => self::formatCountMap($byCredentialSrc)],
				'drivers_released'            => ['label' => 'Released Drivers', 'value' => count(Provider::released())],
				'drivers_ready'               => ['label' => 'Ready Drivers', 'value' => $readyDrivers !== [] ? implode(', ', $readyDrivers) : 'none'],
				'drivers_missing'             => ['label' => 'Missing Drivers', 'value' => $missingDrivers !== [] ? implode(', ', $missingDrivers) : 'none'],
				'health_ok'                   => ['label' => 'Accounts Healthy', 'value' => $healthSummary['ok']],
				'health_partial'              => ['label' => 'Accounts Partial', 'value' => $healthSummary['partial']],
				'health_budget'               => ['label' => 'Accounts Over Budget', 'value' => $healthSummary['budget']],
				'health_failed'               => ['label' => 'Accounts Failed', 'value' => $healthSummary['failed']],
				'health_throttled'            => ['label' => 'Accounts Throttled', 'value' => $healthSummary['throttled']],
				'health_never'                => ['label' => 'Accounts Never Polled', 'value' => $healthSummary['never']],
			],
		];
	}

	/**
	 * Format a sorted string-to-count map as a deterministic scalar summary string.
	 *
	 * @param array<string, int> $counts key => count map
	 * @return string comma-separated key=count pairs or 'none'
	 */
	private static function formatCountMap(array $counts): string {
		if ($counts === []) {
			return 'none';
		}

		$parts = [];

		foreach ($counts as $key => $count) {
			$parts[] = $key . '=' . $count;
		}

		return implode(', ', $parts);
	}
}
