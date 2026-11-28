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

use Somethingwithproof\Cloud\Account;
use Somethingwithproof\Cloud\AccountRepository;
use Somethingwithproof\Cloud\AccountsPage;
use Somethingwithproof\Cloud\CostPreview;
use Somethingwithproof\Cloud\CredentialStore;
use Somethingwithproof\Cloud\DeviceRepository;
use Somethingwithproof\Cloud\Drivers;
use Somethingwithproof\Cloud\EstimateView;
use Somethingwithproof\Cloud\FixedClock;
use Somethingwithproof\Cloud\FreshnessEvaluator;
use Somethingwithproof\Cloud\HealthPage;
use Somethingwithproof\Cloud\HealthRepository;
use Somethingwithproof\Cloud\HealthWarnings;
use Somethingwithproof\Cloud\ObservationRepository;
use Somethingwithproof\Cloud\ResourceRepository;
use Somethingwithproof\Cloud\SecretRepository;
use Somethingwithproof\Cloud\SeriesRepository;
use Somethingwithproof\Cloud\Settings;

beforeEach(function () {
	$GLOBALS['__cloud_db_rows'] = [];
	$GLOBALS['__cloud_db_exec'] = [];
});

afterEach(function () {
	$GLOBALS['__cloud_db_rows'] = [];
	$GLOBALS['__cloud_db_exec'] = [];
});

it('renders AccountsPage list, edit, and delete confirmation without leaking secrets', function () {
	$clock = new FixedClock(1731542400);
	$page  = new AccountsPage(
		new AccountRepository(false, $clock),
		new CredentialStore(new SecretRepository(false, $clock), '/etc/cacti/vault.key', '/etc/cacti/creds'),
		new DeviceRepository(false, $clock)
	);

	// Empty list renders placeholder row.
	ob_start();
	$page->renderList();
	$emptyHtml = (string) ob_get_clean();
	expect($emptyHtml)->toContain('No cloud accounts yet.');

	// Populated list across vault, file, and instance sources with a linked device.
	$GLOBALS['__cloud_db_rows'] = [
		'FROM plugin_cloud_account ORDER BY name' => [
			[
				'id'                => 1,
				'provider'          => 'digitalocean',
				'name'              => 'DO <Prod>',
				'poller_id'         => 1,
				'credential_source' => 'vault',
				'admin_state'       => 'enabled',
				'verify_status'     => 'ok',
			],
			[
				'id'                => 2,
				'provider'          => 'aws',
				'name'              => 'AWS File',
				'poller_id'         => 1,
				'scope'             => json_encode(['account_id' => '123456789012', 'regions' => 'us-east-1']),
				'credential_source' => 'file',
				'credential_file'   => '/etc/cacti/creds/aws.json',
				'admin_state'       => 'disabled',
				'verify_status'     => 'never',
			],
			[
				'id'                => 3,
				'provider'          => 'gcp',
				'name'              => 'GCP IMDS',
				'poller_id'         => 1,
				'scope'             => json_encode(['project' => 'my-proj-1']),
				'credential_source' => 'instance',
				'admin_state'       => 'enabled',
				'verify_status'     => 'ok',
			],
		],
		'FROM plugin_cloud_secret WHERE account_id = ?' => [['c' => 1]],
		'SELECT d.host_id FROM plugin_cloud_device'     => [['host_id' => 77]],
	];

	ob_start();
	$page->renderList();
	$listHtml = (string) ob_get_clean();

	expect($listHtml)->toContain('DO &lt;Prod&gt;')
		->and($listHtml)->toContain('Stored in the vault')
		->and($listHtml)->toContain('File on the poller')
		->and($listHtml)->toContain('Instance identity')
		->and($listHtml)->toContain('Device 77')
		->and($listHtml)->not->toContain('DO <Prod>');

	// New account edit form with validation error and rejected values.
	ob_start();
	$page->renderEdit(null, ['provider' => 'aws', 'name' => 'bad', 'enabled' => ''], ['name' => 'Name error']);
	$createHtml = (string) ob_get_clean();
	expect($createHtml)->toContain('New Cloud Account')
		->and($createHtml)->toContain('Name error')
		->and($createHtml)->toContain('scope_account_id')
		->and($createHtml)->toContain('scope_regions');

	// Existing account edit form and delete confirmation.
	$existing = Account::fromRow($GLOBALS['__cloud_db_rows']['FROM plugin_cloud_account ORDER BY name'][0]);
	ob_start();
	$page->renderEdit($existing);
	$editHtml = (string) ob_get_clean();
	expect($editHtml)->toContain('A credential is stored. Leave blank to keep it.');

	ob_start();
	$page->renderDeleteConfirm($existing);
	$delHtml = (string) ob_get_clean();
	expect($delHtml)->toContain('confirm')
		->and($delHtml)->toContain('DO &lt;Prod&gt;');
});

it('renders HealthPage and EstimateView across admin, viewer, throttled, and empty states', function () {
	$clock = new FixedClock(1731542400);
	$warnings = new HealthWarnings(
		static function (string $path): void {
		},
		static function (string $path, string $dir): void {
			throw \Somethingwithproof\Cloud\CredentialError::because('unreadable file');
		}
	);
	$healthPage = new HealthPage(
		new HealthRepository(false),
		new ResourceRepository(false, $clock),
		new ObservationRepository(false),
		new FreshnessEvaluator($clock),
		$warnings,
		$clock
	);

	$doAccount = Account::fromRow([
		'id'                => 1,
		'provider'          => 'digitalocean',
		'name'              => 'do-prod',
		'poller_id'         => 1,
		'credential_source' => 'file',
		'credential_file'   => '/etc/cacti/do.token',
		'collect_interval'  => 300,
		'request_share'     => 50,
		'admin_state'       => 'enabled',
		'verify_status'     => 'ok',
	]);
	$disabledAccount = Account::fromRow([
		'id'                => 2,
		'provider'          => 'aws',
		'name'              => 'aws-off',
		'poller_id'         => 1,
		'credential_source' => 'instance',
		'collect_interval'  => 300,
		'admin_state'       => 'disabled',
	]);

	$GLOBALS['__cloud_db_rows'] = [
		'FROM plugin_cloud_health WHERE account_id = ?' => [
			[
				'account_id'    => 1,
				'run_started'   => 1731542340,
				'run_finished'  => 1731542350,
				'collection'    => 'failed',
				'error_class'   => 'throttled',
				'requests'      => 12,
				'series_total'  => 10,
				'series_read'   => 4,
				'discovered_at' => 1731400000,
				'discovery'     => 'ok',
				'retry_at'      => 1731542700,
			],
		],
		'SELECT lifecycle, COUNT(*) AS n FROM plugin_cloud_resource' => [
			['lifecycle' => 'active', 'n' => 3],
		],
		'FROM plugin_cloud_observation o INNER JOIN' => [
			['resource_id' => 10, 'metric' => 'do_bw_pub_in', 'value' => '100', 'observed_at' => 1731542300, 'fetched_at' => 1731542350, 'status' => 'ok'],
		],
		'SELECT id, provider_id, name, first_seen, lifecycle FROM plugin_cloud_resource' => [
			['id' => 10, 'provider_id' => '101', 'name' => 'web-1', 'first_seen' => 1731500000, 'lifecycle' => 'active'],
		],
		'FROM plugin_cloud_series s' => [
			['resource_id' => 10, 'type' => 'droplet', 'provider_id' => '101', 'metric' => 'do_bw_pub_in'],
		],
	];

	// Admin sees credential column and admin-only credential file warning.
	ob_start();
	$healthPage->render(Settings::fromRow([]), true, 300, [$doAccount, $disabledAccount]);
	$adminHtml = (string) ob_get_clean();
	expect($adminHtml)->toContain('unreadable file')
		->and($adminHtml)->toContain('Credential')
		->and($adminHtml)->toContain('retry in 300 s')
		->and($adminHtml)->toContain('Disabled')
		->and($adminHtml)->toContain('current 1');

	// Non-admin viewer does not see admin-only credential warning or Credential column.
	ob_start();
	$healthPage->render(Settings::fromRow([]), false, 300, []);
	$viewerEmptyHtml = (string) ob_get_clean();
	expect($viewerEmptyHtml)->toContain('No warnings.')
		->and($viewerEmptyHtml)->toContain('No cloud accounts yet.')
		->and($viewerEmptyHtml)->not->toContain('unreadable file');

	// EstimateView renders both empty and populated DigitalOcean estimates.
	$estimateView = new EstimateView(
		new CostPreview(new ResourceRepository(false, $clock), new SeriesRepository(false)),
		Drivers::production()
	);

	ob_start();
	$estimateView->render([]);
	$emptyEst = (string) ob_get_clean();
	expect($emptyEst)->toContain('No accounts to estimate.');

	ob_start();
	$estimateView->render([$doAccount]);
	$estHtml = (string) ob_get_clean();
	expect($estHtml)->toContain('Cloud Request Estimate')
		->and($estHtml)->toContain('do-prod')
		->and($estHtml)->toContain('Droplet: CPU')
		->and($estHtml)->toContain('Request share:');
});
