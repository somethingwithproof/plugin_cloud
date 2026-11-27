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
 * Microsoft Azure read-only driver: ARM Resource Graph VM discovery (free,
 * paginated with $skipToken, deduplicated for eventual consistency) and
 * Azure Monitor metrics:getBatch collection (up to 50 resources per call,
 * handling 429 and 529 throttling responses).
 */
final class AzureDriver implements ProviderDriver {
	/**
	 * Resource type identifier for Azure Virtual Machines.
	 */
	public const TYPE_VM   = 'azure_vm';

	/**
	 * Azure Resource Manager API host.
	 */
	public const ARM_HOST  = 'management.azure.com';

	/**
	 * Maximum resource IDs per Azure Monitor metrics:getBatch request.
	 */
	public const MAX_BATCH = 50;

	/**
	 * Upper bound on Resource Graph pagination requests per discovery run.
	 */
	private const MAX_PAGES        = 200;

	/**
	 * Maximum bounded Retry-After wait duration in seconds.
	 */
	private const MAX_WAIT         = 3600;

	/**
	 * Nominal 30-day month in seconds for usage estimates.
	 */
	private const MONTH            = 2592000;

	/**
	 * List price in USD per Azure Monitor metrics API call.
	 */
	private const PRICE_PER_CALL   = 0.00001;

	/**
	 * Public Azure Monitor pricing reference URL.
	 */
	private const PRICE_SOURCE     = 'https://azure.microsoft.com/en-us/pricing/details/monitor/';

	/**
	 * Date the referenced Azure list pricing was last checked.
	 */
	private const PRICE_CHECKED    = '2026-11-14';

	/**
	 * Lowercase UUID regular expression fragment for tenant and subscription IDs.
	 */
	private const UUID_RE          = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

	/**
	 * Lowercase Azure region regular expression fragment.
	 */
	private const REGION_RE        = '[a-z][a-z0-9]{1,30}';

	/**
	 * Strict ARM Virtual Machine resource ID pattern.
	 */
	private const RESOURCE_ID_RE   = '#^/subscriptions/[0-9a-f-]{36}/resourceGroups/[^/]+/providers/Microsoft\.Compute/virtualMachines/[^/]+$#i';

	/**
	 * Azure Monitor metric namespace for Virtual Machines.
	 */
	private const METRIC_NAMESPACE = 'Microsoft.Compute/virtualMachines';

	/**
	 * Metric catalog from docs/research/metric-catalogs.md.
	 * Row format: [metricName, aggregation, convert].
	 *
	 * @var array<string, array{0: string, 1: string, 2: string}>
	 */
	private const METRICS = [
		'azvm_cpu_pct'    => ['Percentage CPU', 'Average', 'value'],
		'azvm_net_in_Bps' => ['Network In Total', 'Total', 'rate60'],
		'azvm_net_out_Bps' => ['Network Out Total', 'Total', 'rate60'],
		'azvm_dsk_rd_Bps' => ['Disk Read Bytes', 'Total', 'rate60'],
		'azvm_dsk_wr_Bps' => ['Disk Write Bytes', 'Total', 'rate60'],
		'azvm_dsk_rd_ops' => ['Disk Read Operations/Sec', 'Average', 'value'],
		'azvm_dsk_wr_ops' => ['Disk Write Operations/Sec', 'Average', 'value'],
		'azvm_avail'      => ['VmAvailabilityMetric', 'Minimum', 'value'],
		'azvm_mem_avail'  => ['Available Memory Bytes', 'Average', 'value'],
	];

	/** @var array<string, array{token: Secret, expiresAt: int}> */
	private array $tokens = [];

	/**
	 * Construct an Azure driver bound to a guarded HTTP transport and clock.
	 *
	 * @param HttpTransport $http  guarded outbound transport
	 * @param Clock         $clock wall clock for token expiry and metric windows
	 */
	public function __construct(private readonly HttpTransport $http, private readonly Clock $clock) {
	}

	/**
	 * Return Provider::Azure.
	 *
	 * @return Provider
	 */
	public function provider(): Provider {
		return Provider::Azure;
	}

	/**
	 * Build the outbound host allowlist for an Azure account's configured regions.
	 *
	 * @param Account $account target Azure account
	 * @return OutboundPolicy
	 */
	public function endpoints(Account $account): OutboundPolicy {
		$hosts = [AzureAuth::LOGIN_HOST, self::ARM_HOST];

		foreach ($this->regions($account) as $region) {
			$hosts[] = $region . '.metrics.monitor.azure.com';
		}

		$plain = $account->credentialSource === CredentialSource::Instance ? [AzureAuth::IMDS_HOST] : [];

		return new OutboundPolicy(array_values(array_unique($hosts)), [443], $plain);
	}

	/**
	 * Return the fixed read-only Azure operations allowed through GuardedTransport.
	 *
	 * @return list<Operation>
	 */
	public function operations(): array {
		$uuid   = self::UUID_RE;
		$region = self::REGION_RE;

		return [
			new Operation(
				'Entra ID Token Request',
				'POST',
				AzureAuth::LOGIN_HOST,
				"#^/$uuid/oauth2/v2\\.0/token$#",
				'/^client_id=[^&]+&scope=[^&]+&client_secret=[^&]+&grant_type=client_credentials$/'
			),
			new Operation(
				'Azure IMDS Managed Identity Token',
				'GET',
				AzureAuth::IMDS_HOST,
				'#^/metadata/identity/oauth2/token$#',
				null,
				'/^api-version=2018-02-01&resource=https%3A%2F%2F(management\.azure\.com|metrics\.monitor\.azure\.com)%2F(&client_id=' . $uuid . ')?$/'
			),
			new Operation(
				'ARM Resource Graph VM Query',
				'POST',
				self::ARM_HOST,
				'#^/providers/Microsoft\.ResourceGraph/resources$#',
				'#^\{"subscriptions":\["' . $uuid . '"\],"query":"Resources \| where type =~ \'microsoft\.compute\\\\/virtualmachines\'#',
				'/^api-version=2022-10-01$/'
			),
			new Operation(
				'ARM List Virtual Machines',
				'GET',
				self::ARM_HOST,
				"#^/subscriptions/$uuid/providers/Microsoft\\.Compute/virtualMachines$#",
				null,
				'/^api-version=2024-07-01(&\$skiptoken=[A-Za-z0-9._~%+=\\/-]+)?$/'
			),
			new Operation(
				'Azure Monitor Metrics GetBatch',
				'POST',
				"#^$region\\.metrics\\.monitor\\.azure\\.com$#",
				"#^/subscriptions/$uuid/metrics:getBatch$#",
				'/^\{"resourceids":\[[^\]]+\]\}$/',
				'/^metricnamespace=Microsoft\.Compute%2FvirtualMachines&metricnames=[^&]+&aggregation=(Average|Total|Minimum|Maximum)&interval=PT1M&starttime=[^&]+&endtime=[^&]+&api-version=2023-10-01$/'
			),
		];
	}

	/**
	 * Verify ARM and Azure Monitor token acquisition and read permission.
	 *
	 * @param Account $account    target Azure account
	 * @param Secret  $credential service principal JSON or empty for Instance
	 * @return VerifyStatus
	 */
	public function verify(Account $account, Secret $credential): VerifyStatus {
		try {
			$subscription = $this->subscription($account);
			$armToken     = $this->tokenFor($account, $credential, 'arm');
			// Verify we can also acquire the data-plane metrics token.
			$this->tokenFor($account, $credential, 'metrics');

			$this->sendJson(
				'GET',
				'https://' . self::ARM_HOST . '/subscriptions/' . $subscription
					. '/providers/Microsoft.Compute/virtualMachines?api-version=2024-07-01',
				$armToken
			);

			return VerifyStatus::Ok;
		} catch (DriverError $error) {
			return VerifyStatus::fromError($error->errorClass);
		}
	}

	/**
	 * Discover Azure Virtual Machines across the subscription via ARM Resource Graph.
	 *
	 * @param Account $account    target Azure account
	 * @param Secret  $credential service principal JSON or empty for Instance
	 * @return Inventory
	 * @throws DriverError
	 */
	public function discover(Account $account, Secret $credential): Inventory {
		$subscription = $this->subscription($account);
		$regions      = $this->regions($account);
		$armToken     = $this->tokenFor($account, $credential, 'arm');

		// Eventual-consistency safe: deduplicate across pages by lower-cased resource id.
		/** @var array<string, CloudResource> $seen */
		$seen      = [];
		$requests  = 0;
		$skipToken = null;

		$kql = "Resources | where type =~ 'microsoft.compute/virtualmachines'"
			. ' | project id, name, location | order by id asc';

		for ($page = 1; $page <= self::MAX_PAGES; $page++) {
			$payload = [
				'subscriptions' => [$subscription],
				'query'         => $kql,
				'options'       => ['$top' => 1000, 'resultFormat' => 'objectArray'],
			];

			if ($skipToken !== null) {
				$payload['options']['$skipToken'] = $skipToken;
			}

			try {
				$jsonBody = json_encode($payload, JSON_THROW_ON_ERROR);
			} catch (\JsonException) {
				throw new DriverError(ErrorClass::Config, 'failed to encode Resource Graph query');
			}

			$body = $this->sendJson(
				'POST',
				'https://' . self::ARM_HOST . '/providers/Microsoft.ResourceGraph/resources?api-version=2022-10-01',
				$armToken,
				$jsonBody
			);
			$requests++;

			$rows = is_array($body['data'] ?? null) ? $body['data'] : [];

			foreach ($rows as $row) {
				if (!is_array($row)) {
					continue;
				}

				$id       = is_string($row['id'] ?? null) ? trim($row['id']) : '';
				$name     = is_string($row['name'] ?? null) ? trim($row['name']) : '';
				$location = is_string($row['location'] ?? null) ? strtolower(trim($row['location'])) : '';

				if (preg_match(self::RESOURCE_ID_RE, $id) !== 1) {
					continue;
				}

				if ($regions !== [] && $location !== '' && !in_array($location, $regions, true)) {
					continue;
				}

				$key = strtolower($id);

				if (!isset($seen[$key])) {
					$seen[$key] = new CloudResource(
						self::TYPE_VM,
						$location . '|' . $id,
						$name !== '' ? $name : basename($id),
						$location
					);
				}
			}

			$next = is_string($body['$skipToken'] ?? null) ? trim($body['$skipToken']) : '';

			if ($next === '') {
				return new Inventory(array_values($seen), $requests);
			}

			$skipToken = $next;
		}

		throw new DriverError(ErrorClass::Malformed, 'Azure Resource Graph query exceeded max pages');
	}

	/**
	 * Collect batched Azure Monitor VM metrics up to MAX_BATCH resources per call.
	 *
	 * @param Account             $account    target Azure account
	 * @param Secret              $credential service principal JSON or empty for Instance
	 * @param list<MetricRequest> $requests   metric series to collect
	 * @return CollectResult
	 */
	public function collect(Account $account, Secret $credential, array $requests): CollectResult {
		if ($requests === []) {
			return new CollectResult([], 0);
		}

		try {
			$subscription = $this->subscription($account);
			$regions      = $this->regions($account);
			$metricsToken = $this->tokenFor($account, $credential, 'metrics');
		} catch (DriverError $e) {
			return new CollectResult([], 0, $e->errorClass, $e->retryAfter);
		}

		/**
		 * Group by region and aggregation, then by resource ARM ID.
		 *
		 * @var array<string, array<string, array<string, list<MetricRequest>>>> $grouped
		 */
		$grouped = [];
		$error   = null;

		foreach ($requests as $request) {
			$spec = self::METRICS[$request->metric] ?? null;

			if ($spec === null || $request->resourceType !== self::TYPE_VM) {
				$error ??= ErrorClass::Config;

				continue;
			}

			[$region, $armId] = $this->splitRegionAndArmId($request->providerId, $regions);

			if ($region === null || preg_match(self::RESOURCE_ID_RE, $armId) !== 1) {
				$error ??= ErrorClass::Config;

				continue;
			}

			$aggregation                      = $spec[1];
			$grouped[$region][$aggregation][$armId][] = $request;
		}

		$now          = $this->clock->now();
		$endTime      = gmdate('Y-m-d\TH:i:s\Z', $now);
		$startTime    = gmdate('Y-m-d\TH:i:s\Z', $now - max($account->collectInterval * 2, 600));
		$observations = [];
		$sent         = 0;
		$retryAfter   = 0;

		foreach ($grouped as $region => $byAggregation) {
			foreach ($byAggregation as $aggregation => $byResource) {
				$armIds = array_keys($byResource);

				foreach (array_chunk($armIds, self::MAX_BATCH) as $batchIds) {
					$metricNames = [];
					/** @var array<string, array<string, MetricRequest>> $lookup lowerArmId => (providerMetricName => MetricRequest) */
					$lookup = [];

					foreach ($batchIds as $armId) {
						foreach ($byResource[$armId] as $req) {
							$providerMetric                     = self::METRICS[$req->metric][0];
							$metricNames[$providerMetric]       = true;
							$lookup[strtolower($armId)][$providerMetric] = $req;
						}
					}

					$query = http_build_query([
						'metricnamespace' => self::METRIC_NAMESPACE,
						'metricnames'     => implode(',', array_keys($metricNames)),
						'aggregation'     => $aggregation,
						'interval'        => 'PT1M',
						'starttime'       => $startTime,
						'endtime'         => $endTime,
						'api-version'     => '2023-10-01',
					], '', '&', PHP_QUERY_RFC3986);

					try {
						$jsonBody = json_encode(['resourceids' => $batchIds], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
					} catch (\JsonException) {
						$error ??= ErrorClass::Config;

						continue;
					}

					$url = 'https://' . $region . '.metrics.monitor.azure.com/subscriptions/'
						. $subscription . '/metrics:getBatch?' . $query;

					try {
						$sent++;
						$body = $this->sendJson('POST', $url, $metricsToken, $jsonBody);
					} catch (DriverError $e) {
						$stops      = in_array($e->errorClass, [ErrorClass::Auth, ErrorClass::Permission, ErrorClass::Throttled], true);
						$error      = $stops || $error === null ? $e->errorClass : $error;
						$retryAfter = $e->retryAfter;

						if ($stops) {
							return new CollectResult($observations, $sent, $error, $retryAfter);
						}

						continue;
					}

					$values = is_array($body['values'] ?? null) ? $body['values'] : [];

					foreach ($values as $entry) {
						if (!is_array($entry)) {
							continue;
						}

						$resId = is_string($entry['resourceid'] ?? null) ? strtolower(trim($entry['resourceid'])) : '';

						if ($resId === '' || !isset($lookup[$resId])) {
							continue;
						}

						$metricsList = is_array($entry['value'] ?? null) ? $entry['value'] : [];

						foreach ($metricsList as $metricBlock) {
							if (!is_array($metricBlock)) {
								continue;
							}

							$errCode = is_string($metricBlock['errorCode'] ?? null) ? trim($metricBlock['errorCode']) : '';

							if ($errCode !== '' && strcasecmp($errCode, 'Success') !== 0) {
								$error ??= ErrorClass::Transient;

								continue;
							}

							$nameObj = $metricBlock['name'] ?? null;
							$mName   = is_array($nameObj) && is_string($nameObj['value'] ?? null)
								? $nameObj['value']
								: (is_string($nameObj) ? $nameObj : '');

							if ($mName === '' || !isset($lookup[$resId][$mName])) {
								continue;
							}

							$req   = $lookup[$resId][$mName];
							$point = self::latestPoint($metricBlock, $aggregation);

							if ($point === null) {
								continue;
							}

							$convert        = self::METRICS[$req->metric][2];
							$val            = $convert === 'rate60' ? $point[1] / 60.0 : $point[1];
							$observations[] = new Observation(
								$req->resourceId,
								$req->metric,
								$val,
								$point[0],
								$this->clock->now()
							);
						}
					}
				}
			}
		}

		return new CollectResult($observations, $sent, $error, $retryAfter);
	}

	/**
	 * Azure Monitor metrics:getBatch accepts up to 50 resources per call for
	 * one region and aggregation, billed at $0.01 per 1,000 calls at list
	 * price (excluding shared subscription free tiers, per UsageEstimate).
	 */
	public function estimate(array $requests, int $intervalSeconds): UsageEstimate {
		if ($requests === []) {
			return new UsageEstimate(0, 0, 0.0, self::PRICE_SOURCE, self::PRICE_CHECKED);
		}

		/** @var array<string, array<string, true>> $buckets aggregation => (providerId => true) */
		$buckets = [];

		foreach ($requests as $request) {
			$agg                                 = self::METRICS[$request->metric][1] ?? 'Average';
			$buckets[$agg][$request->providerId] = true;
		}

		$perCollection = 0;

		foreach ($buckets as $resources) {
			$perCollection += (int) ceil(count($resources) / self::MAX_BATCH);
		}

		$collections = self::MONTH / max(1, $intervalSeconds);
		$perMonth    = (int) ceil($perCollection * $collections);
		$monthlyCost = round($perMonth * self::PRICE_PER_CALL, 4);

		return new UsageEstimate($perCollection, $perMonth, $monthlyCost, self::PRICE_SOURCE, self::PRICE_CHECKED);
	}

	/**
	 * @param array<array-key, mixed> $metricBlock
	 * @return array{int, float}|null
	 */
	private static function latestPoint(array $metricBlock, string $aggregation): ?array {
		$field      = strtolower($aggregation);
		$timeseries = is_array($metricBlock['timeseries'] ?? null) ? $metricBlock['timeseries'] : [];
		$bestTs     = null;
		$bestVal    = null;

		foreach ($timeseries as $tsEntry) {
			if (!is_array($tsEntry) || !is_array($tsEntry['data'] ?? null)) {
				continue;
			}

			foreach ($tsEntry['data'] as $sample) {
				if (!is_array($sample)) {
					continue;
				}

				$timeStr = is_string($sample['timeStamp'] ?? null) ? $sample['timeStamp'] : '';
				$ts      = $timeStr !== '' ? strtotime($timeStr) : false;
				$raw     = $sample[$field] ?? null;

				if ($ts === false || !is_numeric($raw) || !is_finite((float) $raw)) {
					continue;
				}

				if ($bestTs === null || $ts > $bestTs) {
					$bestTs  = $ts;
					$bestVal = (float) $raw;
				}
			}
		}

		return $bestTs !== null && $bestVal !== null ? [$bestTs, $bestVal] : null;
	}

	/**
	 * @throws DriverError
	 */
	private function subscription(Account $account): string {
		$sub = $account->scope['subscription'] ?? '';

		if ($sub === '' || preg_match('/^' . self::UUID_RE . '$/', $sub) !== 1) {
			throw new DriverError(ErrorClass::Config, 'valid Azure subscription is required');
		}

		return $sub;
	}

	/**
	 * @return list<string>
	 */
	private function regions(Account $account): array {
		$raw = $account->scope['regions'] ?? '';

		if ($raw === '') {
			return [];
		}

		return array_values(array_filter(explode(',', $raw), static fn (string $r): bool => $r !== ''));
	}

	/**
	 * @param list<string> $regions
	 * @return array{?string, string}
	 */
	private function splitRegionAndArmId(string $providerId, array $regions): array {
		$pipe = strpos($providerId, '|');

		if ($pipe !== false) {
			$region = strtolower(substr($providerId, 0, $pipe));
			$armId  = substr($providerId, $pipe + 1);

			if (preg_match('/^' . self::REGION_RE . '$/', $region) === 1 && ($regions === [] || in_array($region, $regions, true))) {
				return [$region, $armId];
			}

			return [null, $armId];
		}

		return [$regions[0] ?? null, $providerId];
	}

	/**
	 * @throws DriverError
	 */
	private function tokenFor(Account $account, Secret $credential, string $plane): Secret {
		$now = $this->clock->now();

		if (isset($this->tokens[$plane]) && $this->tokens[$plane]['expiresAt'] > $now + 60) {
			return $this->tokens[$plane]['token'];
		}

		if ($account->credentialSource === CredentialSource::Instance) {
			$resource = $plane === 'arm' ? AzureAuth::ARM_RESOURCE : AzureAuth::METRICS_RES;
			$clientId = null;

			if (!$credential->isEmpty()) {
				$decoded = json_decode($credential->reveal(), true, 8);

				if (is_array($decoded) && is_string($decoded['client_id'] ?? null)) {
					$clientId = trim($decoded['client_id']);
				}
			}

			$result = AzureAuth::acquireManagedIdentityToken($this->http, $resource, $now, $clientId);
		} else {
			$tenant = $account->scope['tenant'] ?? '';

			try {
				$doc = json_decode($credential->reveal(), true, 16, JSON_THROW_ON_ERROR);
			} catch (\JsonException) {
				throw new DriverError(ErrorClass::Config, 'Azure credential must be JSON');
			}

			if (!is_array($doc)) {
				throw new DriverError(ErrorClass::Config, 'Azure credential must be a JSON object');
			}

			if (isset($doc['client_certificate']) || isset($doc['private_key'])) {
				throw new DriverError(ErrorClass::Config, 'Azure certificate assertions are not supported; use client_secret or managed identity');
			}

			$clientId     = is_string($doc['client_id'] ?? null) ? trim($doc['client_id']) : '';
			$clientSecret = is_string($doc['client_secret'] ?? null) ? $doc['client_secret'] : '';
			$scope        = $plane === 'arm' ? AzureAuth::ARM_SCOPE : AzureAuth::METRICS_SCOPE;

			$result = AzureAuth::acquireClientCredentialsToken($this->http, $tenant, $clientId, $clientSecret, $scope, $now);
		}

		$this->tokens[$plane] = $result;

		return $result['token'];
	}

	/**
	 * @return array<array-key, mixed>
	 * @throws DriverError
	 */
	private function sendJson(string $method, string $url, Secret $token, ?string $body = null): array {
		$headers = [
			'Authorization' => 'Bearer ' . $token->reveal(),
			'Accept'        => 'application/json',
		];

		if ($body !== null) {
			$headers['Content-Type'] = 'application/json';
		}

		try {
			$response = $this->http->send(new HttpRequest($method, $url, $headers, $body));
		} catch (TransportError $e) {
			throw DriverError::fromTransport($e);
		}

		$class = match (true) {
			$response->status === 200                              => null,
			$response->status === 401                              => ErrorClass::Auth,
			$response->status === 403                              => ErrorClass::Permission,
			$response->status === 429 || $response->status === 529 => ErrorClass::Throttled,
			$response->status >= 500                               => ErrorClass::Transient,
			default                                                => ErrorClass::Malformed,
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
			throw new DriverError(ErrorClass::Malformed, 'Azure response is not JSON');
		}

		if (!is_array($decoded)) {
			throw new DriverError(ErrorClass::Malformed, 'Azure response is not an object');
		}

		return $decoded;
	}

	/**
	 * Parse Retry-After seconds or HTTP-date header bounded to MAX_WAIT.
	 *
	 * @param HttpResponse $response throttled HTTP response
	 * @return int wait duration in seconds
	 */
	private function retryAfter(HttpResponse $response): int {
		$value = trim($response->header('retry-after'));
		$wait  = ctype_digit($value)
			? (int) $value
			: (($at = strtotime($value)) !== false && $value !== '' ? $at - $this->clock->now() : 0);

		return max(0, min(self::MAX_WAIT, $wait));
	}
}
