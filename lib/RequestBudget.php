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
 * How many requests one collector run of an account may send, and which
 * series fit in them (REQ-BUDGET-001, REQ-BUDGET-002).
 *
 * A run may use the account's share of the provider's hourly limit spread
 * over its interval, and never more than its share of the per-minute limit,
 * so runs a poller cycle apart stay inside both. A monthly cost ceiling
 * cuts the series further. Series keep their stored order, so the same
 * ones are read each run and the rest are marked unread with reason budget.
 */
final class RequestBudget {
	/**
	 * Observation status recorded when a series is deferred due to request budget.
	 */
	public const REASON = 'budget';

	/**
	 * Per-credential limits, [per hour, per minute], from
	 * docs/research/provider-facts.md. A provider not listed has no request
	 * budget until its phase adds one.
	 */
	private const LIMITS = [
		'digitalocean' => [5000, 250],
	];

	/**
	 * The account's share of its token's hourly limit, or null for a
	 * provider without one.
	 */
	public static function hourlyShare(Account $account): ?int {
		$limits = self::LIMITS[$account->provider->value] ?? null;

		return $limits === null ? null : intdiv($limits[0] * $account->requestShare, 100);
	}

	/**
	 * Compute the maximum provider API requests allowed in a single collector run.
	 *
	 * @param Account $account target cloud account
	 * @return int|null request budget per run, or null when the provider has no fixed cap
	 */
	public static function perRun(Account $account): ?int {
		$limits = self::LIMITS[$account->provider->value] ?? null;

		if ($limits === null) {
			return null;
		}

		[$hour, $minute] = $limits;
		$interval        = max(60, Collector::dueAfter($account->collectInterval));

		return min(intdiv($hour * $account->requestShare * $interval, 360000), intdiv($minute * $account->requestShare, 100));
	}

	/**
	 * Split $wanted into the series this run reads and those it leaves,
	 * after $spent requests already went to discovery.
	 *
	 * @param list<MetricRequest> $wanted
	 * @return array{list<MetricRequest>, list<MetricRequest>}
	 */
	public static function split(Account $account, ProviderDriver $driver, array $wanted, int $spent): array {
		if ($wanted === []) {
			return [[], []];
		}

		$estimate = $driver->estimate($wanted, $account->collectInterval);
		$fit      = count($wanted);
		$perRun   = self::perRun($account);

		// Requests per series, so a provider that batches series counts right.
		$cost = max(1, $estimate->requestsPerCollection) / count($wanted);

		if ($perRun !== null) {
			$fit = min($fit, (int) floor(max(0, $perRun - $spent) / $cost));
		}

		if ($account->monthlyCeiling !== null && $estimate->monthlyCost > $account->monthlyCeiling) {
			$fit = min($fit, (int) floor(count($wanted) * $account->monthlyCeiling / $estimate->monthlyCost));
		}

		return [array_slice($wanted, 0, $fit), array_slice($wanted, $fit)];
	}
}
