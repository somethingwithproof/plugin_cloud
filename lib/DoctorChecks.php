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
 * Diagnostic checks registered via the doctor_register_checks hook.
 *
 * Checks schema currency, vault key health, provider driver readiness,
 * stale/failed/throttled accounts, and poller/collection interval alignment.
 * Never exposes secret values or raw key contents.
 */
final class DoctorChecks {
	/**
	 * @param \Closure(string): void $keyCheck throws CredentialError when the vault key is unusable
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
	 * @return list<array{id: string, category: string, title: string, callback: \Closure(): array{status: string, message: string, details: array<string, mixed>}}>
	 */
	public function checks(): array {
		return [
			[
				'id'       => 'cloud_schema',
				'category' => 'cloud',
				'title'    => 'Cloud Plugin Schema Currency',
				'callback' => fn (): array => $this->checkSchema(),
			],
			[
				'id'       => 'cloud_vault_key',
				'category' => 'cloud',
				'title'    => 'Cloud Vault Key Health',
				'callback' => fn (): array => $this->checkVaultKey(),
			],
			[
				'id'       => 'cloud_drivers',
				'category' => 'cloud',
				'title'    => 'Cloud Provider Driver Readiness',
				'callback' => fn (): array => $this->checkDrivers(),
			],
			[
				'id'       => 'cloud_accounts_health',
				'category' => 'cloud',
				'title'    => 'Cloud Account Collection Health',
				'callback' => fn (): array => $this->checkAccountHealth(),
			],
			[
				'id'       => 'cloud_poller_intervals',
				'category' => 'cloud',
				'title'    => 'Cloud Poller and Collection Interval Alignment',
				'callback' => fn (): array => $this->checkIntervals(),
			],
		];
	}

	/**
	 * @return array{status: string, message: string, details: array<string, mixed>}
	 */
	public function checkSchema(): array {
		$stored = $this->schema->storedVersion();

		if ($stored === Schema::VERSION) {
			return [
				'status'  => 'ok',
				'message' => 'Cloud plugin schema is current (version ' . Schema::VERSION . ').',
				'details' => ['stored_version' => $stored, 'expected_version' => Schema::VERSION],
			];
		}

		return [
			'status'  => 'error',
			'message' => sprintf('Cloud plugin schema version %d does not match expected version %d.', $stored, Schema::VERSION),
			'details' => ['stored_version' => $stored, 'expected_version' => Schema::VERSION],
		];
	}

	/**
	 * @return array{status: string, message: string, details: array<string, mixed>}
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
				'status'  => 'ok',
				'message' => 'Vault key file is readable and passes permission and size checks.',
				'details' => ['vault_accounts' => $vaultAccounts],
			];
		} catch (CredentialError $e) {
			return [
				'status'  => $vaultAccounts > 0 ? 'error' : 'warning',
				'message' => 'Vault key file check failed: ' . $e->getMessage(),
				'details' => ['vault_accounts' => $vaultAccounts],
			];
		}
	}

	/**
	 * @return array{status: string, message: string, details: array<string, mixed>}
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
				'status'  => 'error',
				'message' => 'Missing production drivers for released providers: ' . implode(', ', $missing),
				'details' => ['ready' => $ready, 'missing' => $missing],
			];
		}

		return [
			'status'  => 'ok',
			'message' => 'All released provider drivers are registered (' . implode(', ', $ready) . ').',
			'details' => ['ready' => $ready, 'missing' => []],
		];
	}

	/**
	 * @return array{status: string, message: string, details: array<string, mixed>}
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
				'status'  => 'error',
				'message' => 'Failed cloud accounts: ' . implode(', ', $failed),
				'details' => ['enabled' => $enabled, 'failed' => $failed, 'throttled' => $throttled, 'stale' => $stale],
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
				'status'  => 'warning',
				'message' => 'Degraded cloud accounts (' . implode('; ', $parts) . ').',
				'details' => ['enabled' => $enabled, 'failed' => [], 'throttled' => $throttled, 'stale' => $stale],
			];
		}

		return [
			'status'  => 'ok',
			'message' => sprintf('%d enabled cloud account(s) healthy.', $enabled),
			'details' => ['enabled' => $enabled, 'failed' => [], 'throttled' => [], 'stale' => []],
		];
	}

	/**
	 * @return array{status: string, message: string, details: array<string, mixed>}
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
				'status'  => 'warning',
				'message' => 'Collection intervals misaligned with poller interval: ' . implode(', ', $misaligned),
				'details' => ['poller_interval' => $this->pollerInterval, 'misaligned' => $misaligned],
			];
		}

		return [
			'status'  => 'ok',
			'message' => 'All enabled cloud account collection intervals align with the poller interval.',
			'details' => ['poller_interval' => $this->pollerInterval, 'misaligned' => []],
		];
	}
}
