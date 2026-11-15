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
use Somethingwithproof\Cloud\CloudResource;
use Somethingwithproof\Cloud\DigitalOceanCatalog;
use Somethingwithproof\Cloud\DigitalOceanDriver;
use Somethingwithproof\Cloud\DriverError;
use Somethingwithproof\Cloud\ErrorClass;
use Somethingwithproof\Cloud\FixedClock;
use Somethingwithproof\Cloud\GuardedTransport;
use Somethingwithproof\Cloud\HttpRequest;
use Somethingwithproof\Cloud\HttpResponse;
use Somethingwithproof\Cloud\HttpTransport;
use Somethingwithproof\Cloud\MetricRequest;
use Somethingwithproof\Cloud\Secret;
use Somethingwithproof\Cloud\TransportError;
use Somethingwithproof\Cloud\VerifyStatus;

// Response bodies are DigitalOcean's own examples from its API
// specification (tests/fixtures/digitalocean). Paging, errors, and odd
// bodies are built from them here; none was recorded from a live account.

// Built at run time: a literal token shape trips secret scanners.
define('CLOUD_DO_TOKEN', 'dop_v1_' . str_repeat('0123456789abcdef', 4));
const CLOUD_DO_NOW   = 1635387200;

function cloud_do_fixture(string $name): array {
	return json_decode(
		(string) file_get_contents(__DIR__ . '/../fixtures/digitalocean/' . $name . '.json'),
		true,
		64,
		JSON_THROW_ON_ERROR
	);
}

/**
 * A transport answering by path with the response a closure returns, behind
 * the driver's real policy and operation list, recording what passed both.
 */
final class DoScripted implements HttpTransport {
	/** @var list<HttpRequest> */
	public array $sent = [];

	/**
	 * @param Closure(string, array<string, string>): HttpResponse $answer
	 */
	public function __construct(private readonly Closure $answer) {
	}

	public function send(HttpRequest $request): HttpResponse {
		$parts = parse_url($request->url);
		parse_str($parts['query'] ?? '', $query);
		$this->sent[] = $request;

		return ($this->answer)($parts['path'] ?? '/', $query);
	}
}

/**
 * @return array{DigitalOceanDriver, DoScripted}
 */
function cloud_do_driver(Closure $answer): array {
	$inner = new DoScripted($answer);
	$probe = new DigitalOceanDriver($inner, new FixedClock(CLOUD_DO_NOW));
	$guard = new class ($inner, $probe->operations(), $probe->endpoints(cloud_do_account())) implements HttpTransport {
		public function __construct(
			private readonly HttpTransport $inner,
			private readonly array $operations,
			private readonly \Somethingwithproof\Cloud\OutboundPolicy $policy
		) {
		}

		public function send(HttpRequest $request): HttpResponse {
			$this->policy->check($request->url);

			return (new GuardedTransport($this->inner, $this->operations))->send($request);
		}
	};

	return [new DigitalOceanDriver($guard, new FixedClock(CLOUD_DO_NOW)), $inner];
}

function cloud_do_account(): Account {
	return Account::fromRow(['id' => 1, 'provider' => 'digitalocean', 'credential_source' => 'vault']);
}

function cloud_do_json(array $body, int $status = 200): HttpResponse {
	return new HttpResponse($status, ['content-type' => 'application/json'], json_encode($body));
}

function cloud_do_status(int $status): Closure {
	return static fn (): HttpResponse => cloud_do_json(cloud_do_fixture('error_401'), $status);
}

function cloud_do_metric(string $metric, string $providerId = '222651441', int $resourceId = 7): MetricRequest {
	return new MetricRequest($resourceId, DigitalOceanCatalog::metric($metric)['type'], $providerId, $metric);
}

it('lists Droplets and load balancers from the documented examples', function () {
	[$driver, $http] = cloud_do_driver(
		static fn (string $path): HttpResponse => cloud_do_json(
			cloud_do_fixture($path === '/v2/droplets' ? 'droplets' : 'load_balancers')
		)
	);

	$inventory = $driver->discover(cloud_do_account(), new Secret(CLOUD_DO_TOKEN));
	$found     = array_map(
		static fn (CloudResource $r): string => "$r->type $r->providerId $r->name $r->region",
		$inventory->resources
	);

	expect($found)->toBe([
		'droplet 3164444 example.com nyc3',
		'droplet 3164459 assets.example.com nyc3',
		'droplet 3164412 stage.example.com nyc3',
		'load_balancer 4de7ac8b-495b-4884-9a69-1050c6793cd6 example-lb-01 nyc3',
		'load_balancer 56775c3f-04ab-4fb3-a7ed-40ef9bc8eece prod-web-lb-01 nyc3',
	])
		->and($inventory->requests)->toBe(2)
		->and($http->sent[0]->url)->toBe('https://api.digitalocean.com/v2/droplets?per_page=100&page=1')
		->and($http->sent[0]->headers['Authorization'])->toBe('Bearer ' . CLOUD_DO_TOKEN);
});

it('pages by number and never requests the URL a response links to', function () {
	$pages = [];

	[$driver, $http] = cloud_do_driver(static function (string $path, array $query) use (&$pages): HttpResponse {
		if ($path === '/v2/load_balancers') {
			return cloud_do_json(['load_balancers' => [], 'links' => [], 'meta' => ['total' => 0]]);
		}

		$pages[] = (int) $query['page'];
		$body    = cloud_do_fixture('droplets');

		if ($query['page'] < 3) {
			$body['links'] = ['pages' => ['next' => 'https://attacker.example/v2/droplets?page=2']];
		}

		return cloud_do_json($body);
	});

	$inventory = $driver->discover(cloud_do_account(), new Secret(CLOUD_DO_TOKEN));

	expect($pages)->toBe([1, 2, 3])
		->and(count($inventory->resources))->toBe(9)
		->and($inventory->requests)->toBe(4);

	foreach ($http->sent as $request) {
		expect(parse_url($request->url, PHP_URL_HOST))->toBe('api.digitalocean.com');
	}
});

it('refuses a list entry whose id is not a Droplet id, rather than storing it', function () {
	[$driver] = cloud_do_driver(static function (string $path): HttpResponse {
		$body                      = cloud_do_fixture('droplets');
		$body['droplets'][1]['id'] = '3164459&x=1';

		return cloud_do_json($body);
	});

	expect(fn () => $driver->discover(cloud_do_account(), new Secret(CLOUD_DO_TOKEN)))
		->toThrow(DriverError::class, 'malformed');
});

it('computes CPU percent busy from the documented example', function () {
	[$driver, $http] = cloud_do_driver(static fn (): HttpResponse => cloud_do_json(cloud_do_fixture('droplet_cpu')));

	$result = $driver->collect(cloud_do_account(), new Secret(CLOUD_DO_TOKEN), [cloud_do_metric('do_cpu_pct')]);
	$seen   = $result->observations[0];

	// Between 1635387000 and 1635387120 idle grew 119.88 s of 119.96 s.
	expect($result->error)->toBeNull()
		->and($result->requests)->toBe(1)
		->and($seen->observedAt)->toBe(1635387120)
		->and($seen->value)->toEqualWithDelta(100 * 0.08 / 119.96, 1e-9)
		->and($seen->fetchedAt)->toBe(CLOUD_DO_NOW)
		->and($http->sent[0]->url)->toBe(
			'https://api.digitalocean.com/v2/monitoring/metrics/droplet/cpu?host_id=222651441&start='
				. (CLOUD_DO_NOW - 900) . '&end=' . CLOUD_DO_NOW
		);
});

it('reads bandwidth as bits per second with its interface and direction', function () {
	[$driver, $http] = cloud_do_driver(static fn (): HttpResponse => cloud_do_json(cloud_do_fixture('droplet_bandwidth')));

	$seen = $driver->collect(cloud_do_account(), new Secret(CLOUD_DO_TOKEN), [cloud_do_metric('do_bw_prv_in')])
		->observations[0];

	expect($seen->observedAt)->toBe(1634052720)
		->and($seen->value)->toEqualWithDelta(16214.285714285712, 1e-6)
		->and($http->sent[0]->url)->toEndWith('&interface=private&direction=inbound');
});

it('reports no observation and no error for a metric with no data, as without the agent', function () {
	[$driver] = cloud_do_driver(
		static fn (): HttpResponse => cloud_do_json(
			['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []]]
		)
	);

	$result = $driver->collect(cloud_do_account(), new Secret(CLOUD_DO_TOKEN), [cloud_do_metric('do_mem_avail')]);

	expect($result->observations)->toBe([])
		->and($result->error)->toBeNull()
		->and($result->requests)->toBe(1);
});

it('records an unknown CPU value across a counter reset, never zero', function () {
	$body = cloud_do_fixture('droplet_cpu');

	foreach ($body['data']['result'] as &$series) {
		$series['values'][2][1] = '0.5';
	}

	[$driver] = cloud_do_driver(static fn (): HttpResponse => cloud_do_json($body));

	expect(
		$driver->collect(cloud_do_account(), new Secret(CLOUD_DO_TOKEN), [cloud_do_metric('do_cpu_pct')])
			->observations[0]->value
	)->toBeNull();
});

it('reports several load balancer series as malformed instead of guessing how to combine them', function () {
	$series = ['metric' => ['lb_id' => '4de7ac8b-495b-4884-9a69-1050c6793cd6'], 'values' => [[1635387120, '3']]];

	[$driver] = cloud_do_driver(
		static fn (): HttpResponse => cloud_do_json(
			['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => [$series, $series]]]
		)
	);

	$result = $driver->collect(
		cloud_do_account(),
		new Secret(CLOUD_DO_TOKEN),
		[cloud_do_metric('dolb_conn_cur', '4de7ac8b-495b-4884-9a69-1050c6793cd6')]
	);

	expect($result->observations)->toBe([])->and($result->error)->toBe(ErrorClass::Malformed);
});

it('maps 401, 403, 429, and 5xx, and stops at a credential, scope, or rate failure', function (
	int $status,
	ErrorClass $class,
	int $sent
) {
	[$driver, $http] = cloud_do_driver(cloud_do_status($status));

	$result = $driver->collect(
		cloud_do_account(),
		new Secret(CLOUD_DO_TOKEN),
		[cloud_do_metric('do_cpu_pct'), cloud_do_metric('do_bw_pub_in'), cloud_do_metric('do_bw_pub_out')]
	);

	expect($result->error)->toBe($class)
		->and(count($http->sent))->toBe($sent)
		->and($result->requests)->toBe($sent);
})->with([
	'expired or revoked token' => [401, ErrorClass::Auth, 1],
	'missing scope'            => [403, ErrorClass::Permission, 1],
	'rate limited'             => [429, ErrorClass::Throttled, 1],
	'provider error'           => [503, ErrorClass::Transient, 3],
	'unexpected status'        => [404, ErrorClass::Malformed, 3],
]);

it('treats a body that is not JSON as malformed', function () {
	[$driver] = cloud_do_driver(static fn (): HttpResponse => new HttpResponse(200, [], '<html>maintenance</html>'));

	expect($driver->collect(cloud_do_account(), new Secret(CLOUD_DO_TOKEN), [cloud_do_metric('do_cpu_pct')])->error)
		->toBe(ErrorClass::Malformed);
});

it('never requests a series whose stored id or metric does not fit its type', function () {
	[$driver, $http] = cloud_do_driver(static fn (): HttpResponse => cloud_do_json(cloud_do_fixture('droplet_cpu')));

	$result = $driver->collect(cloud_do_account(), new Secret(CLOUD_DO_TOKEN), [
		cloud_do_metric('do_cpu_pct', '1&interface=x'),
		new MetricRequest(7, 'droplet', '222651441', 'do_unknown'),
		new MetricRequest(7, 'load_balancer', '222651441', 'do_cpu_pct'),
	]);

	// The driver refuses these itself; the operation list behind it is a
	// second check, so nothing reaches the transport and no request counts.
	expect($http->sent)->toBe([])
		->and($result->requests)->toBe(0)
		->and($result->error)->toBe(ErrorClass::Config);
});

it('verifies each scope it needs, and the monitoring scope against a Droplet', function () {
	[$driver, $http] = cloud_do_driver(static fn (string $path): HttpResponse => cloud_do_json(
		cloud_do_fixture(match ($path) {
			'/v2/droplets'       => 'droplets',
			'/v2/load_balancers' => 'load_balancers',
			default              => 'droplet_cpu',
		})
	));

	expect($driver->verify(cloud_do_account(), new Secret(CLOUD_DO_TOKEN)))->toBe(VerifyStatus::Ok)
		->and(array_map(
			static fn (HttpRequest $r): string => (string) parse_url($r->url, PHP_URL_PATH),
			$http->sent
		))->toBe(['/v2/droplets', '/v2/load_balancers', '/v2/monitoring/metrics/droplet/cpu'])
		->and($http->sent[2]->url)->toContain('host_id=3164444');
});

it('reports a rejected token and a missing scope on verify', function (int $status, VerifyStatus $expected) {
	[$driver] = cloud_do_driver(cloud_do_status($status));

	expect($driver->verify(cloud_do_account(), new Secret(CLOUD_DO_TOKEN)))->toBe($expected);
})->with([
	[401, VerifyStatus::Invalid],
	[403, VerifyStatus::Permission],
	[429, VerifyStatus::Throttled],
	[500, VerifyStatus::Unreachable],
]);

it('allows only GET under the three documented prefixes, and every catalog path', function () {
	$probe = new DigitalOceanDriver(
		new DoScripted(static fn (): HttpResponse => cloud_do_json([])),
		new FixedClock(CLOUD_DO_NOW)
	);
	$guard = new GuardedTransport(new DoScripted(static fn (): HttpResponse => cloud_do_json([])), $probe->operations());

	foreach ($probe->operations() as $operation) {
		expect($operation->method)->toBe('GET')
			->and($operation->host)->toBe('api.digitalocean.com');
	}

	foreach (['droplet' => 'host_id=1', 'load_balancer' => 'lb_id=4de7ac8b-495b-4884-9a69-1050c6793cd6'] as $type => $id) {
		foreach (DigitalOceanCatalog::paths($type) as $path) {
			expect(
				$guard->allows(
					new HttpRequest('GET', "https://api.digitalocean.com/v2/monitoring/metrics/$type/$path?$id&start=1&end=2")
				)
			)->not->toBeNull($path);
		}
	}

	foreach ([
		new HttpRequest(
			'POST',
			'https://api.digitalocean.com/v2/droplets',
			['Content-Type' => 'application/json'],
			'{"name":"x"}'
		),
		new HttpRequest('DELETE', 'https://api.digitalocean.com/v2/droplets?per_page=1&page=1'),
		new HttpRequest('GET', 'https://api.digitalocean.com/v2/droplets/3164444/actions?per_page=1&page=1'),
		new HttpRequest('GET', 'https://api.digitalocean.com/v2/account/keys?per_page=1&page=1'),
		new HttpRequest('GET', 'https://api.digitalocean.com/v2/monitoring/alerts?per_page=1&page=1'),
		new HttpRequest('GET', 'https://api.digitalocean.com/v2/droplets?per_page=1&page=1&tag_name=x'),
	] as $request) {
		expect($guard->allows($request))->toBeNull($request->method . ' ' . $request->url);
	}

});

it('lists only metrics that need no agent by default', function () {
	expect(DigitalOceanCatalog::defaults('droplet'))
		->toBe(['do_cpu_pct', 'do_bw_pub_in', 'do_bw_pub_out', 'do_bw_prv_in', 'do_bw_prv_out'])
		->and(count(DigitalOceanCatalog::defaults('load_balancer')))->toBe(16);

	foreach (
		array_merge(DigitalOceanCatalog::defaults('droplet'), DigitalOceanCatalog::defaults('load_balancer')) as $name
	) {
		expect(strlen($name))->toBeLessThanOrEqual(19)->and(preg_match('/^[a-zA-Z0-9_]+$/', $name))->toBe(1);
	}
});

it('estimates one request per series per collection and no charge', function () {
	$estimate = (new DigitalOceanDriver(
		new DoScripted(static fn (): HttpResponse => cloud_do_json([])),
		new FixedClock(0)
	))
		->estimate(
			array_map(
				static fn (string $m): MetricRequest => cloud_do_metric($m),
				DigitalOceanCatalog::defaults('droplet')
			),
			300
		);

	expect($estimate->requestsPerCollection)->toBe(5)
		->and($estimate->requestsPerMonth)->toBe(5 * 8640)
		->and($estimate->monthlyCost)->toBe(0.0);
});

it('registers DigitalOcean in production, and refuses every request when offline', function () {
	putenv('CLOUD_OFFLINE=1');

	try {
		$driver = \Somethingwithproof\Cloud\Drivers::production()->for(cloud_do_account());

		expect($driver)->toBeInstanceOf(DigitalOceanDriver::class)
			->and($driver->verify(cloud_do_account(), new Secret(CLOUD_DO_TOKEN)))->toBe(VerifyStatus::Config)
			->and(fn () => $driver->discover(cloud_do_account(), new Secret(CLOUD_DO_TOKEN)))
				->toThrow(DriverError::class, 'operation_refused');
	} finally {
		putenv('CLOUD_OFFLINE');
	}
});

function cloud_do_limited(int $limit, int $remaining, int $reset): Closure {
	return static function () use ($limit, $remaining, $reset): HttpResponse {
		$response = cloud_do_json(cloud_do_fixture('droplet_cpu'));

		return new HttpResponse(
			200,
			$response->headers + [
				'ratelimit-limit'     => (string) $limit,
				'ratelimit-remaining' => (string) $remaining,
				'ratelimit-reset'     => (string) $reset,
			],
			$response->body
		);
	};
}

it('passes on Retry-After from a 429, in seconds or as a date, bounded to an hour', function (
	string $header,
	int $expected
) {
	[$driver] = cloud_do_driver(static fn (): HttpResponse => new HttpResponse(429, ['retry-after' => $header], '{}'));

	$result = $driver->collect(
		cloud_do_account(),
		new Secret(CLOUD_DO_TOKEN),
		[cloud_do_metric('do_cpu_pct'), cloud_do_metric('do_bw_pub_in')]
	);

	expect($result->error)->toBe(ErrorClass::Throttled)
		->and($result->requests)->toBe(1)
		->and($result->retryAfter)->toBe($expected);
})->with([
	'seconds'    => ['120', 120],
	'date'       => [gmdate('D, d M Y H:i:s', CLOUD_DO_NOW + 300) . ' GMT', 300],
	'too long'   => ['86400', 3600],
	'absent'     => ['', 0],
	'nonsense'   => ['soon', 0],
]);

it('stops once the rate-limit headers leave only what other users of the token are owed', function () {
	// A 50 percent share of 5,000 leaves 2,500 for others.
	[$driver, $http] = cloud_do_driver(cloud_do_limited(5000, 2500, CLOUD_DO_NOW + 45));

	$result = $driver->collect(
		cloud_do_account(),
		new Secret(CLOUD_DO_TOKEN),
		[cloud_do_metric('do_cpu_pct'), cloud_do_metric('do_cpu_pct', '3164444', 8)]
	);

	expect(count($http->sent))->toBe(1)
		->and($result->observations)->toHaveCount(1)
		->and($result->error)->toBe(ErrorClass::Throttled)
		->and($result->retryAfter)->toBe(45);
});

it('keeps reading while the headers show room in its share', function () {
	[$driver, $http] = cloud_do_driver(cloud_do_limited(5000, 2501, CLOUD_DO_NOW + 45));

	$result = $driver->collect(
		cloud_do_account(),
		new Secret(CLOUD_DO_TOKEN),
		[cloud_do_metric('do_cpu_pct'), cloud_do_metric('do_cpu_pct', '3164444', 8)]
	);

	expect(count($http->sent))->toBe(2)->and($result->error)->toBeNull();
});

it('fails discovery rather than return a list cut off by the request share', function () {
	[$driver] = cloud_do_driver(static function (): HttpResponse {
		$body          = cloud_do_fixture('droplets');
		$body['links'] = ['pages' => ['next' => 'https://api.digitalocean.com/v2/droplets?page=2']];

		return new HttpResponse(
			200,
			['ratelimit-limit' => '5000', 'ratelimit-remaining' => '10', 'ratelimit-reset' => (string) (CLOUD_DO_NOW + 30)],
			json_encode($body)
		);
	});

	try {
		$driver->discover(cloud_do_account(), new Secret(CLOUD_DO_TOKEN));
		$caught = null;
	} catch (DriverError $e) {
		$caught = $e;
	}

	expect($caught?->errorClass)->toBe(ErrorClass::Throttled)->and($caught?->retryAfter)->toBe(30);
});
