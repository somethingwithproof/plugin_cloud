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


use Somethingwithproof\Cloud\AccountRepository;
use Somethingwithproof\Cloud\DeviceRepository;
use Somethingwithproof\Cloud\FixedClock;
use Somethingwithproof\Cloud\FreshnessEvaluator;
use Somethingwithproof\Cloud\ObservationRepository;
use Somethingwithproof\Cloud\ResourceRepository;
use Somethingwithproof\Cloud\ScriptServer;

/*
 * ss_cloud()'s answers (#44) against the database stubs: one account whose
 * scope device is host 7, one Droplet, and its cached datapoints.
 */

const CLOUD_SS_NOW = 1_800_000_000;

function cloud_ss(): ScriptServer {
	$clock = new FixedClock(CLOUD_SS_NOW);

	return new ScriptServer(
		new DeviceRepository(false, $clock),
		new AccountRepository(false, $clock),
		new ResourceRepository(false, $clock),
		new ObservationRepository(false),
		new FreshnessEvaluator($clock),
		$clock
	);
}

/**
 * @param list<array<string, mixed>> $observations
 */
function cloud_ss_world(array $observations = [], array $account = [], ?int $owner = 4): void {
	$GLOBALS['__cloud_db_rows'] = [
		'FROM plugin_cloud_device'      => $owner === null ? [] : [['account_id' => $owner]],
		'FROM plugin_cloud_account'     => [$account + [
			'id'                => 4,
			'provider'          => 'digitalocean',
			'name'              => 'Ops',
			'credential_source' => 'vault',
			'collect_interval'  => 300,
			'admin_state'       => 'enabled',
		]],
		'FROM plugin_cloud_resource'    => [[
			'id'          => 11,
			'provider_id' => '3164444',
			'name'        => "web!01\nprod",
			'first_seen'  => CLOUD_SS_NOW - 5000,
			'lifecycle'   => 'active',
		]],
		'FROM plugin_cloud_observation' => $observations,
	];
}

function cloud_ss_obs(string $metric, ?float $value, int $age, string $status = 'ok'): array {
	return [
		'resource_id' => 11,
		'metric'      => $metric,
		'value'       => $value,
		'observed_at' => $value === null && $status !== 'ok' ? 0 : CLOUD_SS_NOW - $age,
		'fetched_at'  => CLOUD_SS_NOW - 10,
		'status'      => $status,
	];
}

it('lists the account\'s resources as indexes, with ids and names for reindex', function () {
	cloud_ss_world();

	expect(cloud_ss()->run(7, 'droplet', 'index'))->toBe("3164444\n")
		->and(cloud_ss()->run(7, 'droplet', 'num_indexes'))->toBe('1')
		->and(cloud_ss()->run(7, 'droplet', 'query', 'resourceId'))->toBe("3164444!3164444\n")
		->and(cloud_ss()->run(7, 'droplet', 'query', 'resourceName'))->toBe("3164444!web 01 prod\n");
});

dataset('freshness', [
	// The account interval is 300 s, so current is within 600 s and delayed within 1200 s.
	'current'  => [cloud_ss_obs('do_cpu_pct', 12.5, 60), '12.5'],
	'delayed'  => [cloud_ss_obs('do_cpu_pct', 12.5, 900), '12.5'],
	'stale'    => [cloud_ss_obs('do_cpu_pct', 12.5, 1300), 'U'],
	'skewed'   => [cloud_ss_obs('do_cpu_pct', 12.5, -600), 'U'],
	'no value' => [cloud_ss_obs('do_cpu_pct', null, 60), 'U'],
	'budget'   => [cloud_ss_obs('do_cpu_pct', 12.5, 60, 'budget'), 'U'],
	'failed'   => [cloud_ss_obs('do_cpu_pct', null, 60, 'failed'), 'U'],
	'never'    => [cloud_ss_obs('do_cpu_pct', null, 0, 'never'), 'U'],
]);

it('serves a value only while it is current or delayed, and U otherwise', function (
	array $observation,
	string $expected
) {
	cloud_ss_world([$observation]);

	expect(cloud_ss()->run(7, 'droplet', 'get', 'do_cpu_pct', '3164444'))->toBe($expected)
		->and(cloud_ss()->run(7, 'droplet', 'query', 'do_cpu_pct'))->toBe('3164444!' . $expected . "\n");
})->with('freshness');

it('serves U for a metric never collected and for a disabled account', function () {
	cloud_ss_world([cloud_ss_obs('do_cpu_pct', 12.5, 60)]);
	expect(cloud_ss()->run(7, 'droplet', 'get', 'do_bw_pub_in', '3164444'))->toBe('U');

	cloud_ss_world([cloud_ss_obs('do_cpu_pct', 12.5, 60)], ['admin_state' => 'disabled']);
	expect(cloud_ss()->run(7, 'droplet', 'get', 'do_cpu_pct', '3164444'))->toBe('U');
});

it('serves U for a resource discovery no longer lists, but keeps its index, name and age', function () {
	cloud_ss_world([cloud_ss_obs('do_cpu_pct', 12.5, 60)]);
	$GLOBALS['__cloud_db_rows']['FROM plugin_cloud_resource'][0]['lifecycle'] = 'missing';

	expect(cloud_ss()->run(7, 'droplet', 'get', 'do_cpu_pct', '3164444'))->toBe('U')
		->and(cloud_ss()->run(7, 'droplet', 'query', 'do_cpu_pct'))->toBe("3164444!U\n")
		->and(cloud_ss()->run(7, 'droplet', 'index'))->toBe("3164444\n")
		->and(cloud_ss()->run(7, 'droplet', 'get', 'resourceName', '3164444'))->toBe('web 01 prod')
		->and(cloud_ss()->run(7, 'droplet', 'get', 'do_age', '3164444'))->toBe('60');
});

it('keeps age numeric: since the newest value, or since discovery without one', function () {
	cloud_ss_world([cloud_ss_obs('do_cpu_pct', 1.0, 90), cloud_ss_obs('do_bw_pub_in', 2.0, 40)]);
	expect(cloud_ss()->run(7, 'droplet', 'get', 'do_age', '3164444'))->toBe('40');

	cloud_ss_world([cloud_ss_obs('do_cpu_pct', null, 0, 'never')]);
	expect(cloud_ss()->run(7, 'droplet', 'get', 'do_age', '3164444'))->toBe('5000');

	cloud_ss_world([], ['admin_state' => 'disabled']);
	expect(cloud_ss()->run(7, 'droplet', 'get', 'do_age', '3164444'))->toBe('5000');
});

it('refuses a device that is no account\'s scope device', function () {
	cloud_ss_world([cloud_ss_obs('do_cpu_pct', 12.5, 60)], [], null);

	expect(cloud_ss()->run(8, 'droplet', 'index'))->toBe('')
		->and(cloud_ss()->run(8, 'droplet', 'num_indexes'))->toBe('0')
		->and(cloud_ss()->run(8, 'droplet', 'query', 'do_cpu_pct'))->toBe('')
		->and(cloud_ss()->run(8, 'droplet', 'get', 'do_cpu_pct', '3164444'))->toBe('U');
});

it('refuses an index that is not one of the device\'s resources', function () {
	cloud_ss_world([cloud_ss_obs('do_cpu_pct', 12.5, 60)]);

	expect(cloud_ss()->run(7, 'droplet', 'get', 'do_cpu_pct', '9999'))->toBe('U')
		->and(cloud_ss()->run(7, 'droplet', 'get', 'do_cpu_pct', ''))->toBe('U');
});

it('validates the type, command and field', function () {
	cloud_ss_world([cloud_ss_obs('do_cpu_pct', 12.5, 60)]);

	expect(cloud_ss()->run(7, 'volume', 'index'))->toBe('')
		->and(cloud_ss()->run(7, 'volume', 'get', 'do_cpu_pct', '3164444'))->toBe('U')
		->and(cloud_ss()->run(7, 'droplet', 'get', 'dolb_cpu_pct', '3164444'))->toBe('U')
		->and(cloud_ss()->run(7, 'droplet', 'get', 'no_such_field', '3164444'))->toBe('U')
		->and(cloud_ss()->run(7, 'droplet', 'query', 'no_such_field'))->toBe('')
		->and(cloud_ss()->run(7, 'droplet', 'drop'))->toBe('')
		->and(cloud_ss()->run(0, 'droplet', 'get', 'do_cpu_pct', '3164444'))->toBe('U');
});

it('prints plain decimals and names that cannot break the output line', function () {
	expect(ScriptServer::number(1234567890123.0))->toBe('1234567890123')
		->and(ScriptServer::number(0.000001))->toBe('0.000001')
		->and(ScriptServer::number(-0.0))->toBe('0')
		->and(ScriptServer::number(INF))->toBe('U')
		->and(ScriptServer::label("a!b\r\nc\x00d"))->toBe('a b c d')
		->and(ScriptServer::label("!\n"))->toBe('U');
});

it('offers the index, the name, and every data source its graphs use as fields', function () {
	expect(ScriptServer::fields('droplet'))->toBe([
		'resourceId', 'resourceName', 'do_cpu_pct', 'do_bw_pub_in', 'do_bw_pub_out', 'do_bw_prv_in', 'do_bw_prv_out',
		'do_load_1', 'do_load_5', 'do_load_15', 'do_mem_total', 'do_mem_avail', 'do_mem_free', 'do_mem_cached', 'do_age',
	])
		->and(ScriptServer::fields('volume'))->toBe([]);
});
