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

use Somethingwithproof\Cloud\AccountRepository;
use Somethingwithproof\Cloud\FixedClock;
use Somethingwithproof\Cloud\HealthRepository;
use Somethingwithproof\Cloud\Replication;
use Somethingwithproof\Cloud\ReplicationRepository;
use Somethingwithproof\Cloud\ResourceRepository;

beforeEach(function () {
	$GLOBALS['__cloud_db_rows'] = [];
	$GLOBALS['__cloud_db_exec'] = [];
	$GLOBALS['__cloud_log']     = [];
});

afterEach(function () {
	$GLOBALS['__cloud_db_rows'] = [];
	$GLOBALS['__cloud_db_exec'] = [];
	$GLOBALS['__cloud_log']     = [];
});

it('replicates only poller-scoped metadata and poller-owned encrypted secrets, never main poller or raw keys', function () {
	$clock = new FixedClock(1731542400);
	$GLOBALS['__cloud_db_rows'] = [
		'FROM plugin_cloud_account WHERE poller_id = ?' => [
			['id' => 10, 'provider' => 'aws', 'name' => 'Remote AWS', 'poller_id' => 2, 'credential_source' => 'vault'],
		],
		'FROM plugin_cloud_device d' => [
			['host_id' => 55, 'account_id' => 10, 'created_at' => 1700000000],
		],
		'FROM plugin_cloud_series s' => [
			['local_data_id' => 90, 'account_id' => 10, 'resource_id' => 3, 'metric' => 'ec2_cpu_pct', 'created_at' => 1700000000],
		],
		'FROM plugin_cloud_secret s' => [
			['account_id' => 10, 'purpose' => 'credential', 'nonce' => '123456789012', 'ciphertext' => 'cipher', 'tag' => '1234567890123456', 'key_id' => 'k1', 'expires_at' => 0, 'updated_at' => 1700000000],
		],
	];

	$replication = new Replication(
		new ReplicationRepository(false),
		new AccountRepository(false, $clock),
		new HealthRepository(false),
		new ResourceRepository(false, $clock),
		$clock
	);

	// Main poller (id=1) is never replicated out.
	$pushed = [];
	$main   = $replication->replicateOut(1, static function (array $rows, string $table, int $poller) use (&$pushed): void {
		$pushed[$table] = [$poller, $rows];
	});
	expect($main)->toBe([])->and($pushed)->toBe([]);

	// Remote poller (id=2) replicates poller-scoped tables only.
	$counts = $replication->replicateOut(2, static function (array $rows, string $table, int $poller) use (&$pushed): void {
		$pushed[$table] = [$poller, $rows];
	});

	expect($counts)->toBe([
		'plugin_cloud_account' => 1,
		'plugin_cloud_device'  => 1,
		'plugin_cloud_series'  => 1,
		'plugin_cloud_secret'  => 1,
	])
		->and(array_keys($pushed))->toBe([
			'plugin_cloud_account',
			'plugin_cloud_device',
			'plugin_cloud_series',
			'plugin_cloud_secret',
		])
		->and($pushed['plugin_cloud_account'][0])->toBe(2);
});

it('exposes read-only health summary through remoteHealth and cloud_remote_agent with zero secrets', function () {
	$clock = new FixedClock(1731542400);
	$GLOBALS['__cloud_db_rows'] = [
		'FROM plugin_cloud_account WHERE poller_id = ?' => [
			[
				'id'                => 10,
				'provider'          => 'aws',
				'name'              => 'Remote AWS',
				'poller_id'         => 2,
				'credential_source' => 'file',
				'credential_file'   => '/etc/cacti/secret-aws.json',
				'admin_state'       => 'enabled',
				'verify_status'     => 'ok',
			],
		],
		'FROM plugin_cloud_health' => [
			[
				'account_id'   => 10,
				'run_started'  => 1731542300,
				'run_finished' => 1731542310,
				'collection'   => 'ok',
				'discovery'    => 'ok',
				'requests'     => 4,
				'series_read'  => 12,
				'series_total' => 12,
			],
		],
		'GROUP BY lifecycle' => [
			['lifecycle' => 'active', 'n' => 3],
		],
	];

	$replication = new Replication(
		new ReplicationRepository(false),
		new AccountRepository(false, $clock),
		new HealthRepository(false),
		new ResourceRepository(false, $clock),
		$clock
	);

	$payload = $replication->remoteHealth(2);
	$json    = (string) json_encode($payload);

	expect($payload['poller_id'])->toBe(2)
		->and($payload['accounts'])->toHaveCount(1)
		->and($payload['accounts'][0]['name'])->toBe('Remote AWS')
		->and($payload['accounts'][0]['collection'])->toBe('ok')
		->and($payload['accounts'][0]['resources'])->toBe(['active' => 3])
		// Credential file paths and secret fields must never appear.
		->and($json)->not->toContain('/etc/cacti/secret-aws.json')
		->and($json)->not->toContain('credential_file');

	// Unknown remote_agent actions pass through unchanged.
	expect(cloud_remote_agent('other_action'))->toBe('other_action');

	// Known cloud_health action emits JSON and returns true.
	$GLOBALS['config']['poller_id'] = 2;
	ob_start();
	$handled = cloud_remote_agent(Replication::REMOTE_AGENT_ACTION);
	$output  = (string) ob_get_clean();
	$GLOBALS['config']['poller_id'] = 1;

	expect($handled)->toBeTrue()
		->and(json_decode($output, true)['accounts'][0]['name'] ?? null)->toBe('Remote AWS');
});

it('aborts replication without pushing any table when a source query fails', function () {
	$clock = new FixedClock(1731542400);
	$GLOBALS['__cloud_db_rows'] = [
		'FROM plugin_cloud_account WHERE poller_id = ?' => false,
	];

	$replication = new Replication(
		new ReplicationRepository(false),
		new AccountRepository(false, $clock),
		new HealthRepository(false),
		new ResourceRepository(false, $clock),
		$clock
	);

	$pushed = [];
	$counts = $replication->replicateOut(2, static function (array $rows, string $table, int $poller) use (&$pushed): void {
		$pushed[] = $table;
	});

	expect($counts)->toBe([])->and($pushed)->toBe([]);
});
