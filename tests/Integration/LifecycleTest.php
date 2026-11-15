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

// Schema::VERSION; the integration process does not load the plugin classes.
const CLOUD_SCHEMA = '3';

const CLOUD_TABLES = [
	'plugin_cloud_account',
	'plugin_cloud_audit',
	'plugin_cloud_device',
	'plugin_cloud_health',
	'plugin_cloud_lease',
	'plugin_cloud_observation',
	'plugin_cloud_resource',
	'plugin_cloud_secret',
	'plugin_cloud_series',
];

const CLOUD_HOOKS = [
	'config_arrays',
	'config_settings',
	'device_remove',
	'draw_navigation_text',
	'graphs_new_top_links',
	'poller_bottom',
];

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
		->and($state['schema'])->toEqual(CLOUD_SCHEMA)
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
		->and($state['schema'])->toEqual(CLOUD_SCHEMA)
		->and($state['plugin']['version'])->toEqual(CLOUD_VERSION);
});

it('does not advance past a migration that did not run', function () {
	lifecycle('install');
	lifecycle('set_schema', '0');

	$stopped = lifecycle('migrate_through', '0');
	expect($stopped['result'])->toBeTrue()
		->and($stopped['schema'])->toEqual('0');

	$finished = lifecycle('upgrade');
	expect($finished['schema'])->toEqual(CLOUD_SCHEMA);
});

it('adds the retry column to a version 1 database, and a kept uninstall keeps it', function () {
	lifecycle('install');
	lifecycle('seed');
	lifecycle('drop_column', 'plugin_cloud_health.retry_at');
	lifecycle('set_schema', '1');

	$upgraded = lifecycle('upgrade');
	expect($upgraded['result'])->toBeTrue()
		->and($upgraded['schema'])->toEqual(CLOUD_SCHEMA)
		->and($upgraded['health_columns'])->toContain('retry_at')
		->and($upgraded['tracked_columns'])->toBe(['plugin_cloud_health.retry_at'])
		->and($upgraded['tables']['plugin_cloud_account'])->toBe(1);

	// Core drops tracked columns on uninstall; keeping tables keeps them too.
	$kept = lifecycle('uninstall');
	expect($kept['health_columns'])->toContain('retry_at')
		->and($kept['tracked_columns'])->toBe([]);

	$reinstalled = lifecycle('install');
	expect($reinstalled['schema'])->toEqual(CLOUD_SCHEMA)
		->and($reinstalled['health_columns'])->toContain('retry_at');
});

it('creates the device table on a version 2 database and tracks it for uninstall', function () {
	lifecycle('install');
	lifecycle('drop_table', 'plugin_cloud_device');
	lifecycle('set_schema', '2');

	$upgraded = lifecycle('upgrade');
	expect($upgraded['result'])->toBeTrue()
		->and($upgraded['schema'])->toEqual(CLOUD_SCHEMA)
		->and(array_keys($upgraded['tables']))->toContain('plugin_cloud_device')
		->and($upgraded['tracked'])->toContain('plugin_cloud_device');
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

it('stores credentials only as ciphertext and audits every account change', function () {
	lifecycle('install');

	$state = lifecycle('credential_flow');

	expect($state['result']['read_back'])->toBeTrue()
		->and($state['result']['leaks'])->toBe([])
		->and($state['result']['audit'])->toBe(['create', 'credential', 'update', 'verify'])
		->and($state['result']['account_gone'])->toBeTrue()
		->and($state['tables']['plugin_cloud_secret'])->toBe(0)
		->and($state['tables']['plugin_cloud_audit'])->toBe(5);
});

it('collects only what is due, owned, enabled, mapped, and leased', function () {
	lifecycle('install');

	$r = lifecycle('collector_flow')['result'];

	expect($r['run1'])->toBe(['accounts' => 1, 'skipped' => 0, 'stopped_early' => false])
		->and($r['discoveries'])->toBe(1)
		->and($r['resources'])->toBe(2)
		->and($r['others'])->toBe(0)
		// Inside the collection interval nothing runs.
		->and($r['collected_early'])->toBe([])
		// Only the active, plugin-mapped data source is read; discovery is not due again.
		->and($r['collected'])->toBe(['d1/cpu'])
		->and($r['collected_at_200'])->toBe(1)
		->and($r['collected_at_296'])->toBe(2)
		->and($r['discoveries_after'])->toBe(1)
		->and((float) $r['observation']['value'])->toBe(42.5)
		->and($r['observation']['status'])->toBe('ok')
		->and($r['health'])->toEqual(['collection' => 'ok', 'series_total' => '1', 'series_read' => '1'])
		// No lease, or an older datapoint, never overwrites the cache.
		->and($r['after_foreign_writes'])->toEqual([
			'value' => $r['observation']['value'],
			'observed_at' => $r['observation']['observed_at']
		])
		->and($r['while_leased'])->toBe(['accounts' => 0, 'skipped' => 1, 'stopped_early' => false])
		->and($r['no_budget'])->toBe(['accounts' => 0, 'skipped' => 0, 'stopped_early' => true])
		->and($r['d2_lifecycle'])->toBe(['missing', 'missing', 'deleted'])
		->and($r['health_unchanged'])->toBeTrue()
		->and($r['order_run1']['stopped_early'])->toBeTrue()
		->and($r['order'])->toBe(['Second', 'Main'])
		// Over budget: the first series in stored order are read, the rest marked.
		->and($r['budget_collected'])->toBe(['b1/m_a', 'b1/m_b'])
		->and($r['budget_health'])->toEqual(['collection' => 'budget', 'series_total' => '3', 'series_read' => '2'])
		->and($r['budget_status'])->toBe(['m_a' => 'ok', 'm_b' => 'ok', 'm_c' => 'budget'])
		// Throttled: no run until Retry-After has passed.
		->and($r['throttled_health'])->toEqual([
			'collection'  => 'failed',
			'error_class' => 'throttled',
			'retry_at'    => (string) ($r['throttled_at'] + 900),
		])
		->and($r['during_retry'])->toBe([])
		->and($r['after_retry'])->toBe(['b1/m_a', 'b1/m_b']);
});

it('honours keep tables on uninstall when the runtime is refused', function () {
	lifecycle('install');
	lifecycle('seed');

	$kept = lifecycle('uninstall_unbooted');
	expect($kept['tables']['plugin_cloud_account'])->toBe(1)
		->and($kept['tracked'])->toBe([]);

	lifecycle('reset');
	lifecycle('install');
	lifecycle('set_setting', 'cloud_uninstall_keep_tables=');

	$dropped = lifecycle('uninstall_unbooted');
	expect($dropped['tables'])->toBe([])
		->and($dropped['schema'])->toBeEmpty();
});

it('grants realms on install but never on a repair a viewer triggers', function () {
	expect(lifecycle('install')['admin'])->toBe(2);

	$repair = lifecycle('repair_as_viewer');
	expect($repair['realms'])->toHaveCount(2)
		->and($repair['result'])->toBe(['viewer_realms' => 0]);
});

it('exits at once when a collector already runs on the poller', function () {
	lifecycle('install');
	lifecycle('enable');

	$r = lifecycle('collector_second')['result'];

	expect($r['rc'])->toBe(0)
		->and($r['seconds'])->toBeLessThan(5.0)
		->and($r['leases'])->toBe(0)
		->and($r['leases_when_free'])->toBe(1);
});

it('leaves no partial result when a run is killed mid-account, and the next run continues', function () {
	lifecycle('install');

	$r = lifecycle('collector_kill')['result'];

	expect($r['victim_rc'])->not->toBe(0)
		// The dead run still holds its lease, and wrote no health or value.
		->and($r['after_kill']['lease']['holder'])->toBe('victim')
		->and($r['after_kill']['health'])->toBeEmpty()
		->and($r['after_kill']['observations'])->toBe([])
		->and($r['while_leased'])->toBe(['accounts' => 0, 'skipped' => 1, 'stopped_early' => false])
		->and($r['after_expiry'])->toBe(['accounts' => 1, 'skipped' => 0, 'stopped_early' => false])
		->and($r['final']['health'])->toEqual(['run_started' => '1800000420', 'collection' => 'ok', 'series_read' => '1'])
		->and($r['final']['observations'])->toEqual([
			['metric' => 'cpu', 'value' => '7', 'observed_at' => '1799999940', 'status' => 'ok']
		]);
});
