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
 * The cloud collector for one poller, started by the poller_bottom hook:
 *
 *   php plugins/cloud/poller_cloud.php [--poller=N] [--force] [--debug]
 *
 * One instance per poller, through Cacti's process registry. This file must
 * parse on PHP 8.0 and refuses to run below 8.2.
 */

chdir(__DIR__ . '/../..');

require('./include/cli_check.php');
require_once($config['base_path'] . '/lib/poller.php');
require_once($config['base_path'] . '/plugins/cloud/setup.php');

ini_set('max_execution_time', '0');

$cloud_poller = (int) $config['poller_id'];
$cloud_force  = false;

foreach (array_slice($_SERVER['argv'], 1) as $cloud_arg) {
	if (strpos($cloud_arg, '--poller=') === 0) {
		$cloud_poller = (int) substr($cloud_arg, 9);
	} elseif ($cloud_arg === '--force') {
		$cloud_force = true;
	} elseif ($cloud_arg === '--debug') {
		$debug = true;
	} else {
		fwrite(STDERR, 'ERROR: Unknown argument ' . $cloud_arg . PHP_EOL . 'usage: poller_cloud.php [--poller=N] [--force] [--debug]' . PHP_EOL);
		exit(1);
	}
}

if (cloud_refuse_unsupported()) {
	exit(1);
}

cloud_boot();

if (!$cloud_force && !api_plugin_is_enabled('cloud')) {
	exit(0);
}

if ($cloud_poller < 1) {
	exit(0);
}

if (!(new \Somethingwithproof\Cloud\Schema(false))->isCurrent()) {
	cacti_log('ERROR: The cloud collector did not run because the plugin schema is not current; open the Plugins page to finish the upgrade', false, 'CLOUD');
	exit(1);
}

$cloud_budget = cloud_settings()->collectorBudget;

if (!register_process_start('cloud', 'collector', $cloud_poller, $cloud_budget + 120)) {
	cacti_log('NOTE: A cloud collector is already running on poller ' . $cloud_poller, false, 'CLOUD', POLLER_VERBOSITY_MEDIUM);
	exit(0);
}

try {
	$cloud_result = cloud_collector($cloud_poller)->run($cloud_poller, $cloud_budget);
} finally {
	unregister_process('cloud', 'collector', $cloud_poller);
}

cacti_log(sprintf('STATS: Poller:%d Accounts:%d Skipped:%d StoppedEarly:%s', $cloud_poller, $cloud_result['accounts'], $cloud_result['skipped'], $cloud_result['stopped_early'] ? 'yes' : 'no'), false, 'CLOUD', POLLER_VERBOSITY_MEDIUM);

exit(0);
