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
 * One discovery of the Seed account, included by tests/bin/lifecycle.php
 * discover, through the production collector with a fake driver. The
 * argument lists the resources discovery returns, comma separated, each
 * "<type>:<id>=<name>". The collector itself must index the scope device
 * when the resources it serves change; nothing here runs a data query.
 */

use Somethingwithproof\Cloud\Account;
use Somethingwithproof\Cloud\CloudResource;
use Somethingwithproof\Cloud\CollectResult;
use Somethingwithproof\Cloud\Collector;
use Somethingwithproof\Cloud\CredentialStore;
use Somethingwithproof\Cloud\Drivers;
use Somethingwithproof\Cloud\HttpTransport;
use Somethingwithproof\Cloud\Inventory;
use Somethingwithproof\Cloud\OutboundPolicy;
use Somethingwithproof\Cloud\Provider;
use Somethingwithproof\Cloud\ProviderDriver;
use Somethingwithproof\Cloud\Secret;
use Somethingwithproof\Cloud\UsageEstimate;
use Somethingwithproof\Cloud\VerifyStatus;

include_once($config['base_path'] . '/plugins/cloud/setup.php');
cloud_boot();

final class DiscoverDriver implements ProviderDriver {
	/** @var list<CloudResource> */
	public static array $resources = [];

	public function __construct(HttpTransport $http) {
	}

	public function provider(): Provider {
		return Provider::DigitalOcean;
	}

	public function endpoints(Account $account): OutboundPolicy {
		return new OutboundPolicy(['api.example.com']);
	}

	public function operations(): array {
		return [];
	}

	public function verify(Account $account, Secret $credential): VerifyStatus {
		return VerifyStatus::Ok;
	}

	public function discover(Account $account, Secret $credential): Inventory {
		return new Inventory(self::$resources, 1);
	}

	public function collect(Account $account, Secret $credential, array $requests): CollectResult {
		return new CollectResult([], 0);
	}

	public function estimate(array $requests, int $intervalSeconds): UsageEstimate {
		return new UsageEstimate(count($requests), 0, 0.0);
	}
}

foreach (array_filter(explode(',', $argument)) as $entry) {
	list($type_id, $name) = explode('=', $entry, 2);
	list($type, $id)      = explode(':', $type_id, 2);

	DiscoverDriver::$resources[] = new CloudResource($type, $id, $name, 'nyc3');
}

$discover_dir = sys_get_temp_dir() . '/cloud-discover-' . getmypid();
@mkdir($discover_dir, 0700);
file_put_contents($discover_dir . '/token', 'dop_v1_' . str_repeat('c', 64));
chmod($discover_dir . '/token', 0600);

$account = (int) db_fetch_cell("SELECT id FROM plugin_cloud_account WHERE name = 'Seed'");
db_execute_prepared(
	"UPDATE plugin_cloud_account SET credential_source = 'file', credential_file = ? WHERE id = ?",
	array($discover_dir . '/token', $account)
);
// Discovery is due on every call.
db_execute_prepared('DELETE FROM plugin_cloud_health WHERE account_id = ?', array($account));

$discover_clock   = new \Somethingwithproof\Cloud\SystemClock();
$discover_drivers = new Drivers(
	static fn (OutboundPolicy $p): HttpTransport => new \Somethingwithproof\Cloud\RefusingTransport()
);
$discover_drivers->register(
	Provider::DigitalOcean,
	static fn (HttpTransport $http): ProviderDriver => new DiscoverDriver($http)
);

$result = (new Collector(
	new \Somethingwithproof\Cloud\AccountRepository(false, $discover_clock),
	$discover_drivers,
	new CredentialStore(new \Somethingwithproof\Cloud\SecretRepository(false, $discover_clock), '/nonexistent'),
	new \Somethingwithproof\Cloud\LeaseRepository(false, $discover_clock),
	new \Somethingwithproof\Cloud\ResourceRepository(false, $discover_clock),
	new \Somethingwithproof\Cloud\SeriesRepository(false),
	new \Somethingwithproof\Cloud\ObservationRepository(false),
	new \Somethingwithproof\Cloud\HealthRepository(false),
	new \Somethingwithproof\Cloud\Redactor(),
	$discover_clock,
	'lifecycle-discover',
	cloud_scope_devices(1)
))->run(1, 240);

unlink($discover_dir . '/token');
rmdir($discover_dir);
