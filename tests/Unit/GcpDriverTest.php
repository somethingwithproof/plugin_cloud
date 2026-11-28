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
use Somethingwithproof\Cloud\FixedClock;
use Somethingwithproof\Cloud\GcpAuth;
use Somethingwithproof\Cloud\GcpDriver;
use Somethingwithproof\Cloud\GuardedTransport;
use Somethingwithproof\Cloud\HttpRequest;
use Somethingwithproof\Cloud\HttpResponse;
use Somethingwithproof\Cloud\HttpTransport;
use Somethingwithproof\Cloud\MetricRequest;
use Somethingwithproof\Cloud\OutboundPolicy;
use Somethingwithproof\Cloud\Secret;
use Somethingwithproof\Cloud\TransportError;
use Somethingwithproof\Cloud\VerifyStatus;

const CLOUD_GCP_NOW = 1731542400;

function cloud_gcp_account(array $override = []): Account {
	return Account::fromRow($override + [
		'id'                => 31,
		'provider'          => 'gcp',
		'name'              => 'GCP Prod',
		'poller_id'         => 1,
		'scope'             => json_encode(['project' => 'my-cacti-prod-1']),
		'credential_source' => 'vault',
		'collect_interval'  => 300,
		'discover_interval' => 3600,
		'request_share'     => 50,
		'admin_state'       => 'enabled',
	]);
}

/**
 * @return array{privatePem: string, publicPem: string}
 */
function cloud_gcp_keypair(): array {
	$key = openssl_pkey_new([
		'private_key_bits' => 2048,
		'private_key_type' => OPENSSL_KEYTYPE_RSA,
	]);
	openssl_pkey_export($key, $privatePem);
	$details = openssl_pkey_get_details($key);

	return ['privatePem' => (string) $privatePem, 'publicPem' => (string) $details['key']];
}

/**
 * @return array{GcpDriver, object{sent: list<HttpRequest>}}
 */
function cloud_gcp_driver(Account $account, Closure $answer): array {
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

	$probe = new GcpDriver($inner, new FixedClock(CLOUD_GCP_NOW));
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

	return [new GcpDriver($guard, new FixedClock(CLOUD_GCP_NOW)), $inner];
}

it('builds and verifies RS256 service-account JWT assertions with ext-openssl', function () {
	$pair = cloud_gcp_keypair();
	$jwt  = GcpAuth::buildJwt(
		'cacti-reader@my-cacti-prod-1.iam.gserviceaccount.com',
		$pair['privatePem'],
		GcpAuth::DEFAULT_SCOPE,
		GcpAuth::TOKEN_URL,
		CLOUD_GCP_NOW,
		7200,
		'kid-abc-123'
	);

	$parts = explode('.', $jwt);
	expect($parts)->toHaveCount(3);

	$header = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true, 8, JSON_THROW_ON_ERROR);
	$claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true, 8, JSON_THROW_ON_ERROR);
	$sig    = base64_decode(strtr($parts[2], '-_', '+/'), true);

	expect($header)->toBe(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'kid-abc-123'])
		->and($claims['iss'])->toBe('cacti-reader@my-cacti-prod-1.iam.gserviceaccount.com')
		->and($claims['aud'])->toBe(GcpAuth::TOKEN_URL)
		->and($claims['iat'])->toBe(CLOUD_GCP_NOW)
		// Capped to Google's 1-hour maximum lifetime.
		->and($claims['exp'])->toBe(CLOUD_GCP_NOW + 3600)
		->and(openssl_verify($parts[0] . '.' . $parts[1], (string) $sig, $pair['publicPem'], OPENSSL_ALGO_SHA256))->toBe(1);
});

it('enforces a strict read-only GCP operation allowlist and metadata gating', function () {
	$account = cloud_gcp_account();
	$probe   = new GcpDriver(
		new class () implements HttpTransport {
			public function send(HttpRequest $request): HttpResponse {
				return new HttpResponse(200, [], '{}');
			}
		},
		new FixedClock(CLOUD_GCP_NOW)
	);
	$guard = new GuardedTransport(
		new class () implements HttpTransport {
			public function send(HttpRequest $request): HttpResponse {
				return new HttpResponse(200, [], '{}');
			}
		},
		$probe->operations()
	);

	expect($guard->allows(new HttpRequest('DELETE', 'https://compute.googleapis.com/compute/v1/projects/my-cacti-prod-1/zones/us-central1-a/instances/vm1')))->toBeNull()
		->and($guard->allows(new HttpRequest('POST', 'https://monitoring.googleapis.com/v3/projects/my-cacti-prod-1/timeSeries', ['Content-Type' => 'application/json'], '{}')))->toBeNull()
		->and($guard->allows(new HttpRequest('POST', 'https://oauth2.googleapis.com/token', ['Content-Type' => 'application/x-www-form-urlencoded'], 'grant_type=client_credentials')))->toBeNull();

	expect(fn () => $probe->endpoints($account)->check(GcpAuth::METADATA_URL))
		->toThrow(TransportError::class);
});

it('discovers GCE instances, collects Cloud Monitoring time series, and supports GCE metadata identity', function () {
	$pair    = cloud_gcp_keypair();
	$account = cloud_gcp_account();
	$cred    = new Secret((string) json_encode([
		'client_email'   => 'cacti@my-cacti-prod-1.iam.gserviceaccount.com',
		'private_key'    => $pair['privatePem'],
		'private_key_id' => 'key-1',
	]));

	[$driver, $http] = cloud_gcp_driver($account, static function (HttpRequest $req): HttpResponse {
		if ($req->url === GcpAuth::TOKEN_URL) {
			return new HttpResponse(200, [], (string) json_encode(['access_token' => 'ya29.test-token', 'expires_in' => 3600]));
		}

		if (str_contains($req->url, '/aggregated/instances')) {
			return new HttpResponse(200, [], (string) json_encode([
				'items' => [
					'zones/us-central1-a' => [
						'instances' => [
							['id' => '1234567890123456789', 'name' => 'gce-web-1'],
						],
					],
				],
			]));
		}

		return new HttpResponse(200, [], (string) json_encode([
			'timeSeries' => [
				[
					'resource' => ['labels' => ['instance_id' => '1234567890123456789']],
					'points'   => [
						[
							'interval' => ['endTime' => '2024-11-13T23:55:00Z'],
							'value'    => ['doubleValue' => 0.425],
						],
					],
				],
			],
		]));
	});

	$inventory = $driver->discover($account, $cred);
	$collected = $driver->collect($account, $cred, [
		new MetricRequest(5, GcpDriver::TYPE_INSTANCE, '1234567890123456789', 'gce_cpu_pct'),
	]);

	expect($inventory->resources)->toHaveCount(1)
		->and($inventory->resources[0]->providerId)->toBe('1234567890123456789')
		->and($inventory->resources[0]->region)->toBe('us-central1-a')
		->and($collected->error)->toBeNull()
		->and($collected->observations)->toHaveCount(1)
		->and($collected->observations[0]->value)->toBe(42.5)
		// Token was cached between discover and collect.
		->and(count($http->sent))->toBe(3);

	// GCE Instance metadata identity path.
	$metaAccount = cloud_gcp_account(['credential_source' => 'instance']);
	[$metaDriver, $metaHttp] = cloud_gcp_driver($metaAccount, static function (HttpRequest $req): HttpResponse {
		if ($req->url === GcpAuth::METADATA_URL) {
			return new HttpResponse(200, [], (string) json_encode(['access_token' => 'ya29.meta-token', 'expires_in' => 1800]));
		}

		return new HttpResponse(200, [], '{}');
	});

	expect($metaDriver->verify($metaAccount, new Secret('')))->toBe(VerifyStatus::Ok)
		->and($metaHttp->sent[0]->headers['Metadata-Flavor'] ?? null)->toBe('Google');

	// Estimate check.
	$estimate = $driver->estimate([
		new MetricRequest(1, GcpDriver::TYPE_INSTANCE, '100', 'gce_cpu_pct'),
		new MetricRequest(2, GcpDriver::TYPE_INSTANCE, '200', 'gce_cpu_pct'),
		new MetricRequest(1, GcpDriver::TYPE_INSTANCE, '100', 'gce_net_in_Bps'),
	], 300);

	expect($estimate->requestsPerCollection)->toBe(2)
		->and($estimate->requestsPerMonth)->toBe(2 * 8640)
		->and($estimate->monthlyCost)->toBeGreaterThan(0.0);
});
