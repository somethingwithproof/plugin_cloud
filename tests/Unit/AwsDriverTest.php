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
use Somethingwithproof\Cloud\AwsDriver;
use Somethingwithproof\Cloud\AwsSigV4;
use Somethingwithproof\Cloud\DriverError;
use Somethingwithproof\Cloud\ErrorClass;
use Somethingwithproof\Cloud\FixedClock;
use Somethingwithproof\Cloud\GuardedTransport;
use Somethingwithproof\Cloud\HttpRequest;
use Somethingwithproof\Cloud\HttpResponse;
use Somethingwithproof\Cloud\HttpTransport;
use Somethingwithproof\Cloud\MetricRequest;
use Somethingwithproof\Cloud\OutboundPolicy;
use Somethingwithproof\Cloud\RequestBudget;
use Somethingwithproof\Cloud\Secret;
use Somethingwithproof\Cloud\VerifyStatus;

const CLOUD_AWS_NOW = 1731542400;

function cloud_aws_account(array $override = []): Account {
	return Account::fromRow($override + [
		'id'                => 21,
		'provider'          => 'aws',
		'name'              => 'AWS Prod',
		'poller_id'         => 1,
		'scope'             => json_encode(['account_id' => '123456789012', 'regions' => 'us-east-1']),
		'credential_source' => 'vault',
		'collect_interval'  => 300,
		'discover_interval' => 3600,
		'request_share'     => 50,
		'admin_state'       => 'enabled',
	]);
}

function cloud_aws_cred(array $extra = []): Secret {
	return new Secret((string) json_encode($extra + [
		'access_key_id'     => 'AKIAIOSFODNN7EXAMPLE',
		'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
	]));
}

/**
 * @return array{AwsDriver, object{sent: list<HttpRequest>}}
 */
function cloud_aws_driver(Account $account, Closure $answer): array {
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

	$probe = new AwsDriver($inner, new FixedClock(CLOUD_AWS_NOW));
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

	return [new AwsDriver($guard, new FixedClock(CLOUD_AWS_NOW)), $inner];
}

it('matches official AWS SigV4 IAM User Guide signing key and canonical request vectors', function () {
	// Official IAM User Guide signing key vector (20120215 / us-east-1 / iam).
	$derived = AwsSigV4::signingKey(
		'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
		'20120215',
		'us-east-1',
		'iam'
	);

	expect(bin2hex($derived))->toBe('f4780e2d9f65fa895f9c67b32ce1baf0b0d8a43505a000a1a9e090d414db404d');

	// Official AWS SigV4 get-vanilla test vector (20150830T123600Z / us-east-1 / service).
	$signed = AwsSigV4::sign(
		'GET',
		'/',
		'',
		[['Host', 'example.amazonaws.com']],
		'',
		'AKIDEXAMPLE',
		'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
		null,
		'us-east-1',
		'service',
		new DateTimeImmutable('2015-08-30T12:36:00Z')
	);

	expect($signed['canonical'])->toBe(
		"GET\n/\n\nhost:example.amazonaws.com\nx-amz-date:20150830T123600Z\n\nhost;x-amz-date\n"
			. 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'
	)
		->and($signed['signature'])->toBe('5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31')
		->and($signed['authorization'])->toBe(
			'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, '
				. 'SignedHeaders=host;x-amz-date, Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31'
		);

	// Official IAM User Guide ListUsers request vector (20150830T123600Z / us-east-1 / iam).
	$iam = AwsSigV4::sign(
		'GET',
		'/',
		'Action=ListUsers&Version=2010-05-08',
		[
			['Content-Type', 'application/x-www-form-urlencoded; charset=utf-8'],
			['Host', 'iam.amazonaws.com'],
		],
		'',
		'AKIDEXAMPLE',
		'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
		null,
		'us-east-1',
		'iam',
		new DateTimeImmutable('2015-08-30T12:36:00Z')
	);

	expect($iam['signature'])->toBe('5d672d79c15b13162d9279b0855cfba6789a8edb4c82c400e06b5924a6f2b5d7');
});

it('signs temporary session tokens into X-Amz-Security-Token and SignedHeaders', function () {
	$req = AwsSigV4::signRequest(
		new HttpRequest('POST', 'https://monitoring.us-east-1.amazonaws.com/', ['Content-Type' => 'application/x-www-form-urlencoded'], 'Action=GetMetricData'),
		'ASIAEXAMPLE',
		'secret',
		'FwoGZXIvYXdzEXAMPLE',
		'us-east-1',
		'monitoring',
		new DateTimeImmutable('@' . CLOUD_AWS_NOW)
	);

	expect($req->headers['X-Amz-Security-Token'] ?? null)->toBe('FwoGZXIvYXdzEXAMPLE')
		->and($req->headers['Authorization'] ?? '')->toContain('x-amz-security-token')
		->and($req->headers['X-Amz-Content-Sha256'] ?? '')->toBe(hash('sha256', 'Action=GetMetricData'));
});

it('enforces a strict read-only AWS operation allowlist', function () {
	$account = cloud_aws_account();
	$probe   = new AwsDriver(
		new class () implements HttpTransport {
			public function send(HttpRequest $request): HttpResponse {
				return new HttpResponse(200, [], '');
			}
		},
		new FixedClock(CLOUD_AWS_NOW)
	);
	$guard = new GuardedTransport(
		new class () implements HttpTransport {
			public function send(HttpRequest $request): HttpResponse {
				return new HttpResponse(200, [], '');
			}
		},
		$probe->operations()
	);
	$form = ['Content-Type' => 'application/x-www-form-urlencoded'];

	expect($guard->allows(new HttpRequest('POST', 'https://ec2.us-east-1.amazonaws.com/', $form, 'Action=DescribeInstances&Version=2016-11-15')))->not->toBeNull()
		->and($guard->allows(new HttpRequest('POST', 'https://monitoring.us-east-1.amazonaws.com/', $form, 'Action=GetMetricData&Version=2010-08-01')))->not->toBeNull()
		->and($guard->allows(new HttpRequest('POST', 'https://ec2.us-east-1.amazonaws.com/', $form, 'Action=TerminateInstances&Version=2016-11-15')))->toBeNull()
		->and($guard->allows(new HttpRequest('POST', 'https://monitoring.us-east-1.amazonaws.com/', $form, 'Action=PutMetricAlarm&Version=2010-08-01')))->toBeNull()
		->and($guard->allows(new HttpRequest('POST', 'https://rds.us-east-1.amazonaws.com/', $form, 'Action=DeleteDBInstance&Version=2014-10-31')))->toBeNull()
		->and($guard->allows(new HttpRequest('POST', 'https://monitoring.us-east-1.amazonaws.com/', $form, 'Action=GetMetricData&Version=2010-08-01&Action=DeleteAlarms')))->toBeNull();

	// Non-Instance accounts must never allow IMDSv2 link-local HTTP.
	expect(fn () => $probe->endpoints($account)->check('http://169.254.169.254/latest/api/token'))
		->toThrow(\Somethingwithproof\Cloud\TransportError::class);
});

it('discovers EC2, RDS, and ELB resources across regions with pagination', function () {
	$account = cloud_aws_account();

	[$driver, $http] = cloud_aws_driver($account, static function (HttpRequest $req): HttpResponse {
		parse_str($req->body ?? '', $params);
		$action = $params['Action'] ?? '';

		if ($action === 'DescribeInstances') {
			return new HttpResponse(200, [], '<DescribeInstancesResponse><reservationSet><item><instancesSet>'
				. '<item><instanceId>i-0123456789abcdef0</instanceId><instanceState><name>running</name></instanceState>'
				. '<tagSet><item><key>Name</key><value>web-01</value></item></tagSet></item>'
				. '<item><instanceId>i-deadbeefdeadbeef0</instanceId><instanceState><name>terminated</name></instanceState></item>'
				. '</instancesSet></item></reservationSet></DescribeInstancesResponse>');
		}

		if ($action === 'DescribeDBInstances') {
			return new HttpResponse(200, [], '<DescribeDBInstancesResponse><DescribeDBInstancesResult><DBInstances>'
				. '<DBInstance><DBInstanceIdentifier>prod-db-1</DBInstanceIdentifier></DBInstance>'
				. '</DBInstances></DescribeDBInstancesResult></DescribeDBInstancesResponse>');
		}

		return new HttpResponse(200, [], '<DescribeLoadBalancersResponse><DescribeLoadBalancersResult><LoadBalancers>'
			. '<member><LoadBalancerArn>arn:aws:elasticloadbalancing:us-east-1:123456789012:loadbalancer/app/web-alb/0123456789abcdef</LoadBalancerArn>'
			. '<LoadBalancerName>web-alb</LoadBalancerName></member>'
			. '</LoadBalancers></DescribeLoadBalancersResult></DescribeLoadBalancersResponse>');
	});

	$inventory = $driver->discover($account, cloud_aws_cred());

	expect($inventory->requests)->toBe(3)
		->and($inventory->resources)->toHaveCount(3)
		->and($inventory->resources[0]->type)->toBe(AwsDriver::TYPE_EC2)
		->and($inventory->resources[0]->providerId)->toBe('us-east-1/i-0123456789abcdef0')
		->and($inventory->resources[0]->name)->toBe('web-01')
		->and($inventory->resources[1]->type)->toBe(AwsDriver::TYPE_RDS)
		->and($inventory->resources[1]->providerId)->toBe('us-east-1/prod-db-1')
		->and($inventory->resources[2]->type)->toBe(AwsDriver::TYPE_ELB)
		->and($inventory->resources[2]->providerId)->toBe('us-east-1/app/web-alb/0123456789abcdef')
		->and(count($http->sent))->toBe(3);
});

it('batches CloudWatch GetMetricData up to 500 queries per call and converts Sum rates', function () {
	$account = cloud_aws_account();
	$batches = [];

	[$driver] = cloud_aws_driver($account, static function (HttpRequest $req) use (&$batches): HttpResponse {
		$batches[] = (int) preg_match_all('/(?:^|&)MetricDataQueries\.member\.\d+\.Id=/', $req->body ?? '');

		return new HttpResponse(200, [], '<GetMetricDataResponse><GetMetricDataResult><MetricDataResults>'
			. '<member><Id>m1</Id><Timestamps><member>2024-11-13T23:55:00Z</member></Timestamps><Values><member>6000</member></Values></member>'
			. '</MetricDataResults></GetMetricDataResult></GetMetricDataResponse>');
	});

	$requests = [new MetricRequest(1, AwsDriver::TYPE_EC2, 'us-east-1/i-0123456789abcdef0', 'ec2_net_in_Bps')];

	for ($i = 2; $i <= 505; $i++) {
		$id         = sprintf('i-%017x', $i);
		$requests[] = new MetricRequest($i, AwsDriver::TYPE_EC2, 'us-east-1/' . $id, 'ec2_cpu_pct');
	}

	$result = $driver->collect($account, cloud_aws_cred(), $requests);

	expect($batches)->toBe([500, 5])
		->and($result->requests)->toBe(2)
		->and($result->error)->toBeNull()
		->and($result->observations[0]->value)->toBe(20.0);
});

it('supports STS AssumeRole and IMDSv2 for Instance accounts, and verifies caller account ID', function () {
	$stsAccount = cloud_aws_account();
	[$stsDriver, $stsHttp] = cloud_aws_driver($stsAccount, static function (HttpRequest $req): HttpResponse {
		parse_str($req->body ?? '', $params);

		if (($params['Action'] ?? '') === 'AssumeRole') {
			return new HttpResponse(200, [], '<AssumeRoleResponse><AssumeRoleResult><Credentials>'
				. '<AccessKeyId>ASIATEMPEXAMPLE</AccessKeyId><SecretAccessKey>tempsecret</SecretAccessKey><SessionToken>temptoken</SessionToken>'
				. '</Credentials></AssumeRoleResult></AssumeRoleResponse>');
		}

		if (($params['Action'] ?? '') === 'GetCallerIdentity') {
			return new HttpResponse(200, [], '<GetCallerIdentityResponse><GetCallerIdentityResult><Account>123456789012</Account></GetCallerIdentityResult></GetCallerIdentityResponse>');
		}

		return new HttpResponse(200, [], '<DescribeInstancesResponse><reservationSet/></DescribeInstancesResponse>');
	});

	$credWithRole = cloud_aws_cred([
		'role_arn'    => 'arn:aws:iam::123456789012:role/CactiReadOnly',
		'external_id' => 'ext-123',
	]);

	expect($stsDriver->verify($stsAccount, $credWithRole))->toBe(VerifyStatus::Ok)
		->and(count($stsHttp->sent))->toBe(3)
		->and($stsHttp->sent[1]->headers['X-Amz-Security-Token'] ?? null)->toBe('temptoken');

	$imdsAccount = cloud_aws_account(['credential_source' => 'instance']);
	[$imdsDriver, $imdsHttp] = cloud_aws_driver($imdsAccount, static function (HttpRequest $req): HttpResponse {
		if ($req->method === 'PUT' && str_contains($req->url, '/latest/api/token')) {
			return new HttpResponse(200, [], 'imds-v2-session-token');
		}

		if ($req->method === 'GET' && str_ends_with($req->url, '/security-credentials/')) {
			return new HttpResponse(200, [], "cacti-poller-role\n");
		}

		if ($req->method === 'GET' && str_ends_with($req->url, '/cacti-poller-role')) {
			return new HttpResponse(200, [], (string) json_encode([
				'AccessKeyId'     => 'ASIAIMDSKEY',
				'SecretAccessKey' => 'imds-secret',
				'Token'           => 'imds-token',
			]));
		}

		parse_str($req->body ?? '', $params);

		if (($params['Action'] ?? '') === 'GetCallerIdentity') {
			return new HttpResponse(200, [], '<GetCallerIdentityResponse><GetCallerIdentityResult><Account>123456789012</Account></GetCallerIdentityResult></GetCallerIdentityResponse>');
		}

		return new HttpResponse(200, [], '<DescribeInstancesResponse><reservationSet/></DescribeInstancesResponse>');
	});

	expect($imdsDriver->verify($imdsAccount, new Secret('')))->toBe(VerifyStatus::Ok)
		->and($imdsHttp->sent[0]->method)->toBe('PUT')
		->and($imdsHttp->sent[1]->headers['X-aws-ec2-metadata-token'] ?? null)->toBe('imds-v2-session-token')
		->and($imdsHttp->sent[3]->headers['X-Amz-Security-Token'] ?? null)->toBe('imds-token');
});

it('estimates CloudWatch GetMetricData monthly cost and integrates with RequestBudget ceilings', function () {
	$account  = cloud_aws_account(['monthly_ceiling' => 10.0]);
	[$driver] = cloud_aws_driver($account, static fn (): HttpResponse => new HttpResponse(200, [], ''));

	$wanted = [];

	for ($i = 1; $i <= 1000; $i++) {
		$wanted[] = new MetricRequest($i, AwsDriver::TYPE_EC2, sprintf('us-east-1/i-%017x', $i), 'ec2_cpu_pct');
	}

	$estimate = $driver->estimate($wanted, 300);

	// 1,000 metrics / 500 = 2 calls per collection; 8,640 collections/mo; 1,000 * 8,640 * $0.00001 = $86.40.
	expect($estimate->requestsPerCollection)->toBe(2)
		->and($estimate->requestsPerMonth)->toBe(17280)
		->and($estimate->monthlyCost)->toBe(86.4);

	[$fit, $left] = RequestBudget::split($account, $driver, $wanted, 0);

	expect(count($fit))->toBe(115)
		->and(count($left))->toBe(885);
});
