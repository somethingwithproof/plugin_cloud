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
 * The Cloud Request Estimate box on the health page: what each account
 * collects now (REQ-COST-002) and what each graph would add before it is
 * created (REQ-COST-001). Every figure comes from cached inventory.
 */
final class EstimateView {
	public const ANCHOR = 'cloud-estimate-';

	public function __construct(
		private readonly CostPreview $cost,
		private readonly Drivers $drivers,
	) {
	}

	/**
	 * @param list<Account> $accounts
	 */
	public function render(array $accounts): void {
		html_start_box(__('Cloud Request Estimate', 'cloud'), '100%', '', '3', 'center', '');

		$headers = [
			__('Account', 'cloud'),
			__('Selection', 'cloud'),
			__('Resources', 'cloud'),
			__('Requests per Hour, One Resource', 'cloud'),
			__('Requests per Hour', 'cloud'),
			__('Requests per Month', 'cloud'),
			__('Cost per Month', 'cloud'),
		];

		print '<tr class="tableHeader"><th>' . implode('</th><th>', array_map('html_escape', $headers)) . '</th></tr>';

		$sources = [];

		foreach ($accounts as $account) {
			$driver = $this->drivers->for($account);

			if ($driver === null) {
				continue;
			}

			$first = true;

			foreach ($this->rows($account, $driver) as $row) {
				$attributes = $first ? ' id="' . self::ANCHOR . $account->id . '"' : '';
				$first      = false;

				print '<tr class="tableRow"' . $attributes . '><td>'
					. implode('</td><td>', array_map('html_escape', $row['cells'])) . '</td></tr>';

				if ($row['source'] !== '') {
					$sources[$account->provider->label()] = [$row['source'], $row['checked']];
				}
			}

			$note = $this->budgetNote($account, $driver);

			if ($note !== null) {
				print '<tr><td colspan="' . count($headers) . '"' . ($note[1] ? ' class="textError"' : '') . '>'
					. html_escape($note[0]) . '</td></tr>';
			}
		}

		if ($sources === []) {
			print '<tr><td colspan="' . count($headers) . '"><em>'
				. html_escape(__('No accounts to estimate.', 'cloud')) . '</em></td></tr>';
		}

		print '<tr><td colspan="' . count($headers) . '">' . html_escape(__(
			'Estimates use list prices and exclude discounts and shared free tiers.',
			'cloud'
		));

		foreach ($sources as $provider => [$source, $checked]) {
			print '<br>' . html_escape(__('%s prices from', $provider, 'cloud')) . ' <a href="' . html_escape($source)
				. '" target="_blank" rel="noopener noreferrer">' . html_escape($source) . '</a>'
				. html_escape(', ' . __('checked %s.', $checked, 'cloud'));
		}

		print '</td></tr>';

		html_end_box();
	}

	/**
	 * The account's rows as plain text: what it collects now, every graph
	 * on every resource, then each graph.
	 *
	 * @return list<array{cells: list<string>, source: string, checked: string}>
	 */
	public function rows(Account $account, ProviderDriver $driver): array {
		$interval  = $account->collectInterval;
		$collected = $this->cost->collected($account, $driver);
		$all       = $this->cost->everything($account, $driver);
		$rows      = [
			self::row($account->name, __('Collected now', 'cloud'), '-', '-', $collected, $interval),
			self::row('', __('Every graph on every resource', 'cloud'), '-', '-', $all, $interval),
		];

		foreach ($this->cost->graphs($account, $driver) as $graph) {
			$rows[] = self::row(
				'',
				self::typeLabel($graph['type']) . ': ' . $graph['title'],
				(string) $graph['resources'],
				number_format(CostPreview::perHour($graph['perResource'], $interval)),
				$graph['all'],
				$interval
			);
		}

		return $rows;
	}

	/**
	 * Whether graphing everything fits the account's share of its token's
	 * hourly limit, and whether what it collects now does.
	 *
	 * @return array{0: string, 1: bool}|null the note, and whether it warns
	 */
	public function budgetNote(Account $account, ProviderDriver $driver): ?array {
		$share = RequestBudget::hourlyShare($account);

		if ($share === null) {
			return null;
		}

		$now = CostPreview::perHour($this->cost->collected($account, $driver), $account->collectInterval);
		$all = CostPreview::perHour($this->cost->everything($account, $driver), $account->collectInterval);

		return [
			__(
				// Cacti's __() formats its own way and has no %%, so every value is a string.
				'Request share: %s requests per hour, %s percent of the token limit. Collected now: %s. Every graph: %s.',
				number_format($share),
				(string) $account->requestShare,
				number_format($now),
				number_format($all),
				'cloud'
			) . ($now > $share ? ' ' . __('Collection already exceeds the share; the rest reads U.', 'cloud') : ''),
			$now > $share,
		];
	}

	/**
	 * @return array{cells: list<string>, source: string, checked: string}
	 */
	private static function row(
		string $account,
		string $selection,
		string $resources,
		string $oneResource,
		UsageEstimate $estimate,
		int $interval
	): array {
		return [
			'cells'   => [
				$account,
				$selection,
				$resources,
				$oneResource,
				number_format(CostPreview::perHour($estimate, $interval)),
				number_format($estimate->requestsPerMonth),
				$estimate->monthlyCost > 0
					? '$' . number_format($estimate->monthlyCost, 2)
					: __('No charge documented', 'cloud'),
			],
			'source'  => $estimate->priceSource,
			'checked' => $estimate->priceChecked,
		];
	}

	private static function typeLabel(string $type): string {
		return match ($type) {
			DigitalOceanCatalog::DROPLET       => __('Droplet', 'cloud'),
			DigitalOceanCatalog::LOAD_BALANCER => __('Load Balancer', 'cloud'),
			default                            => $type,
		};
	}
}
