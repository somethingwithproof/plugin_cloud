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
 * Google Cloud Platform read-only driver: Compute Engine instances discovery
 * via instances.aggregatedList and Cloud Monitoring timeSeries.list metric
 * collection using RS256 service-account JWTs or GCE instance identity.
 */
final class GcpDriver implements ProviderDriver {
	public const TYPE_INSTANCE   = 'gce_instance';
	public const COMPUTE_HOST    = 'compute.googleapis.com';
	public const MONITORING_HOST = 'monitoring.googleapis.com';

	private const MAX_PAGES      = 200;
	private const MAX_WAIT       = 3600;
	private const MONTH          = 2592000;
	private const PRICE_PER_TS   = 0.0000005;
	private const PRICE_SOURCE   = 'https://cloud.google.com/products/observability/pricing';
	private const PRICE_CHECKED  = '2026-11-14';
	private const PROJECT_RE     = '[a-z][a-z0-9-]{4,28}[a-z0-9]';
	private const INSTANCE_ID_RE = '/^[0-9]{1,20}$/';

	/**
	 * Metric catalog from docs/research/metric-catalogs.md.
	 * Row format: [metricType, aligner, reducer, convert].
	 *
	 * @var array<string, array{0: string, 1: string, 2: ?string, 3: string}>
	 */
	private const METRICS = [
		'gce_cpu_pct'     => ['compute.googleapis.com/instance/cpu/utilization', 'ALIGN_MEAN', null, 'percent'],
		'gce_net_in_Bps'  => ['compute.googleapis.com/instance/network/received_bytes_count', 'ALIGN_RATE', 'REDUCE_SUM', 'value'],
		'gce_net_out_Bps' => ['compute.googleapis.com/instance/network/sent_bytes_count', 'ALIGN_RATE', 'REDUCE_SUM', 'value'],
		'gce_pkt_in_ps'   => ['compute.googleapis.com/instance/network/received_packets_count', 'ALIGN_RATE', 'REDUCE_SUM', 'value'],
		'gce_pkt_out_ps'  => ['compute.googleapis.com/instance/network/sent_packets_count', 'ALIGN_RATE', 'REDUCE_SUM', 'value'],
		'gce_dsk_rd_Bps'  => ['compute.googleapis.com/instance/disk/read_bytes_count', 'ALIGN_RATE', 'REDUCE_SUM', 'value'],
		'gce_dsk_wr_Bps'  => ['compute.googleapis.com/instance/disk/write_bytes_count', 'ALIGN_RATE', 'REDUCE_SUM', 'value'],
		'gce_dsk_rd_ops'  => ['compute.googleapis.com/instance/disk/read_ops_count', 'ALIGN_RATE', 'REDUCE_SUM', 'value'],
		'gce_dsk_wr_ops'  => ['compute.googleapis.com/instance/disk/write_ops_count', 'ALIGN_RATE', 'REDUCE_SUM', 'value'],
	];

	private ?Secret $cachedToken = null;
	private int $tokenExpiresAt  = 0;

	public function __construct(private readonly HttpTransport $http, private readonly Clock $clock) {
	}

	public function provider(): Provider {
		return Provider::GoogleCloud;
	}

	public function endpoints(Account $account): OutboundPolicy {
		$hosts = [GcpAuth::TOKEN_HOST, self::COMPUTE_HOST, self::MONITORING_HOST];
		$plain = $account->credentialSource === CredentialSource::Instance ? [GcpAuth::METADATA_HOST] : [];

		return new OutboundPolicy($hosts, [443], $plain);
	}

	public function operations(): array {
		$project = self::PROJECT_RE;

		return [
			new Operation(
				'Google OAuth2 Token Exchange',
				'POST',
				GcpAuth::TOKEN_HOST,
				'#^/token$#',
				'/^grant_type=urn%3Aietf%3Aparams%3Aoauth%3Agrant-type%3Ajwt-bearer&assertion=[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/'
			),
			new Operation(
				'GCE Metadata Access Token',
				'GET',
				GcpAuth::METADATA_HOST,
				'#^/computeMetadata/v1/instance/service-accounts/default/token$#'
			),
			new Operation(
				'Compute Engine Instances AggregatedList',
				'GET',
				self::COMPUTE_HOST,
				"#^/compute/v1/projects/$project/aggregated/instances$#",
				null,
				'/^maxResults=[0-9]{1,3}(&pageToken=[A-Za-z0-9._~%+=\\/-]+)?$/'
			),
			new Operation(
				'Cloud Monitoring TimeSeries List',
				'GET',
				self::MONITORING_HOST,
				"#^/v3/projects/$project/timeSeries$#",
				null,
				'/^filter=[^&]+&interval\.startTime=[^&]+&interval\.endTime=[^&]+(&aggregation\.alignmentPeriod=[0-9]+s)?(&aggregation\.perSeriesAligner=ALIGN_[A-Z_]+)?(&aggregation\.crossSeriesReducer=REDUCE_[A-Z_]+)?(&aggregation\.groupByFields=[^&]+)?(&view=(FULL|HEADERS))?(&pageSize=[0-9]{1,4})?(&pageToken=[A-Za-z0-9._~%+=\\/-]+)?$/'
			),
		];
	}

	public function verify(Account $account, Secret $credential): VerifyStatus {
		try {
			$project = $this->project($account);
			$token   = $this->accessToken($account, $credential);

			$this->getJson(
				self::COMPUTE_HOST,
				'/compute/v1/projects/' . $project . '/aggregated/instances',
				['maxResults' => '1'],
				$token
			);

			$now = $this->clock->now();
			$this->getJson(
				self::MONITORING_HOST,
				'/v3/projects/' . $project . '/timeSeries',
				[
					'filter'             => 'metric.type="compute.googleapis.com/instance/cpu/utilization"',
					'interval.startTime' => gmdate('Y-m-d\TH:i:s\Z', $now - 600),
					'interval.endTime'   => gmdate('Y-m-d\TH:i:s\Z', $now),
					'pageSize'           => '1',
				],
				$token
			);

			return VerifyStatus::Ok;
		} catch (DriverError $error) {
			return VerifyStatus::fromError($error->errorClass);
		}
	}

	public function discover(Account $account, Secret $credential): Inventory {
		$project   = $this->project($account);
		$token     = $this->accessToken($account, $credential);
		$resources = [];
		$requests  = 0;
		$pageToken = null;

		for ($page = 1; $page <= self::MAX_PAGES; $page++) {
			$query = ['maxResults' => '500'];

			if ($pageToken !== null) {
				$query['pageToken'] = $pageToken;
			}

			$body = $this->getJson(
				self::COMPUTE_HOST,
				'/compute/v1/projects/' . $project . '/aggregated/instances',
				$query,
				$token
			);
			$requests++;

			$items = is_array($body['items'] ?? null) ? $body['items'] : [];

			foreach ($items as $scopeKey => $zoneBlock) {
				if (!is_array($zoneBlock) || !is_array($zoneBlock['instances'] ?? null)) {
					continue;
				}

				$zone = str_starts_with((string) $scopeKey, 'zones/') ? substr((string) $scopeKey, 6) : '';

				foreach ($zoneBlock['instances'] as $instance) {
					if (!is_array($instance)) {
						continue;
					}

					$rawId = $instance['id'] ?? null;
					$id    = is_int($rawId) ? (string) $rawId : (is_string($rawId) ? trim($rawId) : '');
					$name  = is_string($instance['name'] ?? null) ? trim($instance['name']) : $id;

					if (preg_match(self::INSTANCE_ID_RE, $id) !== 1) {
						throw new DriverError(ErrorClass::Malformed, 'invalid GCE instance id');
					}

					$resources[] = new CloudResource(self::TYPE_INSTANCE, $id, $name, $zone);
				}
			}

			$next = is_string($body['nextPageToken'] ?? null) ? trim($body['nextPageToken']) : '';

			if ($next === '') {
				return new Inventory($resources, $requests);
			}

			$pageToken = $next;
		}

		throw new DriverError(ErrorClass::Malformed, 'GCE instances.aggregatedList exceeded max pages');
	}

	public function collect(Account $account, Secret $credential, array $requests): CollectResult {
		if ($requests === []) {
			return new CollectResult([], 0);
		}

		try {
			$project = $this->project($account);
			$token   = $this->accessToken($account, $credential);
		} catch (DriverError $e) {
			return new CollectResult([], 0, $e->errorClass, $e->retryAfter);
		}

		/** @var array<string, array<string, MetricRequest>> $byMetric metricName => (instanceId => MetricRequest) */
		$byMetric = [];
		$error    = null;

		foreach ($requests as $request) {
			if (
				!isset(self::METRICS[$request->metric])
				|| $request->resourceType !== self::TYPE_INSTANCE
				|| preg_match(self::INSTANCE_ID_RE, $request->providerId) !== 1
			) {
				$error ??= ErrorClass::Config;

				continue;
			}

			$byMetric[$request->metric][$request->providerId] = $request;
		}

		$now          = $this->clock->now();
		$alignment    = max(60, $account->collectInterval);
		$endTime      = $now;
		$startTime    = $endTime - max($alignment * 2, 600);
		$observations = [];
		$sent         = 0;
		$retryAfter   = 0;

		foreach ($byMetric as $metricName => $wantedByInstance) {
			[$metricType, $aligner, $reducer, $convert] = self::METRICS[$metricName];
			/** @var array<string, array{int, float}> $latest */
			$latest    = [];
			$pageToken = null;
			$stopped   = false;

			for ($page = 1; $page <= self::MAX_PAGES; $page++) {
				$query = [
					'filter'                        => 'metric.type="' . $metricType . '"',
					'interval.startTime'            => gmdate('Y-m-d\TH:i:s\Z', $startTime),
					'interval.endTime'              => gmdate('Y-m-d\TH:i:s\Z', $endTime),
					'aggregation.alignmentPeriod'   => $alignment . 's',
					'aggregation.perSeriesAligner'  => $aligner,
				];

				if ($reducer !== null) {
					$query['aggregation.crossSeriesReducer'] = $reducer;
					$query['aggregation.groupByFields']      = 'resource.label.instance_id';
				}

				$query['view']     = 'FULL';
				$query['pageSize'] = '500';

				if ($pageToken !== null) {
					$query['pageToken'] = $pageToken;
				}

				try {
					$sent++;
					$body = $this->getJson(
						self::MONITORING_HOST,
						'/v3/projects/' . $project . '/timeSeries',
						$query,
						$token
					);
				} catch (DriverError $e) {
					$stops      = in_array($e->errorClass, [ErrorClass::Auth, ErrorClass::Permission, ErrorClass::Throttled], true);
					$error      = $stops || $error === null ? $e->errorClass : $error;
					$retryAfter = $e->retryAfter;
					$stopped    = $stops;

					break;
				}

				if (is_array($body['executionErrors'] ?? null) && $body['executionErrors'] !== []) {
					$error ??= ErrorClass::Transient;
				}

				$seriesList = is_array($body['timeSeries'] ?? null) ? $body['timeSeries'] : [];

				foreach ($seriesList as $series) {
					if (!is_array($series)) {
						continue;
					}

					$labels     = is_array($series['resource'] ?? null) && is_array($series['resource']['labels'] ?? null)
						? $series['resource']['labels']
						: [];
					$instanceId = is_string($labels['instance_id'] ?? null) ? trim($labels['instance_id']) : '';

					if ($instanceId === '' || !isset($wantedByInstance[$instanceId])) {
						continue;
					}

					$points = is_array($series['points'] ?? null) ? $series['points'] : [];

					foreach ($points as $point) {
						if (!is_array($point)) {
							continue;
						}

						$interval = is_array($point['interval'] ?? null) ? $point['interval'] : [];
						$endStr   = is_string($interval['endTime'] ?? null) ? $interval['endTime'] : '';
						$ts       = $endStr !== '' ? strtotime($endStr) : false;
						$valBlock = is_array($point['value'] ?? null) ? $point['value'] : [];
						$rawVal   = $valBlock['doubleValue'] ?? ($valBlock['int64Value'] ?? null);

						if ($ts === false || !is_numeric($rawVal)) {
							continue;
						}

						$num = (float) $rawVal;

						if (!is_finite($num)) {
							continue;
						}

						if (!isset($latest[$instanceId]) || $ts > $latest[$instanceId][0]) {
							$latest[$instanceId] = [$ts, $num];
						}
					}
				}

				$next = is_string($body['nextPageToken'] ?? null) ? trim($body['nextPageToken']) : '';

				if ($next === '') {
					break;
				}

				$pageToken = $next;
			}

			foreach ($latest as $instanceId => [$observedAt, $rawVal]) {
				$req            = $wantedByInstance[$instanceId];
				$converted      = $convert === 'percent' ? $rawVal * 100.0 : $rawVal;
				$observations[] = new Observation(
					$req->resourceId,
					$req->metric,
					$converted,
					$observedAt,
					$this->clock->now()
				);
			}

			if ($stopped) {
				return new CollectResult($observations, $sent, $error, $retryAfter);
			}
		}

		return new CollectResult($observations, $sent, $error, $retryAfter);
	}

	/**
	 * Cloud Monitoring bills $0.50 per million returned time series at list
	 * price (excluding shared billing-account free tiers, per UsageEstimate).
	 */
	public function estimate(array $requests, int $intervalSeconds): UsageEstimate {
		$seriesCount = count($requests);

		if ($seriesCount === 0) {
			return new UsageEstimate(0, 0, 0.0, self::PRICE_SOURCE, self::PRICE_CHECKED);
		}

		$metricTypes = [];

		foreach ($requests as $request) {
			$metricTypes[$request->metric] = true;
		}

		$perCollection = count($metricTypes);
		$collections   = self::MONTH / max(1, $intervalSeconds);
		$perMonth      = (int) ceil($perCollection * $collections);
		$monthlyCost   = round($seriesCount * $collections * self::PRICE_PER_TS, 4);

		return new UsageEstimate($perCollection, $perMonth, $monthlyCost, self::PRICE_SOURCE, self::PRICE_CHECKED);
	}

	/**
	 * @throws DriverError
	 */
	private function project(Account $account): string {
		$project = $account->scope['project'] ?? '';

		if ($project === '' || preg_match('/^' . self::PROJECT_RE . '$/', $project) !== 1) {
			throw new DriverError(ErrorClass::Config, 'valid GCP project is required');
		}

		return $project;
	}

	/**
	 * @throws DriverError
	 */
	private function accessToken(Account $account, Secret $credential): Secret {
		$now = $this->clock->now();

		if ($this->cachedToken !== null && $this->tokenExpiresAt > $now + 60) {
			return $this->cachedToken;
		}

		if ($account->credentialSource === CredentialSource::Instance) {
			$result = GcpAuth::fetchMetadataToken($this->http, $now);
		} else {
			try {
				$doc = json_decode($credential->reveal(), true, 16, JSON_THROW_ON_ERROR);
			} catch (\JsonException) {
				throw new DriverError(ErrorClass::Config, 'GCP service account credential must be JSON');
			}

			if (!is_array($doc)) {
				throw new DriverError(ErrorClass::Config, 'GCP service account credential must be a JSON object');
			}

			$result = GcpAuth::exchangeServiceAccount($this->http, $doc, GcpAuth::DEFAULT_SCOPE, $now);
		}

		$this->cachedToken    = $result['token'];
		$this->tokenExpiresAt = $result['expiresAt'];

		return $this->cachedToken;
	}

	/**
	 * @param array<string, string> $query
	 * @return array<array-key, mixed>
	 * @throws DriverError
	 */
	private function getJson(string $host, string $path, array $query, Secret $token): array {
		$url = 'https://' . $host . $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

		try {
			$response = $this->http->send(new HttpRequest(
				'GET',
				$url,
				[
					'Authorization' => 'Bearer ' . $token->reveal(),
					'Accept'        => 'application/json',
				]
			));
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

		try {
			$decoded = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new DriverError(ErrorClass::Malformed, 'GCP response is not JSON');
		}

		if (!is_array($decoded)) {
			throw new DriverError(ErrorClass::Malformed, 'GCP response is not an object');
		}

		return $decoded;
	}

	private function retryAfter(HttpResponse $response): int {
		$value = trim($response->header('retry-after'));
		$wait  = ctype_digit($value)
			? (int) $value
			: (($at = strtotime($value)) !== false && $value !== '' ? $at - $this->clock->now() : 0);

		return max(0, min(self::MAX_WAIT, $wait));
	}
}
