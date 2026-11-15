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
 * Collector restart scenarios (#35), included by tests/bin/lifecycle.php:
 *
 *   collector_kill    a run killed mid-account leaves no partial result and
 *                     the next run continues once the lease expires
 *   collector_victim  the run that is killed (started by collector_kill)
 *   collector_second  a second collector start exits at once
 *
 * Sets $result. Must parse on PHP 8.0 apart from the driver class, which
 * only loads where the plugin boots.
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

const RESTART_TIME   = 1800000000;
const RESTART_BUDGET = 240;

final class RestartDriver implements ProviderDriver {
	public static bool $kill = false;

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
		return new Inventory([new CloudResource('droplet', 'r1', 'Restart one', 'nyc3')], 1);
	}

	public function collect(Account $account, Secret $credential, array $requests): CollectResult {
		// Killed outright, as by the OOM killer or a reboot: no finally
		// block runs, so the lease is never released.
		if (self::$kill) {
			function_exists('posix_kill') ? posix_kill(getmypid(), 9) : exec('kill -9 ' . getmypid());
			sleep(5);
		}

		$out = [];

		foreach ($requests as $request) {
			$out[] = new Observation($request->resourceId, $request->metric, 7.0, RESTART_TIME - 60, RESTART_TIME);
		}

		return new CollectResult($out, 1);
	}

	public function estimate(array $requests, int $intervalSeconds): UsageEstimate {
		return new UsageEstimate(0, 0, 0.0);
	}
}

function restart_collector(FixedClock $clock, string $holder): Collector {
	$drivers = new Drivers(static fn (OutboundPolicy $p): HttpTransport => new \Somethingwithproof\Cloud\RefusingTransport());
	$drivers->register(Provider::DigitalOcean, static fn (HttpTransport $http): ProviderDriver => new RestartDriver($http));

	return new Collector(
		new \Somethingwithproof\Cloud\AccountRepository(false, $clock), $drivers,
		new CredentialStore(new SecretRepository(false, $clock), '/nonexistent'),
		new LeaseRepository(false, $clock), new ResourceRepository(false, $clock), new SeriesRepository(false),
		new ObservationRepository(false), new HealthRepository(false), new Redactor(), $clock, $holder
	);
}

function restart_state(int $account): array {
	return array(
		'lease'        => db_fetch_row_prepared('SELECT holder, expires_at FROM plugin_cloud_lease WHERE account_id = ?', array($account)),
		'health'       => db_fetch_row_prepared('SELECT run_started, collection, series_read FROM plugin_cloud_health WHERE account_id = ?', array($account)),
		'observations' => db_fetch_assoc('SELECT metric, value, observed_at, status FROM plugin_cloud_observation ORDER BY metric'),
	);
}

$restart_token = sys_get_temp_dir() . '/cloud-restart.token';

switch ($action) {
	case 'collector_second':
		// An enabled account a running collector would lease at once.
		db_execute("INSERT INTO plugin_cloud_account (provider, name, poller_id, credential_source, admin_state) VALUES ('digitalocean', 'Second start', 1, 'vault', 'enabled')");

		// A live process holds the collector slot for poller 1. Cacti 1.2.x
		// treats a slot as held only when the holder runs the same script,
		// so the stand-in names poller_cloud.php on its command line.
		$script  = $config['base_path'] . '/plugins/cloud/poller_cloud.php';
		$holder  = proc_open(array(PHP_BINARY, '-r', 'sleep(60);', $script), array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
		$status  = proc_get_status($holder);
		register_process('cloud', 'collector', 1, $status['pid'], 300);
		$started = microtime(true);
		exec(escapeshellarg(PHP_BINARY) . ' -q ' . escapeshellarg($script) . ' --poller=1 --force 2>&1', $lines, $rc);
		proc_terminate($holder, 9);
		proc_close($holder);
		unregister_process('cloud', 'collector', 1);
		$result = array('rc' => $rc, 'seconds' => round(microtime(true) - $started, 1), 'output' => implode("\n", $lines), 'leases' => (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_cloud_lease'));

		// Control: with the slot free, the same start does take the lease.
		exec(escapeshellarg(PHP_BINARY) . ' -q ' . escapeshellarg($config['base_path'] . '/plugins/cloud/poller_cloud.php') . ' --poller=1 --force 2>&1', $lines, $rc);
		$result['leases_when_free'] = (int) db_fetch_cell('SELECT COUNT(*) FROM plugin_cloud_lease');

		break;
	case 'collector_victim':
		RestartDriver::$kill = true;
		restart_collector(new FixedClock(RESTART_TIME), 'victim')->run(1, RESTART_BUDGET);

		break;
	case 'collector_kill':
		file_put_contents($restart_token, 'dop_v1_' . str_repeat('c', 64));
		chmod($restart_token, 0600);
		db_execute_prepared("INSERT INTO plugin_cloud_account (provider, name, poller_id, credential_source, credential_file, collect_interval, discover_interval, admin_state) VALUES ('digitalocean', 'Restart', 1, 'file', ?, 300, 3600, 'enabled')", array($restart_token));
		$account = (int) db_fetch_insert_id();

		// Discovery has run once; one active series is mapped.
		db_execute_prepared("INSERT INTO plugin_cloud_resource (account_id, type, provider_id, name, region, lifecycle, first_seen, last_seen) VALUES (?, 'droplet', 'r1', 'Restart one', 'nyc3', 'active', ?, ?)", array($account, RESTART_TIME - 600, RESTART_TIME - 600));
		$resource = (int) db_fetch_insert_id();
		db_execute("INSERT INTO data_template_data (local_data_id, data_template_id, name, active) VALUES (900101, 0, 'restart', 'on')");
		db_execute_prepared("INSERT INTO plugin_cloud_series (local_data_id, account_id, resource_id, metric) VALUES (900101, ?, ?, 'cpu')", array($account, $resource));

		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/lifecycle.php') . ' collector_victim 2>&1', $lines, $rc);
		$result = array('victim_rc' => $rc, 'after_kill' => restart_state($account));

		// Inside the dead run's lease, the account is skipped, not doubled.
		$clock = new FixedClock(RESTART_TIME + 60);
		$result['while_leased'] = restart_collector($clock, 'next')->run(1, RESTART_BUDGET);

		// After it expires, the next run takes over and finishes the account.
		$clock->advance(RESTART_BUDGET + 120);
		$result['after_expiry'] = restart_collector($clock, 'next')->run(1, RESTART_BUDGET);
		$result['final']        = restart_state($account);

		db_execute('DELETE FROM data_template_data WHERE local_data_id = 900101');
		unlink($restart_token);

		break;
}
