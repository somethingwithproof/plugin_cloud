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
 * Cloud Health (Cloud View realm). Parses on PHP 8.0; cloud_page_start()
 * refuses an unsupported runtime before any plugin class loads.
 */

chdir('../../');
include('./include/auth.php');
include_once('./plugins/cloud/setup.php');

if (!cloud_page_start()) {
	exit;
}

// Without Cloud Admin, Cacti's device permissions decide which accounts show.
$cloud_admin    = api_user_realm_auth('cloud_accounts.php');
$cloud_accounts = $cloud_admin ? cloud_accounts()->all() : cloud_device_allowed_accounts();
$cloud_clock    = new \Somethingwithproof\Cloud\SystemClock();
$cloud_page     = new \Somethingwithproof\Cloud\HealthPage(
	new \Somethingwithproof\Cloud\HealthRepository(false),
	new \Somethingwithproof\Cloud\ResourceRepository(false, $cloud_clock),
	new \Somethingwithproof\Cloud\ObservationRepository(false),
	new \Somethingwithproof\Cloud\FreshnessEvaluator($cloud_clock),
	\Somethingwithproof\Cloud\HealthWarnings::production(),
	$cloud_clock
);

top_header();
$cloud_page->render(
	cloud_settings(),
	$cloud_admin,
	(int) read_config_option('poller_interval'),
	$cloud_accounts
);
cloud_estimate_view()->render($cloud_accounts);
bottom_footer();
