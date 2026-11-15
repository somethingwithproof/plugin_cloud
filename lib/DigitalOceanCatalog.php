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
 * The DigitalOcean metrics the plugin reads, from docs/research/metric-catalogs.md.
 * Keys are the Cacti data source names. DigitalOcean documents no sample
 * period or publication delay for any metric, so none is listed here until
 * the live checks on #16 record them.
 *
 * Conversions:
 * - cpu: CPU seconds per mode, read as cumulative (inferred from the API
 *   example, unconfirmed): percent busy between the two newest samples.
 * - mbps: megabits per second, as the API states, to bits per second.
 * - value: the newest value as it is.
 * - single: the newest value as it is. Load balancer label sets are
 *   undocumented, so a response with several series is reported as
 *   malformed until the live check shows what they mean.
 *
 * Every conversion but cpu expects one series.
 *
 * Filesystem metrics are left out: they need one index per mount point.
 */
final class DigitalOceanCatalog {
	public const DROPLET       = 'droplet';
	public const LOAD_BALANCER = 'load_balancer';

	/**
	 * Each row is [type, path, query, convert, agent]; metric() names the fields.
	 *
	 * @var array<string, array{0: string, 1: string, 2: array<string, string>, 3: string, 4: bool}>
	 */
	private const METRICS = [
		'do_cpu_pct'      => [self::DROPLET, 'cpu', [], 'cpu', false],
		'do_bw_pub_in'    => [self::DROPLET, 'bandwidth', ['interface' => 'public', 'direction' => 'inbound'], 'mbps', false],
		'do_bw_pub_out'   => [
			self::DROPLET, 'bandwidth', ['interface' => 'public', 'direction' => 'outbound'], 'mbps', false,
		],
		'do_bw_prv_in'    => [
			self::DROPLET, 'bandwidth', ['interface' => 'private', 'direction' => 'inbound'], 'mbps', false,
		],
		'do_bw_prv_out'   => [
			self::DROPLET, 'bandwidth', ['interface' => 'private', 'direction' => 'outbound'], 'mbps', false,
		],
		'do_load_1'       => [self::DROPLET, 'load_1', [], 'value', true],
		'do_load_5'       => [self::DROPLET, 'load_5', [], 'value', true],
		'do_load_15'      => [self::DROPLET, 'load_15', [], 'value', true],
		'do_mem_total'    => [self::DROPLET, 'memory_total', [], 'value', true],
		'do_mem_avail'    => [self::DROPLET, 'memory_available', [], 'value', true],
		'do_mem_free'     => [self::DROPLET, 'memory_free', [], 'value', true],
		'do_mem_cached'   => [self::DROPLET, 'memory_cached', [], 'value', true],
		'dolb_conn_cur'   => [self::LOAD_BALANCER, 'frontend_connections_current', [], 'single', false],
		'dolb_conn_lim'   => [self::LOAD_BALANCER, 'frontend_connections_limit', [], 'single', false],
		'dolb_cpu_pct'    => [self::LOAD_BALANCER, 'frontend_cpu_utilization', [], 'single', false],
		'dolb_http_rps'   => [self::LOAD_BALANCER, 'frontend_http_requests_per_second', [], 'single', false],
		'dolb_http_resp'  => [self::LOAD_BALANCER, 'frontend_http_responses', [], 'single', false],
		'dolb_http_Bps'   => [self::LOAD_BALANCER, 'frontend_network_throughput_http', [], 'single', false],
		'dolb_tcp_Bps'    => [self::LOAD_BALANCER, 'frontend_network_throughput_tcp', [], 'single', false],
		'dolb_udp_Bps'    => [self::LOAD_BALANCER, 'frontend_network_throughput_udp', [], 'single', false],
		'dolb_nlbtcp_Bps' => [self::LOAD_BALANCER, 'frontend_nlb_tcp_network_throughput', [], 'single', false],
		'dolb_nlbudp_Bps' => [self::LOAD_BALANCER, 'frontend_nlb_udp_network_throughput', [], 'single', false],
		'dolb_hc_status'  => [self::LOAD_BALANCER, 'droplets_health_checks', [], 'single', false],
		'dolb_downtime'   => [self::LOAD_BALANCER, 'droplets_downtime', [], 'single', false],
		'dolb_resp_avg_s' => [self::LOAD_BALANCER, 'droplets_http_response_time_avg', [], 'single', false],
		'dolb_resp_p95_s' => [self::LOAD_BALANCER, 'droplets_http_response_time_95p', [], 'single', false],
		'dolb_back_conn'  => [self::LOAD_BALANCER, 'droplets_connections', [], 'single', false],
		'dolb_queue'      => [self::LOAD_BALANCER, 'droplets_queue_size', [], 'single', false],
	];

	/**
	 * The data source name of each resource type's collection age, which the
	 * script server computes from the cache (ADR 0006); no API request reads it.
	 */
	public const AGE = [self::DROPLET => 'do_age', self::LOAD_BALANCER => 'dolb_age'];

	/**
	 * Graph templates: key => [type, title, vertical label, upper limit or
	 * null, data source names]. Each graph gets its own data template, so a
	 * graph collects only its own metrics (ADR 0005). Every metric and age
	 * name appears in exactly one graph; a template test checks it.
	 *
	 * @var array<string, array{0: string, 1: string, 2: string, 3: int|null, 4: list<string>}>
	 */
	public const GRAPHS = [
		'do_cpu'           => [self::DROPLET, 'CPU', 'percent', 100, ['do_cpu_pct']],
		'do_bandwidth'     => [self::DROPLET, 'Bandwidth', 'bits per second', null, [
			'do_bw_pub_in', 'do_bw_pub_out', 'do_bw_prv_in', 'do_bw_prv_out',
		]],
		'do_load'          => [self::DROPLET, 'Load Average', 'load', null, ['do_load_1', 'do_load_5', 'do_load_15']],
		'do_memory'        => [self::DROPLET, 'Memory', 'bytes', null, [
			'do_mem_total', 'do_mem_avail', 'do_mem_free', 'do_mem_cached',
		]],
		'do_age'           => [self::DROPLET, 'Collection Age', 'seconds', null, ['do_age']],
		'dolb_connections' => [self::LOAD_BALANCER, 'Connections', 'connections', null, ['dolb_conn_cur', 'dolb_conn_lim']],
		'dolb_cpu'         => [self::LOAD_BALANCER, 'CPU', 'percent', 100, ['dolb_cpu_pct']],
		'dolb_http_rps'    => [self::LOAD_BALANCER, 'HTTP Requests', 'requests per second', null, ['dolb_http_rps']],
		'dolb_http_resp'   => [self::LOAD_BALANCER, 'HTTP Responses', 'responses', null, ['dolb_http_resp']],
		'dolb_throughput'  => [self::LOAD_BALANCER, 'Throughput', 'bytes per second', null, [
			'dolb_http_Bps', 'dolb_tcp_Bps', 'dolb_udp_Bps', 'dolb_nlbtcp_Bps', 'dolb_nlbudp_Bps',
		]],
		'dolb_health'      => [self::LOAD_BALANCER, 'Health Checks', 'status', null, ['dolb_hc_status']],
		'dolb_downtime'    => [self::LOAD_BALANCER, 'Downtime', 'status', null, ['dolb_downtime']],
		'dolb_response'    => [self::LOAD_BALANCER, 'Response Time', 'seconds', null, ['dolb_resp_avg_s', 'dolb_resp_p95_s']],
		'dolb_backend'     => [self::LOAD_BALANCER, 'Droplet Connections', 'connections', null, [
			'dolb_back_conn', 'dolb_queue',
		]],
		'dolb_age'         => [self::LOAD_BALANCER, 'Collection Age', 'seconds', null, ['dolb_age']],
	];

	private const FIELDS = ['type', 'path', 'query', 'convert', 'agent'];

	/**
	 * @return array{type: string, path: string, query: array<string, string>, convert: string, agent: bool}|null
	 */
	public static function metric(string $name): ?array {
		return isset(self::METRICS[$name]) ? self::named(self::METRICS[$name]) : null;
	}

	/**
	 * @return list<string> every metric name of the catalog, in catalog order
	 */
	public static function names(): array {
		return array_keys(self::METRICS);
	}

	/**
	 * Metrics read by default for a resource type: those that need no agent.
	 *
	 * @return list<string>
	 */
	public static function defaults(string $type): array {
		return array_keys(array_filter(self::all(), static fn (array $m): bool => $m['type'] === $type && !$m['agent']));
	}

	/**
	 * Every API path name of one resource type, for the operation list.
	 *
	 * @return list<string>
	 */
	public static function paths(string $type): array {
		return array_values(
			array_unique(
				array_column(
					array_filter(self::all(), static fn (array $m): bool => $m['type'] === $type),
					'path'
				)
			)
		);
	}

	/**
	 * @return array<string, array{type: string, path: string, query: array<string, string>, convert: string, agent: bool}>
	 */
	private static function all(): array {
		return array_map(self::named(...), self::METRICS);
	}

	/**
	 * @param array{0: string, 1: string, 2: array<string, string>, 3: string, 4: bool} $row
	 * @return array{type: string, path: string, query: array<string, string>, convert: string, agent: bool}
	 */
	private static function named(array $row): array {
		/** @var array{type: string, path: string, query: array<string, string>, convert: string, agent: bool} */
		return array_combine(self::FIELDS, $row);
	}
}
