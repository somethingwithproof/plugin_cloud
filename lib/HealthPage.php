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
		private readonly HealthWarnings $warnings,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @param bool $admin whether the viewer has Cloud Admin, which alone sees
	 *                    credential state and credential warnings
	 */
	public function render(Settings $settings, bool $admin, int $pollerInterval): void {
		$accounts = $this->accounts->all();

		html_start_box(__('Cloud Warnings', 'cloud'), '100%', '', '3', 'center', '');

		$shown = 0;

		foreach ($this->warnings->for($settings, $accounts, $pollerInterval) as $warning) {
			if ($warning['admin'] && !$admin) {
				continue;
			}

			print '<tr><td class="textError">' . html_escape($warning['message']) . '</td></tr>';
			$shown++;
		}

		if ($shown === 0) {
			print '<tr><td>' . html_escape(__('No warnings.', 'cloud')) . '</td></tr>';
		}

		html_end_box();

		html_start_box(__('Cloud Collection', 'cloud'), '100%', '', '3', 'center', '');

		$headers = [__('Account', 'cloud'), __('Collection', 'cloud'), __('Last Run', 'cloud'), __('Series Read', 'cloud'), __('Requests', 'cloud'), __('Discovery', 'cloud'), __('Resources', 'cloud'), __('Data', 'cloud')];

		if ($admin) {
			array_splice($headers, 1, 0, [__('Credential', 'cloud')]);
		}

		print '<tr class="tableHeader"><th>' . implode('</th><th>', array_map('html_escape', $headers)) . '</th></tr>';

		if ($accounts === []) {
			print '<tr><td colspan="' . count($headers) . '"><em>' . html_escape(__('No cloud accounts yet.', 'cloud')) . '</em></td></tr>';
		}

		foreach ($accounts as $account) {
			$row = $this->row($account);

			if ($admin) {
				array_splice($row, 1, 0, [$account->verifyStatus->message()]);
			}

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
}
