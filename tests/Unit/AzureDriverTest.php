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
use Somethingwithproof\Cloud\AzureAuth;
use Somethingwithproof\Cloud\AzureDriver;
use Somethingwithproof\Cloud\ErrorClass;
use Somethingwithproof\Cloud\FixedClock;
use Somethingwithproof\Cloud\GuardedTransport;
use Somethingwithproof\Cloud\HttpRequest;
use Somethingwithproof\Cloud\HttpResponse;
use Somethingwithproof\Cloud\HttpTransport;
use Somethingwithproof\Cloud\MetricRequest;
use Somethingwithproof\Cloud\OutboundPolicy;
use Somethingwithproof\Cloud\Secret;
use Somethingwithproof\Cloud\TransportError;
use Somethingwithproof\Cloud\VerifyStatus;

const CLOUD_AZ_NOW    = 1731542400;
const CLOUD_AZ_TENANT = '0f8fad5b-d9cb-469f-a165-70867728950e';
const CLOUD_AZ_SUB    = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

function cloud_az_account(array $override = []): Account {
	return Account::fromRow($override + [
		'id'                => 41,
		'provider'          => 'azure',
		'name'              => 'Azure Prod',
		'poller_id'         => 1,
		'scope'             => json_encode([
			'tenant'       => CLOUD_AZ_TENANT,
			'subscription' => CLOUD_AZ_SUB,
			'regions'      => 'eastus',
		]),
		'credential_source' => 'vault',
		'collect_interval'  => 300,
		'discover_interval' => 3600,
		'request_share'     => 50,
		'admin_state'       => 'enabled',
	]);
}

function cloud_az_cred(array $extra = []): Secret {
	return new Secret((string) json_encode($extra + [
		'client_id'     => '11111111-2222-3333-4444-555555555555',
		'client_secret' => 'test-secret-value',
	]));
}

/**
 * @return array{AzureDriver, object{sent: list<HttpRequest>}}
 */
function cloud_az_driver(Account $account, Closure $answer): array {
	$inner = new class ($answer) implements HttpTransport {
		/** @var list<HttpRequest> */
		public array $sent = [];

		public function __construct(private readonly Closure $answer) {
		}

		public function send(HttpRequest $request): HttpResponse {
			$this->sent[] = $request;

			return ($this->answer)($request);
		}
	};

	$probe = new AzureDriver($inner, new FixedClock(CLOUD_AZ_NOW));
	$guard = new class ($inner, $probe->operations(), $probe->endpoints($account)) implements HttpTransport {
		public function __construct(
			private readonly HttpTransport $inner,
			private readonly array $operations,
			private readonly OutboundPolicy $policy,
		) {
		}

		public function send(HttpRequest $request): HttpResponse {
			$this->policy->check($request->url);

			return (new GuardedTransport($this->inner, $this->operations))->send($request);
		}
	};

	return [new AzureDriver($guard, new FixedClock(CLOUD_AZ_NOW)), $inner];
}

it('encodes Entra ID client credentials form per Microsoft docs and refuses certificate assertions', function () {
	$body = AzureAuth::buildClientCredentialsBody(
		'11111111-2222-3333-4444-555555555555',
		'secret+value/1=',
		AzureAuth::ARM_SCOPE
	);

	expect($body)->toBe(
		'client_id=11111111-2222-3333-4444-555555555555'
			. '&scope=https%3A%2F%2Fmanagement.azure.com%2F.default'
			. '&client_secret=secret%2Bvalue%2F1%3D'
			. '&grant_type=client_credentials'
	);

	$account  = cloud_az_account();
	[$driver] = cloud_az_driver($account, static fn (): HttpResponse => new HttpResponse(200, [], '{}'));
	$certCred = new Secret((string) json_encode(['client_id' => 'abc', 'client_certificate' => 'pem']));

	expect($driver->verify($account, $certCred))->toBe(VerifyStatus::Config);
});

it('discovers VMs via Resource Graph with eventual-consistency deduplication and pagination', function () {
	$account = cloud_az_account();
	$vm1     = '/subscriptions/' . CLOUD_AZ_SUB . '/resourceGroups/rg1/providers/Microsoft.Compute/virtualMachines/vm-1';
	$vm2     = '/subscriptions/' . CLOUD_AZ_SUB . '/resourceGroups/rg1/providers/Microsoft.Compute/virtualMachines/vm-2';
	$pages   = 0;

	[$driver] = cloud_az_driver($account, static function (HttpRequest $req) use ($vm1, $vm2, &$pages): HttpResponse {
		if (str_contains($req->url, 'login.microsoftonline.com')) {
			return new HttpResponse(200, [], (string) json_encode(['access_token' => 'arm-token', 'expires_in' => 3600]));
		}

		$pages++;

		if ($pages === 1) {
			return new HttpResponse(200, [], (string) json_encode([
				'data'       => [
					['id' => $vm1, 'name' => 'vm-1', 'location' => 'eastus'],
				],
				'$skipToken' => 'page-2-token',
			]));
		}

		// Page 2 repeats vm-1 (eventual consistency) and adds vm-2.
		return new HttpResponse(200, [], (string) json_encode([
			'data' => [
				['id' => strtoupper($vm1), 'name' => 'vm-1', 'location' => 'eastus'],
				['id' => $vm2, 'name' => 'vm-2', 'location' => 'eastus'],
			],
		]));
	});

	$inventory = $driver->discover($account, cloud_az_cred());

	expect($inventory->requests)->toBe(2)
		->and($inventory->resources)->toHaveCount(2)
		->and($inventory->resources[0]->providerId)->toBe('eastus|' . $vm1)
		->and($inventory->resources[1]->providerId)->toBe('eastus|' . $vm2);
});

it('batches Azure Monitor metrics:getBatch up to 50 resources, handles 429/529 throttling, and supports IMDS', function () {
	$account = cloud_az_account();
	$batches = [];

	[$driver] = cloud_az_driver($account, static function (HttpRequest $req) use (&$batches): HttpResponse {
		if (str_contains($req->url, 'login.microsoftonline.com')) {
			return new HttpResponse(200, [], (string) json_encode(['access_token' => 'metrics-token', 'expires_in' => 3600]));
		}

		$decoded   = json_decode($req->body ?? '', true, 16, JSON_THROW_ON_ERROR);
		$batches[] = count($decoded['resourceids']);
		$firstId   = $decoded['resourceids'][0];

		return new HttpResponse(200, [], (string) json_encode([
			'values' => [
				[
					'resourceid' => $firstId,
					'value'      => [
						[
							'name'       => ['value' => 'Network In Total'],
							'timeseries' => [
								['data' => [['timeStamp' => '2024-11-13T23:55:00Z', 'total' => 12000.0]]],
							],
						],
					],
				],
			],
		]));
	});

	$requests = [];

	for ($i = 1; $i <= 55; $i++) {
		$armId      = '/subscriptions/' . CLOUD_AZ_SUB . '/resourceGroups/rg1/providers/Microsoft.Compute/virtualMachines/vm-' . $i;
		$requests[] = new MetricRequest($i, AzureDriver::TYPE_VM, 'eastus|' . $armId, 'azvm_net_in_Bps');
	}

	$result = $driver->collect($account, cloud_az_cred(), $requests);

	expect($batches)->toBe([50, 5])
		->and($result->requests)->toBe(2)
		->and($result->error)->toBeNull()
		// 12000 bytes / 60s = 200 B/s.
		->and($result->observations[0]->value)->toBe(200.0);

	// 529 throttling response with Retry-After.
	[$throttled] = cloud_az_driver($account, static function (HttpRequest $req): HttpResponse {
		if (str_contains($req->url, 'login.microsoftonline.com')) {
			return new HttpResponse(200, [], (string) json_encode(['access_token' => 'metrics-token', 'expires_in' => 3600]));
		}

		return new HttpResponse(529, ['retry-after' => '180'], '{}');
	});

	$throttledResult = $throttled->collect($account, cloud_az_cred(), [$requests[0]]);

	expect($throttledResult->error)->toBe(ErrorClass::Throttled)
		->and($throttledResult->retryAfter)->toBe(180);

	// Managed identity IMDS path (strictly gated to Instance accounts).
	$probe = new AzureDriver(
		new class () implements HttpTransport {
			public function send(HttpRequest $request): HttpResponse {
				return new HttpResponse(200, [], '{}');
			}
		},
		new FixedClock(CLOUD_AZ_NOW)
	);
	expect(fn () => $probe->endpoints($account)->check('http://169.254.169.254/metadata/identity/oauth2/token'))
		->toThrow(TransportError::class);

	$miAccount = cloud_az_account(['credential_source' => 'instance']);
	[$miDriver, $miHttp] = cloud_az_driver($miAccount, static function (HttpRequest $req): HttpResponse {
		if (str_contains($req->url, '169.254.169.254')) {
			return new HttpResponse(200, [], (string) json_encode(['access_token' => 'mi-token', 'expires_in' => 3600]));
		}

		return new HttpResponse(200, [], (string) json_encode(['value' => []]));
	});

	expect($miDriver->verify($miAccount, new Secret('')))->toBe(VerifyStatus::Ok)
		->and($miHttp->sent[0]->headers['Metadata'] ?? null)->toBe('true');

	// Estimate check for 55 VMs across 1 aggregation = 2 batch calls per collection.
	$estimate = $driver->estimate($requests, 300);
	expect($estimate->requestsPerCollection)->toBe(2)
		->and($estimate->requestsPerMonth)->toBe(2 * 8640)
		->and($estimate->monthlyCost)->toBeGreaterThan(0.0);
});
