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
use Somethingwithproof\Cloud\HealthWarnings;
use Somethingwithproof\Cloud\Settings;

/*
 * Each health warning from a file or settings fixture (#37), through the
 * production checks.
 */

beforeEach(function () {
	$this->dir = sys_get_temp_dir() . '/cloud-warn-' . bin2hex(random_bytes(4));
	mkdir($this->dir, 0700);
	$this->key = $this->dir . '/vault.key';
	file_put_contents($this->key, bin2hex(random_bytes(32)));
	chmod($this->key, 0600);
	$this->token = $this->dir . '/token';
	file_put_contents($this->token, 'dop_v1_' . str_repeat('a', 64));
	chmod($this->token, 0600);
});

afterEach(function () {
	foreach (glob($this->dir . '/*') ?: [] as $path) {
		unlink($path);
	}

	rmdir($this->dir);
});

function cloud_warn_account(array $row = []): Account {
	return Account::fromRow(
		$row + [
			'id' => 4,
			'provider' => 'digitalocean',
			'name' => 'Ops',
			'credential_source' => 'vault',
			'collect_interval' => 300,
			'admin_state' => 'enabled'
		]
	);
}

function cloud_warnings(string $key, array $accounts, int $poller = 300): array {
	return HealthWarnings::production()->for(Settings::fromRow(['cloud_vault_key_file' => $key]), $accounts, $poller);
}

it('has no warnings for a usable key, safe files, and matching intervals', function () {
	expect(
		cloud_warnings(
			$this->key,
			[
				cloud_warn_account(),
				cloud_warn_account([
					'id' => 5,
					'credential_source' => 'file',
					'credential_file' => $this->token,
					'collect_interval' => 600
				])
			]
		)
	)->toBe([]);
});

it('warns everyone when the vault key file is missing or refused', function (string $fixture, string $reason) {
	$path = match ($fixture) {
		'missing' => $this->dir . '/none.key',
		'shared'  => (function () {
			chmod($this->key, 0644);

			return $this->key;
		})->call($this),
		'short'   => (function () {
			file_put_contents($this->key, 'abc');

			return $this->key;
		})->call($this),
	};

	$warnings = cloud_warnings($path, [cloud_warn_account()]);

	expect($warnings)->toHaveCount(1)
		->and($warnings[0]['admin'])->toBeFalse()
		->and($warnings[0]['message'])->toStartWith('The vault key file cannot be used:')
		->and($warnings[0]['message'])->toContain($reason);
})->with([
	['missing', 'does not exist'],
	['shared', 'other users'],
	['short', '32 bytes or 64 hexadecimal'],
]);

it('warns Cloud Admin only when a credential file is unsafe', function (string $fixture, string $reason) {
	$path = match ($fixture) {
		'missing' => $this->dir . '/none',
		'shared'  => (function () {
			chmod($this->token, 0604);

			return $this->token;
		})->call($this),
		'link'    => (function () {
			symlink($this->token, $this->dir . '/link');

			return $this->dir . '/link';
		})->call($this),
	};

	$warnings = cloud_warnings(
		$this->key,
		[cloud_warn_account(['credential_source' => 'file', 'credential_file' => $path])]
	);

	expect($warnings)->toHaveCount(1)
		->and($warnings[0]['admin'])->toBeTrue()
		->and($warnings[0]['message'])->toStartWith('Ops: the credential file cannot be used:')
		->and($warnings[0]['message'])->toContain($reason)
		->and($warnings[0]['message'])->not->toContain($this->dir);
})->with([
	['missing', 'does not exist'],
	['shared', 'other users'],
	['link', 'symbolic link'],
]);

it('warns when the poller interval cannot honour the collection interval', function (
	int $collect,
	int $poller,
	?string $expected
) {
	$messages = array_column(
		cloud_warnings($this->key, [cloud_warn_account(['collect_interval' => $collect])], $poller),
		'message'
	);

	expect($messages)->toBe(
		$expected === null ? [] : ['Ops: the collection interval of ' . $collect . ' seconds ' . $expected]
	);
})->with([
	[300, 300, null],
	[600, 300, null],
	[60, 60, null],
	[120, 300, 'is shorter than the poller interval of 300 seconds, so it is collected every 300 seconds.'],
	[450, 300, 'is not a multiple of the poller interval of 300 seconds, so it is collected every 600 seconds.'],
	// Within the collector's slack of a poller cycle, so due on that cycle.
	[310, 300, 'is not a multiple of the poller interval of 300 seconds, so it is collected every 300 seconds.'],
]);

it('skips disabled accounts', function () {
	expect(
		cloud_warnings(
			$this->key,
			[
				cloud_warn_account([
					'admin_state' => 'disabled',
					'credential_source' => 'file',
					'credential_file' => '/none',
					'collect_interval' => 450
				])
			]
		)
	)->toBe([]);
});
