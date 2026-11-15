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
 * Drive the plugin lifecycle through Cacti's own plugin API in a fresh PHP
 * process, the way plugins.php and cli/plugin_manage.php do, and print the
 * resulting state as JSON. Used by tests/Integration. Disposable databases
 * only.
 *
 *   php plugins/cloud/tests/bin/lifecycle.php <action> [argument]
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/plugins.php');
require_once($config['base_path'] . '/lib/poller.php');

if (getenv('CLOUD_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set CLOUD_TEST_DATABASE=disposable\n");
	exit(2);
}

$action   = isset($argv[1]) ? $argv[1] : 'state';
$argument = isset($argv[2]) ? $argv[2] : '';
$result   = null;

ob_start();

switch ($action) {
	case 'reset':
		api_plugin_uninstall('cloud');

		foreach (db_fetch_assoc(
			"SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE " .
				"'plugin\\_cloud\\_%'"
		) as $row) {
			db_execute('DROP TABLE `' . $row['t'] . '`');
		}

		db_execute("DELETE FROM settings WHERE name LIKE 'cloud\\_%'");
		db_execute("DELETE FROM plugin_db_changes WHERE plugin = 'cloud'");

		break;
	case 'install':
		api_plugin_install('cloud');

		break;
	case 'enable':
		api_plugin_enable('cloud');

		break;
	case 'disable':
		api_plugin_disable('cloud');

		break;
	case 'uninstall':
		api_plugin_uninstall('cloud');

		break;
	case 'upgrade':
		include_once($config['base_path'] . '/plugins/cloud/setup.php');
		$result = plugin_cloud_upgrade();

		break;
	case 'migrate_through':
		// Stop after the given migration, as an upgrade killed part way would.
		include_once($config['base_path'] . '/plugins/cloud/setup.php');
		cloud_boot();
		$result = (new \Somethingwithproof\Cloud\Schema(false))->migrate((int) $argument);

		break;
	case 'set_version':
		db_execute_prepared("UPDATE plugin_config SET version = ? WHERE directory = 'cloud'", array($argument));

		break;
	case 'set_schema':
		db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('cloud_schema_version', ?)", array($argument));

		break;
	case 'set_setting':
		list($name, $value) = explode('=', $argument, 2);
		db_execute_prepared('REPLACE INTO settings (name, value) VALUES (?, ?)', array($name, $value));

		break;
	case 'drop_registrations':
		// What a failed hook registration on an earlier request leaves.
		db_execute("DELETE FROM plugin_hooks WHERE name = 'cloud'");
		db_execute("DELETE FROM plugin_realms WHERE plugin = 'cloud'");

		break;
	case 'drop_column':
		// A version 1 database: the column a later migration adds is absent.
		list($table, $column) = explode('.', preg_replace('/[^a-z_.]/', '', $argument), 2);
		db_execute('ALTER TABLE `' . $table . '` DROP COLUMN `' . $column . '`');

		break;
	case 'templates':
		// Run the template install on its own, as a second upgrade would.
		include_once($config['base_path'] . '/plugins/cloud/setup.php');
		cloud_boot();
		$result = cloud_setup_templates(1);

		break;
	case 'delete_graph_template':
		// What an administrator deleting one shipped graph template leaves.
		include_once($config['base_path'] . '/plugins/cloud/setup.php');
		cloud_boot();
		$id = (int) db_fetch_cell_prepared(
			'SELECT id FROM graph_templates WHERE hash = ?',
			array(\Somethingwithproof\Cloud\TemplateRepository::hash('graph_template/' . $argument))
		);
		// The same rows graph_templates.php deletes for a template.
		$graphs = array_column(
			db_fetch_assoc_prepared('SELECT id FROM snmp_query_graph WHERE graph_template_id = ?', array($id)),
			'id'
		);

		foreach ($graphs as $graph) {
			foreach (array('snmp_query_graph_rrd', 'snmp_query_graph_rrd_sv', 'snmp_query_graph_sv') as $table) {
				db_execute_prepared('DELETE FROM ' . $table . ' WHERE snmp_query_graph_id = ?', array($graph));
			}
		}

		db_execute_prepared(
			'DELETE FROM graph_template_input_defs WHERE graph_template_input_id IN ' .
				'(SELECT id FROM graph_template_input WHERE graph_template_id = ?)',
			array($id)
		);

		foreach (array('graph_templates', 'snmp_query_graph', 'graph_template_input', 'host_template_graph') as $table) {
			db_execute_prepared(
				'DELETE FROM ' . $table . ' WHERE ' . ($table === 'graph_templates' ? 'id' : 'graph_template_id') . ' = ?',
				array($id)
			);
		}

		db_execute_prepared(
			'DELETE FROM graph_templates_graph WHERE graph_template_id = ? AND local_graph_id = 0',
			array($id)
		);
		db_execute_prepared(
			'DELETE FROM graph_templates_item WHERE graph_template_id = ? AND local_graph_id = 0',
			array($id)
		);

		break;
	case 'scope_device':
		// The account a seed made, with two Droplets, gets its scope device.
		include_once($config['base_path'] . '/plugins/cloud/setup.php');
		cloud_boot();
		$account = (int) db_fetch_cell("SELECT id FROM plugin_cloud_account WHERE name = 'Seed'");

		foreach (array('101' => 'web-1', '102' => 'web-2') as $provider_id => $name) {
			db_execute_prepared(
				"INSERT IGNORE INTO plugin_cloud_resource (account_id, type, provider_id, name, lifecycle, first_seen, last_seen)
					VALUES (?, 'droplet', ?, ?, 'active', UNIX_TIMESTAMP(), UNIX_TIMESTAMP())",
				array($account, $provider_id, $name)
			);
		}

		// "locked": another request holds the account's creation lock.
		if ($argument === 'locked') {
			$other = new PDO(
				'mysql:host=' . $database_hostname . ';port=' . $database_port . ';dbname=' . $database_default,
				$database_username,
				$database_password
			);
			$other->prepare('SELECT GET_LOCK(?, 0)')->execute(array('plugin_cloud_device_' . $account));
		}

		$result = cloud_scope_devices(1)->create(cloud_accounts()->find($account));

		break;
	case 'resource':
		// Change one Droplet as discovery would: add, rename or retire it.
		list($provider_id, $change) = explode('=', $argument, 2);
		$account = (int) db_fetch_cell("SELECT id FROM plugin_cloud_account WHERE name = 'Seed'");

		if ($change === 'deleted') {
			db_execute_prepared(
				"UPDATE plugin_cloud_resource SET lifecycle = 'deleted' WHERE account_id = ? AND provider_id = ?",
				array($account, $provider_id)
			);
		} else {
			db_execute_prepared(
				"INSERT INTO plugin_cloud_resource (account_id, type, provider_id, name, lifecycle, first_seen, last_seen)
					VALUES (?, 'droplet', ?, ?, 'active', UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
					ON DUPLICATE KEY UPDATE name = VALUES(name)",
				array($account, $provider_id, $change)
			);
		}

		break;
	case 'reindex':
		include_once($config['base_path'] . '/lib/data_query.php');
		include_once($config['base_path'] . '/lib/snmp.php');

		foreach (db_fetch_assoc('SELECT host_id, snmp_query_id FROM host_snmp_query') as $row) {
			run_data_query($row['host_id'], $row['snmp_query_id']);
		}

		break;
	case 'edit_device':
		// Save the scope device with a new description, as host.php does,
		// optionally after setting its data queries' reindex method as on the
		// device page. Prints each query's reindex method and reindex checks
		// after the save.
		include_once($config['base_path'] . '/plugins/cloud/setup.php');
		cloud_scope_devices();
		$host    = (int) db_fetch_cell('SELECT host_id FROM plugin_cloud_device');
		$account = cloud_accounts()->find((int) db_fetch_cell('SELECT account_id FROM plugin_cloud_device'));

		if ($argument !== '') {
			foreach (db_fetch_assoc_prepared('SELECT snmp_query_id FROM host_snmp_query WHERE host_id = ?', array($host)) as $row) {
				api_device_dq_change($host, $row['snmp_query_id'], (int) $argument);
			}
		}

		$fields    = \Somethingwithproof\Cloud\ScopeDevices::deviceFields(
			$account,
			(int) db_fetch_cell_prepared('SELECT host_template_id FROM host WHERE id = ?', array($host))
		);
		$fields[0] = $host;
		$fields[2] = 'Seed edited';
		call_user_func_array('api_device_save', $fields);

		$result = array(
			'description' => db_fetch_cell_prepared('SELECT description FROM host WHERE id = ?', array($host)),
			'reindex'     => array_map('intval', array_column(
				db_fetch_assoc_prepared('SELECT reindex_method FROM host_snmp_query WHERE host_id = ? ORDER BY snmp_query_id', array($host)),
				'reindex_method'
			)),
			'checks'      => array_column(
				db_fetch_assoc_prepared(
					'SELECT assert_value FROM poller_reindex WHERE host_id = ? ORDER BY data_query_id',
					array($host)
				),
				'assert_value'
			),
		);

		break;
	case 'drop_host':
		// Delete the device row without Cacti's hooks, as when the plugin is disabled.
		db_execute('DELETE h FROM host h INNER JOIN plugin_cloud_device d ON d.host_id = h.id');

		break;
	case 'remove_device':
		include_once($config['base_path'] . '/lib/api_device.php');
		api_device_remove((int) db_fetch_cell('SELECT host_id FROM plugin_cloud_device'));

		break;
	case 'ss':
		// Call ss_cloud() as the script server does: "<host> <type> <cmd> [field] [index]".
		$called_by_script_server = true;
		include_once($config['base_path'] . '/plugins/cloud/ss_cloud.php');
		$result = (string) call_user_func_array('ss_cloud', explode(' ', $argument));

		break;
	case 'graph':
		// Create one plugin graph on the scope device, as New Graphs does: "<graph key> <provider id>".
		include_once($config['base_path'] . '/plugins/cloud/setup.php');
		include_once($config['base_path'] . '/lib/api_data_source.php');
		include_once($config['base_path'] . '/lib/api_graph.php');
		include_once($config['base_path'] . '/lib/data_query.php');
		include_once($config['base_path'] . '/lib/template.php');
		include_once($config['base_path'] . '/lib/utility.php');
		cloud_boot();
		list($key, $provider_id) = explode(' ', $argument, 2);
		$type      = \Somethingwithproof\Cloud\DigitalOceanCatalog::GRAPHS[$key][0];
		$templates = new \Somethingwithproof\Cloud\TemplateRepository(false);
		$query     = $templates->id('snmp_query', 'data_query/' . $type);
		$host      = (int) db_fetch_cell('SELECT host_id FROM plugin_cloud_device');
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
		$result    = (int) $created['local_graph_id'];

		break;
	case 'remove_graph':
		// Delete a graph and its data sources, as the Graphs page does.
		include_once($config['base_path'] . '/lib/api_data_source.php');
		include_once($config['base_path'] . '/lib/api_graph.php');
		$sources = array_column(
			db_fetch_assoc_prepared(
				'SELECT DISTINCT dtr.local_data_id FROM graph_templates_item gti
					INNER JOIN data_template_rrd dtr ON dtr.id = gti.task_item_id WHERE gti.local_graph_id = ?',
				array((int) $argument)
			),
			'local_data_id'
		);
		api_data_source_remove_multi($sources);
		api_graph_remove((int) $argument);

		break;
	case 'sync':
		// Sync the series as a collector holding the lease, or, with
		// "stolen", as one whose lease another run has taken over.
		include_once($config['base_path'] . '/plugins/cloud/setup.php');
		cloud_boot();
		$account = (int) db_fetch_cell("SELECT id FROM plugin_cloud_account WHERE name = 'Seed'");
		$leases  = new \Somethingwithproof\Cloud\LeaseRepository(false, new \Somethingwithproof\Cloud\SystemClock());
		$holder  = $argument === 'stolen' ? 'lifecycle-newer' : 'lifecycle';
		$leases->acquire($account, $holder, 60);
		$result = (new \Somethingwithproof\Cloud\SeriesRepository(false))->sync($account, $leases, 'lifecycle');
		$leases->release($account, $holder);

		break;
	case 'drop_table':
		db_execute('DROP TABLE IF EXISTS `' . preg_replace('/[^a-z_]/', '', $argument) . '`');

		break;
	case 'seed':
		// An account row, so table drops and keeps are visible.
		db_execute(
			"INSERT INTO plugin_cloud_account (provider, name, credential_source) VALUES ('digitalocean', 'Seed', 'vault')"
		);

		break;
	case 'credential_flow':
		// Create, edit, store a vault credential, read it back, and delete
		// an account through the repositories, then search every plugin
		// table and the Cacti log for the secret (REQ-CRED-004).
		include_once($config['base_path'] . '/plugins/cloud/setup.php');
		cloud_boot();

		$secret  = 'dop_v1_' . bin2hex(random_bytes(32));
		$keyFile = sys_get_temp_dir() . '/cloud-test-' . getmypid() . '.key';
		file_put_contents($keyFile, bin2hex(random_bytes(32)));
		chmod($keyFile, 0600);

		$clock    = new \Somethingwithproof\Cloud\SystemClock();
		$accounts = new \Somethingwithproof\Cloud\AccountRepository(false, $clock);
		$store    = new \Somethingwithproof\Cloud\CredentialStore(
			new \Somethingwithproof\Cloud\SecretRepository(false, $clock),
			$keyFile
		);
		$input    = array(
			'provider' => 'digitalocean',
			'name' => 'Flow',
			'poller_id' => '1',
			'credential_source' => 'vault',
			'collect_interval' => '300',
			'discover_interval' => '3600',
			'request_share' => '50',
			'enabled' => 'on'
		);

		$id = $accounts->save(
			\Somethingwithproof\Cloud\AccountInput::parse($input, 0, \Somethingwithproof\Cloud\Provider::released())->account,
			1
		);
		$store->storeVaultCredential($id, new \Somethingwithproof\Cloud\Secret($secret));
		$accounts->recordCredentialChange($id, 1);
		$accounts->save(
			\Somethingwithproof\Cloud\AccountInput::parse(
				array('name' => 'Flow renamed') + $input,
				$id,
				\Somethingwithproof\Cloud\Provider::released()
			)->account,
			1
		);
		$accounts->recordVerify($id, \Somethingwithproof\Cloud\VerifyStatus::Invalid, 1);

		$readBack = $store->credentialFor($accounts->find($id))->reveal() === $secret;
		$audit    = array_reverse(array_column($accounts->auditLog($id), 'action'));
		$leaks    = array();

		foreach (db_fetch_assoc(
			"SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE " .
				"'plugin\\_cloud\\_%'"
		) as $table) {
			foreach (db_fetch_assoc('SELECT * FROM `' . $table['t'] . '`') as $row) {
				if (strpos(implode("\0", array_map('strval', $row)), $secret) !== false) {
					$leaks[] = $table['t'];
				}
			}
		}

		if (strpos((string) @file_get_contents(cacti_log_file()), $secret) !== false) {
			$leaks[] = 'cacti.log';
		}

		$accounts->delete($id, 1);
		unlink($keyFile);

		$result = array(
			'read_back' => $readBack,
			'audit' => $audit,
			'leaks' => $leaks,
			'account_gone' => $accounts->find($id) === null
		);

		break;
	case 'collector_flow':
		include($config['base_path'] . '/plugins/cloud/tests/bin/collector-flow.php');

		break;
	case 'uninstall_unbooted':
		// What core does on uninstall when cloud_boot() refuses: the PHP 8.0
		// fallback, then core drops whatever is still tracked.
		include_once($config['base_path'] . '/plugins/cloud/include/unbooted-uninstall.php');
		cloud_uninstall_unbooted();
		api_plugin_db_changes_remove('cloud');
		api_plugin_remove_hooks('cloud');
		api_plugin_remove_realms('cloud');
		db_execute("DELETE FROM plugin_config WHERE directory = 'cloud'");

		break;
	case 'repair_as_viewer':
		// A viewer opens a plugin page after the realm rows went missing.
		db_execute("DELETE FROM user_auth WHERE username = 'cloudviewer'");
		db_execute(
			"INSERT INTO user_auth (username, password, realm, full_name, enabled) VALUES ('cloudviewer', '', 0, 'viewer', " .
				"'on')"
		);
		$viewer = (int) db_fetch_insert_id();
		db_execute("DELETE FROM plugin_realms WHERE plugin = 'cloud'");
		db_execute("DELETE FROM plugin_hooks WHERE name = 'cloud'");
		$_SESSION['sess_user_id'] = $viewer;
		db_execute("REPLACE INTO settings (name, value) VALUES ('admin_user', '0')");
		include_once($config['base_path'] . '/plugins/cloud/setup.php');
		plugin_cloud_upgrade();
		$result = array('viewer_realms' => (int) db_fetch_cell_prepared(
			'SELECT COUNT(*) FROM user_auth_realm WHERE user_id = ?',
			array($viewer)
		));
		db_execute("REPLACE INTO settings (name, value) VALUES ('admin_user', '1')");

		break;
	case 'collector_second':
	case 'collector_victim':
	case 'collector_kill':
		include($config['base_path'] . '/plugins/cloud/tests/bin/collector-restart.php');

		break;
	case 'state':
		break;
	default:
		fwrite(STDERR, "Unknown action $action\n");
		exit(2);
}

$output = ob_get_clean();

$tables = array();

foreach (db_fetch_assoc(
	"SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE " .
		"'plugin\\_cloud\\_%' ORDER BY TABLE_NAME"
) as $row) {
	$tables[$row['t']] = (int) db_fetch_cell('SELECT COUNT(*) FROM `' . $row['t'] . '`');
}

// The device state, only while the plugin's device table exists.
$device_state = array(
	'device_reindex' => array(),
	'devices'        => array(),
	'device_queries' => array(),
	'device_index'   => array(),
	'series'         => array(),
);

if (isset($tables['plugin_cloud_device'])) {
	$device_state = array(
		'device_reindex' => array_map('intval', array_column(
			db_fetch_assoc(
				'SELECT q.reindex_method FROM host_snmp_query q INNER JOIN plugin_cloud_device d ON d.host_id = q.host_id
					ORDER BY q.snmp_query_id'
			),
			'reindex_method'
		)),
		'devices' => db_fetch_assoc(
			'SELECT d.host_id, d.account_id, h.description, h.availability_method, h.poller_id, ht.name AS template
				FROM plugin_cloud_device d INNER JOIN host h ON h.id = d.host_id
				LEFT JOIN host_template ht ON ht.id = h.host_template_id'
		),
		'device_queries' => array_column(
			db_fetch_assoc(
				'SELECT sq.name FROM host_snmp_query hsq INNER JOIN snmp_query sq ON sq.id = hsq.snmp_query_id
					INNER JOIN plugin_cloud_device d ON d.host_id = hsq.host_id ORDER BY sq.name'
			),
			'name'
		),
		'series' => array_map(
			function ($row) {
				return $row['provider_id'] . ' ' . $row['metric'];
			},
			db_fetch_assoc(
				'SELECT r.provider_id, s.metric FROM plugin_cloud_series s
					INNER JOIN plugin_cloud_resource r ON r.id = s.resource_id ORDER BY r.provider_id, s.metric'
			)
		),
		'device_index' => array_column(
			db_fetch_assoc(
				"SELECT c.snmp_index, c.field_value FROM host_snmp_cache c INNER JOIN plugin_cloud_device d ON d.host_id = c.host_id
					WHERE c.field_name = 'resourceName' ORDER BY c.snmp_index"
			),
			'field_value',
			'snmp_index'
		),
	);
}

print json_encode(array(
	'result'  => $result,
	'output'  => $output,
	'plugin'  => db_fetch_row("SELECT status, version FROM plugin_config WHERE directory = 'cloud'"),
	'schema'  => db_fetch_cell("SELECT value FROM settings WHERE name = 'cloud_schema_version'"),
	'hooks'   => array_column(
		db_fetch_assoc("SELECT hook, status FROM plugin_hooks WHERE name = 'cloud' ORDER BY hook"),
		'status',
		'hook'
	),
	'realms'  => array_column(
		db_fetch_assoc("SELECT file, display FROM plugin_realms WHERE plugin = 'cloud' ORDER BY file"),
		'display',
		'file'
	),
	'admin'   => (int) db_fetch_cell(
		"SELECT COUNT(*) FROM user_auth_realm AS uar INNER JOIN plugin_realms AS pr ON pr.id + 100 = uar.realm_id WHERE " .
			"pr.plugin = 'cloud' AND uar.user_id = 1"
	),
	'tracked' => array_column(
		db_fetch_assoc("SELECT `table` FROM plugin_db_changes WHERE plugin = 'cloud' AND method = 'create' ORDER BY `table`"),
		'table'
	),
	'tracked_columns' => array_map(
		static fn ($r) => $r['table'] . '.' . $r['column'],
		db_fetch_assoc(
			"SELECT `table`, `column` FROM plugin_db_changes WHERE plugin = 'cloud' AND method = 'addcolumn' " .
				'ORDER BY `table`, `column`'
		)
	),
	'health_columns'  => array_column(
		db_fetch_assoc(
			'SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ' .
				"AND TABLE_NAME = 'plugin_cloud_health' ORDER BY ORDINAL_POSITION"
		),
		'c'
	),
	'tables'  => $tables,
	// Rows of the tables a template import writes, to show a re-import adds none.
	'template_rows' => array(
		'host_template'         => (int) db_fetch_cell('SELECT COUNT(*) FROM host_template'),
		'host_template_snmp'    => (int) db_fetch_cell('SELECT COUNT(*) FROM host_template_snmp_query'),
		'snmp_query'            => (int) db_fetch_cell('SELECT COUNT(*) FROM snmp_query'),
		'snmp_query_graph'      => (int) db_fetch_cell('SELECT COUNT(*) FROM snmp_query_graph'),
		'snmp_query_graph_rrd'  => (int) db_fetch_cell('SELECT COUNT(*) FROM snmp_query_graph_rrd'),
		'data_template'         => (int) db_fetch_cell('SELECT COUNT(*) FROM data_template'),
		'data_template_rrd'     => (int) db_fetch_cell('SELECT COUNT(*) FROM data_template_rrd WHERE local_data_id = 0'),
		'graph_templates'       => (int) db_fetch_cell('SELECT COUNT(*) FROM graph_templates'),
		'graph_templates_item'  => (int) db_fetch_cell('SELECT COUNT(*) FROM graph_templates_item WHERE local_graph_id = 0'),
		'graph_template_input'  => (int) db_fetch_cell('SELECT COUNT(*) FROM graph_template_input'),
	),
	'template_names' => array_column(
		db_fetch_assoc("SELECT name FROM graph_templates WHERE name LIKE 'Cloud: %' ORDER BY name"),
		'name'
	),
	'devices' => $device_state['devices'],
	'device_queries' => $device_state['device_queries'],
	'device_index' => $device_state['device_index'],
	'device_reindex' => $device_state['device_reindex'],
	'series' => $device_state['series'],
	'hosts' => (int) db_fetch_cell('SELECT COUNT(*) FROM host'),
	'templates_sha' => db_fetch_cell("SELECT value FROM settings WHERE name = 'cloud_templates_sha256'"),
	'resource_files' => array_map(
		'basename',
		glob($config['base_path'] . '/resource/script_server/cloud_*.xml') ?: array()
	),
), JSON_PRETTY_PRINT) . PHP_EOL;
