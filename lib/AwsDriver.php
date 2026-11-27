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
 * Amazon Web Services read-only driver: SigV4 signing, EC2/RDS/ELB discovery,
 * batched CloudWatch GetMetricData (up to 500 queries per call), STS
 * AssumeRole, and IMDSv2 instance identity strictly gated to Instance accounts.
 */
final class AwsDriver implements ProviderDriver {
	/**
	 * Resource type identifier for Amazon EC2 instances.
	 */
	public const TYPE_EC2 = 'ec2_instance';

	/**
	 * Resource type identifier for Amazon RDS DB instances.
	 */
	public const TYPE_RDS = 'rds_instance';

	/**
	 * Resource type identifier for AWS Elastic Load Balancers (ALB/NLB/GWLB).
	 */
	public const TYPE_ELB = 'aws_elb';

	/** CloudWatch GetMetricData accepts at most 500 MetricDataQueries per call. */
	public const MAX_BATCH = 500;

	/**
	 * Link-local AWS EC2 Instance Metadata Service (IMDSv2) host.
	 */
	private const IMDS_HOST     = '169.254.169.254';

	/**
	 * Upper bound on paginated requests per discovery or collection call.
	 */
	private const MAX_PAGES     = 200;

	/**
	 * Maximum bounded Retry-After wait duration in seconds.
	 */
	private const MAX_WAIT      = 3600;

	/**
	 * Nominal 30-day month in seconds for CloudWatch usage estimates.
	 */
	private const MONTH         = 2592000;

	/**
	 * CloudWatch GetMetricData list price in USD per requested metric.
	 */
	private const PRICE_PER_GMD = 0.00001;

	/**
	 * Public Amazon CloudWatch pricing reference URL.
	 */
	private const PRICE_SOURCE  = 'https://aws.amazon.com/cloudwatch/pricing/';

	/**
	 * Date the referenced AWS list pricing was last checked.
	 */
	private const PRICE_CHECKED = '2026-11-14';

	/**
	 * Regular expression fragment matching valid AWS region codes.
	 */
	private const REGION_RE     = '[a-z]{2}(?:-gov|-iso[a-z]?)?-[a-z]+-\d{1,2}';

	/**
	 * Metric catalog from docs/research/metric-catalogs.md.
	 * Row format: [resourceType, namespace, metricName, dimensionName, stat, convert].
	 *
	 * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
	 */
	private const METRICS = [
		'ec2_cpu_pct'     => [self::TYPE_EC2, 'AWS/EC2', 'CPUUtilization', 'InstanceId', 'Average', 'value'],
		'ec2_net_in_Bps'  => [self::TYPE_EC2, 'AWS/EC2', 'NetworkIn', 'InstanceId', 'Sum', 'rate'],
		'ec2_net_out_Bps' => [self::TYPE_EC2, 'AWS/EC2', 'NetworkOut', 'InstanceId', 'Sum', 'rate'],
		'ec2_pkt_in_ps'   => [self::TYPE_EC2, 'AWS/EC2', 'NetworkPacketsIn', 'InstanceId', 'Sum', 'rate'],
		'ec2_pkt_out_ps'  => [self::TYPE_EC2, 'AWS/EC2', 'NetworkPacketsOut', 'InstanceId', 'Sum', 'rate'],
		'ec2_ebs_rd_Bps'  => [self::TYPE_EC2, 'AWS/EC2', 'EBSReadBytes', 'InstanceId', 'Sum', 'rate'],
		'ec2_ebs_wr_Bps'  => [self::TYPE_EC2, 'AWS/EC2', 'EBSWriteBytes', 'InstanceId', 'Sum', 'rate'],
		'ec2_ebs_rd_ops'  => [self::TYPE_EC2, 'AWS/EC2', 'EBSReadOps', 'InstanceId', 'Sum', 'rate'],
		'ec2_ebs_wr_ops'  => [self::TYPE_EC2, 'AWS/EC2', 'EBSWriteOps', 'InstanceId', 'Sum', 'rate'],
		'ec2_dsk_rd_Bps'  => [self::TYPE_EC2, 'AWS/EC2', 'DiskReadBytes', 'InstanceId', 'Sum', 'rate'],
		'ec2_dsk_wr_Bps'  => [self::TYPE_EC2, 'AWS/EC2', 'DiskWriteBytes', 'InstanceId', 'Sum', 'rate'],
		'ec2_dsk_rd_ops'  => [self::TYPE_EC2, 'AWS/EC2', 'DiskReadOps', 'InstanceId', 'Sum', 'rate'],
		'ec2_dsk_wr_ops'  => [self::TYPE_EC2, 'AWS/EC2', 'DiskWriteOps', 'InstanceId', 'Sum', 'rate'],
		'ec2_status_fail' => [self::TYPE_EC2, 'AWS/EC2', 'StatusCheckFailed', 'InstanceId', 'Maximum', 'value'],
		'rds_cpu_pct'     => [self::TYPE_RDS, 'AWS/RDS', 'CPUUtilization', 'DBInstanceIdentifier', 'Average', 'value'],
		'rds_free_mem'    => [self::TYPE_RDS, 'AWS/RDS', 'FreeableMemory', 'DBInstanceIdentifier', 'Average', 'value'],
		'rds_db_conns'    => [self::TYPE_RDS, 'AWS/RDS', 'DatabaseConnections', 'DBInstanceIdentifier', 'Average', 'value'],
		'rds_read_iops'   => [self::TYPE_RDS, 'AWS/RDS', 'ReadIOPS', 'DBInstanceIdentifier', 'Average', 'value'],
		'rds_write_iops'  => [self::TYPE_RDS, 'AWS/RDS', 'WriteIOPS', 'DBInstanceIdentifier', 'Average', 'value'],
		'alb_req_ps'      => [self::TYPE_ELB, 'AWS/ApplicationELB', 'RequestCount', 'LoadBalancer', 'Sum', 'rate'],
		'alb_conn_act'    => [self::TYPE_ELB, 'AWS/ApplicationELB', 'ActiveConnectionCount', 'LoadBalancer', 'Sum', 'value'],
		'alb_bytes_ps'    => [self::TYPE_ELB, 'AWS/ApplicationELB', 'ProcessedBytes', 'LoadBalancer', 'Sum', 'rate'],
		'alb_t5xx_ps'     => [self::TYPE_ELB, 'AWS/ApplicationELB', 'HTTPCode_Target_5XX_Count', 'LoadBalancer', 'Sum', 'rate'],
		'alb_e5xx_ps'     => [self::TYPE_ELB, 'AWS/ApplicationELB', 'HTTPCode_ELB_5XX_Count', 'LoadBalancer', 'Sum', 'rate'],
		'alb_resp_s'      => [self::TYPE_ELB, 'AWS/ApplicationELB', 'TargetResponseTime', 'LoadBalancer', 'Average', 'value'],
		'nlb_flow_act'    => [self::TYPE_ELB, 'AWS/NetworkELB', 'ActiveFlowCount', 'LoadBalancer', 'Average', 'value'],
		'nlb_flow_new_ps' => [self::TYPE_ELB, 'AWS/NetworkELB', 'NewFlowCount', 'LoadBalancer', 'Sum', 'rate'],
		'nlb_bytes_ps'    => [self::TYPE_ELB, 'AWS/NetworkELB', 'ProcessedBytes', 'LoadBalancer', 'Sum', 'rate'],
		'nlb_pkts_ps'     => [self::TYPE_ELB, 'AWS/NetworkELB', 'ProcessedPackets', 'LoadBalancer', 'Sum', 'rate'],
		'nlb_tgt_rst_ps'  => [self::TYPE_ELB, 'AWS/NetworkELB', 'TCP_Target_Reset_Count', 'LoadBalancer', 'Sum', 'rate'],
	];

	/**
	 * Strict validation patterns for AWS resource identifiers by type.
	 */
	private const ID_PATTERNS = [
		self::TYPE_EC2 => '/^i-[0-9a-f]{8,17}$/',
		self::TYPE_RDS => '/^[a-zA-Z][a-zA-Z0-9-]{0,62}$/',
		self::TYPE_ELB => '/^(?:app|net|gwy)\/[A-Za-z0-9-]{1,64}\/[0-9a-f]{16}$/',
	];

	/**
	 * Construct an AWS driver bound to a guarded HTTP transport and clock.
	 *
	 * @param HttpTransport $http  guarded outbound transport
	 * @param Clock         $clock wall clock for SigV4 signing and metric windows
	 */
	public function __construct(private readonly HttpTransport $http, private readonly Clock $clock) {
	}

	/**
	 * Return Provider::Aws.
	 *
	 * @return Provider
	 */
	public function provider(): Provider {
		return Provider::Aws;
	}

	/**
	 * Build the outbound host allowlist for an AWS account's configured regions.
	 *
	 * @param Account $account target AWS account
	 * @return OutboundPolicy
	 */
	public function endpoints(Account $account): OutboundPolicy {
		$hosts = ['sts.amazonaws.com'];

		foreach ($this->regions($account) as $region) {
			$hosts[] = 'ec2.' . $region . '.amazonaws.com';
			$hosts[] = 'rds.' . $region . '.amazonaws.com';
			$hosts[] = 'elasticloadbalancing.' . $region . '.amazonaws.com';
			$hosts[] = 'monitoring.' . $region . '.amazonaws.com';
			$hosts[] = 'sts.' . $region . '.amazonaws.com';
		}

		$plain = $account->credentialSource === CredentialSource::Instance ? [self::IMDS_HOST] : [];

		return new OutboundPolicy(array_values(array_unique($hosts)), [443], $plain);
	}

	/**
	 * Return the fixed read-only AWS operations allowed through GuardedTransport.
	 *
	 * @return list<Operation>
	 */
	public function operations(): array {
		$region = self::REGION_RE;

		return [
			new Operation(
				'STS GetCallerIdentity',
				'POST',
				"#^sts(?:\.$region)?\.amazonaws\.com$#",
				'#^/$#',
				'/^Action=GetCallerIdentity&Version=2011-06-15$/'
			),
			new Operation(
				'STS AssumeRole',
				'POST',
				"#^sts(?:\.$region)?\.amazonaws\.com$#",
				'#^/$#',
				'/^Action=AssumeRole&Version=2011-06-15(&|$)/'
			),
			new Operation(
				'EC2 DescribeInstances',
				'POST',
				"#^ec2\.$region\.amazonaws\.com$#",
				'#^/$#',
				'/^Action=DescribeInstances&Version=2016-11-15(&|$)/'
			),
			new Operation(
				'RDS DescribeDBInstances',
				'POST',
				"#^rds\.$region\.amazonaws\.com$#",
				'#^/$#',
				'/^Action=DescribeDBInstances&Version=2014-10-31(&|$)/'
			),
			new Operation(
				'ELB DescribeLoadBalancers',
				'POST',
				"#^elasticloadbalancing\.$region\.amazonaws\.com$#",
				'#^/$#',
				'/^Action=DescribeLoadBalancers&Version=2015-12-01(&|$)/'
			),
			new Operation(
				'CloudWatch GetMetricData',
				'POST',
				"#^monitoring\.$region\.amazonaws\.com$#",
				'#^/$#',
				'/^Action=GetMetricData&Version=2010-08-01(&|$)/'
			),
			new Operation(
				'IMDSv2 Put Token',
				'PUT',
				self::IMDS_HOST,
				'#^/latest/api/token$#',
				'/^$/'
			),
			new Operation(
				'IMDSv2 List IAM Role',
				'GET',
				self::IMDS_HOST,
				'#^/latest/meta-data/iam/security-credentials/$#'
			),
			new Operation(
				'IMDSv2 Read IAM Credentials',
				'GET',
				self::IMDS_HOST,
				'#^/latest/meta-data/iam/security-credentials/[A-Za-z0-9+=,.@_-]{1,128}$#'
			),
		];
	}

	/**
	 * Verify AWS credentials via STS GetCallerIdentity and EC2 DescribeInstances.
	 *
	 * @param Account $account    target AWS account
	 * @param Secret  $credential IAM JSON credentials or empty for Instance
	 * @return VerifyStatus
	 */
	public function verify(Account $account, Secret $credential): VerifyStatus {
		try {
			$regions = $this->regions($account);

			if ($regions === []) {
				return VerifyStatus::Config;
			}

			$creds = $this->resolveCredentials($account, $credential, $regions[0]);
			$xml   = $this->postQuery(
				'sts.' . $regions[0] . '.amazonaws.com',
				$regions[0],
				'sts',
				['Action' => 'GetCallerIdentity', 'Version' => '2011-06-15'],
				$creds
			);

			$callerAccount = self::xmlText($xml, 'GetCallerIdentityResult', 'Account');
			$expectedId    = $account->scope['account_id'] ?? '';

			if ($expectedId !== '' && $callerAccount !== $expectedId) {
				return VerifyStatus::Permission;
			}

			$this->postQuery(
				'ec2.' . $regions[0] . '.amazonaws.com',
				$regions[0],
				'ec2',
				['Action' => 'DescribeInstances', 'Version' => '2016-11-15', 'MaxResults' => '5'],
				$creds
			);

			return VerifyStatus::Ok;
		} catch (DriverError $error) {
			return VerifyStatus::fromError($error->errorClass);
		}
	}

	/**
	 * Discover EC2, RDS, and ELB resources across all configured AWS regions.
	 *
	 * @param Account $account    target AWS account
	 * @param Secret  $credential IAM JSON credentials or empty for Instance
	 * @return Inventory
	 * @throws DriverError
	 */
	public function discover(Account $account, Secret $credential): Inventory {
		$regions = $this->regions($account);

		if ($regions === []) {
			throw new DriverError(ErrorClass::Config, 'no AWS regions configured');
		}

		$creds     = $this->resolveCredentials($account, $credential, $regions[0]);
		$resources = [];
		$requests  = 0;

		foreach ($regions as $region) {
			[$ec2, $n] = $this->discoverEc2($region, $creds);
			$resources = array_merge($resources, $ec2);
			$requests += $n;

			[$rds, $n] = $this->discoverRds($region, $creds);
			$resources = array_merge($resources, $rds);
			$requests += $n;

			[$elb, $n] = $this->discoverElb($region, $creds);
			$resources = array_merge($resources, $elb);
			$requests += $n;
		}

		return new Inventory($resources, $requests);
	}

	/**
	 * Collect CloudWatch metrics in batches of up to MAX_BATCH queries per region.
	 *
	 * @param Account             $account    target AWS account
	 * @param Secret              $credential IAM JSON credentials or empty for Instance
	 * @param list<MetricRequest> $requests   metric series to collect
	 * @return CollectResult
	 */
	public function collect(Account $account, Secret $credential, array $requests): CollectResult {
		if ($requests === []) {
			return new CollectResult([], 0);
		}

		$regions = $this->regions($account);

		if ($regions === []) {
			return new CollectResult([], 0, ErrorClass::Config);
		}

		try {
			$creds = $this->resolveCredentials($account, $credential, $regions[0]);
		} catch (DriverError $e) {
			return new CollectResult([], 0, $e->errorClass, $e->retryAfter);
		}

		$period  = max(60, (int) (intdiv($account->collectInterval, 60) * 60));
		$now     = $this->clock->now();
		$endTime = intdiv($now, $period) * $period;
		$start   = $endTime - max($period * 2, 600);

		/** @var array<string, list<array{request: MetricRequest, id: string, spec: array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}}>> $byRegion */
		$byRegion = [];
		$error    = null;

		foreach ($requests as $request) {
			$spec = self::METRICS[$request->metric] ?? null;

			if ($spec === null || $spec[0] !== $request->resourceType) {
				$error ??= ErrorClass::Config;

				continue;
			}

			[$region, $providerId] = $this->splitRegionAndId($request->providerId, $regions);
			$pattern               = self::ID_PATTERNS[$request->resourceType];

			if ($region === null || preg_match($pattern, $providerId) !== 1) {
				$error ??= ErrorClass::Config;

				continue;
			}

			$byRegion[$region][] = [
				'request' => $request,
				'id'      => $providerId,
				'spec'    => $spec,
			];
		}

		$observations = [];
		$sent         = 0;
		$retryAfter   = 0;

		foreach ($byRegion as $region => $items) {
			foreach (array_chunk($items, self::MAX_BATCH) as $chunk) {
				$queryMap = [];
				$params   = [
					'Action'    => 'GetMetricData',
					'Version'   => '2010-08-01',
					'StartTime' => gmdate('Y-m-d\TH:i:s\Z', $start),
					'EndTime'   => gmdate('Y-m-d\TH:i:s\Z', $endTime),
					'ScanBy'    => 'TimestampDescending',
				];

				foreach ($chunk as $idx => $entry) {
					$n             = $idx + 1;
					$qId           = 'm' . $n;
					$queryMap[$qId] = $entry;
					$prefix        = "MetricDataQueries.member.$n";

					$params[$prefix . '.Id']                                       = $qId;
					$params[$prefix . '.MetricStat.Metric.Namespace']              = $entry['spec'][1];
					$params[$prefix . '.MetricStat.Metric.MetricName']             = $entry['spec'][2];
					$params[$prefix . '.MetricStat.Metric.Dimensions.member.1.Name']  = $entry['spec'][3];
					$params[$prefix . '.MetricStat.Metric.Dimensions.member.1.Value'] = $entry['id'];
					$params[$prefix . '.MetricStat.Period']                        = (string) $period;
					$params[$prefix . '.MetricStat.Stat']                          = $entry['spec'][4];
				}

				/** @var array<string, array{int, float}> $latest */
				$latest    = [];
				$nextToken = null;
				$stopped   = false;

				for ($page = 1; $page <= self::MAX_PAGES; $page++) {
					$pageParams = $nextToken === null ? $params : $params + ['NextToken' => $nextToken];

					try {
						$sent++;
						$xml = $this->postQuery(
							'monitoring.' . $region . '.amazonaws.com',
							$region,
							'monitoring',
							$pageParams,
							$creds
						);
					} catch (DriverError $e) {
						$stops      = in_array($e->errorClass, [ErrorClass::Auth, ErrorClass::Permission, ErrorClass::Throttled], true);
						$error      = $stops || $error === null ? $e->errorClass : $error;
						$retryAfter = $e->retryAfter;
						$stopped    = $stops;

						break;
					}

					$result = $xml->GetMetricDataResult ?? null;

					if ($result instanceof \SimpleXMLElement && isset($result->MetricDataResults->member)) {
						foreach ($result->MetricDataResults->member as $member) {
							$id         = trim((string) ($member->Id ?? ''));
							$statusCode = trim((string) ($member->StatusCode ?? ''));

							if ($statusCode === 'InternalError' || $statusCode === 'Forbidden') {
								$error ??= $statusCode === 'Forbidden' ? ErrorClass::Permission : ErrorClass::Transient;
							}

							if ($id === '' || !isset($queryMap[$id])) {
								continue;
							}

							$timestamps = [];
							$values     = [];

							if (isset($member->Timestamps->member)) {
								foreach ($member->Timestamps->member as $ts) {
									$parsed = strtotime(trim((string) $ts));

									if ($parsed !== false) {
										$timestamps[] = $parsed;
									}
								}
							}

							if (isset($member->Values->member)) {
								foreach ($member->Values->member as $val) {
									$raw = trim((string) $val);

									if (is_numeric($raw) && is_finite((float) $raw)) {
										$values[] = (float) $raw;
									}
								}
							}

							$count = min(count($timestamps), count($values));

							for ($i = 0; $i < $count; $i++) {
								if (!isset($latest[$id]) || $timestamps[$i] > $latest[$id][0]) {
									$latest[$id] = [$timestamps[$i], $values[$i]];
								}
							}
						}
					}

					$nextToken = $result instanceof \SimpleXMLElement ? trim((string) ($result->NextToken ?? '')) : '';

					if ($nextToken === '') {
						break;
					}
				}

				foreach ($latest as $qId => [$observedAt, $rawValue]) {
					$entry          = $queryMap[$qId];
					$converted      = $entry['spec'][5] === 'rate' ? $rawValue / $period : $rawValue;
					$observations[] = new Observation(
						$entry['request']->resourceId,
						$entry['request']->metric,
						$converted,
						$observedAt,
						$this->clock->now()
					);
				}

				if ($stopped) {
					return new CollectResult($observations, $sent, $error, $retryAfter);
				}
			}
		}

		return new CollectResult($observations, $sent, $error, $retryAfter);
	}

	/**
	 * CloudWatch GetMetricData charges $0.01 per 1,000 metrics requested with
	 * no free tier, batched up to 500 metrics per call.
	 */
	public function estimate(array $requests, int $intervalSeconds): UsageEstimate {
		$count = count($requests);

		if ($count === 0) {
			return new UsageEstimate(0, 0, 0.0, self::PRICE_SOURCE, self::PRICE_CHECKED);
		}

		$perCollection = (int) ceil($count / self::MAX_BATCH);
		$collections   = self::MONTH / max(1, $intervalSeconds);
		$perMonth      = (int) ceil($perCollection * $collections);
		$monthlyCost   = round($count * $collections * self::PRICE_PER_GMD, 4);

		return new UsageEstimate($perCollection, $perMonth, $monthlyCost, self::PRICE_SOURCE, self::PRICE_CHECKED);
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
	private function splitRegionAndId(string $providerId, array $regions): array {
		$slash = strpos($providerId, '/');

		if ($slash !== false) {
			$prefix = substr($providerId, 0, $slash);

			if (in_array($prefix, $regions, true)) {
				return [$prefix, substr($providerId, $slash + 1)];
			}
		}

		return [$regions[0] ?? null, $providerId];
	}

	/**
	 * @return array{accessKey: string, secretKey: Secret, sessionToken: ?Secret}
	 * @throws DriverError
	 */
	private function resolveCredentials(Account $account, Secret $credential, string $stsRegion): array {
		if ($account->credentialSource === CredentialSource::Instance) {
			return $this->fetchImdsV2Credentials();
		}

		$raw = $credential->reveal();

		try {
			$decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new DriverError(ErrorClass::Config, 'AWS credential must be JSON');
		}

		if (!is_array($decoded)) {
			throw new DriverError(ErrorClass::Config, 'AWS credential must be a JSON object');
		}

		$accessKey = is_string($decoded['access_key_id'] ?? null) ? trim($decoded['access_key_id']) : '';
		$secretKey = is_string($decoded['secret_access_key'] ?? null) ? trim($decoded['secret_access_key']) : '';
		$token     = is_string($decoded['session_token'] ?? null) && $decoded['session_token'] !== ''
			? new Secret($decoded['session_token'])
			: null;

		if ($accessKey === '' || $secretKey === '') {
			throw new DriverError(ErrorClass::Config, 'AWS access_key_id and secret_access_key are required');
		}

		$creds = [
			'accessKey'    => $accessKey,
			'secretKey'    => new Secret($secretKey),
			'sessionToken' => $token,
		];

		$roleArn = is_string($decoded['role_arn'] ?? null) ? trim($decoded['role_arn']) : '';

		if ($roleArn !== '') {
			if (preg_match('/^arn:aws(?:-[a-z-]+)?:iam::\d{12}:role\/[A-Za-z0-9+=,.@_\/-]{1,512}$/', $roleArn) !== 1) {
				throw new DriverError(ErrorClass::Config, 'invalid AWS role_arn');
			}

			$externalId  = is_string($decoded['external_id'] ?? null) ? trim($decoded['external_id']) : '';
			$sessionName = is_string($decoded['role_session_name'] ?? null) && $decoded['role_session_name'] !== ''
				? trim($decoded['role_session_name'])
				: 'cacti-cloud-readonly';

			return $this->assumeRole($stsRegion, $creds, $roleArn, $sessionName, $externalId);
		}

		return $creds;
	}

	/**
	 * @return array{accessKey: string, secretKey: Secret, sessionToken: ?Secret}
	 * @throws DriverError
	 */
	private function fetchImdsV2Credentials(): array {
		try {
			$tokenResp = $this->http->send(new HttpRequest(
				'PUT',
				OutboundPolicy::metadataUrl(self::IMDS_HOST, '/latest/api/token'),
				['X-aws-ec2-metadata-token-ttl-seconds' => '21600'],
				''
			));
		} catch (TransportError $e) {
			throw DriverError::fromTransport($e);
		}

		if ($tokenResp->status !== 200 || trim($tokenResp->body) === '') {
			throw new DriverError(ErrorClass::Auth, 'IMDSv2 token unavailable');
		}

		$imdsToken = trim($tokenResp->body);

		try {
			$roleResp = $this->http->send(new HttpRequest(
				'GET',
				OutboundPolicy::metadataUrl(self::IMDS_HOST, '/latest/meta-data/iam/security-credentials/'),
				['X-aws-ec2-metadata-token' => $imdsToken]
			));
		} catch (TransportError $e) {
			throw DriverError::fromTransport($e);
		}

		$roleName = trim(explode("\n", $roleResp->body)[0]);

		if ($roleResp->status !== 200 || preg_match('/^[A-Za-z0-9+=,.@_-]{1,128}$/', $roleName) !== 1) {
			throw new DriverError(ErrorClass::Auth, 'IMDSv2 role unavailable');
		}

		try {
			$credResp = $this->http->send(new HttpRequest(
				'GET',
				OutboundPolicy::metadataUrl(self::IMDS_HOST, '/latest/meta-data/iam/security-credentials/' . $roleName),
				['X-aws-ec2-metadata-token' => $imdsToken]
			));
		} catch (TransportError $e) {
			throw DriverError::fromTransport($e);
		}

		if ($credResp->status !== 200) {
			throw new DriverError(ErrorClass::Auth, 'IMDSv2 credentials unavailable');
		}

		try {
			$doc = json_decode($credResp->body, true, 16, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new DriverError(ErrorClass::Malformed, 'IMDSv2 credentials not JSON');
		}

		if (
			!is_array($doc)
			|| !is_string($doc['AccessKeyId'] ?? null)
			|| !is_string($doc['SecretAccessKey'] ?? null)
			|| !is_string($doc['Token'] ?? null)
		) {
			throw new DriverError(ErrorClass::Malformed, 'IMDSv2 credential document incomplete');
		}

		return [
			'accessKey'    => $doc['AccessKeyId'],
			'secretKey'    => new Secret($doc['SecretAccessKey']),
			'sessionToken' => new Secret($doc['Token']),
		];
	}

	/**
	 * @param array{accessKey: string, secretKey: Secret, sessionToken: ?Secret} $base
	 * @return array{accessKey: string, secretKey: Secret, sessionToken: ?Secret}
	 * @throws DriverError
	 */
	private function assumeRole(
		string $region,
		array $base,
		string $roleArn,
		string $sessionName,
		string $externalId
	): array {
		$params = [
			'Action'          => 'AssumeRole',
			'Version'         => '2011-06-15',
			'RoleArn'         => $roleArn,
			'RoleSessionName' => $sessionName,
			'DurationSeconds' => '900',
		];

		if ($externalId !== '') {
			$params['ExternalId'] = $externalId;
		}

		$xml = $this->postQuery('sts.' . $region . '.amazonaws.com', $region, 'sts', $params, $base);

		$creds     = $xml->AssumeRoleResult->Credentials ?? null;
		$accessKey = $creds instanceof \SimpleXMLElement ? trim((string) ($creds->AccessKeyId ?? '')) : '';
		$secretKey = $creds instanceof \SimpleXMLElement ? trim((string) ($creds->SecretAccessKey ?? '')) : '';
		$token     = $creds instanceof \SimpleXMLElement ? trim((string) ($creds->SessionToken ?? '')) : '';

		if ($accessKey === '' || $secretKey === '' || $token === '') {
			throw new DriverError(ErrorClass::Malformed, 'STS AssumeRole response missing credentials');
		}

		return [
			'accessKey'    => $accessKey,
			'secretKey'    => new Secret($secretKey),
			'sessionToken' => new Secret($token),
		];
	}

	/**
	 * @param array{accessKey: string, secretKey: Secret, sessionToken: ?Secret} $creds
	 * @return array{list<CloudResource>, int}
	 * @throws DriverError
	 */
	private function discoverEc2(string $region, array $creds): array {
		$resources = [];
		$requests  = 0;
		$nextToken = null;

		for ($page = 1; $page <= self::MAX_PAGES; $page++) {
			$params = ['Action' => 'DescribeInstances', 'Version' => '2016-11-15', 'MaxResults' => '100'];

			if ($nextToken !== null) {
				$params['NextToken'] = $nextToken;
			}

			$xml = $this->postQuery('ec2.' . $region . '.amazonaws.com', $region, 'ec2', $params, $creds);
			$requests++;

			if (isset($xml->reservationSet->item)) {
				foreach ($xml->reservationSet->item as $reservation) {
					if (!isset($reservation->instancesSet->item)) {
						continue;
					}

					foreach ($reservation->instancesSet->item as $instance) {
						$id    = trim((string) ($instance->instanceId ?? ''));
						$state = trim((string) ($instance->instanceState->name ?? ''));

						if ($id === '' || $state === 'terminated') {
							continue;
						}

						if (preg_match(self::ID_PATTERNS[self::TYPE_EC2], $id) !== 1) {
							throw new DriverError(ErrorClass::Malformed, 'invalid EC2 instance id');
						}

						$name = $id;

						if (isset($instance->tagSet->item)) {
							foreach ($instance->tagSet->item as $tag) {
								if (trim((string) ($tag->key ?? '')) === 'Name') {
									$val = trim((string) ($tag->value ?? ''));

									if ($val !== '') {
										$name = $val;
									}
								}
							}
						}

						$resources[] = new CloudResource(self::TYPE_EC2, $region . '/' . $id, $name, $region);
					}
				}
			}

			$nextToken = trim((string) ($xml->nextToken ?? ''));

			if ($nextToken === '') {
				return [$resources, $requests];
			}
		}

		throw new DriverError(ErrorClass::Malformed, 'EC2 DescribeInstances exceeded max pages');
	}

	/**
	 * @param array{accessKey: string, secretKey: Secret, sessionToken: ?Secret} $creds
	 * @return array{list<CloudResource>, int}
	 * @throws DriverError
	 */
	private function discoverRds(string $region, array $creds): array {
		$resources = [];
		$requests  = 0;
		$marker    = null;

		for ($page = 1; $page <= self::MAX_PAGES; $page++) {
			$params = ['Action' => 'DescribeDBInstances', 'Version' => '2014-10-31', 'MaxRecords' => '100'];

			if ($marker !== null) {
				$params['Marker'] = $marker;
			}

			$xml = $this->postQuery('rds.' . $region . '.amazonaws.com', $region, 'rds', $params, $creds);
			$requests++;

			$result = $xml->DescribeDBInstancesResult ?? null;

			if ($result instanceof \SimpleXMLElement && isset($result->DBInstances->DBInstance)) {
				foreach ($result->DBInstances->DBInstance as $db) {
					$id = trim((string) ($db->DBInstanceIdentifier ?? ''));

					if ($id === '') {
						continue;
					}

					if (preg_match(self::ID_PATTERNS[self::TYPE_RDS], $id) !== 1) {
						throw new DriverError(ErrorClass::Malformed, 'invalid RDS instance identifier');
					}

					$resources[] = new CloudResource(self::TYPE_RDS, $region . '/' . $id, $id, $region);
				}
			}

			$marker = $result instanceof \SimpleXMLElement ? trim((string) ($result->Marker ?? '')) : '';

			if ($marker === '') {
				return [$resources, $requests];
			}
		}

		throw new DriverError(ErrorClass::Malformed, 'RDS DescribeDBInstances exceeded max pages');
	}

	/**
	 * @param array{accessKey: string, secretKey: Secret, sessionToken: ?Secret} $creds
	 * @return array{list<CloudResource>, int}
	 * @throws DriverError
	 */
	private function discoverElb(string $region, array $creds): array {
		$resources = [];
		$requests  = 0;
		$marker    = null;

		for ($page = 1; $page <= self::MAX_PAGES; $page++) {
			$params = ['Action' => 'DescribeLoadBalancers', 'Version' => '2015-12-01', 'PageSize' => '100'];

			if ($marker !== null) {
				$params['Marker'] = $marker;
			}

			$xml = $this->postQuery(
				'elasticloadbalancing.' . $region . '.amazonaws.com',
				$region,
				'elasticloadbalancing',
				$params,
				$creds
			);
			$requests++;

			$result = $xml->DescribeLoadBalancersResult ?? null;

			if ($result instanceof \SimpleXMLElement && isset($result->LoadBalancers->member)) {
				foreach ($result->LoadBalancers->member as $lb) {
					$arn  = trim((string) ($lb->LoadBalancerArn ?? ''));
					$name = trim((string) ($lb->LoadBalancerName ?? ''));

					if (preg_match('/:loadbalancer\/((?:app|net|gwy)\/[A-Za-z0-9-]{1,64}\/[0-9a-f]{16})$/', $arn, $m) !== 1) {
						continue;
					}

					$dimId       = $m[1];
					$resources[] = new CloudResource(self::TYPE_ELB, $region . '/' . $dimId, $name !== '' ? $name : $dimId, $region);
				}
			}

			$marker = $result instanceof \SimpleXMLElement ? trim((string) ($result->NextMarker ?? '')) : '';

			if ($marker === '') {
				return [$resources, $requests];
			}
		}

		throw new DriverError(ErrorClass::Malformed, 'ELB DescribeLoadBalancers exceeded max pages');
	}

	/**
	 * @param array<string, string>                                              $params
	 * @param array{accessKey: string, secretKey: Secret, sessionToken: ?Secret} $creds
	 * @throws DriverError
	 */
	private function postQuery(
		string $host,
		string $region,
		string $service,
		array $params,
		array $creds
	): \SimpleXMLElement {
		$body    = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
		$now     = (new \DateTimeImmutable('@' . $this->clock->now()))->setTimezone(new \DateTimeZone('UTC'));
		$request = AwsSigV4::signRequest(
			new HttpRequest(
				'POST',
				'https://' . $host . '/',
				['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8'],
				$body
			),
			$creds['accessKey'],
			$creds['secretKey']->reveal(),
			$creds['sessionToken']?->reveal(),
			$region,
			$service,
			$now
		);

		try {
			$response = $this->http->send($request);
		} catch (TransportError $e) {
			throw DriverError::fromTransport($e);
		}

		if ($response->status !== 200) {
			throw $this->mapError($response);
		}

		return self::parseXml($response->body);
	}

	/**
	 * Map a non-200 AWS XML error response to a classified DriverError.
	 *
	 * @param HttpResponse $response non-200 HTTP response
	 * @return DriverError
	 */
	private function mapError(HttpResponse $response): DriverError {
		$code = '';

		if ($response->body !== '') {
			$prev = libxml_use_internal_errors(true);

			try {
				$xml = simplexml_load_string($response->body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

				if ($xml instanceof \SimpleXMLElement) {
					$code = trim((string) ($xml->Error->Code ?? $xml->Errors->Error->Code ?? ''));
				}
			} finally {
				libxml_clear_errors();
				libxml_use_internal_errors($prev);
			}
		}

		$class = match (true) {
			in_array($code, ['InvalidClientTokenId', 'SignatureDoesNotMatch', 'ExpiredToken', 'ExpiredTokenException', 'AuthFailure'], true) => ErrorClass::Auth,
			in_array($code, ['AccessDenied', 'AccessDeniedException', 'UnauthorizedOperation'], true) => ErrorClass::Permission,
			in_array($code, ['Throttling', 'ThrottlingException', 'RequestLimitExceeded', 'EC2ThrottledException'], true) => ErrorClass::Throttled,
			$response->status === 401 => ErrorClass::Auth,
			$response->status === 403 => ErrorClass::Permission,
			$response->status === 429 => ErrorClass::Throttled,
			$response->status >= 500  => ErrorClass::Transient,
			default                   => ErrorClass::Malformed,
		};

		return new DriverError(
			$class,
			'HTTP ' . $response->status,
			$class === ErrorClass::Throttled ? $this->retryAfter($response) : 0
		);
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

	/**
	 * Parse an AWS XML response body with network entity loading disabled.
	 *
	 * @param string $body raw XML response body
	 * @return \SimpleXMLElement
	 * @throws DriverError
	 */
	private static function parseXml(string $body): \SimpleXMLElement {
		$prev = libxml_use_internal_errors(true);

		try {
			$xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

			if (!$xml instanceof \SimpleXMLElement) {
				throw new DriverError(ErrorClass::Malformed, 'AWS response is not valid XML');
			}

			return $xml;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($prev);
		}
	}

	/**
	 * Extract trimmed text content from a two-level XML child path.
	 *
	 * @param \SimpleXMLElement $xml   root XML element
	 * @param string            $child parent child element name
	 * @param string            $leaf  leaf element name
	 * @return string trimmed element text, or empty string when missing
	 */
	private static function xmlText(\SimpleXMLElement $xml, string $child, string $leaf): string {
		$node = $xml->{$child} ?? null;

		if (!$node instanceof \SimpleXMLElement) {
			return '';
		}

		return trim((string) ($node->{$leaf} ?? ''));
	}
}
