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
use Somethingwithproof\Cloud\AccountInput;
use Somethingwithproof\Cloud\AdminState;
use Somethingwithproof\Cloud\CredentialSource;
use Somethingwithproof\Cloud\Provider;
use Somethingwithproof\Cloud\VerifyStatus;

function cloud_account_input(array $override = []): array {
	return $override + [
		'provider'          => 'digitalocean',
		'name'              => 'Production',
		'poller_id'         => '1',
		'credential_source' => 'vault',
		'credential_file'   => '',
		'collect_interval'  => '300',
		'discover_interval' => '3600',
		'request_share'     => '50',
		'monthly_ceiling'   => '',
		'enabled'           => 'on',
	];
}

it('builds an account from valid input', function () {
	$parsed = AccountInput::parse(cloud_account_input(), 0, Provider::released());

	expect($parsed->errors)->toBe([])
		->and($parsed->account?->provider)->toBe(Provider::DigitalOcean)
		->and($parsed->account?->scope)->toBe([])
		->and($parsed->account?->adminState)->toBe(AdminState::Enabled)
		->and($parsed->account?->monthlyCeiling)->toBeNull();
});

it('refuses each invalid field with a message naming it', function (array $override, string $field, string $named) {
	$parsed = AccountInput::parse(cloud_account_input($override), 0, Provider::released());

	expect($parsed->account)->toBeNull()
		->and($parsed->errors)->toHaveKey($field)
		->and($parsed->errors[$field])->toStartWith($named . ':');
})->with([
	'empty name'        => [['name' => ''], 'name', 'Name'],
	'control in name'   => [['name' => "a\nb"], 'name', 'Name'],
	'remote poller'     => [['poller_id' => '2'], 'poller_id', 'Poller'],
	'unknown source'    => [['credential_source' => 'env'], 'credential_source', 'Credential Source'],
	'instance on DO'    => [['credential_source' => 'instance'], 'credential_source', 'Credential Source'],
	'relative file'     => [
		['credential_source' => 'file', 'credential_file' => 'etc/token'],
		'credential_file',
		'Credential File'
	],
	'traversal file'    => [
		['credential_source' => 'file', 'credential_file' => '/etc/../token'],
		'credential_file',
		'Credential File'
	],
	'short collect'     => [['collect_interval' => '30'], 'collect_interval', 'Collection Interval'],
	'text discover'     => [['discover_interval' => 'hourly'], 'discover_interval', 'Discovery Interval'],
	'zero share'        => [['request_share' => '0'], 'request_share', 'Request Share'],
	'negative ceiling'  => [['monthly_ceiling' => '-1'], 'monthly_ceiling', 'Monthly Ceiling'],
	'text ceiling'      => [['monthly_ceiling' => 'lots'], 'monthly_ceiling', 'Monthly Ceiling'],
]);

it('refuses providers that are not released for new accounts', function () {
	foreach ([0, -1] as $id) {
		expect(
			AccountInput::parse(cloud_account_input(['provider' => 'aws']), $id, Provider::released())->errors
		)->toHaveKey('provider');
	}
});

it('validates every provider scope field against its strict pattern', function (
	string $provider,
	string $field,
	string $good,
	string $bad
) {
	$offered = Provider::cases();
	$fields  = [];

	foreach (Provider::from($provider)->scopeFields() as $name => $rule) {
		$fields['scope_' . $name] = [
			'account_id' => '123456789012',
			'regions' => $provider === 'aws' ? 'us-east-1,eu-west-2' : 'eastus',
			'project' => 'my-project-1',
			'tenant' => '0f8fad5b-d9cb-469f-a165-70867728950e',
			'subscription' => '7c9e6679-7425-40de-944b-e07fc1f90ae7'
		][$name];
	}

	$good_input = cloud_account_input(['provider' => $provider, 'scope_' . $field => $good] + $fields);
	$bad_input  = cloud_account_input(['provider' => $provider, 'scope_' . $field => $bad] + $fields);

	expect(AccountInput::parse($good_input, 0, $offered)->errors)->toBe([])
		->and(AccountInput::parse($bad_input, 0, $offered)->errors)->toHaveKey('scope_' . $field)
		->and(
			AccountInput::parse($bad_input, 0, $offered)->errors['scope_' . $field]
		)->toStartWith(AccountInput::scopeLabel($field) . ':');
})->with([
	['aws', 'account_id', '123456789012', '12345678901/'],
	['aws', 'regions', 'us-east-1, us-gov-west-1', 'us-east-1,evil.example.com'],
	['gcp', 'project', 'my-project-1', 'my_project'],
	['azure', 'tenant', '0f8fad5b-d9cb-469f-a165-70867728950e', '0f8fad5b'],
	['azure', 'subscription', '7c9e6679-7425-40de-944b-e07fc1f90ae7', '../subscriptions'],
	['azure', 'regions', 'eastus,westeurope', 'east us/../x'],
]);

it('pins fromRow on empty, NULL, and malformed values', function () {
	$row = [
		'id'                => '7',
		'provider'          => 'digitalocean',
		'name'              => null,
		'poller_id'         => '',
		'scope'             => 'not json',
		'credential_source' => 'file',
		'credential_file'   => ' /etc/cacti/do.token ',
		'collect_interval'  => '10',
		'discover_interval' => 'x',
		'request_share'     => '500',
		'monthly_ceiling'   => '-3',
		'admin_state'       => 'on',
		'verify_status'     => 'bogus',
		'verified_at'       => '-1',
	];

	$account = Account::fromRow($row);

	expect($account)->not->toBeNull()
		->and($account->name)->toBe('')
		->and($account->pollerId)->toBe(Account::MAIN_POLLER)
		->and($account->scope)->toBe([])
		->and($account->credentialSource)->toBe(CredentialSource::File)
		->and($account->credentialFile)->toBe('/etc/cacti/do.token')
		->and($account->collectInterval)->toBe(AccountInput::DEFAULT_COLLECT)
		->and($account->discoverInterval)->toBe(AccountInput::DEFAULT_DISCOVER)
		->and($account->requestShare)->toBe(100)
		->and($account->monthlyCeiling)->toBeNull()
		->and($account->adminState)->toBe(AdminState::Disabled)
		->and($account->verifyStatus)->toBe(VerifyStatus::Never)
		->and($account->verifiedAt)->toBe(0);
});

it('rejects rows that cannot be accounts', function (array $row) {
	expect(Account::fromRow($row))->toBeNull();
})->with([
	[['id' => '', 'provider' => 'aws', 'credential_source' => 'vault']],
	[['id' => '0', 'provider' => 'aws', 'credential_source' => 'vault']],
	[['id' => '1', 'provider' => 'oracle', 'credential_source' => 'vault']],
	[['id' => '1', 'provider' => 'aws', 'credential_source' => null]],
]);

it('drops stored scope values that no longer match their pattern', function () {
	$account = Account::fromRow([
		'id' => 1,
		'provider' => 'aws',
		'credential_source' => 'vault',
		'scope' => json_encode(['account_id' => '123456789012', 'regions' => 'us-east-1/../x', 'extra' => 'y'])
	]);

	expect($account?->scope)->toBe(['account_id' => '123456789012']);
});

it('round-trips through toRow and fromRow', function () {
	$account = AccountInput::parse(cloud_account_input(['monthly_ceiling' => '12.5']), 0, Provider::released())->account;
	$again   = Account::fromRow(['id' => 3] + $account->toRow());

	expect($again->toRow())->toBe($account->toRow());
});

it('names only the changed columns for the audit log', function () {
	$before = AccountInput::parse(cloud_account_input(), 4, Provider::released())->account;
	$after  = AccountInput::parse(
		cloud_account_input(['name' => 'Renamed', 'collect_interval' => '600']),
		4,
		Provider::released()
	)->account;

	expect($after->changedFields($before))->toBe(['name', 'collect_interval'])
		->and($before->changedFields(null))->toBe(array_keys($before->toRow()));
});

it('maps every error class to a fixed verify message', function () {
	foreach (\Somethingwithproof\Cloud\ErrorClass::cases() as $error) {
		$status = VerifyStatus::fromError($error);

		expect($status)->not->toBe(VerifyStatus::Ok)
			->and($status->message())->not->toBe('');
	}

	expect(VerifyStatus::fromError(\Somethingwithproof\Cloud\ErrorClass::Auth))->toBe(VerifyStatus::Invalid)
		->and(VerifyStatus::fromError(\Somethingwithproof\Cloud\ErrorClass::Permission))->toBe(VerifyStatus::Permission)
		->and(VerifyStatus::fromError(\Somethingwithproof\Cloud\ErrorClass::Transient))->toBe(VerifyStatus::Unreachable);
});

function cloud_delete_with(array $inTransaction): array {
	$GLOBALS['__cloud_db_exec'] = [];
	$GLOBALS['__cloud_db_rows'] = [
		'FROM plugin_cloud_account WHERE id' => [
			['id' => 7, 'provider' => 'digitalocean', 'name' => 'Ops', 'credential_source' => 'vault']
		]
	];
	$GLOBALS['__cloud_in_tx']   = $inTransaction;

	$deleted = (
		new \Somethingwithproof\Cloud\AccountRepository(false, new \Somethingwithproof\Cloud\FixedClock(1))
	)->delete(7, 1);
	$log     = array_map(
		fn (array $e) => preg_match('/^(BEGIN|COMMIT|ROLLBACK)$/', $e['sql']) ? $e['sql'] : (
			preg_match('/(?:DELETE \w* ?FROM|INSERT INTO) (plugin_cloud_\w+)/', $e['sql'], $m) ? $m[1] : $e['sql']
		),
		$GLOBALS['__cloud_db_exec']
	);

	$GLOBALS['__cloud_db_rows'] = [];
	$GLOBALS['__cloud_in_tx']   = [];

	return [$deleted, $log];
}

it('deletes an account and its data in one transaction, then audits it', function () {
	[$deleted, $log] = cloud_delete_with([]);

	expect($deleted)->toBeTrue()
		->and($log)->toBe([
			'BEGIN',
			'plugin_cloud_secret',
			'plugin_cloud_series',
			'plugin_cloud_lease',
			'plugin_cloud_health',
			'plugin_cloud_observation',
			'plugin_cloud_resource',
			'plugin_cloud_account',
			'COMMIT',
			'plugin_cloud_audit'
		]);
});

it('reruns the whole delete when InnoDB ends the transaction partway', function () {
	// The third statement's transaction is gone, as after a deadlock.
	[$deleted, $log] = cloud_delete_with([1, 1, 0, 0]);

	expect($deleted)->toBeTrue()
		->and(array_slice($log, 0, 4))->toBe(['BEGIN', 'plugin_cloud_secret', 'plugin_cloud_series', 'plugin_cloud_lease'])
		->and(array_slice($log, 4))->toBe([
			'BEGIN',
			'plugin_cloud_secret',
			'plugin_cloud_series',
			'plugin_cloud_lease',
			'plugin_cloud_health',
			'plugin_cloud_observation',
			'plugin_cloud_resource',
			'plugin_cloud_account',
			'COMMIT',
			'plugin_cloud_audit'
		]);
});

it('gives up without auditing when every attempt loses its transaction', function () {
	[$deleted, $log] = cloud_delete_with(array_fill(0, 12, 0));

	expect($deleted)->toBeFalse()
		->and($log)->not->toContain('COMMIT')
		->and($log)->not->toContain('plugin_cloud_audit')
		->and(array_count_values($log)['BEGIN'])->toBe(3);
});
