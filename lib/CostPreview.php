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
 * Usage estimates for what an account could graph and what it collects
 * (REQ-COST-001, REQ-COST-002, ADR 0005). Every figure comes from the
 * provider driver's own estimate, from cached inventory only; nothing here
 * calls a provider.
 */
final class CostPreview {
	private const SECONDS_PER_HOUR = 3600;

	public function __construct(
		private readonly ResourceRepository $resources,
		private readonly SeriesRepository $series,
	) {
	}

	/**
	 * One row per graph the account's resources can have: the cost of that
	 * graph on one resource, and on every resource of its type.
	 *
	 * @return list<array{graph: string, type: string, title: string, resources: int,
	 *     perResource: UsageEstimate, all: UsageEstimate}>
	 */
	public function graphs(Account $account, ProviderDriver $driver): array {
		if ($account->provider !== Provider::DigitalOcean) {
			return [];
		}

		$rows   = [];
		$served = [];

		foreach (DigitalOceanCatalog::GRAPHS as $graph => [$type, $title, , , $fields]) {
			$served[$type] ??= $this->resources->served($account->id, $type);
			$metrics         = self::metrics($fields);
			$example         = [['id' => 0, 'providerId' => '0']];

			$rows[] = [
				'graph'       => $graph,
				'type'        => $type,
				'title'       => $title,
				'resources'   => count($served[$type]),
				'perResource' => $driver->estimate(self::requests($example, $type, $metrics), $account->collectInterval),
				'all'         => $driver->estimate(self::requests($served[$type], $type, $metrics), $account->collectInterval),
			];
		}

		return $rows;
	}

	/**
	 * Every graph on every resource the account has.
	 */
	public function everything(Account $account, ProviderDriver $driver): UsageEstimate {
		$requests = [];

		if ($account->provider === Provider::DigitalOcean) {
			$metrics = [];

			foreach (DigitalOceanCatalog::GRAPHS as [$type, , , , $fields]) {
				$metrics[$type] = array_merge($metrics[$type] ?? [], self::metrics($fields));
			}

			foreach ($metrics as $type => $names) {
				$requests = array_merge(
					$requests,
					self::requests($this->resources->served($account->id, $type), $type, $names)
				);
			}
		}

		return $driver->estimate($requests, $account->collectInterval);
	}

	/**
	 * The metric series that fit the collector budget. Discovery requests
	 * already spent by a run reduce the allowance in exactly the same way.
	 */
	public function collected(Account $account, ProviderDriver $driver, int $discoveryRequests = 0): UsageEstimate {
		[$fit] = RequestBudget::split($account, $driver, $this->series->enabledFor($account->id), $discoveryRequests);

		return $driver->estimate($fit, $account->collectInterval);
	}

	public function requested(Account $account, ProviderDriver $driver): UsageEstimate {
		return $driver->estimate($this->series->enabledFor($account->id), $account->collectInterval);
	}

	public static function perHour(UsageEstimate $estimate, int $collectInterval): int {
		return (int) ceil($estimate->requestsPerCollection * self::SECONDS_PER_HOUR / max(1, $collectInterval));
	}

	/**
	 * The fields of a graph that read a metric; age reads nothing.
	 *
	 * @param list<string> $fields
	 * @return list<string>
	 */
	private static function metrics(array $fields): array {
		return array_values(array_filter(
			$fields,
			static fn (string $field): bool => DigitalOceanCatalog::metric($field) !== null
		));
	}

	/**
	 * @param list<array{id: int, providerId: string}> $resources
	 * @param list<string>                             $metrics
	 * @return list<MetricRequest>
	 */
	private static function requests(array $resources, string $type, array $metrics): array {
		$requests = [];

		foreach ($resources as $resource) {
			foreach ($metrics as $metric) {
				$requests[] = new MetricRequest($resource['id'], $type, $resource['providerId'], $metric);
			}
		}

		return $requests;
	}
}
