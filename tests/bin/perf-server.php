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

/*
 * The DigitalOcean fixture server for the performance runs (#47). Serves
 * every monitoring path over TLS from the recorded fixtures, with their
 * timestamps moved to now so each datapoint is current, plus the rate
 * limit headers the driver reads. One connection at a time, like the
 * collector. Must parse on PHP 8.0.
 *
 *   php perf-server.php <port> <cert.pem> <seconds> [latency ms]
 *
 * The latency is added before each response, to stand in for the provider.
 */

$port    = (int) ($argv[1] ?? 0);
$cert    = (string) ($argv[2] ?? '');
$seconds = (int) ($argv[3] ?? 600);
$latency = (int) ($argv[4] ?? 0);
$fixture = dirname(__DIR__) . '/fixtures/digitalocean/';

$templates = array(
	'bandwidth' => json_decode((string) file_get_contents($fixture . 'droplet_bandwidth.json'), true),
	'default'   => json_decode((string) file_get_contents($fixture . 'droplet_cpu.json'), true),
);

$server = stream_socket_server(
	'ssl://127.0.0.1:' . $port,
	$errno,
	$errstr,
	STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
	stream_context_create(array('ssl' => array('local_cert' => $cert)))
);

if ($server === false) {
	fwrite(STDERR, "listen failed: $errstr\n");
	exit(1);
}

/**
 * The fixture for $path with each series' points ending a minute ago.
 */
function perf_body($templates, $path) {
	$body = $templates[basename($path) === 'bandwidth' ? 'bandwidth' : 'default'];
	$now  = time() - 60;

	foreach ($body['data']['result'] as $i => $series) {
		$count = count($series['values']);

		foreach ($series['values'] as $j => $point) {
			$body['data']['result'][$i]['values'][$j][0] = $now - ($count - 1 - $j) * 60;
		}
	}

	return json_encode($body);
}

$deadline = time() + $seconds;

while (time() < $deadline) {
	$conn = @stream_socket_accept($server, 1);

	if ($conn === false) {
		continue;
	}

	stream_set_timeout($conn, 5);
	$head = '';

	while (strpos($head, "\r\n\r\n") === false && strlen($head) < 65536) {
		$line = fgets($conn, 8192);

		if ($line === false) {
			break;
		}

		$head .= $line;
	}

	usleep($latency * 1000);

	$target = explode(' ', explode("\r\n", $head, 2)[0]) + array('', '', '');
	$path   = (string) parse_url($target[1], PHP_URL_PATH);
	$body   = strpos($path, '/v2/monitoring/metrics/') === 0 ? perf_body($templates, $path) : '{"id":"not_found"}';
	$status = strpos($path, '/v2/monitoring/metrics/') === 0 ? '200 OK' : '404 Not Found';

	@fwrite(
		$conn,
		"HTTP/1.1 $status\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n" .
			"ratelimit-limit: 5000\r\nratelimit-remaining: 4999\r\nratelimit-reset: " . (time() + 3600) . "\r\n" .
			"Connection: close\r\n\r\n" . $body
	);
	fclose($conn);
}
