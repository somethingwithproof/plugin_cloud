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
use Somethingwithproof\Cloud\DigitalOceanDriver;
use Somethingwithproof\Cloud\FixedClock;
use Somethingwithproof\Cloud\HttpRequest;
use Somethingwithproof\Cloud\HttpResponse;
use Somethingwithproof\Cloud\HttpTransport;
use Somethingwithproof\Cloud\MetricRequest;
use Somethingwithproof\Cloud\RequestBudget;

function cloud_budget_account(array $row = []): Account {
	return Account::fromRow($row + [
		'id'                => 1,
		'provider'          => 'digitalocean',
		'credential_source' => 'vault',
		'collect_interval'  => 300,
		'request_share'     => 50,
	]);
}

function cloud_budget_driver(): DigitalOceanDriver {
	return new DigitalOceanDriver(new class () implements HttpTransport {
		public function send(HttpRequest $request): HttpResponse {
			throw new LogicException('the budget never sends');
		}
	}, new FixedClock(0));
}

/**
 * @return list<MetricRequest>
 */
function cloud_budget_series(int $droplets): array {
	$series = [];

	for ($i = 1; $i <= $droplets; $i++) {
		foreach (['do_cpu_pct', 'do_bw_pub_in', 'do_bw_pub_out', 'do_bw_prv_in', 'do_bw_prv_out'] as $metric) {
			$series[] = new MetricRequest($i, 'droplet', (string) (1000 + $i), $metric);
		}
	}

	return $series;
}

it('allows the smaller of the hourly share per run and the per-minute share', function (
	int $interval,
	int $share,
	int $expected
) {
	expect(RequestBudget::perRun(cloud_budget_account(['collect_interval' => $interval, 'request_share' => $share])))
		->toBe($expected);
})->with([
	// 5,000 x 50% x 270 s / 3,600 s = 187, above 250 x 50% = 125.
	'5 minutes at the default share' => [300, 50, 125],
	// 5,000 x 10% x 60 s / 3,600 s = 8.
	'1 minute at 10 percent'         => [60, 10, 8],
	// 5,000 x 1% x 270 s / 3,600 s = 3, above 250 x 1% = 2.
	'5 minutes at 1 percent'         => [300, 1, 2],
]);

it('keeps 500 Droplets inside the per-minute and per-hour limits, reading in stored order', function () {
	$account = cloud_budget_account();
	$wanted  = cloud_budget_series(500);
	$perRun  = RequestBudget::perRun($account);

	// Hourly discovery: 5 Droplet pages and 1 load balancer page.
	[$fit, $left] = RequestBudget::split($account, cloud_budget_driver(), $wanted, 6);

	expect(count($wanted))->toBe(2500)
		->and($fit)->toBe(array_slice($wanted, 0, 119))
		->and($left)->toBe(array_slice($wanted, 119))
		// One run a poller cycle apart never exceeds the per-minute share.
		->and($perRun)->toBeLessThanOrEqual(125)
		// Runs come no closer than the collector's due threshold (270 s).
		->and((int) ceil(3600 / 270) * $perRun)->toBeLessThanOrEqual(2500);
});

it('reads everything for a provider with no known limit', function () {
	$account = Account::fromRow([
		'id'                => 1,
		'provider'          => 'aws',
		'credential_source' => 'vault',
		'scope'             => json_encode(['account_id' => '123456789012', 'regions' => 'us-east-1']),
	]);

	expect(RequestBudget::perRun($account))->toBeNull();
});

it('cuts the series to the monthly cost ceiling', function () {
	$driver = new class () implements \Somethingwithproof\Cloud\ProviderDriver {
		public function provider(): \Somethingwithproof\Cloud\Provider {
			return \Somethingwithproof\Cloud\Provider::Aws;
		}

		public function endpoints(Account $account): \Somethingwithproof\Cloud\OutboundPolicy {
			return new \Somethingwithproof\Cloud\OutboundPolicy([]);
		}

		public function operations(): array {
			return [];
		}

		public function verify(
			Account $account,
			\Somethingwithproof\Cloud\Secret $credential
		): \Somethingwithproof\Cloud\VerifyStatus {
			return \Somethingwithproof\Cloud\VerifyStatus::Ok;
		}

		public function discover(
			Account $account,
			\Somethingwithproof\Cloud\Secret $credential
		): \Somethingwithproof\Cloud\Inventory {
			return new \Somethingwithproof\Cloud\Inventory([], 0);
		}

		public function collect(
			Account $account,
			\Somethingwithproof\Cloud\Secret $credential,
			array $requests
		): \Somethingwithproof\Cloud\CollectResult {
			return new \Somethingwithproof\Cloud\CollectResult([], 0);
		}

		// $2.00 a month per series.
		public function estimate(array $requests, int $intervalSeconds): \Somethingwithproof\Cloud\UsageEstimate {
			return new \Somethingwithproof\Cloud\UsageEstimate(count($requests), 0, 2.0 * count($requests));
		}
	};

	$account = Account::fromRow([
		'id'                => 1,
		'provider'          => 'aws',
		'credential_source' => 'vault',
		'monthly_ceiling'   => 7.0,
		'scope'             => json_encode(['account_id' => '123456789012', 'regions' => 'us-east-1']),
	]);
	$wanted  = cloud_budget_series(2);

	[$fit, $left] = RequestBudget::split($account, $driver, $wanted, 0);

	// 10 series cost $20; $7 pays for 3 of them.
	expect($fit)->toBe(array_slice($wanted, 0, 3))->and($left)->toHaveCount(7);
});

it('shows a budget run, and how long a throttled account waits, on the health page', function (
	array $health,
	string $expected
) {
	$clock = new FixedClock(1800000000);

	$GLOBALS['__cloud_db_rows'] = [
		'FROM plugin_cloud_health' => [$health + ['account_id' => 1, 'run_started' => 1800000000 - 60]],
	];

	$page = new \Somethingwithproof\Cloud\HealthPage(
		new \Somethingwithproof\Cloud\HealthRepository(false),
		new \Somethingwithproof\Cloud\ResourceRepository(false, $clock),
		new \Somethingwithproof\Cloud\ObservationRepository(false),
		new \Somethingwithproof\Cloud\FreshnessEvaluator($clock),
		new \Somethingwithproof\Cloud\HealthWarnings(static fn () => null, static fn () => null),
		$clock,
	);

	try {
		expect($page->row(cloud_budget_account(['admin_state' => 'enabled']))[1])->toBe($expected);
	} finally {
		$GLOBALS['__cloud_db_rows'] = [];
	}
})->with([
	'over budget'      => [['collection' => 'budget'], 'budget'],
	'throttled, wait'  => [
		['collection' => 'failed', 'error_class' => 'throttled', 'retry_at' => 1800000000 + 840],
		'failed (throttled), retry in 840 s',
	],
	'wait has passed'  => [
		['collection' => 'failed', 'error_class' => 'throttled', 'retry_at' => 1800000000 - 1],
		'failed (throttled)',
	],
]);
