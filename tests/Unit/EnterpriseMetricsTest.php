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

use Somethingwithproof\Cloud\AwsDriver;
use Somethingwithproof\Cloud\AzureDriver;
use Somethingwithproof\Cloud\GcpAuth;
use Somethingwithproof\Cloud\GcpDriver;
use Somethingwithproof\Cloud\HttpRequest;
use Somethingwithproof\Cloud\HttpResponse;
use Somethingwithproof\Cloud\MetricRequest;
use Somethingwithproof\Cloud\Secret;

it('collects AWS RDS latency/storage, EC2 CPUCreditBalance, ALB UnHealthyHostCount, NAT Gateway, and SQS metrics', function () {
	$catalog = AwsDriver::metricCatalog();

	expect($catalog)->toHaveKeys([
		'rds_free_storage', 'rds_read_lat_s', 'rds_write_lat_s',
		'ec2_cpu_credits', 'alb_unhealthy_hosts',
		'nat_bytes_out_Bps', 'nat_pkt_drop_ps',
		'sqs_msgs_visible', 'sqs_oldest_age_s',
	]);

	$account     = cloud_aws_account();
	$seenQueries = [];

	[$driver] = cloud_aws_driver($account, static function (HttpRequest $req) use (&$seenQueries): HttpResponse {
		parse_str($req->body ?? '', $params);
		$seenQueries = $params;

		$xml = '<GetMetricDataResponse><GetMetricDataResult><MetricDataResults>';

		for ($i = 1; $i <= 9; $i++) {
			$val = match ($i) {
				1 => '107374182400', // FreeStorageSpace (bytes)
				2 => '0.0045',       // ReadLatency (s)
				3 => '0.0082',       // WriteLatency (s)
				4 => '240.5',        // CPUCreditBalance
				5 => '2',            // UnHealthyHostCount
				6 => '30000',        // BytesOutToDestination (Sum over 300s -> 100 B/s)
				7 => '600',          // PacketsDropCount (Sum over 300s -> 2 pkt/s)
				8 => '42',           // ApproximateNumberOfMessagesVisible
				9 => '185',          // ApproximateAgeOfOldestMessage
			};
			$xml .= "<member><Id>m$i</Id><StatusCode>Complete</StatusCode>"
				. '<Timestamps><member>2024-11-13T23:55:00Z</member></Timestamps>'
				. "<Values><member>$val</member></Values></member>";
		}

		$xml .= '</MetricDataResults></GetMetricDataResult></GetMetricDataResponse>';

		return new HttpResponse(200, [], $xml);
	});

	$requests = [
		new MetricRequest(1, AwsDriver::TYPE_RDS, 'us-east-1/prod-db-1', 'rds_free_storage'),
		new MetricRequest(1, AwsDriver::TYPE_RDS, 'us-east-1/prod-db-1', 'rds_read_lat_s'),
		new MetricRequest(1, AwsDriver::TYPE_RDS, 'us-east-1/prod-db-1', 'rds_write_lat_s'),
		new MetricRequest(2, AwsDriver::TYPE_EC2, 'us-east-1/i-0123456789abcdef0', 'ec2_cpu_credits'),
		new MetricRequest(3, AwsDriver::TYPE_ELB, 'us-east-1/app/web-alb/0123456789abcdef', 'alb_unhealthy_hosts'),
		new MetricRequest(4, AwsDriver::TYPE_NAT, 'us-east-1/nat-0123456789abcdef0', 'nat_bytes_out_Bps'),
		new MetricRequest(4, AwsDriver::TYPE_NAT, 'us-east-1/nat-0123456789abcdef0', 'nat_pkt_drop_ps'),
		new MetricRequest(5, AwsDriver::TYPE_SQS, 'us-east-1/orders-prod.fifo', 'sqs_msgs_visible'),
		new MetricRequest(5, AwsDriver::TYPE_SQS, 'us-east-1/orders-prod.fifo', 'sqs_oldest_age_s'),
	];

	$result = $driver->collect($account, cloud_aws_cred(), $requests);
	$byKey  = [];

	foreach ($result->observations as $obs) {
		$byKey[$obs->metric] = $obs->value;
	}

	expect($result->error)->toBeNull()
		->and($result->requests)->toBe(1)
		->and($seenQueries['MetricDataQueries_member_6_MetricStat_Metric_Namespace'] ?? '')->toBe('AWS/NATGateway')
		->and($seenQueries['MetricDataQueries_member_6_MetricStat_Metric_Dimensions_member_1_Name'] ?? '')->toBe('NatGatewayId')
		->and($seenQueries['MetricDataQueries_member_8_MetricStat_Metric_Namespace'] ?? '')->toBe('AWS/SQS')
		->and($seenQueries['MetricDataQueries_member_8_MetricStat_Metric_Dimensions_member_1_Name'] ?? '')->toBe('QueueName')
		->and($byKey['rds_free_storage'])->toBe(107374182400.0)
		->and($byKey['rds_read_lat_s'])->toBe(0.0045)
		->and($byKey['rds_write_lat_s'])->toBe(0.0082)
		->and($byKey['ec2_cpu_credits'])->toBe(240.5)
		->and($byKey['alb_unhealthy_hosts'])->toBe(2.0)
		->and($byKey['nat_bytes_out_Bps'])->toBe(100.0)
		->and($byKey['nat_pkt_drop_ps'])->toBe(2.0)
		->and($byKey['sqs_msgs_visible'])->toBe(42.0)
		->and($byKey['sqs_oldest_age_s'])->toBe(185.0);
});

it('collects GCP Cloud SQL, HTTPS Load Balancer, and GCE guest-agent memory metrics', function () {
	$catalog = GcpDriver::metricCatalog();

	expect($catalog)->toHaveKeys([
		'gce_mem_used_pct',
		'csql_cpu_pct', 'csql_mem_pct', 'csql_disk_pct', 'csql_conns',
		'glb_req_ps', 'glb_lat_p95_ms',
	]);

	$account = cloud_gcp_account(['credential_source' => 'instance']);

	[$driver] = cloud_gcp_driver($account, static function (HttpRequest $req): HttpResponse {
		if ($req->url === GcpAuth::METADATA_URL) {
			return new HttpResponse(200, [], (string) json_encode(['access_token' => 'ya29.meta', 'expires_in' => 3600]));
		}

		$query = [];
		parse_str((string) parse_url($req->url, PHP_URL_QUERY), $query);
		$filter = (string) ($query['filter'] ?? '');

		$series = match (true) {
			str_contains($filter, 'agent.googleapis.com/memory/percent_used') => [[
				'resource' => ['labels' => ['instance_id' => '1234567890123456789']],
				'points'   => [['interval' => ['endTime' => '2024-11-13T23:55:00Z'], 'value' => ['doubleValue' => 71.4]]],
			]],
			str_contains($filter, 'database/cpu/utilization') => [[
				'resource' => ['labels' => ['database_id' => 'my-cacti-prod-1:pg-main']],
				'points'   => [['interval' => ['endTime' => '2024-11-13T23:55:00Z'], 'value' => ['doubleValue' => 0.62]]],
			]],
			str_contains($filter, 'database/memory/utilization') => [[
				'resource' => ['labels' => ['database_id' => 'my-cacti-prod-1:pg-main']],
				'points'   => [['interval' => ['endTime' => '2024-11-13T23:55:00Z'], 'value' => ['doubleValue' => 0.78]]],
			]],
			str_contains($filter, 'database/disk/utilization') => [[
				'resource' => ['labels' => ['database_id' => 'my-cacti-prod-1:pg-main']],
				'points'   => [['interval' => ['endTime' => '2024-11-13T23:55:00Z'], 'value' => ['doubleValue' => 0.44]]],
			]],
			str_contains($filter, 'database/network/connections') => [[
				'resource' => ['labels' => ['database_id' => 'my-cacti-prod-1:pg-main']],
				'points'   => [['interval' => ['endTime' => '2024-11-13T23:55:00Z'], 'value' => ['int64Value' => '128']]],
			]],
			str_contains($filter, 'https/request_count') => [[
				'resource' => ['labels' => ['url_map_name' => 'prod-https-map']],
				'points'   => [['interval' => ['endTime' => '2024-11-13T23:55:00Z'], 'value' => ['doubleValue' => 345.2]]],
			]],
			str_contains($filter, 'https/total_latencies') => [[
				'resource' => ['labels' => ['url_map_name' => 'prod-https-map']],
				'points'   => [['interval' => ['endTime' => '2024-11-13T23:55:00Z'], 'value' => ['distributionValue' => ['mean' => 28.6]]]],
			]],
			default => [],
		};

		return new HttpResponse(200, [], (string) json_encode(['timeSeries' => $series]));
	});

	$requests = [
		new MetricRequest(10, GcpDriver::TYPE_INSTANCE, '1234567890123456789', 'gce_mem_used_pct'),
		new MetricRequest(11, GcpDriver::TYPE_CLOUDSQL, 'my-cacti-prod-1:pg-main', 'csql_cpu_pct'),
		new MetricRequest(11, GcpDriver::TYPE_CLOUDSQL, 'my-cacti-prod-1:pg-main', 'csql_mem_pct'),
		new MetricRequest(11, GcpDriver::TYPE_CLOUDSQL, 'my-cacti-prod-1:pg-main', 'csql_disk_pct'),
		// Bare Cloud SQL instance name also matches project:instance database_id label.
		new MetricRequest(11, GcpDriver::TYPE_CLOUDSQL, 'pg-main', 'csql_conns'),
		new MetricRequest(12, GcpDriver::TYPE_HTTPS_LB, 'prod-https-map', 'glb_req_ps'),
		new MetricRequest(12, GcpDriver::TYPE_HTTPS_LB, 'prod-https-map', 'glb_lat_p95_ms'),
	];

	$result = $driver->collect($account, new Secret(''), $requests);
	$byKey  = [];

	foreach ($result->observations as $obs) {
		$byKey[$obs->metric] = $obs->value;
	}

	expect($result->error)->toBeNull()
		->and($byKey['gce_mem_used_pct'])->toBe(71.4)
		->and($byKey['csql_cpu_pct'])->toBe(62.0)
		->and($byKey['csql_mem_pct'])->toBe(78.0)
		->and($byKey['csql_disk_pct'])->toBe(44.0)
		->and($byKey['csql_conns'])->toBe(128.0)
		->and($byKey['glb_req_ps'])->toBe(345.2)
		->and($byKey['glb_lat_p95_ms'])->toBe(28.6);
});

it('collects Azure SQL, Application Gateway / Load Balancer, and VM disk throttling metrics', function () {
	$catalog = AzureDriver::metricCatalog();

	expect($catalog)->toHaveKeys([
		'azvm_os_dsk_qdepth', 'azvm_data_dsk_iops_pct',
		'azsql_cpu_pct', 'azsql_dtu_pct', 'azsql_storage_pct',
		'azgw_healthy_hosts', 'azgw_failed_reqs', 'azgw_total_reqs',
	]);

	$account    = cloud_az_account();
	$namespaces = [];

	$vmId    = '/subscriptions/' . CLOUD_AZ_SUB . '/resourceGroups/rg1/providers/Microsoft.Compute/virtualMachines/vm-1';
	$sqlId   = '/subscriptions/' . CLOUD_AZ_SUB . '/resourceGroups/rg1/providers/Microsoft.Sql/servers/sql-1/databases/appdb';
	$appGwId = '/subscriptions/' . CLOUD_AZ_SUB . '/resourceGroups/rg1/providers/Microsoft.Network/applicationGateways/gw-1';
	$lbId    = '/subscriptions/' . CLOUD_AZ_SUB . '/resourceGroups/rg1/providers/Microsoft.Network/loadBalancers/lb-1';

	[$driver] = cloud_az_driver($account, static function (HttpRequest $req) use (&$namespaces): HttpResponse {
		if (str_contains($req->url, 'login.microsoftonline.com')) {
			return new HttpResponse(200, [], (string) json_encode(['access_token' => 'az-metrics-token', 'expires_in' => 3600]));
		}

		$query = [];
		parse_str((string) parse_url($req->url, PHP_URL_QUERY), $query);
		$ns           = (string) ($query['metricnamespace'] ?? '');
		$agg          = strtolower((string) ($query['aggregation'] ?? 'average'));
		$namespaces[] = $ns;
		$decoded      = json_decode($req->body ?? '{}', true, 16, JSON_THROW_ON_ERROR);
		$metricNames  = explode(',', (string) ($query['metricnames'] ?? ''));

		$values = [];

		foreach ($decoded['resourceids'] as $rid) {
			$blocks = [];

			foreach ($metricNames as $name) {
				$sampleVal = match ($name) {
					'OS Disk Queue Depth'                => 3.5,
					'Data Disk IOPS Consumed Percentage' => 89.2,
					'cpu_percent'                        => 54.0,
					'dtu_consumption_percent'            => 67.5,
					'storage_percent'                    => 48.0,
					'HealthyHostCount'                   => 4.0,
					'FailedRequests'                     => 7.0,
					'TotalRequests'                      => 1250.0,
					default                              => 0.0,
				};
				$blocks[] = [
					'name'       => ['value' => $name],
					'timeseries' => [['data' => [['timeStamp' => '2024-11-13T23:55:00Z', $agg => $sampleVal]]]],
				];
			}

			$values[] = ['resourceid' => $rid, 'value' => $blocks];
		}

		return new HttpResponse(200, [], (string) json_encode(['values' => $values]));
	});

	$requests = [
		new MetricRequest(1, AzureDriver::TYPE_VM, 'eastus|' . $vmId, 'azvm_os_dsk_qdepth'),
		new MetricRequest(1, AzureDriver::TYPE_VM, 'eastus|' . $vmId, 'azvm_data_dsk_iops_pct'),
		new MetricRequest(2, AzureDriver::TYPE_SQL, 'eastus|' . $sqlId, 'azsql_cpu_pct'),
		new MetricRequest(2, AzureDriver::TYPE_SQL, 'eastus|' . $sqlId, 'azsql_dtu_pct'),
		new MetricRequest(2, AzureDriver::TYPE_SQL, 'eastus|' . $sqlId, 'azsql_storage_pct'),
		new MetricRequest(3, AzureDriver::TYPE_APPGW, 'eastus|' . $appGwId, 'azgw_healthy_hosts'),
		new MetricRequest(3, AzureDriver::TYPE_APPGW, 'eastus|' . $appGwId, 'azgw_failed_reqs'),
		new MetricRequest(3, AzureDriver::TYPE_APPGW, 'eastus|' . $appGwId, 'azgw_total_reqs'),
		new MetricRequest(4, AzureDriver::TYPE_APPGW, 'eastus|' . $lbId, 'azgw_healthy_hosts'),
	];

	$result = $driver->collect($account, cloud_az_cred(), $requests);
	$byKey  = [];

	foreach ($result->observations as $obs) {
		$byKey[$obs->resourceId . ':' . $obs->metric] = $obs->value;
	}

	expect($result->error)->toBeNull()
		->and($namespaces)->toContain(
			'Microsoft.Compute/virtualMachines',
			'Microsoft.Sql/servers/databases',
			'Microsoft.Network/applicationGateways',
			'Microsoft.Network/loadBalancers'
		)
		->and($byKey['1:azvm_os_dsk_qdepth'])->toBe(3.5)
		->and($byKey['1:azvm_data_dsk_iops_pct'])->toBe(89.2)
		->and($byKey['2:azsql_cpu_pct'])->toBe(54.0)
		->and($byKey['2:azsql_dtu_pct'])->toBe(67.5)
		->and($byKey['2:azsql_storage_pct'])->toBe(48.0)
		->and($byKey['3:azgw_healthy_hosts'])->toBe(4.0)
		->and($byKey['3:azgw_failed_reqs'])->toBe(7.0)
		->and($byKey['3:azgw_total_reqs'])->toBe(1250.0)
		->and($byKey['4:azgw_healthy_hosts'])->toBe(4.0);
});
