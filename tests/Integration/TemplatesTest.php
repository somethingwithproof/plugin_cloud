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
 * The shipped templates against a real Cacti (REQ-OBJ-002, #42). Template
 * objects live in Cacti's tables, so they survive the lifecycle reset; each
 * test states what it expects regardless of what an earlier one imported.
 */

beforeEach(function () {
	lifecycle('reset');
});

afterAll(function () {
	lifecycle('reset');
});

it('imports every template on install and copies the data query definitions', function () {
	$state = lifecycle('install');

	expect($state['templates_sha'])->toBe(hash_file('sha256', dirname(__DIR__, 2) . '/templates/cloud_digitalocean.xml'))
		->and($state['resource_files'])->toBe(['cloud_do_droplet.xml', 'cloud_do_load_balancer.xml'])
		->and($state['template_names'])->toHaveCount(15)
		->and($state['template_names'])->toContain('Cloud: DigitalOcean Droplets - CPU')
		->and($state['template_names'])->toContain('Cloud: DigitalOcean Load Balancers - Collection Age');
});

it('re-imports without adding a single template row', function () {
	$first = lifecycle('install');

	// The reset forgets the recorded checksum, so this install imports again.
	lifecycle('reset');
	$second = lifecycle('install');

	expect($second['templates_sha'])->toBe($first['templates_sha'])
		->and($second['template_rows'])->toBe($first['template_rows']);
});

it('skips the import when the package is unchanged and every object is present', function () {
	$installed = lifecycle('install');
	$again     = lifecycle('templates');

	expect($again['result'])->toBeTrue()
		->and($again['template_rows'])->toBe($installed['template_rows']);
});

it('restores a shipped graph template an administrator deleted', function () {
	$installed = lifecycle('install');

	$deleted = lifecycle('delete_graph_template', 'do_cpu');
	expect($deleted['template_names'])->not->toContain('Cloud: DigitalOcean Droplets - CPU');

	$restored = lifecycle('templates');
	expect($restored['result'])->toBeTrue()
		->and($restored['template_names'])->toContain('Cloud: DigitalOcean Droplets - CPU')
		->and($restored['template_rows'])->toBe($installed['template_rows']);
});

it('imports a changed package on the next upgrade check', function () {
	lifecycle('install');
	lifecycle('set_setting', 'cloud_templates_sha256=stale');

	$upgraded = lifecycle('upgrade');

	expect($upgraded['result'])->toBeTrue()
		->and($upgraded['templates_sha'])
			->toBe(hash_file('sha256', dirname(__DIR__, 2) . '/templates/cloud_digitalocean.xml'));
});
