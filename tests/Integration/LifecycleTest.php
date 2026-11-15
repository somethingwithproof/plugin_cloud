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

// The version INFO declares, which Cacti records as the installed version.
define('CLOUD_VERSION', (string) (parse_ini_file(dirname(__DIR__, 2) . '/INFO', true)['info']['version'] ?? ''));

const CLOUD_TABLES = [
	'plugin_cloud_account',
	'plugin_cloud_audit',
	'plugin_cloud_health',
	'plugin_cloud_lease',
	'plugin_cloud_observation',
	'plugin_cloud_resource',
	'plugin_cloud_secret',
	'plugin_cloud_series',
];

const CLOUD_HOOKS = ['config_arrays', 'config_settings', 'draw_navigation_text'];

beforeEach(function () {
	lifecycle('reset');
});

afterAll(function () {
	lifecycle('reset');
});

it('installs fresh with tracked tables, hooks, and both realms, leaving the plugin disabled', function () {
	$state = lifecycle('install');

	expect($state['plugin']['status'])->toEqual('4')
		->and($state['plugin']['version'])->toEqual(CLOUD_VERSION)
		->and($state['schema'])->toEqual('1')
		->and(array_keys($state['tables']))->toBe(CLOUD_TABLES)
		->and($state['tracked'])->toBe(CLOUD_TABLES)
		->and(array_keys($state['hooks']))->toBe(CLOUD_HOOKS)
		->and($state['realms'])->toEqual([
			'cloud_accounts.php' => 'Cloud Admin',
			'cloud_health.php'   => 'Cloud View',
		])
		->and($state['admin'])->toBe(2);
});

it('enables and disables through core, keeping data on disable', function () {
	lifecycle('install');
	lifecycle('seed');

	$enabled = lifecycle('enable');
	expect($enabled['plugin']['status'])->toEqual('1')
		->and(array_values(array_unique(array_map('strval', $enabled['hooks']))))->toBe(['1']);

	$disabled = lifecycle('disable');
	expect($disabled['plugin']['status'])->toEqual('4')
		->and($disabled['tables']['plugin_cloud_account'])->toBe(1);
});

it('upgrades from an older recorded version and is idempotent on repeated runs', function () {
	lifecycle('install');
	lifecycle('seed');
	lifecycle('set_version', '0.0');

	$first = lifecycle('upgrade');
	expect($first['result'])->toBeTrue()
		->and($first['plugin']['version'])->toEqual(CLOUD_VERSION);

	foreach ([lifecycle('upgrade'), lifecycle('upgrade')] as $run) {
		expect($run['result'])->toBeTrue()
			->and($run['tables'])->toBe($first['tables'])
			->and($run['hooks'])->toBe($first['hooks'])
			->and($run['realms'])->toBe($first['realms'])
			->and($run['tracked'])->toBe(CLOUD_TABLES);
	}

	expect($first['tables']['plugin_cloud_account'])->toBe(1);
});

it('re-registers hooks and realms that went missing', function () {
	lifecycle('install');

	$missing = lifecycle('drop_registrations');
	expect($missing['hooks'])->toBe([]);

	$state = lifecycle('upgrade');

	expect($state['result'])->toBeTrue()
		->and(array_keys($state['hooks']))->toBe(CLOUD_HOOKS)
		->and($state['realms'])->toHaveCount(2);
});

it('finishes an upgrade that was interrupted after creating only some tables', function () {
	lifecycle('install');
	lifecycle('seed');
	lifecycle('drop_table', 'plugin_cloud_health');
	lifecycle('drop_table', 'plugin_cloud_audit');
	lifecycle('set_schema', '0');
	lifecycle('set_version', '0.0');

	$state = lifecycle('upgrade');

	expect($state['result'])->toBeTrue()
		->and(array_keys($state['tables']))->toBe(CLOUD_TABLES)
		->and($state['tables']['plugin_cloud_account'])->toBe(1)
		->and($state['schema'])->toEqual('1')
		->and($state['plugin']['version'])->toEqual(CLOUD_VERSION);
});

it('does not advance past a migration that did not run', function () {
	lifecycle('install');
	lifecycle('set_schema', '0');

	$stopped = lifecycle('migrate_through', '0');
	expect($stopped['result'])->toBeTrue()
		->and($stopped['schema'])->toEqual('0');

	$finished = lifecycle('upgrade');
	expect($finished['schema'])->toEqual('1');
});

it('refuses a schema newer than the code and leaves the recorded version alone', function () {
	lifecycle('install');
	lifecycle('set_schema', '99');
	lifecycle('set_version', '0.0');

	$state = lifecycle('upgrade');

	expect($state['result'])->toBeFalse()
		->and($state['plugin']['version'])->toEqual('0.0')
		->and($state['schema'])->toEqual('99');
});

it('keeps tables on uninstall by default, and drops them only when the box was cleared', function () {
	lifecycle('install');
	lifecycle('seed');

	$kept = lifecycle('uninstall');
	expect(array_keys($kept['tables']))->toBe(CLOUD_TABLES)
		->and($kept['tables']['plugin_cloud_account'])->toBe(1)
		->and($kept['tracked'])->toBe([])
		->and($kept['hooks'])->toBe([])
		->and($kept['realms'])->toBe([]);

	// A reinstall reuses the kept tables and their rows.
	$reinstalled = lifecycle('install');
	expect($reinstalled['plugin']['status'])->toEqual('4')
		->and($reinstalled['tables']['plugin_cloud_account'])->toBe(1)
		->and($reinstalled['tracked'])->toBe(CLOUD_TABLES);

	lifecycle('set_setting', 'cloud_uninstall_keep_tables=');

	$dropped = lifecycle('uninstall');
	expect($dropped['plugin'])->toBeEmpty()
		->and($dropped['tables'])->toBe([])
		->and($dropped['schema'])->toBeEmpty();
});
