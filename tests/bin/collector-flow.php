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
 * Collector scenarios against a real Cacti database, included by
 * tests/bin/lifecycle.php collector_flow. A fake driver stands in for a
 * provider; everything else is the production code. Sets $result.
 */

use Somethingwithproof\Cloud\Account;
use Somethingwithproof\Cloud\CloudResource;
use Somethingwithproof\Cloud\CollectResult;
use Somethingwithproof\Cloud\Collector;
use Somethingwithproof\Cloud\CredentialStore;
use Somethingwithproof\Cloud\Drivers;
use Somethingwithproof\Cloud\FixedClock;
use Somethingwithproof\Cloud\HealthRepository;
use Somethingwithproof\Cloud\HttpTransport;
use Somethingwithproof\Cloud\Inventory;
use Somethingwithproof\Cloud\LeaseRepository;
use Somethingwithproof\Cloud\Observation;
use Somethingwithproof\Cloud\ObservationRepository;
use Somethingwithproof\Cloud\OutboundPolicy;
use Somethingwithproof\Cloud\Provider;
use Somethingwithproof\Cloud\ProviderDriver;
use Somethingwithproof\Cloud\Redactor;
use Somethingwithproof\Cloud\ResourceRepository;
use Somethingwithproof\Cloud\Secret;
use Somethingwithproof\Cloud\SecretRepository;
use Somethingwithproof\Cloud\SeriesRepository;
use Somethingwithproof\Cloud\UsageEstimate;
use Somethingwithproof\Cloud\VerifyStatus;

include_once($config['base_path'] . '/plugins/cloud/setup.php');
cloud_boot();

final class FlowDriver implements ProviderDriver {
	/** @var list<string> resource ids discovery returns */
	public static array $present = ['d1', 'd2'];
	public static int $discoveries = 0;
	/** @var list<string> */
	public static array $collected = [];
	public static int $observedAt = 0;
	/** @var list<string> account names in the order discovery ran */
	public static array $order = [];
	public static ?FixedClock $clock = null;
	/** seconds each discovery takes on $clock */
	public static int $tick = 0;

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
		self::$discoveries++;
		self::$order[] = $account->name;
		self::$clock?->advance(self::$tick);

		return new Inventory(
			array_map(fn (string $id) => new CloudResource('droplet', $id, 'Droplet ' . $id, 'nyc3'), self::$present),
			1
		);
	}

	public function collect(Account $account, Secret $credential, array $requests): CollectResult {
		$out = [];

		foreach ($requests as $request) {
			self::$collected[] = $request->providerId . '/' . $request->metric;
			$out[]             = new Observation(
				$request->resourceId,
				$request->metric,
				42.5,
				self::$observedAt,
				self::$observedAt + 30
			);
		}

		return new CollectResult($out, count($requests));
	}

	public function estimate(array $requests, int $intervalSeconds): UsageEstimate {
		return new UsageEstimate(0, 0, 0.0);
	}
}

$flow_dir = sys_get_temp_dir() . '/cloud-flow-' . getmypid();
@mkdir($flow_dir, 0700);
file_put_contents($flow_dir . '/token', 'dop_v1_' . str_repeat('b', 64));
chmod($flow_dir . '/token', 0600);

$flow_clock = new FixedClock(1800000000);

function flow_account($name, $poller, $state) {
	global $flow_dir;

	db_execute_prepared(
		"INSERT INTO plugin_cloud_account (provider, name, poller_id, credential_source, credential_file, " .
			"collect_interval, discover_interval, admin_state) VALUES ('digitalocean', ?, ?, 'file', ?, 300, 3600, ?)",
		array($name, $poller, $flow_dir . '/token', $state)
	);

	return (int) db_fetch_insert_id();
}

function flow_collector($clock, $holder) {
	$drivers = new Drivers(
		static fn (OutboundPolicy $p): HttpTransport => new \Somethingwithproof\Cloud\RefusingTransport()
	);
	$drivers->register(Provider::DigitalOcean, static fn (HttpTransport $http): ProviderDriver => new FlowDriver($http));

	return new Collector(
		new \Somethingwithproof\Cloud\AccountRepository(false, $clock), $drivers,
		new CredentialStore(new SecretRepository(false, $clock), '/nonexistent'),
		new LeaseRepository(false, $clock), new ResourceRepository(false, $clock), new SeriesRepository(false),
		new ObservationRepository(false), new HealthRepository(false), new Redactor(), $clock, $holder
	);
}

$main     = flow_account('Main', 1, 'enabled');
$remote   = flow_account('Remote', 2, 'enabled');
$disabled = flow_account('Off', 1, 'disabled');
$result   = array();

// Run 1: discovery is due; no series are mapped yet, so nothing is read.
FlowDriver::$observedAt = 1800000000 - 120;
$result['run1']        = flow_collector($flow_clock, 'h1')->run(1, 240);
$result['discoveries'] = FlowDriver::$discoveries;
$result['resources']   = (int) db_fetch_cell_prepared(
	'SELECT COUNT(*) FROM plugin_cloud_resource WHERE account_id = ?',
	array($main)
);
$result['others']      = (int) db_fetch_cell_prepared(
	'SELECT COUNT(*) FROM plugin_cloud_resource WHERE account_id IN (?, ?)',
	array($remote, $disabled)
);

// Map d1/cpu to an active data source, d2/cpu to an inactive one, and
// d1/load to a data source that does not exist.
$d1 = (int) db_fetch_cell_prepared(
	"SELECT id FROM plugin_cloud_resource WHERE account_id = ? AND provider_id = 'd1'",
	array($main)
);
$d2 = (int) db_fetch_cell_prepared(
	"SELECT id FROM plugin_cloud_resource WHERE account_id = ? AND provider_id = 'd2'",
	array($main)
);
db_execute(
	"INSERT INTO data_template_data (local_data_id, data_template_id, name, active) VALUES (900001, 0, 'flow on', " .
		"'on'), (900002, 0, 'flow off', '')"
);
db_execute_prepared(
	'INSERT INTO plugin_cloud_series (local_data_id, account_id, resource_id, metric) VALUES (900001, ?, ?, ?), ' .
		'(900002, ?, ?, ?), (900003, ?, ?, ?)',
	array($main, $d1, 'cpu', $main, $d2, 'cpu', $main, $d1, 'load')
);

// Run 2 within the interval does nothing; run 3 after it collects.
$flow_clock->advance(60);
flow_collector($flow_clock, 'h2')->run(1, 240);
$result['collected_early'] = FlowDriver::$collected;

$flow_clock->advance(300);
FlowDriver::$observedAt = $flow_clock->now() - 120;
flow_collector($flow_clock, 'h3')->run(1, 240);
$result['collected'] = FlowDriver::$collected;

// A run a few seconds early, as poller start times drift, is still due;
// one well inside the interval is not.
$flow_clock->advance(200);
flow_collector($flow_clock, 'h3a')->run(1, 240);
$result['collected_at_200'] = count(FlowDriver::$collected);
$flow_clock->advance(96);
flow_collector($flow_clock, 'h3b')->run(1, 240);
$result['collected_at_296'] = count(FlowDriver::$collected);
$result['discoveries_after'] = FlowDriver::$discoveries;
$result['observation'] = db_fetch_row_prepared(
	'SELECT value, observed_at, status FROM plugin_cloud_observation WHERE resource_id = ? AND metric = ?',
	array($d1, 'cpu')
);
$result['health'] = db_fetch_row_prepared(
	'SELECT collection, series_total, series_read FROM plugin_cloud_health WHERE account_id = ?',
	array($main)
);

// A writer without the lease, and an older datapoint, change nothing.
$observations = new ObservationRepository(false);
$observations->write(
	$main,
	'stranger',
	$flow_clock->now(),
	new Observation($d1, 'cpu', 1.0, $flow_clock->now(), $flow_clock->now())
);
(new LeaseRepository(false, $flow_clock))->acquire($main, 'h4', 3600);
$observations->write($main, 'h4', $flow_clock->now(), new Observation($d1, 'cpu', 2.0, 1, $flow_clock->now()));
$result['after_foreign_writes'] = db_fetch_row_prepared(
	'SELECT value, observed_at FROM plugin_cloud_observation WHERE resource_id = ? AND metric = ?',
	array($d1, 'cpu')
);

// A lease held by another live run makes this run skip the account.
$flow_clock->advance(400);
$result['while_leased'] = flow_collector($flow_clock, 'h5')->run(1, 240);
(new LeaseRepository(false, $flow_clock))->release($main, 'h4');

// A zero budget stops before the first account.
$result['no_budget'] = flow_collector($flow_clock, 'h6')->run(1, 0);

// Discovery without d2, three times past its interval: missing, then deleted.
FlowDriver::$present = array('d1');
for ($i = 0; $i < 3; $i++) {
	$flow_clock->advance(3700);
	flow_collector($flow_clock, 'h7')->run(1, 240);
	$result['d2_lifecycle'][] = db_fetch_cell_prepared(
		'SELECT lifecycle FROM plugin_cloud_resource WHERE id = ?',
		array($d2)
	);
}

// A writer without the lease cannot replace health either.
$health_before = db_fetch_row_prepared('SELECT * FROM plugin_cloud_health WHERE account_id = ?', array($main));
(new HealthRepository(false))->save(
	new \Somethingwithproof\Cloud\AccountHealth($main, 1, 1, 0.0, \Somethingwithproof\Cloud\CollectionState::Failed),
	'stranger',
	$flow_clock->now()
);
$result['health_unchanged'] = db_fetch_row_prepared(
	'SELECT * FROM plugin_cloud_health WHERE account_id = ?',
	array($main)
) == $health_before;

// Each discovery takes 300 s against a 100 s budget, so a run reaches one
// account. The never-run account goes first, then the least recent.
$second = flow_account('Second', 1, 'enabled');
FlowDriver::$order = array();
FlowDriver::$clock = $flow_clock;
FlowDriver::$tick  = 300;
$flow_clock->advance(3700);
$result['order_run1'] = flow_collector($flow_clock, 'h8')->run(1, 100);
$flow_clock->advance(3700);
flow_collector($flow_clock, 'h9')->run(1, 100);
$result['order'] = FlowDriver::$order;
FlowDriver::$clock = null;

db_execute('DELETE FROM data_template_data WHERE local_data_id IN (900001, 900002)');
unlink($flow_dir . '/token');
rmdir($flow_dir);
