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

		foreach (db_fetch_assoc("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'plugin\\_cloud\\_%'") as $row) {
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
	case 'drop_table':
		db_execute('DROP TABLE IF EXISTS `' . preg_replace('/[^a-z_]/', '', $argument) . '`');

		break;
	case 'seed':
		// An account row, so table drops and keeps are visible.
		db_execute("INSERT INTO plugin_cloud_account (provider, name, credential_source) VALUES ('digitalocean', 'Seed', 'vault')");

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
		$store    = new \Somethingwithproof\Cloud\CredentialStore(new \Somethingwithproof\Cloud\SecretRepository(false, $clock), $keyFile);
		$input    = array('provider' => 'digitalocean', 'name' => 'Flow', 'poller_id' => '1', 'credential_source' => 'vault', 'collect_interval' => '300', 'discover_interval' => '3600', 'request_share' => '50', 'enabled' => 'on');

		$id = $accounts->save(\Somethingwithproof\Cloud\AccountInput::parse($input, 0, \Somethingwithproof\Cloud\Provider::released())->account, 1);
		$store->storeVaultCredential($id, new \Somethingwithproof\Cloud\Secret($secret));
		$accounts->recordCredentialChange($id, 1);
		$accounts->save(\Somethingwithproof\Cloud\AccountInput::parse(array('name' => 'Flow renamed') + $input, $id, \Somethingwithproof\Cloud\Provider::released())->account, 1);
		$accounts->recordVerify($id, \Somethingwithproof\Cloud\VerifyStatus::Invalid, 1);

		$readBack = $store->credentialFor($accounts->find($id))->reveal() === $secret;
		$audit    = array_reverse(array_column($accounts->auditLog($id), 'action'));
		$leaks    = array();

		foreach (db_fetch_assoc("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'plugin\\_cloud\\_%'") as $table) {
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

		$result = array('read_back' => $readBack, 'audit' => $audit, 'leaks' => $leaks, 'account_gone' => $accounts->find($id) === null);

		break;
	case 'state':
		break;
	default:
		fwrite(STDERR, "Unknown action $action\n");
		exit(2);
}

$output = ob_get_clean();

$tables = array();

foreach (db_fetch_assoc("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'plugin\\_cloud\\_%' ORDER BY TABLE_NAME") as $row) {
	$tables[$row['t']] = (int) db_fetch_cell('SELECT COUNT(*) FROM `' . $row['t'] . '`');
}

print json_encode(array(
	'result'  => $result,
	'output'  => $output,
	'plugin'  => db_fetch_row("SELECT status, version FROM plugin_config WHERE directory = 'cloud'"),
	'schema'  => db_fetch_cell("SELECT value FROM settings WHERE name = 'cloud_schema_version'"),
	'hooks'   => array_column(db_fetch_assoc("SELECT hook, status FROM plugin_hooks WHERE name = 'cloud' ORDER BY hook"), 'status', 'hook'),
	'realms'  => array_column(db_fetch_assoc("SELECT file, display FROM plugin_realms WHERE plugin = 'cloud' ORDER BY file"), 'display', 'file'),
	'admin'   => (int) db_fetch_cell("SELECT COUNT(*) FROM user_auth_realm AS uar INNER JOIN plugin_realms AS pr ON pr.id + 100 = uar.realm_id WHERE pr.plugin = 'cloud' AND uar.user_id = 1"),
	'tracked' => array_column(db_fetch_assoc("SELECT `table` FROM plugin_db_changes WHERE plugin = 'cloud' AND method = 'create' ORDER BY `table`"), 'table'),
	'tables'  => $tables,
), JSON_PRETTY_PRINT) . PHP_EOL;
