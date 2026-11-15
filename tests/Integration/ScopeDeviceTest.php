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


/*
 * Scope devices against a real Cacti (#43, #44): created through Cacti's
 * device API, indexed through ss_cloud(), and kept in step with discovery.
 */

beforeEach(function () {
	lifecycle('reset');
	lifecycle('install');
	lifecycle('enable');
	lifecycle('seed');
});

afterAll(function () {
	lifecycle('reset');
});

it('creates a scope device with its template, no availability check, and the account\'s poller', function () {
	$created = lifecycle('scope_device');
	$host    = $created['result'];

	expect($host)->toBeGreaterThan(0)
		->and($created['devices'])->toBe([[
			'host_id'             => $host,
			'account_id'          => $created['devices'][0]['account_id'],
			'description'         => 'Seed',
			'availability_method' => 0,
			'poller_id'           => 1,
			'template'            => 'Cloud: DigitalOcean Scope',
		]])
		->and($created['device_queries'])->toBe(['Cloud: DigitalOcean Droplets', 'Cloud: DigitalOcean Load Balancers'])
		->and($created['device_index'])->toBe(['101' => 'web-1', '102' => 'web-2']);
});

it('reindexes resources added, renamed, and retired upstream', function () {
	lifecycle('scope_device');
	lifecycle('resource', '103=web-3');
	lifecycle('resource', '101=web-1-renamed');
	lifecycle('resource', '102=deleted');

	expect(lifecycle('reindex')['device_index'])->toBe(['101' => 'web-1-renamed', '103' => 'web-3']);
});

it('serves the device\'s own resources through the script server, and nothing to another device', function () {
	$host = (string) lifecycle('scope_device')['result'];

	expect(lifecycle('ss', $host . ' droplet get resourceName 101')['result'])->toBe('web-1')
		->and(lifecycle('ss', $host . ' droplet get do_cpu_pct 101')['result'])->toBe('U')
		->and(lifecycle('ss', '1 droplet get resourceName 101')['result'])->toBe('U')
		->and(lifecycle('ss', $host . ' droplet num_indexes')['output'])->toBe('2');
});

it('forgets the device when Cacti deletes it, and creates a new one on request', function () {
	$first = lifecycle('scope_device')['result'];

	expect(lifecycle('remove_device')['devices'])->toBe([]);

	$second = lifecycle('scope_device');
	expect($second['result'])->toBeGreaterThan($first)
		->and($second['devices'])->toHaveCount(1);
});

it('ignores a link whose device went while the plugin was disabled, and replaces it', function () {
	$first = (string) lifecycle('scope_device')['result'];
	lifecycle('drop_host');

	expect(lifecycle('ss', $first . ' droplet get resourceName 101')['result'])->toBe('U');

	$second = lifecycle('scope_device');
	expect($second['result'])->toBeGreaterThan((int) $first)
		->and($second['devices'])->toHaveCount(1)
		->and($second['device_index'])->toBe(['101' => 'web-1', '102' => 'web-2']);
});

it('collects exactly the metrics graphed on the scope device, and stops when a graph goes', function () {
	lifecycle('scope_device');
	$cpu = lifecycle('graph', 'do_cpu 101')['result'];
	lifecycle('graph', 'do_load 102');
	lifecycle('graph', 'do_age 102');

	expect($cpu)->toBeGreaterThan(0)
		->and(lifecycle('sync')['series'])->toBe(['101 do_cpu_pct', '102 do_load_1', '102 do_load_15', '102 do_load_5']);

	lifecycle('remove_graph', (string) $cpu);

	expect(lifecycle('sync')['series'])->toBe(['102 do_load_1', '102 do_load_15', '102 do_load_5']);
});
