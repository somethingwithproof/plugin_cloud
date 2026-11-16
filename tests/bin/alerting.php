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
 * Thold alerting and Maint suppression end to end (#46). With Thold and Maint
 * installed and enabled, it builds one account with one Droplet, its scope
 * device, and the CPU and Collection Age graphs, then puts a hi threshold on
 * each data source. Observations are written to the plugin's cache directly,
 * and each step runs Cacti's poller and Thold's checker as a site would:
 *
 *   REQ-ALERT-001  CPU above the threshold alerts, and below it restores
 *   REQ-ALERT-002  when collection stops, the age threshold alerts
 *   REQ-ALERT-003  with the scope device in a Maint schedule, neither alerts,
 *                  and the same breach alerts once the schedule is removed
 *
 * A lease held for the whole run keeps the plugin's own collector off the
 * account, so only the values written here are graphed. Disposable databases
 * only. Must parse on PHP 8.0.
 *
 *   CLOUD_TEST_DATABASE=disposable php plugins/cloud/tests/bin/alerting.php
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');

if (getenv('CLOUD_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set CLOUD_TEST_DATABASE=disposable\n");
	exit(2);
}

include_once($config['base_path'] . '/lib/api_data_source.php');
include_once($config['base_path'] . '/lib/api_graph.php');
include_once($config['base_path'] . '/lib/data_query.php');
include_once($config['base_path'] . '/lib/template.php');
include_once($config['base_path'] . '/lib/utility.php');
include_once($config['base_path'] . '/plugins/cloud/setup.php');

foreach (array('cloud', 'thold', 'maint') as $alert_plugin) {
	if (!api_plugin_is_enabled($alert_plugin)) {
		fwrite(STDERR, "The $alert_plugin plugin must be installed and enabled\n");
		exit(2);
	}
}

cloud_boot();

$failures = 0;

function alert_check($ok, $message) {
	global $failures;

	print ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;

	if (!$ok) {
		$failures++;
	}
}

// Thold skips checks at weekends when either exemption is on.
foreach (array('alert_exempt' => '', 'thold_disable_all' => '', 'thold_daemon_enable' => '') as $name => $value) {
	db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', array($name, $value));
}

db_execute(
	"INSERT INTO plugin_cloud_account (provider, name, poller_id, credential_source, collect_interval, " .
		"discover_interval, admin_state) VALUES ('digitalocean', 'Alerting', 1, 'vault', 300, 3600, 'enabled')"
);
$account = (int) db_fetch_insert_id();
db_execute_prepared(
	"INSERT INTO plugin_cloud_lease (account_id, holder, expires_at) " .
		"VALUES (?, 'alerting-test', UNIX_TIMESTAMP() + 86400)",
	array($account)
);
db_execute_prepared(
	"INSERT INTO plugin_cloud_resource (account_id, type, provider_id, name, lifecycle, first_seen, last_seen)
		VALUES (?, 'droplet', '46001', 'alert-1', 'active', UNIX_TIMESTAMP(), UNIX_TIMESTAMP())",
	array($account)
);
$resource = (int) db_fetch_insert_id();

cloud_scope_devices()->create(cloud_accounts()->find($account));
$host = (int) db_fetch_cell_prepared('SELECT host_id FROM plugin_cloud_device WHERE account_id = ?', array($account));

/**
 * One plugin graph on the scope device, as New Graphs creates it: the graph,
 * then each data source pushed out to the poller cache.
 *
 * @return array{graph: int, data: int}
 */
function alert_graph($key, $provider_id) {
	global $host;

	$type      = \Somethingwithproof\Cloud\DigitalOceanCatalog::GRAPHS[$key][0];
	$templates = new \Somethingwithproof\Cloud\TemplateRepository(false);
	$query     = $templates->id('snmp_query', 'data_query/' . $type);
	$suggested = array();
	$created   = create_complete_graph_from_template(
		$templates->id('graph_templates', 'graph_template/' . $key),
		$host,
		array(
			'snmp_query_id'       => $query,
			'snmp_index_on'       => get_best_data_query_index_type($host, $query),
			'snmp_query_graph_id' => $templates->id('snmp_query_graph', 'data_query_graph/' . $key),
			'snmp_index'          => $provider_id,
		),
		$suggested
	);

	foreach ($created['local_data_id'] as $local_data_id) {
		push_out_host($host, $local_data_id);
	}

	return array('graph' => (int) $created['local_graph_id'], 'data' => (int) reset($created['local_data_id']));
}

/**
 * A hi threshold that alerts on the first breach, with no notification
 * targets: Thold still logs each alert and restoral.
 *
 * @return int the threshold id
 */
function alert_threshold($graph, $name, $hi) {
	global $host;

	$rrd = db_fetch_row_prepared(
		'SELECT id, local_data_id FROM data_template_rrd WHERE local_data_id = ? AND data_source_name = ?',
		array($graph['data'], $name)
	);
	db_execute_prepared(
		"INSERT INTO thold_data (name, name_cache, local_data_id, data_template_rrd_id, data_source_name, host_id,
			local_graph_id, thold_type, thold_hi, thold_low, thold_fail_trigger, thold_enabled, thold_per_enabled,
			exempt, restored_alert, thold_template_id)
			VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, '', 1, 'on', 'on', '', '', 0)",
		array($name, $name, $rrd['local_data_id'], $rrd['id'], $name, $host, $graph['graph'], (string) $hi)
	);

	return (int) db_fetch_insert_id();
}

$cpu_graph = alert_graph('do_cpu', '46001');
$age_graph = alert_graph('do_age', '46001');
(new \Somethingwithproof\Cloud\SeriesRepository(false))->sync($account);

$cpu = alert_threshold($cpu_graph, 'do_cpu_pct', 80);
$age = alert_threshold($age_graph, 'do_age', 900);

alert_check(
	(int) db_fetch_cell_prepared('SELECT COUNT(*) FROM poller_item WHERE host_id = ?', array($host)) === 2,
	'both data sources are in the poller cache'
);

/** CPU at $value percent, its newest datapoint $age_seconds old. */
function alert_observe($value, $age_seconds) {
	global $resource;

	db_execute_prepared(
		"REPLACE INTO plugin_cloud_observation (resource_id, metric, value, observed_at, fetched_at, status)
			VALUES (?, 'do_cpu_pct', ?, UNIX_TIMESTAMP() - ?, UNIX_TIMESTAMP(), 'ok')",
		array($resource, $value, $age_seconds)
	);
}

/**
 * One poller cycle: Cacti's poller hands the values to Thold, then Thold
 * checks every staged threshold. The poller also starts Thold's checker in
 * the background, so this waits until no threshold is still staged.
 */
function alert_cycle() {
	global $config, $cpu, $age;

	$php = escapeshellarg(PHP_BINARY);
	exec($php . ' -q ' . escapeshellarg($config['base_path'] . '/poller.php') . ' --force 2>&1', $lines, $rc);
	exec($php . ' -q ' . escapeshellarg($config['base_path'] . '/plugins/thold/poller_thold.php') . ' --force 2>&1');

	for ($i = 0; $i < 60; $i++) {
		$staged = (int) db_fetch_cell_prepared(
			'SELECT COUNT(*) FROM thold_data WHERE tcheck = 1 AND id IN (?, ?)',
			array($cpu, $age)
		);

		if ($staged === 0) {
			break;
		}

		usleep(500000);
	}

	return $rc;
}

/** Log rows of one status for a threshold: 4 alert, 5 restoral. */
function alert_logged($threshold, $status) {
	return (int) db_fetch_cell_prepared(
		'SELECT COUNT(*) FROM plugin_thold_log WHERE threshold_id = ? AND status = ?',
		array($threshold, $status)
	);
}

function alert_state($threshold) {
	return db_fetch_row_prepared(
		'SELECT thold_alert, thold_fail_count, lastread FROM thold_data WHERE id = ?',
		array($threshold)
	);
}

// The first cycle brings the scope device up (Thold checks only devices that are up).
alert_observe(20, 30);
alert_check(alert_cycle() === 0, "Cacti's poller runs");
alert_cycle();
alert_check(
	(int) db_fetch_cell_prepared('SELECT status FROM host WHERE id = ?', array($host)) === 3,
	'the scope device is up'
);
alert_check(
	alert_state($cpu)['lastread'] === '20' && alert_logged($cpu, 4) === 0,
	'Thold reads the cloud value (20) and does not alert below the threshold'
);

// REQ-ALERT-001: breach, then recovery.
alert_observe(95, 30);
alert_cycle();
alert_check(alert_logged($cpu, 4) === 1 && (int) alert_state($cpu)['thold_alert'] === 2, 'CPU at 95 alerts');
alert_observe(10, 30);
alert_cycle();
alert_check(alert_logged($cpu, 5) === 1 && (int) alert_state($cpu)['thold_alert'] === 0, 'CPU at 10 restores');

// REQ-ALERT-002: collection stops. The CPU value goes unknown; the age keeps rising.
alert_check(alert_logged($age, 4) === 0, 'age does not alert while collection is current');
alert_observe(10, 3600);
alert_cycle();
alert_check((int) alert_state($age)['lastread'] >= 3600, 'age reports the hour since the last datapoint');
alert_check(alert_logged($age, 4) === 1, 'age above 900 seconds alerts when collection stops');
alert_check(
	alert_logged($cpu, 4) === 1 && alert_logged($cpu, 5) === 1,
	'an unknown CPU value neither alerts nor restores'
);
alert_observe(10, 30);
alert_cycle();
alert_check(alert_logged($age, 5) === 1, 'age restores when collection resumes');

// REQ-ALERT-003: the scope device in maintenance.
db_execute(
	"INSERT INTO plugin_maint_schedules (enabled, name, mtype, stime, etime, minterval)
		VALUES ('on', 'Cloud alerting test', 1, UNIX_TIMESTAMP() - 60, UNIX_TIMESTAMP() + 3600, 0)"
);
$schedule = (int) db_fetch_insert_id();
db_execute_prepared('INSERT INTO plugin_maint_hosts (type, host, schedule) VALUES (1, ?, ?)', array($host, $schedule));

alert_observe(95, 30);
alert_cycle();
$state = alert_state($cpu);
alert_check(
	alert_logged($cpu, 4) === 1 && (int) $state['thold_fail_count'] === 0,
	'under maintenance, CPU at 95 is not counted and does not alert'
);
alert_observe(95, 3600);
alert_cycle();
alert_check(alert_logged($age, 4) === 1, 'under maintenance, stopped collection does not alert');

db_execute_prepared('DELETE FROM plugin_maint_hosts WHERE schedule = ?', array($schedule));
db_execute_prepared('DELETE FROM plugin_maint_schedules WHERE id = ?', array($schedule));
alert_observe(95, 30);
alert_cycle();
alert_check(alert_logged($cpu, 4) === 2, 'with the schedule removed, the same breach alerts');

db_execute_prepared('DELETE FROM plugin_cloud_lease WHERE account_id = ?', array($account));

exit($failures > 0 ? 1 : 0);
