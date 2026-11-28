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

namespace Somethingwithproof\Cloud;

/**
 * DigitalOcean: a bearer token, Droplets and load balancers, and one GET per
 * metric under /v2/monitoring/metrics (REQ-DO-001 to REQ-DO-006). Facts are
 * from docs/research/provider-facts.md; the ones only a live account can
 * settle are marked there and wait on #16.
 *
 * Every URL is built here from the fixed host, a catalog path, and a stored
 * resource id checked against its type's pattern. Pagination counts pages
 * itself instead of following links.pages.next, so no URL a response carries
 * is ever requested.
 */
final class DigitalOceanDriver implements ProviderDriver {
	/**
	 * DigitalOcean v2 API hostname.
	 */
	public const HOST = 'api.digitalocean.com';

	/**
	 * Maximum items requested per DigitalOcean paginated list call.
	 */
	private const PER_PAGE = 100;

	/** Discovery fails rather than return a cut-off list, which would retire the rest. */
	private const MAX_PAGES = 1000;

	/** Seconds of history asked for per metric: enough for two CPU samples. */
	private const WINDOW = 900;

	/**
	 * Nominal 30-day month in seconds for usage estimates.
	 */
	private const MONTH = 2592000;

	/** Where the rate limit is documented and no API charge is (provider facts DO10). */
	private const PRICE_SOURCE  = 'https://docs.digitalocean.com/reference/api/reference/openapi.json';

	/**
	 * Date the DigitalOcean API pricing reference was last checked.
	 */
	private const PRICE_CHECKED = '2026-11-14';

	/** The longest wait a provider may impose through Retry-After or ratelimit-reset. */
	private const MAX_WAIT = 3600;

	/**
	 * Validation patterns for DigitalOcean resource identifiers by type.
	 */
	private const ID_PATTERNS = [
		DigitalOceanCatalog::DROPLET       => '/^[1-9][0-9]{0,19}$/',
		DigitalOceanCatalog::LOAD_BALANCER => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
	];

	/** Percent of the token's limit this account may use; set per call. */
	private int $share = 100;

	/**
	 * Seconds to wait once the rate-limit headers show the account's share
	 * is used up: the rest of the token's limit is left to its other users
	 * (REQ-BUDGET-003). Null while there is room.
	 */
	private ?int $exhausted = null;

	/**
	 * Construct a DigitalOcean driver bound to a guarded HTTP transport and clock.
	 *
	 * @param HttpTransport $http  guarded outbound transport
	 * @param Clock         $clock wall clock for metric windows and rate-limit resets
	 */
	public function __construct(private readonly HttpTransport $http, private readonly Clock $clock) {
	}

	/**
	 * Return Provider::DigitalOcean.
	 *
	 * @return Provider
	 */
	public function provider(): Provider {
		return Provider::DigitalOcean;
	}

	/**
	 * Build the outbound HTTPS allowlist for api.digitalocean.com.
	 *
	 * @param Account $account target DigitalOcean account
	 * @return OutboundPolicy
	 */
	public function endpoints(Account $account): OutboundPolicy {
		return new OutboundPolicy([self::HOST]);
	}

	/**
	 * Return the fixed read-only DigitalOcean operations allowed through GuardedTransport.
	 *
	 * @return list<Operation>
	 */
	public function operations(): array {
		$page    = '/^per_page=[0-9]{1,3}&page=[0-9]{1,4}$/';
		$window  = 'start=[0-9]{1,12}&end=[0-9]{1,12}';
		$droplet = implode('|', DigitalOceanCatalog::paths(DigitalOceanCatalog::DROPLET));
		$lb      = implode('|', DigitalOceanCatalog::paths(DigitalOceanCatalog::LOAD_BALANCER));

		return [
			new Operation('List Droplets', 'GET', self::HOST, '#^/v2/droplets$#', null, $page),
			new Operation('List load balancers', 'GET', self::HOST, '#^/v2/load_balancers$#', null, $page),
			new Operation(
				'Read Droplet metrics',
				'GET',
				self::HOST,
				"#^/v2/monitoring/metrics/droplet/($droplet)$#",
				null,
				"/^host_id=[0-9]{1,20}&$window(&interface=(public|private)&direction=(inbound|outbound))?$/"
			),
			new Operation(
				'Read load balancer metrics',
				'GET',
				self::HOST,
				"#^/v2/monitoring/metrics/load_balancer/($lb)$#",
				null,
				"/^lb_id=[0-9a-f-]{36}&$window$/"
			),
		];
	}

	/**
	 * One read per scope the account needs. The monitoring scope can only be
	 * tested against a Droplet, so a team with none passes on the list scopes.
	 */
	public function verify(Account $account, Secret $credential): VerifyStatus {
		$this->share = $account->requestShare;

		try {
			$droplets = $this->get($credential, '/v2/droplets', ['per_page' => 1, 'page' => 1]);
			$this->get($credential, '/v2/load_balancers', ['per_page' => 1, 'page' => 1]);

			$list  = $droplets['droplets'] ?? null;
			$first = is_array($list) && is_array($list[0] ?? null) ? ($list[0]['id'] ?? null) : null;

			if (is_int($first) && $first > 0) {
				$now = $this->clock->now();
				$this->get(
					$credential,
					'/v2/monitoring/metrics/droplet/cpu',
					['host_id' => $first, 'start' => $now - self::WINDOW, 'end' => $now]
				);
			}

			return VerifyStatus::Ok;
		} catch (DriverError $error) {
			return VerifyStatus::fromError($error->errorClass);
		}
	}

	/**
	 * Discover DigitalOcean Droplets and load balancers using page-counted pagination.
	 *
	 * @param Account $account    target DigitalOcean account
	 * @param Secret  $credential bearer token
	 * @return Inventory
	 * @throws DriverError
	 */
	public function discover(Account $account, Secret $credential): Inventory {
		$this->share = $account->requestShare;
		$resources   = [];
		$requests  = 0;

		foreach (
			[DigitalOceanCatalog::DROPLET => 'droplets', DigitalOceanCatalog::LOAD_BALANCER => 'load_balancers'] as $type => $key
		) {
			for ($page = 1; ; $page++) {
				if ($page > self::MAX_PAGES) {
					throw new DriverError(ErrorClass::Malformed, $key . ' list exceeds ' . self::MAX_PAGES . ' pages');
				}

				// Stopping part way would return a cut-off list.
				if ($this->exhausted !== null) {
					throw new DriverError(ErrorClass::Throttled, 'request share used up', $this->exhausted);
				}

				$body = $this->get($credential, '/v2/' . $key, ['per_page' => self::PER_PAGE, 'page' => $page]);
				$requests++;

				$items = $body[$key] ?? null;

				if (!is_array($items) || !array_is_list($items)) {
					throw new DriverError(ErrorClass::Malformed, $key . ' list missing');
				}

				foreach ($items as $item) {
					$resources[] = self::resource($type, $item);
				}

				$pages = is_array($body['links'] ?? null) && is_array($body['links']['pages'] ?? null)
					? $body['links']['pages']
					: [];

				if ($items === [] || !isset($pages['next'])) {
					break;
				}
			}
		}

		return new Inventory($resources, $requests);
	}

	/**
	 * Collect requested DigitalOcean monitoring metrics while respecting token share.
	 *
	 * @param Account             $account    target DigitalOcean account
	 * @param Secret              $credential bearer token
	 * @param list<MetricRequest> $requests   metric series to collect
	 * @return CollectResult
	 */
	public function collect(Account $account, Secret $credential, array $requests): CollectResult {
		$this->share  = $account->requestShare;
		$observations = [];
		$sent         = 0;
		$error        = null;
		$retryAfter   = 0;
		$now          = $this->clock->now();

		foreach ($requests as $request) {
			if ($this->exhausted !== null) {
				$error      = ErrorClass::Throttled;
				$retryAfter = $this->exhausted;

				break;
			}

			$metric = DigitalOceanCatalog::metric($request->metric);
			$id     = self::ID_PATTERNS[$request->resourceType] ?? null;

			if (
				$metric === null
				|| $metric['type'] !== $request->resourceType
				|| $id === null
				|| preg_match($id, $request->providerId) !== 1
			) {
				$error ??= ErrorClass::Config;

				continue;
			}

			$key   = $metric['type'] === DigitalOceanCatalog::DROPLET ? 'host_id' : 'lb_id';
			$query = [$key => $request->providerId, 'start' => $now - self::WINDOW, 'end' => $now] + $metric['query'];

			try {
				$sent++;
				$point = self::convert(
					$this->get($credential, '/v2/monitoring/metrics/' . $metric['type'] . '/' . $metric['path'], $query),
					$metric['convert']
				);
			} catch (DriverError $e) {
				// A credential, scope, or rate problem fails every later
				// request too, so the run stops there.
				$stops      = in_array($e->errorClass, [ErrorClass::Auth, ErrorClass::Permission, ErrorClass::Throttled], true);
				$error      = $stops || $error === null ? $e->errorClass : $error;
				$retryAfter = $e->retryAfter;

				if ($stops) {
					break;
				}

				continue;
			}

			// No data: the Droplet is new, off, or lacks the metrics agent.
			if ($point !== null) {
				$observations[] = new Observation(
					$request->resourceId,
					$request->metric,
					$point[1],
					$point[0],
					$this->clock->now()
				);
			}
		}

		return new CollectResult($observations, $sent, $error, $retryAfter);
	}

	/**
	 * DigitalOcean documents no charge for API calls, only the rate limit,
	 * so the cost is zero and the page says "no charge documented".
	 */
	public function estimate(array $requests, int $intervalSeconds): UsageEstimate {
		$perCollection = count($requests);

		return new UsageEstimate(
			$perCollection,
			(int) ceil($perCollection * self::MONTH / max(1, $intervalSeconds)),
			0.0,
			self::PRICE_SOURCE,
			self::PRICE_CHECKED
		);
	}

	/**
	 * @param array<string, int|string> $query
	 * @return array<array-key, mixed>
	 * @throws DriverError
	 */
	private function get(Secret $credential, string $path, array $query): array {
		$url = 'https://' . self::HOST . $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

		try {
			$response = $this->http->send(
				new HttpRequest(
					'GET',
					$url,
					['Authorization' => 'Bearer ' . $credential->reveal(), 'Accept' => 'application/json']
				)
			);
		} catch (TransportError $e) {
			throw DriverError::fromTransport($e);
		}

		$class = match (true) {
			$response->status === 200 => null,
			$response->status === 401 => ErrorClass::Auth,
			$response->status === 403 => ErrorClass::Permission,
			$response->status === 429 => ErrorClass::Throttled,
			$response->status >= 500  => ErrorClass::Transient,
			default                   => ErrorClass::Malformed,
		};

		if ($class !== null) {
			throw new DriverError(
				$class,
				'HTTP ' . $response->status,
				$class === ErrorClass::Throttled ? $this->retryAfter($response) : 0
			);
		}

		$this->checkShare($response);

		try {
			$body = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new DriverError(ErrorClass::Malformed, 'response is not JSON');
		}

		if (!is_array($body)) {
			throw new DriverError(ErrorClass::Malformed, 'response is not an object');
		}

		return $body;
	}

	/**
	 * Retry-After as seconds or an HTTP date, bounded; 0 when absent.
	 */
	private function retryAfter(HttpResponse $response): int {
		$value = trim($response->header('retry-after'));
		$wait  = ctype_digit($value)
			? (int) $value
			: (($at = strtotime($value)) !== false && $value !== '' ? $at - $this->clock->now() : 0);

		return max(0, min(self::MAX_WAIT, $wait));
	}

	/**
	 * The hourly limit is a rolling count per token. Once what remains is no
	 * more than the part of it reserved for other users of the token, stop
	 * until ratelimit-reset, when the oldest request expires.
	 */
	private function checkShare(HttpResponse $response): void {
		$limit     = $response->header('ratelimit-limit');
		$remaining = $response->header('ratelimit-remaining');

		if (
			!ctype_digit($limit)
			|| !ctype_digit($remaining)
			|| (int) $remaining > intdiv((int) $limit * (100 - $this->share), 100)
		) {
			return;
		}

		$reset           = $response->header('ratelimit-reset');
		$this->exhausted = max(1, min(self::MAX_WAIT, ctype_digit($reset) ? (int) $reset - $this->clock->now() : 60));
	}

	/**
	 * @throws DriverError
	 */
	private static function resource(string $type, mixed $item): CloudResource {
		if (!is_array($item)) {
			throw new DriverError(ErrorClass::Malformed, $type . ' entry is not an object');
		}

		$id     = $item['id'] ?? null;
		$id     = is_int($id) ? (string) $id : (is_string($id) ? $id : '');
		$name   = is_string($item['name'] ?? null) ? $item['name'] : '';
		$region = is_array($item['region'] ?? null) && is_string($item['region']['slug'] ?? null)
			? $item['region']['slug']
			: '';

		if (preg_match(self::ID_PATTERNS[$type], $id) !== 1) {
			throw new DriverError(ErrorClass::Malformed, $type . ' id has an unexpected form');
		}

		return new CloudResource($type, $id, $name, $region);
	}

	/**
	 * The newest datapoint as [time, value], or null when the response holds
	 * no data or the value cannot be computed from it.
	 *
	 * @param array<array-key, mixed> $body
	 * @return array{int, ?float}|null
	 * @throws DriverError
	 */
	private static function convert(array $body, string $conversion): ?array {
		$series = self::matrix($body);

		if ($series === []) {
			return null;
		}

		if ($conversion === 'cpu') {
			return self::cpu($series);
		}

		// One series expected; several are not combined until the live
		// check shows what their labels mean.
		if (count($series) !== 1) {
			throw new DriverError(ErrorClass::Malformed, count($series) . ' series where one was expected');
		}

		$points = $series[0]['points'];

		if ($points === []) {
			return null;
		}

		[$time, $value] = $points[array_key_last($points)];

		return [$time, $conversion === 'mbps' ? $value * 1000000 : $value];
	}

	/**
	 * Percent busy between the newest two times every mode reports.
	 *
	 * @param list<array{labels: array<string, string>, points: array<int, array{int, float}>}> $series
	 * @return array{int, ?float}|null
	 */
	private static function cpu(array $series): ?array {
		$common = $series[0]['points'];
		$count  = count($series);

		for ($i = 1; $i < $count; $i++) {
			$common = array_intersect_key($common, $series[$i]['points']);
		}

		$times = array_keys($common);
		sort($times);

		if (count($times) < 2) {
			return null;
		}

		$newest = $times[count($times) - 1];
		$before = $times[count($times) - 2];
		$total  = 0.0;
		$idle   = null;

		foreach ($series as $one) {
			$delta  = $one['points'][$newest][1] - $one['points'][$before][1];
			$total += $delta;

			if (($one['labels']['mode'] ?? '') === 'idle') {
				$idle = $delta;
			}
		}

		// Without an idle series, or across a counter reset, the value is
		// unknown, never zero.
		if ($idle === null || $total <= 0.0 || $idle < 0.0) {
			return [$newest, null];
		}

		return [$newest, max(0.0, min(100.0, 100.0 * ($total - $idle) / $total))];
	}

	/**
	 * Series of a Prometheus-style matrix, each with its points keyed by time
	 * in ascending order. Points whose value is not a number are dropped.
	 *
	 * @param array<array-key, mixed> $body
	 * @return list<array{labels: array<string, string>, points: array<int, array{int, float}>}>
	 * @throws DriverError
	 */
	private static function matrix(array $body): array {
		$data   = is_array($body['data'] ?? null) ? $body['data'] : null;
		$result = $data['result'] ?? null;

		if ($data === null || ($data['resultType'] ?? null) !== 'matrix' || !is_array($result) || !array_is_list($result)) {
			throw new DriverError(ErrorClass::Malformed, 'metrics response is not a matrix');
		}

		$series = [];

		foreach ($result as $entry) {
			if (!is_array($entry) || !is_array($entry['values'] ?? null)) {
				throw new DriverError(ErrorClass::Malformed, 'metrics series has no values');
			}

			$labels = [];

			foreach (is_array($entry['metric'] ?? null) ? $entry['metric'] : [] as $name => $value) {
				if (is_string($value)) {
					$labels[(string) $name] = $value;
				}
			}

			$points = [];

			foreach ($entry['values'] as $pair) {
				if (!is_array($pair) || !is_int($pair[0] ?? null) || !is_string($pair[1] ?? null) || !is_numeric($pair[1])) {
					continue;
				}

				$value = (float) $pair[1];

				if (is_finite($value)) {
					$points[$pair[0]] = [$pair[0], $value];
				}
			}

			ksort($points);
			$series[] = ['labels' => $labels, 'points' => $points];
		}

		return $series;
	}
}
