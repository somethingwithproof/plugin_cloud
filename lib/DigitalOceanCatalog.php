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
	 * @var array<string, array{type: string, path: string, query: array<string, string>, convert: string, agent: bool}>
	 */
	private const METRICS = [
		'do_cpu_pct'    => ['type' => self::DROPLET, 'path' => 'cpu', 'query' => [], 'convert' => 'cpu', 'agent' => false],
		'do_bw_pub_in'  => ['type' => self::DROPLET, 'path' => 'bandwidth', 'query' => ['interface' => 'public', 'direction' => 'inbound'], 'convert' => 'mbps', 'agent' => false],
		'do_bw_pub_out' => ['type' => self::DROPLET, 'path' => 'bandwidth', 'query' => ['interface' => 'public', 'direction' => 'outbound'], 'convert' => 'mbps', 'agent' => false],
		'do_bw_prv_in'  => ['type' => self::DROPLET, 'path' => 'bandwidth', 'query' => ['interface' => 'private', 'direction' => 'inbound'], 'convert' => 'mbps', 'agent' => false],
		'do_bw_prv_out' => ['type' => self::DROPLET, 'path' => 'bandwidth', 'query' => ['interface' => 'private', 'direction' => 'outbound'], 'convert' => 'mbps', 'agent' => false],
		'do_load_1'     => ['type' => self::DROPLET, 'path' => 'load_1', 'query' => [], 'convert' => 'value', 'agent' => true],
		'do_load_5'     => ['type' => self::DROPLET, 'path' => 'load_5', 'query' => [], 'convert' => 'value', 'agent' => true],
		'do_load_15'    => ['type' => self::DROPLET, 'path' => 'load_15', 'query' => [], 'convert' => 'value', 'agent' => true],
		'do_mem_total'  => ['type' => self::DROPLET, 'path' => 'memory_total', 'query' => [], 'convert' => 'value', 'agent' => true],
		'do_mem_avail'  => ['type' => self::DROPLET, 'path' => 'memory_available', 'query' => [], 'convert' => 'value', 'agent' => true],
		'do_mem_free'   => ['type' => self::DROPLET, 'path' => 'memory_free', 'query' => [], 'convert' => 'value', 'agent' => true],
		'do_mem_cached' => ['type' => self::DROPLET, 'path' => 'memory_cached', 'query' => [], 'convert' => 'value', 'agent' => true],

		'dolb_conn_cur'   => ['type' => self::LOAD_BALANCER, 'path' => 'frontend_connections_current', 'query' => [], 'convert' => 'single', 'agent' => false],
		'dolb_conn_lim'   => ['type' => self::LOAD_BALANCER, 'path' => 'frontend_connections_limit', 'query' => [], 'convert' => 'single', 'agent' => false],
		'dolb_cpu_pct'    => ['type' => self::LOAD_BALANCER, 'path' => 'frontend_cpu_utilization', 'query' => [], 'convert' => 'single', 'agent' => false],
		'dolb_http_rps'   => ['type' => self::LOAD_BALANCER, 'path' => 'frontend_http_requests_per_second', 'query' => [], 'convert' => 'single', 'agent' => false],
		'dolb_http_resp'  => ['type' => self::LOAD_BALANCER, 'path' => 'frontend_http_responses', 'query' => [], 'convert' => 'single', 'agent' => false],
		'dolb_http_Bps'   => ['type' => self::LOAD_BALANCER, 'path' => 'frontend_network_throughput_http', 'query' => [], 'convert' => 'single', 'agent' => false],
		'dolb_tcp_Bps'    => ['type' => self::LOAD_BALANCER, 'path' => 'frontend_network_throughput_tcp', 'query' => [], 'convert' => 'single', 'agent' => false],
		'dolb_udp_Bps'    => ['type' => self::LOAD_BALANCER, 'path' => 'frontend_network_throughput_udp', 'query' => [], 'convert' => 'single', 'agent' => false],
		'dolb_nlbtcp_Bps' => ['type' => self::LOAD_BALANCER, 'path' => 'frontend_nlb_tcp_network_throughput', 'query' => [], 'convert' => 'single', 'agent' => false],
		'dolb_nlbudp_Bps' => ['type' => self::LOAD_BALANCER, 'path' => 'frontend_nlb_udp_network_throughput', 'query' => [], 'convert' => 'single', 'agent' => false],
		'dolb_hc_status'  => ['type' => self::LOAD_BALANCER, 'path' => 'droplets_health_checks', 'query' => [], 'convert' => 'single', 'agent' => false],
		'dolb_downtime'   => ['type' => self::LOAD_BALANCER, 'path' => 'droplets_downtime', 'query' => [], 'convert' => 'single', 'agent' => false],
		'dolb_resp_avg_s' => ['type' => self::LOAD_BALANCER, 'path' => 'droplets_http_response_time_avg', 'query' => [], 'convert' => 'single', 'agent' => false],
		'dolb_resp_p95_s' => ['type' => self::LOAD_BALANCER, 'path' => 'droplets_http_response_time_95p', 'query' => [], 'convert' => 'single', 'agent' => false],
		'dolb_back_conn'  => ['type' => self::LOAD_BALANCER, 'path' => 'droplets_connections', 'query' => [], 'convert' => 'single', 'agent' => false],
		'dolb_queue'      => ['type' => self::LOAD_BALANCER, 'path' => 'droplets_queue_size', 'query' => [], 'convert' => 'single', 'agent' => false],
	];

	/**
	 * @return array{type: string, path: string, query: array<string, string>, convert: string, agent: bool}|null
	 */
	public static function metric(string $name): ?array {
		return self::METRICS[$name] ?? null;
	}

	/**
	 * Metrics read by default for a resource type: those that need no agent.
	 *
	 * @return list<string>
	 */
	public static function defaults(string $type): array {
		return array_keys(array_filter(self::METRICS, static fn (array $m): bool => $m['type'] === $type && !$m['agent']));
	}

	/**
	 * Every API path name of one resource type, for the operation list.
	 *
	 * @return list<string>
	 */
	public static function paths(string $type): array {
		return array_values(array_unique(array_column(array_filter(self::METRICS, static fn (array $m): bool => $m['type'] === $type), 'path')));
	}
}
