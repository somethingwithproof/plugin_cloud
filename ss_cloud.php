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
 * Script server entry point for the resource data queries (#44). Cacti runs
 * this file two ways: the script server includes it once per process and
 * calls ss_cloud() for each poller item, and a data query reindex runs it as
 * a CLI script. Kept parseable by PHP 8.0, like the other entry points.
 */

if (!isset($called_by_script_server)) {
	include_once(__DIR__ . '/../../include/cli_check.php');

	array_shift($_SERVER['argv']);

	print call_user_func_array('ss_cloud', $_SERVER['argv']);
}

/**
 * The script server calls only a function defined in the file the poller
 * item names, after checking that file resolves under Cacti's base path.
 * Parameters stay untyped, like Cacti's own ss_ functions: every argument
 * arrives as a string.
 *
 * @param mixed $host_id the calling device, from the |host_id| argument
 * @param mixed $type    the resource type the data query names
 * @param mixed $cmd     index, num_indexes, query, or get
 * @param mixed $arg1    the field, for query and get
 * @param mixed $arg2    the index, for get
 */
function ss_cloud($host_id = 0, $type = '', $cmd = 'index', $arg1 = '', $arg2 = '') {
	include_once(__DIR__ . '/setup.php');

	$get = $cmd === 'get';

	// An unsupported runtime or a disabled plugin serves nothing.
	if (!cloud_php_supported() || !api_plugin_is_enabled('cloud') || !cloud_boot()) {
		return $get ? 'U' : '';
	}

	$output = cloud_script_server()->run(
		is_numeric($host_id) ? (int) $host_id : 0,
		is_string($type) ? $type : '',
		is_string($cmd) ? $cmd : '',
		is_scalar($arg1) ? (string) $arg1 : '',
		is_scalar($arg2) ? (string) $arg2 : ''
	);

	if ($get) {
		return $output;
	}

	print $output;

	return '';
}
