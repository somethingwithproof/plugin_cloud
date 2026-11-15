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
use Somethingwithproof\Cloud\CostPreview;
use Somethingwithproof\Cloud\DigitalOceanDriver;
use Somethingwithproof\Cloud\Drivers;
use Somethingwithproof\Cloud\EstimateView;
use Somethingwithproof\Cloud\FixedClock;
use Somethingwithproof\Cloud\RefusingTransport;
use Somethingwithproof\Cloud\ResourceRepository;
use Somethingwithproof\Cloud\SeriesRepository;

/*
 * #41's acceptance: estimates for a fixture selection match hand-computed
 * values. Three Droplets and one load balancer, collected every 300 s, so a
 * series costs 12 requests an hour and 8,640 a month (30 days).
 */

function cloud_cost_account(array $row = []): Account {
	return Account::fromRow($row + [
		'id'                => 4,
		'provider'          => 'digitalocean',
		'name'              => 'Ops',
		'credential_source' => 'vault',
		'collect_interval'  => 300,
		'request_share'     => 50,
		'admin_state'       => 'enabled',
	]);
}

/**
 * @param list<string> $collected Droplet metrics the collector reads, one series each on d1
 */
function cloud_cost_world(array $collected = ['do_cpu_pct', 'do_load_1']): void {
	$GLOBALS['__cloud_db_rows'] = [
		'FROM plugin_cloud_resource' => fn (string $sql, array $params): array => match ($params[1]) {
			'droplet'       => [
				['id' => 1, 'provider_id' => '101', 'name' => 'a', 'first_seen' => 0],
				['id' => 2, 'provider_id' => '102', 'name' => 'b', 'first_seen' => 0],
				['id' => 3, 'provider_id' => '103', 'name' => 'c', 'first_seen' => 0],
			],
			'load_balancer' => [
				['id' => 9, 'provider_id' => '4de7ac8b-495b-4884-9a69-1050c6793cd6', 'name' => 'lb', 'first_seen' => 0],
			],
			default         => [],
		},
		'FROM plugin_cloud_series'   => array_map(fn (string $metric): array => [
			'resource_id' => 1,
			'type'        => 'droplet',
			'provider_id' => '101',
			'metric'      => $metric,
		], $collected),
	];
}

function cloud_cost_view(): EstimateView {
	$clock = new FixedClock(1_800_000_000);

	return new EstimateView(
		new CostPreview(new ResourceRepository(false, $clock), new SeriesRepository(false)),
		Drivers::production()
	);
}

function cloud_cost_driver(): DigitalOceanDriver {
	return new DigitalOceanDriver(new RefusingTransport(), new FixedClock(1_800_000_000));
}

it('estimates each graph, everything, and what is collected now from the driver\'s request count', function () {
	cloud_cost_world();

	$rows = array_column(cloud_cost_view()->rows(cloud_cost_account(), cloud_cost_driver()), 'cells');
	$by   = array_column($rows, null, 1);
	$none = 'No charge documented';

	// Account, selection, resources, per hour on one resource, per hour, per month, cost.
	expect($rows[0])->toBe(['Ops', 'Collected now', '-', '-', '24', '17,280', $none])
		->and($rows[1])->toBe(['', 'Every graph on every resource', '-', '-', '624', '449,280', $none])
		->and($by['Droplet: CPU'])->toBe(['', 'Droplet: CPU', '3', '12', '36', '25,920', $none])
		->and($by['Droplet: Bandwidth'])->toBe(['', 'Droplet: Bandwidth', '3', '48', '144', '103,680', $none])
		->and($by['Droplet: Load Average'])->toBe(['', 'Droplet: Load Average', '3', '36', '108', '77,760', $none])
		->and($by['Droplet: Memory'])->toBe(['', 'Droplet: Memory', '3', '48', '144', '103,680', $none])
		->and($by['Droplet: Collection Age'])->toBe(['', 'Droplet: Collection Age', '3', '0', '0', '0', $none])
		->and($by['Load Balancer: Throughput'])->toBe(['', 'Load Balancer: Throughput', '1', '60', '60', '43,200', $none])
		->and($rows)->toHaveCount(2 + 15);
});

it('agrees with the graphs: every graph on every resource is the sum of the graph rows', function () {
	cloud_cost_world();

	$rows  = array_column(cloud_cost_view()->rows(cloud_cost_account(), cloud_cost_driver()), 'cells');
	$total = array_sum(array_map(fn (array $row): int => (int) str_replace(',', '', $row[4]), array_slice($rows, 2)));

	expect($total)->toBe(624);
});

it('names the price source and check date, and that estimates use list prices', function () {
	cloud_cost_world();
	$rows = cloud_cost_view()->rows(cloud_cost_account(), cloud_cost_driver());

	expect($rows[0]['source'])->toBe('https://docs.digitalocean.com/reference/api/reference/openapi.json')
		->and($rows[0]['checked'])->toBe('2026-11-14');

	ob_start();
	cloud_cost_view()->render([cloud_cost_account()]);
	$html = (string) ob_get_clean();

	expect($html)->toContain('Estimates use list prices and exclude discounts and shared free tiers.')
		->and($html)->toContain('href="https://docs.digitalocean.com/reference/api/reference/openapi.json"')
		->and($html)->toContain('checked 2026-11-14.')
		->and($html)->toContain('id="cloud-estimate-4"');
});

it('compares collection and everything with the account\'s request share', function () {
	cloud_cost_world();
	expect(cloud_cost_view()->budgetNote(cloud_cost_account(), cloud_cost_driver()))->toBe([
		'Request share: 2,500 requests per hour, 50 percent of the token limit. Collected now: 24. Every graph: 624.',
		false,
	]);

	// 1% of 5,000 is 50 an hour; five series are 60.
	cloud_cost_world(['do_cpu_pct', 'do_load_1', 'do_load_5', 'do_load_15', 'do_mem_free']);
	expect(cloud_cost_view()->budgetNote(cloud_cost_account(['request_share' => 1]), cloud_cost_driver()))->toBe([
		'Request share: 50 requests per hour, 1 percent of the token limit. Collected now: 60. Every graph: 624. '
			. 'Collection already exceeds the share; the rest reads U.',
		true,
	]);
});

it('scales with the interval: every 60 s costs five times every 300 s', function () {
	cloud_cost_world();
	$view = cloud_cost_view();
	$rows = array_column($view->rows(cloud_cost_account(['collect_interval' => 60]), cloud_cost_driver()), 'cells');

	expect($rows[1])->toBe(['', 'Every graph on every resource', '-', '-', '3,120', '2,246,400', 'No charge documented']);
});
