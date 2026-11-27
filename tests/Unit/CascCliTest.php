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
use Somethingwithproof\Cloud\CascProvider;
use Somethingwithproof\Cloud\Cli;
use Somethingwithproof\Cloud\FixedClock;
use Somethingwithproof\Cloud\HealthRepository;
use Somethingwithproof\Cloud\Replication;
use Somethingwithproof\Cloud\ReplicationRepository;
use Somethingwithproof\Cloud\ResourceRepository;

function cloud_casc_fixture(): array {
	$clock = new FixedClock(1731542400);
	$acc   = new AccountRepository(false, $clock);
	$rep   = new Replication(
		new ReplicationRepository(false),
		$acc,
		new HealthRepository(false),
		new ResourceRepository(false, $clock),
		$clock
	);
	$casc  = new CascProvider($acc, $rep);

	return [$casc, new Cli($casc)];
}

beforeEach(function () {
	$GLOBALS['__cloud_db_rows'] = [];
	$GLOBALS['__cloud_db_exec'] = [];
});

afterEach(function () {
	$GLOBALS['__cloud_db_rows'] = [];
	$GLOBALS['__cloud_db_exec'] = [];
});

it('exports YAML and XML deterministically with vault references and zero raw secrets', function () {
	$GLOBALS['__cloud_db_rows'] = [
		'FROM plugin_cloud_account ORDER BY name' => [
			[
				'id'                => 2,
				'provider'          => 'aws',
				'name'              => 'aws-prod',
				'poller_id'         => 2,
				'scope'             => json_encode(['account_id' => '123456789012', 'regions' => 'us-east-1']),
				'credential_source' => 'vault',
				'collect_interval'  => 300,
				'discover_interval' => 3600,
				'request_share'     => 50,
				'monthly_ceiling'   => 25.5,
				'admin_state'       => 'enabled',
			],
			[
				'id'                => 1,
				'provider'          => 'digitalocean',
				'name'              => 'do-main',
				'poller_id'         => 1,
				'scope'             => '{}',
				'credential_source' => 'vault',
				'collect_interval'  => 300,
				'discover_interval' => 3600,
				'request_share'     => 50,
				'monthly_ceiling'   => null,
				'admin_state'       => 'enabled',
			],
		],
	];

	[$casc, $cli] = cloud_casc_fixture();
	$yaml         = $casc->export('yaml');
	$xml          = $casc->export('xml');

	expect($yaml)->toContain('credential_ref: vault:account:aws-prod')
		->and($yaml)->toContain('credential_ref: vault:account:do-main')
		->and($yaml)->toContain('account_id: 123456789012')
		->and($xml)->toContain('<credential_ref>vault:account:aws-prod</credential_ref>')
		->and($xml)->toContain('<cloud_config schema_version="1">');

	// Both exported formats validate cleanly round-trip.
	expect($casc->validate($yaml, 'yaml')['valid'])->toBeTrue()
		->and($casc->validate($xml, 'xml')['valid'])->toBeTrue();

	// CLI export matches.
	$res = $cli->run(['export', '--format=yaml'], static fn (): ?string => null);
	expect($res['exitCode'])->toBe(0)->and($res['stdout'])->toBe($yaml);
});

it('rejects raw secret fields in CasC manifests', function () {
	[$casc] = cloud_casc_fixture();
	$badYaml = <<<YAML
schema_version: 1
accounts:
  - name: leaky-aws
    provider: aws
    poller_id: 1
    credential_source: vault
    secret_access_key: super-secret-raw-key
    collect_interval: 300
    discover_interval: 3600
    request_share: 50
    enabled: true
    scope:
      account_id: 123456789012
      regions: us-east-1
YAML;

	$check = $casc->validate($badYaml, 'yaml');

	expect($check['valid'])->toBeFalse()
		->and(implode("\n", $check['errors']))->toContain('raw secret field "secret_access_key" is forbidden');
});

it('plans deterministic diffs with SHA-256 PLAN_HASH and refuses apply without exact confirmation', function () {
	$GLOBALS['__cloud_db_rows'] = [
		'FROM plugin_cloud_account ORDER BY name' => [
			[
				'id'                => 1,
				'provider'          => 'digitalocean',
				'name'              => 'do-main',
				'poller_id'         => 1,
				'scope'             => '{}',
				'credential_source' => 'vault',
				'collect_interval'  => 300,
				'discover_interval' => 3600,
				'request_share'     => 50,
				'monthly_ceiling'   => null,
				'admin_state'       => 'enabled',
			],
		],
		'FROM plugin_cloud_account WHERE id' => [
			[
				'id'                => 1,
				'provider'          => 'digitalocean',
				'name'              => 'do-main',
				'poller_id'         => 1,
				'scope'             => '{}',
				'credential_source' => 'vault',
				'collect_interval'  => 300,
				'discover_interval' => 3600,
				'request_share'     => 50,
				'monthly_ceiling'   => null,
				'admin_state'       => 'enabled',
			],
		],
	];

	$desiredYaml = <<<YAML
schema_version: 1
accounts:
  - name: do-main
    provider: digitalocean
    poller_id: 1
    credential_source: vault
    credential_ref: vault:account:do-main
    collect_interval: 600
    discover_interval: 3600
    request_share: 50
    enabled: true
    scope: {}
  - name: gcp-new
    provider: gcp
    poller_id: 2
    credential_source: instance
    collect_interval: 300
    discover_interval: 3600
    request_share: 50
    enabled: true
    scope:
      project: my-cacti-prod-1
YAML;

	[$casc, $cli] = cloud_casc_fixture();
	$plan1        = $casc->plan($desiredYaml, 'yaml');
	$plan2        = $casc->plan($desiredYaml, 'yaml');

	expect($plan1['valid'])->toBeTrue()
		->and($plan1['plan_hash'])->toMatch('/^[0-9a-f]{64}$/')
		->and($plan1['plan_hash'])->toBe($plan2['plan_hash'])
		->and(array_column($plan1['actions'], 'action'))->toBe(['update', 'create']);

	// Apply refuses missing or mismatched confirmation hash.
	$noConfirm  = $cli->run(['apply', '--file=cloud.yaml'], static fn (): string => $desiredYaml);
	$badConfirm = $cli->run(['apply', '--file=cloud.yaml', '--confirm=0000'], static fn (): string => $desiredYaml);

	expect($noConfirm['exitCode'])->toBe(2)
		->and($badConfirm['exitCode'])->toBe(1)
		->and($GLOBALS['__cloud_db_exec'])->toBe([]);

	// Apply succeeds with exact matching PLAN_HASH.
	$applied = $cli->run(
		['apply', '--file=cloud.yaml', '--confirm=' . $plan1['plan_hash']],
		static fn (): string => $desiredYaml
	);

	expect($applied['exitCode'])->toBe(0)
		->and($applied['stdout'])->toContain('APPLIED plan ' . $plan1['plan_hash'])
		->and($applied['stdout'])->toContain('created=1, updated=1, deleted=0');
});

it('renders read-only CLI health in text and json formats', function () {
	$GLOBALS['__cloud_db_rows'] = [
		'FROM plugin_cloud_account ORDER BY name' => [
			[
				'id'                => 1,
				'provider'          => 'digitalocean',
				'name'              => 'do-main',
				'poller_id'         => 1,
				'scope'             => '{}',
				'credential_source' => 'vault',
				'admin_state'       => 'enabled',
			],
		],
	];

	[, $cli] = cloud_casc_fixture();
	$text    = $cli->run(['health', '--format=text'], static fn (): ?string => null);
	$json    = $cli->run(['health', '--format=json'], static fn (): ?string => null);

	expect($text['exitCode'])->toBe(0)
		->and($text['stdout'])->toContain("do-main\tdigitalocean\t1\tenabled")
		->and($json['exitCode'])->toBe(0)
		->and(json_decode($json['stdout'], true)['accounts'][0]['name'] ?? null)->toBe('do-main');
});

it('rejects unrecognized enabled values, truncated YAML accounts, and missing XML accounts sections', function () {
	[$casc] = cloud_casc_fixture();

	$badEnabled = <<<YAML
schema_version: 1
accounts:
  - name: do-main
    provider: digitalocean
    poller_id: 1
    credential_source: vault
    collect_interval: 300
    discover_interval: 3600
    request_share: 50
    enabled: maybe
    scope: {}
YAML;

	expect($casc->validate($badEnabled, 'yaml')['valid'])->toBeFalse()
		->and($casc->validate("schema_version: 1\naccounts:\n", 'yaml')['valid'])->toBeFalse()
		->and($casc->validate("schema_version: 1\naccounts: []\n", 'yaml')['valid'])->toBeTrue()
		->and($casc->validate('<?xml version="1.0"?><cloud_config/>', 'xml')['valid'])->toBeFalse()
		->and($casc->validate('<?xml version="1.0"?><cloud_config><accounts/></cloud_config>', 'xml')['valid'])->toBeTrue();
});

it('exercises canonical plugin_casc array descriptor callbacks for export, validate, plan, and apply', function () {
	[$casc] = cloud_casc_fixture();
	$desc   = $casc->descriptor();

	expect($desc['slug'])->toBe('cloud')
		->and($desc['schemaVersion'])->toBe('1.0');

	$exported = ($desc['export'])([]);
	expect($exported)->toBe(['schema_version' => 1, 'accounts' => []]);

	$desired = [
		'schema_version' => 1,
		'accounts'       => [
			[
				'name'              => 'do-array',
				'provider'          => 'digitalocean',
				'poller_id'         => 1,
				'credential_source' => 'vault',
				'credential_ref'    => 'vault:account:do-array',
				'collect_interval'  => 300,
				'discover_interval' => 3600,
				'request_share'     => 50,
				'enabled'           => true,
				'scope'             => [],
			],
		],
	];

	expect(($desc['validate'])($desired))->toBe([]);
	expect(($desc['validate'])(['schema_version' => 99, 'accounts' => []]))->not->toBeEmpty();

	$plan = ($desc['plan'])($desired, []);
	expect($plan['valid'])->toBeTrue()
		->and($plan['actions'])->toHaveCount(1)
		->and($plan['actions'][0]['action'])->toBe('create');

	$badApply = ($desc['apply'])($desired, '0000', ['user_id' => 1]);
	expect($badApply['applied'])->toBeFalse();

	$goodApply = ($desc['apply'])($desired, $plan['plan_hash'], ['user_id' => 1]);
	expect($goodApply['applied'])->toBeTrue()
		->and($goodApply['created'])->toBe(1);
});
