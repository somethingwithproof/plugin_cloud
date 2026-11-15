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
 * Performance runs (#47), on a disposable database and a running Cacti:
 *
 *   collect   1,000 series in four accounts of 250, the most one token
 *             reads in a run, timed against the fixture server over TLS
 *   budget    1,000 series in one account, where the request budget stops
 *             the run at 250 and leaves the rest unread
 *   page      the health page over HTTP at 10,000 cached series
 *
 * The real driver, guarded transport, and socket transport run; only the
 * host is rewritten to the fixture server after the operation check.
 * Prints the results as JSON with the hardware and settings, and exits 1
 * when the 1,000-series run does not finish within one poller interval.
 * Must parse on PHP 8.0.
 *
 *   CLOUD_TEST_DATABASE=disposable php plugins/cloud/tests/bin/perf.php http://127.0.0.1:8080/cacti/ [latency ms]
 *
 * A latency, in milliseconds, delays each fixture response to stand in for
 * the provider's round trip.
 */

use Somethingwithproof\Cloud\Collector;
use Somethingwithproof\Cloud\CredentialStore;
use Somethingwithproof\Cloud\DigitalOceanDriver;
use Somethingwithproof\Cloud\Drivers;
use Somethingwithproof\Cloud\HealthRepository;
use Somethingwithproof\Cloud\HttpRequest;
use Somethingwithproof\Cloud\HttpResponse;
use Somethingwithproof\Cloud\HttpTransport;
use Somethingwithproof\Cloud\LeaseRepository;
use Somethingwithproof\Cloud\ObservationRepository;
use Somethingwithproof\Cloud\OutboundPolicy;
use Somethingwithproof\Cloud\Provider;
use Somethingwithproof\Cloud\ProviderDriver;
use Somethingwithproof\Cloud\Redactor;
use Somethingwithproof\Cloud\ResourceRepository;
use Somethingwithproof\Cloud\SecretRepository;
use Somethingwithproof\Cloud\SeriesRepository;
use Somethingwithproof\Cloud\SocketTransport;
use Somethingwithproof\Cloud\SystemClock;

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');

if (getenv('CLOUD_TEST_DATABASE') !== 'disposable' || !isset($argv[1])) {
	fwrite(STDERR, "Set CLOUD_TEST_DATABASE=disposable and pass the Cacti base URL\n");
	exit(2);
}

include_once($config['base_path'] . '/plugins/cloud/setup.php');

if (!cloud_boot()) {
	fwrite(STDERR, "The plugin did not boot; install and enable it first\n");
	exit(2);
}

const PERF_METRICS = array('do_cpu_pct', 'do_bw_pub_in', 'do_bw_pub_out', 'do_bw_prv_in', 'do_bw_prv_out');
const PERF_LOCAL   = 9000000;

$root     = rtrim($argv[1], '/') . '/';
$tmp      = sys_get_temp_dir() . '/cloud-perf-' . getmypid();
$password = 'Perf-' . bin2hex(random_bytes(8));
$poller   = (int) read_config_option('poller_interval');
$poller   = $poller > 0 ? $poller : 300;
$latency  = max(0, (int) ($argv[2] ?? 0));

mkdir($tmp, 0700);
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../transport-spike/make-certs.php') . ' '
	. escapeshellarg($tmp) . ' >/dev/null');
file_put_contents($tmp . '/token', 'dop_v1_' . str_repeat('p', 64));
chmod($tmp . '/token', 0600);

$probe = stream_socket_server('tcp://127.0.0.1:0');
$name  = (string) stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr($name, strrpos($name, ':') + 1);

$server = proc_open(
	array(PHP_BINARY, __DIR__ . '/perf-server.php', (string) $port, $tmp . '/good.pem', '1800', (string) $latency),
	array(
		0 => array('file', '/dev/null', 'r'),
		1 => array('file', '/dev/null', 'w'),
		2 => array('file', '/dev/null', 'w'),
	),
	$pipes
);

// Cacti's error handler logs a refused probe even under @, so wait quietly.
set_error_handler(static function () {
	return true;
});

for ($i = 0; $i < 100 && stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.1) === false; $i++) {
	usleep(50000);
}

restore_error_handler();

/**
 * Sends each request to the fixture server instead of the provider, after
 * the guarded transport has checked it against the driver's operations.
 */
final class PerfTransport implements HttpTransport {
	private SocketTransport $inner;
	private string $origin;

	public function __construct(int $port, string $caFile) {
		$policy       = new OutboundPolicy(array('127.0.0.1'), array($port));
		$this->inner  = new SocketTransport($policy, 5.0, 30.0, 5242880, $caFile);
		$this->origin = 'https://127.0.0.1:' . $port . '/';
	}

	public function send(HttpRequest $request): HttpResponse {
		$prefix = 'https://' . DigitalOceanDriver::HOST . '/';
		$url    = strpos($request->url, $prefix) === 0
			? $this->origin . substr($request->url, strlen($prefix))
			: $request->url;

		return $this->inner->send(new HttpRequest($request->method, $url, $request->headers, $request->body));
	}
}

function perf_reset() {
	foreach (array('plugin_cloud_observation', 'plugin_cloud_series', 'plugin_cloud_health', 'plugin_cloud_lease',
		'plugin_cloud_resource', 'plugin_cloud_account') as $table) {
		db_execute('DELETE FROM ' . $table);
	}

	db_execute_prepared('DELETE FROM data_template_data WHERE local_data_id >= ?', array(PERF_LOCAL));
}

/**
 * Insert rows in chunks of 500.
 *
 * @param list<list<mixed>> $rows
 */
function perf_insert($sql, $rows) {
	foreach (array_chunk($rows, 500) as $chunk) {
		$marks = '(' . implode(', ', array_fill(0, count($chunk[0]), '?')) . ')';
		db_execute_prepared($sql . implode(', ', array_fill(0, count($chunk), $marks)), array_merge(...$chunk));
	}
}

/**
 * $accounts accounts of $droplets Droplets with five agent-free series
 * each, discovered just now so no run rediscovers them.
 */
function perf_world($accounts, $droplets, $observed) {
	global $tmp;

	perf_reset();
	$now   = time();
	$local = PERF_LOCAL;

	for ($a = 1; $a <= $accounts; $a++) {
		db_execute_prepared(
			"INSERT INTO plugin_cloud_account (provider, name, poller_id, credential_source, credential_file,
				collect_interval, discover_interval, request_share, admin_state)
				VALUES ('digitalocean', ?, 1, 'file', ?, 300, 86400, 100, 'enabled')",
			array('Perf ' . $a, $tmp . '/token')
		);
		$account = (int) db_fetch_insert_id();
		db_execute_prepared(
			"INSERT INTO plugin_cloud_health (account_id, discovered_at, discovery) VALUES (?, ?, 'ok')",
			array($account, $now)
		);

		$resources = array();

		for ($d = 1; $d <= $droplets; $d++) {
			$id          = (string) ($a * 100000 + $d);
			$resources[] = array($account, 'droplet', $id, 'perf-' . $a . '-' . $d, 'active', $now, $now);
		}

		perf_insert(
			'INSERT INTO plugin_cloud_resource (account_id, type, provider_id, name, lifecycle, first_seen, last_seen) VALUES ',
			$resources
		);

		$sources = array();
		$series  = array();
		$points  = array();

		$rows = db_fetch_assoc_prepared('SELECT id FROM plugin_cloud_resource WHERE account_id = ?', array($account));

		foreach ($rows as $row) {
			foreach (PERF_METRICS as $metric) {
				$sources[] = array($local, 0, 'perf', 'on');
				$series[]  = array($local, $account, (int) $row['id'], $metric, $now);
				$points[]  = array((int) $row['id'], $metric, 1.5, $now - 60, $now - 30, 'ok');
				$local++;
			}
		}

		perf_insert('INSERT INTO data_template_data (local_data_id, data_template_id, name, active) VALUES ', $sources);
		perf_insert(
			'INSERT INTO plugin_cloud_series (local_data_id, account_id, resource_id, metric, created_at) VALUES ',
			$series
		);

		if ($observed) {
			perf_insert(
				'INSERT INTO plugin_cloud_observation (resource_id, metric, value, observed_at, fetched_at, status) VALUES ',
				$points
			);
		}
	}
}

/**
 * One collector run on poller 1, timed, with what the accounts recorded.
 */
function perf_collect() {
	global $port, $tmp, $poller;

	$clock   = new SystemClock();
	$drivers = new Drivers(static function (OutboundPolicy $policy) use ($port, $tmp): HttpTransport {
		return new PerfTransport($port, $tmp . '/ca.pem');
	});
	$drivers->register(Provider::DigitalOcean, static function (HttpTransport $http): ProviderDriver {
		return new DigitalOceanDriver($http, new SystemClock());
	});
	$collector = new Collector(
		new \Somethingwithproof\Cloud\AccountRepository(false, $clock),
		$drivers,
		new CredentialStore(new SecretRepository(false, $clock), '/nonexistent', $tmp),
		new LeaseRepository(false, $clock),
		new ResourceRepository(false, $clock),
		new SeriesRepository(false),
		new ObservationRepository(false),
		new HealthRepository(false),
		new Redactor(),
		$clock,
		'perf'
	);

	$started = microtime(true);
	$collector->run(1, max(10, $poller - 10));
	$seconds = microtime(true) - $started;
	$health  = db_fetch_row(
		'SELECT SUM(series_total) AS total, SUM(series_read) AS read_count, SUM(requests) AS requests,
			GROUP_CONCAT(DISTINCT collection) AS collection FROM plugin_cloud_health'
	);

	return array(
		'seconds'      => round($seconds, 2),
		'series'       => (int) $health['total'],
		'series_read'  => (int) $health['read_count'],
		'requests'     => (int) $health['requests'],
		'collection'   => (string) $health['collection'],
		'observations' => (int) db_fetch_cell("SELECT COUNT(*) FROM plugin_cloud_observation WHERE status = 'ok'"),
	);
}

function perf_http($url, $jar, $post = null) {
	$ch = curl_init($url);
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_COOKIEJAR      => $jar,
		CURLOPT_COOKIEFILE     => $jar,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_TIMEOUT        => 120,
	));

	if ($post !== null) {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
	}

	$started = microtime(true);
	$body    = (string) curl_exec($ch);

	return array((int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $body, microtime(true) - $started);
}

/**
 * The health page as a logged-in administrator: the median of five loads
 * after one warm-up, and whether every load rendered the estimate.
 */
function perf_page() {
	global $root, $password, $tmp;

	db_execute_prepared(
		"UPDATE user_auth SET password = ?, must_change_password = '', password_change = '', enabled = 'on',
			locked = '', failed_attempts = 0 WHERE id = 1",
		array(password_hash($password, PASSWORD_DEFAULT))
	);

	$jar = $tmp . '/cookies';
	list(, $login) = perf_http($root . 'index.php', $jar);
	preg_match('/csrfMagicToken\s*=\s*"([^"]+)"/', $login, $m);
	perf_http($root . 'index.php', $jar, array(
		'__csrf_magic'   => isset($m[1]) ? $m[1] : '',
		'action'         => 'login',
		'login_username' => 'admin',
		'login_password' => $password,
	));

	$times    = array();
	$rendered = true;

	for ($i = 0; $i < 6; $i++) {
		list($code, $body, $seconds) = perf_http($root . 'plugins/cloud/cloud_health.php', $jar);
		$rendered = $rendered && $code === 200 && strpos($body, 'Cloud Request Estimate') !== false
			&& strpos($body, 'Perf 10') !== false;

		if ($i > 0) {
			$times[] = $seconds;
		}
	}

	sort($times);

	return array('seconds' => round($times[2], 2), 'slowest' => round(end($times), 2), 'rendered' => $rendered);
}

// lscpu names ARM and x86 processors alike; a VM may hide the model.
$cpu = array();

foreach (explode("\n", (string) shell_exec('lscpu 2>/dev/null')) as $line) {
	if (preg_match('/^(Vendor ID|Model name):\s*(.+)$/', $line, $field) === 1 && trim($field[2]) !== '-') {
		$cpu[] = trim($field[2]);
	}
}

$memory = preg_match('/^MemTotal:\s+(\d+)/m', (string) @file_get_contents('/proc/meminfo'), $mem) === 1
	? round((int) $mem[1] / 1048576, 1) . ' GiB'
	: '';

perf_world(4, 50, false);
$collect = perf_collect();
perf_world(1, 200, false);
$budget = perf_collect();
perf_world(10, 200, true);
$page = perf_page();
perf_reset();

proc_terminate($server);

foreach (array('token', 'cookies', 'ca.pem', 'good.pem', 'wrongname.pem', 'selfsigned.pem', 'openssl.cnf') as $file) {
	@unlink($tmp . '/' . $file);
}

@rmdir($tmp);

$within = $collect['series_read'] === 1000 && $collect['seconds'] < $poller;

print json_encode(array(
	'environment' => array(
		'cpu'             => implode(' ', $cpu),
		'cores'           => trim((string) shell_exec('nproc')),
		'memory'          => $memory,
		'machine'         => php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m'),
		'php'             => PHP_VERSION,
		'database'        => (string) db_fetch_cell('SELECT VERSION()'),
		'cacti'           => CACTI_VERSION,
		'poller_interval' => $poller,
		'latency_ms'      => $latency,
	),
	'collect_1000_in_4_accounts' => $collect,
	'budget_1000_in_1_account'   => $budget,
	'health_page_10000_series'   => $page,
	'within_poller_interval'     => $within,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($within ? 0 : 1);
