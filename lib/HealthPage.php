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
 * The Cloud Health page. It shows what the collectors last recorded and
 * never calls a provider. Ages come from datapoint and run times, so a recent
 * page load never looks like a recent measurement.
 *
 * Until the metric catalogs arrive with each driver, freshness uses the
 * account's collection interval as the metric period and no publication
 * delay.
 */
final class HealthPage {
	public const FILE = 'cloud_health.php';

	public function __construct(
		private readonly AccountRepository $accounts,
		private readonly HealthRepository $health,
		private readonly ResourceRepository $resources,
		private readonly ObservationRepository $observations,
		private readonly FreshnessEvaluator $freshness,
		private readonly Clock $clock,
	) {
	}

	public function render(Settings $settings): void {
		$this->renderVault($settings);

		html_start_box(__('Cloud Collection', 'cloud'), '100%', '', '3', 'center', '');

		print '<tr class="tableHeader"><th>' . implode('</th><th>', array_map('html_escape', [
			__('Account', 'cloud'), __('Collection', 'cloud'), __('Last Run', 'cloud'), __('Series Read', 'cloud'), __('Requests', 'cloud'), __('Discovery', 'cloud'), __('Resources', 'cloud'), __('Data', 'cloud'),
		])) . '</th></tr>';

		$accounts = $this->accounts->all();

		if ($accounts === []) {
			print '<tr><td colspan="8"><em>' . html_escape(__('No cloud accounts yet.', 'cloud')) . '</em></td></tr>';
		}

		foreach ($accounts as $account) {
			$row = $this->row($account);

			print '<tr class="tableRow"><td>' . implode('</td><td>', array_map('html_escape', $row)) . '</td></tr>';
		}

		html_end_box();
	}

	/**
	 * The cells of one account's row, as plain text.
	 *
	 * @return list<string>
	 */
	public function row(Account $account): array {
		$health = $this->health->find($account->id);

		$collection = $account->enabled() ? $this->collectionLabel($health) : __('Disabled', 'cloud');
		$counts     = $this->resources->counts($account->id);
		$freshness  = [];

		foreach ($this->observations->forAccount($account->id) as $entry) {
			$state = match (true) {
				// A recent datapoint without a value is still no measurement.
				$entry['observation']->value === null => 'unknown',
				$entry['status'] === 'ok'             => $this->freshness->evaluate($entry['observation'], $account->collectInterval, 0, $account->collectInterval)->value,
				default                               => $entry['status'],
			};

			$freshness[$state] = ($freshness[$state] ?? 0) + 1;
		}

		ksort($freshness);

		return [
			$account->name,
			$collection,
			$health->runStarted > 0 ? $this->age($health->runStarted) : __('Never', 'cloud'),
			$health->seriesTotal > 0 ? $health->seriesRead . ' / ' . $health->seriesTotal : '-',
			(string) $health->requests,
			$health->discoveredAt > 0 ? $health->discovery->value . ', ' . $this->age($health->discoveredAt) : __('Never', 'cloud'),
			$counts === [] ? '-' : implode(', ', array_map(static fn (string $state, int $n): string => "$state $n", array_keys($counts), $counts)),
			$freshness === [] ? '-' : implode(', ', array_map(static fn (string $state, int $n): string => "$state $n", array_keys($freshness), $freshness)),
		];
	}

	private function collectionLabel(AccountHealth $health): string {
		return $health->error === null ? $health->collection->value : $health->collection->value . ' (' . $health->error->value . ')';
	}

	private function age(int $time): string {
		$seconds = max(0, $this->clock->now() - $time);

		return match (true) {
			$seconds < 120   => __('%d seconds ago', $seconds, 'cloud'),
			$seconds < 7200  => __('%d minutes ago', intdiv($seconds, 60), 'cloud'),
			$seconds < 172800 => __('%d hours ago', intdiv($seconds, 3600), 'cloud'),
			default          => __('%d days ago', intdiv($seconds, 86400), 'cloud'),
		};
	}

	/**
	 * Checked on the server rendering the page. A collector on another poller
	 * reads its own copy of the key file.
	 */
	private function renderVault(Settings $settings): void {
		try {
			VaultKey::fromFile($settings->vaultKeyFile);
			$message = __('The vault key file is present and passes its checks.', 'cloud');
		} catch (CredentialError $e) {
			$message = __('The vault key file cannot be used: %s. Accounts that store credentials in the vault cannot be verified or collected here.', $e->getMessage(), 'cloud');
		}

		html_start_box(__('Credential Vault', 'cloud'), '100%', '', '3', 'center', '');
		print '<tr><td>' . html_escape($message) . '</td></tr>';
		html_end_box();
	}
}
