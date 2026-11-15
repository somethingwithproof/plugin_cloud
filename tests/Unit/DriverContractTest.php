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
use Somethingwithproof\Cloud\CollectResult;
use Somethingwithproof\Cloud\CredentialStore;
use Somethingwithproof\Cloud\DriverError;
use Somethingwithproof\Cloud\Drivers;
use Somethingwithproof\Cloud\ErrorClass;
use Somethingwithproof\Cloud\FixedClock;
use Somethingwithproof\Cloud\HttpRequest;
use Somethingwithproof\Cloud\HttpResponse;
use Somethingwithproof\Cloud\HttpTransport;
use Somethingwithproof\Cloud\Inventory;
use Somethingwithproof\Cloud\Operation;
use Somethingwithproof\Cloud\OutboundPolicy;
use Somethingwithproof\Cloud\Provider;
use Somethingwithproof\Cloud\ProviderDriver;
use Somethingwithproof\Cloud\SecretRepository;
use Somethingwithproof\Cloud\Secret;
use Somethingwithproof\Cloud\TransportError;
use Somethingwithproof\Cloud\UsageEstimate;
use Somethingwithproof\Cloud\Verifier;
use Somethingwithproof\Cloud\VerifyStatus;

/**
 * A driver that answers Verify from a canned HTTP status, the way a real one
 * maps provider responses, and that would happily build a write request if
 * asked, so the test proves the guard refuses it.
 */
final class FakeDriver implements ProviderDriver {
	public function __construct(private readonly HttpTransport $http) {
	}

	public function provider(): Provider {
		return Provider::DigitalOcean;
	}

	public function endpoints(Account $account): OutboundPolicy {
		return new OutboundPolicy(['api.example.com']);
	}

	public function operations(): array {
		return [new Operation('Account', 'GET', 'api.example.com', '#^/v2/account$#')];
	}

	public function verify(Account $account, Secret $credential): VerifyStatus {
		try {
			$status = $this->http->send(
				new HttpRequest('GET', 'https://api.example.com/v2/account', ['Authorization' => 'Bearer ' . $credential->reveal()])
			)->status;
		} catch (TransportError $e) {
			throw DriverError::fromTransport($e);
		}

		return match (true) {
			$status === 200 => VerifyStatus::Ok,
			$status === 401 => VerifyStatus::Invalid,
			$status === 403 => VerifyStatus::Permission,
			default         => VerifyStatus::Malformed,
		};
	}

	public function discover(Account $account, Secret $credential): Inventory {
		$this->http->send(new HttpRequest('DELETE', 'https://api.example.com/v2/droplets/1'));

		return new Inventory([], 1);
	}

	public function collect(Account $account, Secret $credential, array $requests): CollectResult {
		return new CollectResult([], 0);
	}

	public function estimate(array $requests, int $intervalSeconds): UsageEstimate {
		return new UsageEstimate(count($requests), count($requests) * intdiv(2592000, max(1, $intervalSeconds)), 0.0);
	}
}

/**
 * Stands in for SocketTransport behind the guard: replies with a status, or
 * fails as an unreachable network would.
 */
function cloud_canned(?int $status): Closure {
	return static fn (OutboundPolicy $policy): HttpTransport => new class ($status, $policy) implements HttpTransport {
		public function __construct(private readonly ?int $status, private readonly OutboundPolicy $policy) {
		}

		public function send(HttpRequest $request): HttpResponse {
			$this->policy->check($request->url);

			if ($this->status === null) {
				throw new TransportError('connect_timeout');
			}

			return new HttpResponse($this->status, [], '');
		}
	};
}

function cloud_verify_with(?int $status, string $source = 'file'): VerifyStatus {
	$dir  = sys_get_temp_dir() . '/cloud-verify-' . bin2hex(random_bytes(4));
	mkdir($dir, 0700);
	file_put_contents($dir . '/token', 'dop_v1_' . str_repeat('a', 64));
	chmod($dir . '/token', 0600);

	$account = Account::fromRow([
		'id' => 9,
		'provider' => 'digitalocean',
		'name' => 'T',
		'credential_source' => $source,
		'credential_file' => $dir . '/token',
		'admin_state' => 'enabled'
	]);
	$drivers = new Drivers(cloud_canned($status));
	$drivers->register(Provider::DigitalOcean, static fn (HttpTransport $http): ProviderDriver => new FakeDriver($http));

	$clock    = new FixedClock(1_800_000_000);
	$verifier = new Verifier(
		$drivers,
		new CredentialStore(new SecretRepository(false, $clock), $dir . '/missing.key'),
		new AccountRepository(false, $clock)
	);
	$result   = $verifier->verify($account, 1);

	unlink($dir . '/token');
	rmdir($dir);

	return $result;
}

beforeEach(function () {
	$GLOBALS['__cloud_db_exec'] = [];
});

it('records a fixed status for valid, expired, under-scoped, and unreachable credentials', function (
	?int $status,
	VerifyStatus $expected
) {
	expect(cloud_verify_with($status))->toBe($expected);

	$update = $GLOBALS['__cloud_db_exec'][0];

	expect($update['sql'])->toContain('UPDATE plugin_cloud_account SET verify_status')
		->and($update['params'][0])->toBe($expected->value);
})->with([
	'valid'        => [200, VerifyStatus::Ok],
	'expired'      => [401, VerifyStatus::Invalid],
	'under-scoped' => [403, VerifyStatus::Permission],
	'unreachable'  => [null, VerifyStatus::Unreachable],
]);

it('reports a configuration status when the credential cannot be read', function () {
	expect(cloud_verify_with(200, 'vault'))->toBe(VerifyStatus::Config);
});

it('reports a configuration status for a provider without a driver', function () {
	$account  = Account::fromRow(['id' => 9, 'provider' => 'digitalocean', 'credential_source' => 'vault']);
	$clock    = new FixedClock(1);
	$verifier = new Verifier(
		new Drivers(cloud_canned(200)),
		new CredentialStore(new SecretRepository(false, $clock), '/nonexistent'),
		new AccountRepository(false, $clock)
	);

	expect($verifier->verify($account, 1))->toBe(VerifyStatus::Config);
});

it('reports a configuration status, and no exception text, when a driver fails unexpectedly', function () {
	$GLOBALS['__cloud_log'] = [];
	$account = Account::fromRow(['id' => 9, 'provider' => 'digitalocean', 'credential_source' => 'vault']);
	$drivers = new Drivers(cloud_canned(200));
	$drivers->register(
		Provider::DigitalOcean,
		static fn (HttpTransport $http): ProviderDriver => throw new RuntimeException('provider said sk-live-123')
	);
	$clock    = new FixedClock(1);
	$verifier = new Verifier(
		$drivers,
		new CredentialStore(new SecretRepository(false, $clock), '/nonexistent'),
		new AccountRepository(false, $clock)
	);

	expect($verifier->verify($account, 1))->toBe(VerifyStatus::Config)
		->and(implode("\n", array_column($GLOBALS['__cloud_log'], 'message')))->not->toContain('sk-live-123');
});

it('refuses resource identities that do not fit or hold control characters, and trims display fields', function () {
	$long = new \Somethingwithproof\Cloud\CloudResource('droplet', 'd1', str_repeat('n', 300), str_repeat('r', 80));

	expect(mb_strlen($long->name))->toBe(255)
		->and(mb_strlen($long->region))->toBe(64)
		->and(
			fn () => new \Somethingwithproof\Cloud\CloudResource('droplet', str_repeat('i', 256), 'x')
		)->toThrow(DriverError::class)
		->and(
			fn () => new \Somethingwithproof\Cloud\CloudResource(str_repeat('t', 65), 'd1', 'x')
		)->toThrow(DriverError::class)
		->and(fn () => new \Somethingwithproof\Cloud\CloudResource('droplet', "d1\nd2", 'x'))->toThrow(DriverError::class)
		->and(fn () => new \Somethingwithproof\Cloud\CloudResource('droplet', '', 'x'))->toThrow(DriverError::class);
});

it('wires drivers to a transport that refuses operations off their list', function () {
	$drivers = new Drivers(cloud_canned(200));
	$drivers->register(Provider::DigitalOcean, static fn (HttpTransport $http): ProviderDriver => new FakeDriver($http));
	$account = Account::fromRow(['id' => 9, 'provider' => 'digitalocean', 'credential_source' => 'vault']);

	$driver = $drivers->for($account);

	expect(fn () => $driver->discover($account, new Secret('x')))->toThrow(TransportError::class, 'operation_refused');
});

it('lists only read operations for every registered driver', function () {
	// The production registry; each provider phase adds its driver here.
	$drivers = Drivers::production();

	foreach (Provider::cases() as $provider) {
		$driver = $drivers->for(
			Account::fromRow(['id' => 1, 'provider' => $provider->value, 'credential_source' => 'vault'])
		);

		foreach ($driver?->operations() ?? [] as $operation) {
			expect($operation->method === 'GET' || $operation->bodyPattern !== null)->toBeTrue($operation->name);
		}
	}

	expect(true)->toBeTrue();
});

it('maps every transport failure to an error class', function () {
	foreach ([
		'url_rejected',
		'host_refused',
		'operation_refused',
		'tls_verify',
		'malformed',
		'too_large',
		'total_timeout',
		'connect_refused'
	] as $kind) {
		expect((new TransportError($kind))->errorClass())->toBeInstanceOf(ErrorClass::class);
	}

	expect((new TransportError('total_timeout'))->errorClass())->toBe(ErrorClass::Transient)
		->and((new TransportError('host_refused'))->errorClass())->toBe(ErrorClass::Config)
		->and((new TransportError('too_large'))->errorClass())->toBe(ErrorClass::Malformed);
});
