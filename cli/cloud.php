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
 * Operator and CasC CLI entry point:
 *
 *   php plugins/cloud/cli/cloud.php <export|validate|plan|apply|health> [options]
 *
 * This file must parse on PHP 8.0 and refuses to run below 8.2.
 */

chdir(__DIR__ . '/../../..');

require('./include/cli_check.php');
require_once($config['base_path'] . '/plugins/cloud/setup.php');

if (cloud_refuse_unsupported()) {
	exit(1);
}

cloud_boot();

$cloud_args   = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? array_slice($_SERVER['argv'], 1) : array();
$cloud_cli    = new \Somethingwithproof\Cloud\Cli(cloud_casc_provider());
$cloud_result = $cloud_cli->run($cloud_args, function (string $path): ?string {
	if (!is_file($path) || !is_readable($path)) {
		return null;
	}

	$data = file_get_contents($path);

	return is_string($data) ? $data : null;
});

if ($cloud_result['stdout'] !== '') {
	fwrite(STDOUT, $cloud_result['stdout']);
}

if ($cloud_result['stderr'] !== '') {
	fwrite(STDERR, $cloud_result['stderr']);
}

exit($cloud_result['exitCode']);
