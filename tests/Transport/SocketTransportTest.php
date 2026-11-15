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
 * The transport against local HTTPS servers, extending the milestone 1
 * spike's cases (REQ-TEST-003). The servers and certificates are the spike's
 * own. The test server is reached through the constructor's test binding (a
 * CA file and an extra port), never through a setting.
 */

use Somethingwithproof\Cloud\GuardedTransport;
use Somethingwithproof\Cloud\HttpRequest;
use Somethingwithproof\Cloud\Operation;
use Somethingwithproof\Cloud\OutboundPolicy;
use Somethingwithproof\Cloud\SocketTransport;
use Somethingwithproof\Cloud\TransportError;

const CLOUD_SPIKE = __DIR__ . '/../transport-spike';

function cloud_free_port(): int {
	$s    = stream_socket_server('tcp://127.0.0.1:0');
	$name = (string) stream_socket_get_name($s, false);
	fclose($s);

	return (int) substr($name, strrpos($name, ':') + 1);
}

/**
 * @param list<string> $args
 */
function cloud_server(array $args): int {
	$port = cloud_free_port();

	$GLOBALS['__cloud_servers'][] = proc_open(
		array_merge([PHP_BINARY, CLOUD_SPIKE . '/server.php', $args[0], (string) $port], array_slice($args, 1)),
		[0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
		$pipes
	);

	// A refused probe raises a warning, which the suite treats as a failure.
	set_error_handler(static fn (): bool => true);

	try {
		for ($i = 0; $i < 100; $i++) {
			$fp = stream_socket_client('tcp://127.0.0.1:' . $port, $e, $s, 0.1);

			if ($fp !== false) {
				fclose($fp);

				return $port;
			}

			usleep(50000);
		}
	} finally {
		restore_error_handler();
	}

	throw new RuntimeException('test server did not start');
}

function cloud_transport(int $port, float $total = 4.0, int $max = 1048576): SocketTransport {
	return new SocketTransport(
		new OutboundPolicy(['127.0.0.1'], [$port]),
		2.0,
		$total,
		$max,
		$GLOBALS['__cloud_certs'] . '/ca.pem'
	);
}

function cloud_kind(callable $call): string {
	try {
		$call();
	} catch (TransportError $e) {
		return $e->kind;
	}

	return 'sent';
}

beforeAll(function () {
	$GLOBALS['__cloud_servers'] = [];
	$GLOBALS['__cloud_certs']   = sys_get_temp_dir() . '/cloud-certs-' . getmypid();
	@mkdir($GLOBALS['__cloud_certs'], 0700);
	exec(
		escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(
			CLOUD_SPIKE . '/make-certs.php'
		) . ' ' . escapeshellarg($GLOBALS['__cloud_certs']),
		$out,
		$rc
	);

	if ($rc !== 0) {
		throw new RuntimeException('make-certs failed');
	}
});

afterAll(function () {
	foreach ($GLOBALS['__cloud_servers'] as $server) {
		proc_terminate($server);
	}
});

it('returns status, headers, and body from a trusted server', function () {
	$port = cloud_server(['tls', $GLOBALS['__cloud_certs'] . '/good.pem']);
	$r    = cloud_transport($port)->send(
		new HttpRequest('GET', "https://127.0.0.1:$port/ok", ['Accept' => 'application/json'])
	);

	expect($r->status)->toBe(200)
		->and($r->body)->toBe('{"ok":true}')
		->and($r->header('X-Spike'))->toBe('one');
});

it('ends HEAD and 204 responses at their headers whatever Content-Length says', function () {
	$port = cloud_server(['tls', $GLOBALS['__cloud_certs'] . '/good.pem']);

	expect(cloud_transport($port)->send(new HttpRequest('HEAD', "https://127.0.0.1:$port/head"))->body)->toBe('')
		->and(cloud_transport($port)->send(new HttpRequest('GET', "https://127.0.0.1:$port/no-content"))->status)->toBe(204)
		->and(
			cloud_kind(fn () => cloud_transport($port)->send(new HttpRequest('GET', "https://127.0.0.1:$port/head")))
		)->toBe('malformed');
});

it('decodes chunked bodies, skips 1xx responses, and returns error bodies', function () {
	$port = cloud_server(['tls', $GLOBALS['__cloud_certs'] . '/good.pem']);
	$t    = cloud_transport($port);

	expect($t->send(new HttpRequest('GET', "https://127.0.0.1:$port/chunked"))->body)->not->toBe('')
		->and($t->send(new HttpRequest('GET', "https://127.0.0.1:$port/early-hints"))->status)->toBe(200)
		->and($t->send(new HttpRequest('GET', "https://127.0.0.1:$port/error"))->status)->toBeGreaterThanOrEqual(400);
});

it('refuses untrusted certificates, wrong names, and redirects', function () {
	$self  = cloud_server(['tls', $GLOBALS['__cloud_certs'] . '/selfsigned.pem']);
	$wrong = cloud_server(['tls', $GLOBALS['__cloud_certs'] . '/wrongname.pem']);
	$good  = cloud_server(['tls', $GLOBALS['__cloud_certs'] . '/good.pem']);

	expect(
		cloud_kind(fn () => cloud_transport($self)->send(new HttpRequest('GET', "https://127.0.0.1:$self/ok")))
	)->toBe('tls_verify')
		->and(
			cloud_kind(fn () => cloud_transport($wrong)->send(new HttpRequest('GET', "https://127.0.0.1:$wrong/ok")))
		)->toBe('tls_name')
		->and(
			cloud_kind(fn () => cloud_transport($good)->send(new HttpRequest('GET', "https://127.0.0.1:$good/redirect")))
		)->toBe('redirect_refused');
});

it('refuses malformed framing', function (string $path, string $kind) {
	$port = cloud_server(['tls', $GLOBALS['__cloud_certs'] . '/good.pem']);

	expect(
		cloud_kind(fn () => cloud_transport($port)->send(new HttpRequest('GET', "https://127.0.0.1:$port$path")))
	)->toBe($kind);
})->with([
	['/bad-terminator', 'malformed'],
	['/bad-chunk', 'malformed'],
	['/dup-length', 'malformed'],
	['/huge-header?n=1048576', 'headers_too_large'],
	['/header-flood', 'headers_too_large'],
	['/big?n=20000000&len=0', 'too_large'],
	['/big-withheld?n=20000000', 'too_large'],
]);

it('stops slow and stalled responses at the total timeout', function (string $path) {
	$port  = cloud_server(['tls', $GLOBALS['__cloud_certs'] . '/good.pem']);
	$start = microtime(true);

	expect(
		cloud_kind(fn () => cloud_transport($port, 2.0)->send(new HttpRequest('GET', "https://127.0.0.1:$port$path")))
	)->toBe('total_timeout')
		->and(microtime(true) - $start)->toBeLessThan(3.5);
})->with(['/stall', '/slow-body', '/slow-headers']);

it('stops a TLS handshake that is never answered within the deadline', function () {
	$port  = cloud_server(['silent']);
	$start = microtime(true);

	expect(
		cloud_kind(fn () => cloud_transport($port, 1.5)->send(new HttpRequest('GET', "https://127.0.0.1:$port/ok")))
	)->toBeIn(['connect_timeout', 'total_timeout', 'open_failed'])
		->and(microtime(true) - $start)->toBeLessThan(3.0);
});

it('refuses header injection before sending', function () {
	$port = cloud_server(['tls', $GLOBALS['__cloud_certs'] . '/good.pem']);

	expect(
		cloud_kind(
			fn () => cloud_transport($port)->send(
				new HttpRequest('GET', "https://127.0.0.1:$port/echo", ['X-Test' => "a\r\nX-Injected: 1"])
			)
		)
	)->toBe('header_rejected');
});

it('refuses URLs outside the policy without opening a socket', function (string $url, string $kind) {
	// Port 9 on this host has nothing listening: a refusal that tried to
	// connect would report a connect error instead.
	$t = new SocketTransport(new OutboundPolicy(['api.example.com'], [443]), 0.5, 1.0);

	expect(cloud_kind(fn () => $t->send(new HttpRequest('GET', $url))))->toBe($kind);
})->with([
	['http://api.example.com/v2', 'url_rejected'],
	['https://other.example.com/v2', 'host_refused'],
	['https://127.0.0.1:9/v2', 'host_refused'],
	['https://user:pass@api.example.com/v2', 'url_rejected'],
	['https://api.example.com/v2#frag', 'url_rejected'],
	['https://api.example.com\\@evil.example/', 'url_rejected'],
	['https://api.example.com:9/v2', 'url_rejected'],
	['https://api.example.com:+443/v2', 'url_rejected'],
	["https://api.example.com/v2\r\nX: 1", 'url_rejected'],
	['https://api.example.com/v2/../admin', 'url_rejected'],
	['https://api.example.com/v2/%2e%2e/admin', 'url_rejected'],
	['https://api.example.com/v2/%2E./admin', 'url_rejected'],
	['https://api.example.com/v2%2fdroplets', 'url_rejected'],
	['https://api.example.com/v2%5cdroplets', 'url_rejected'],
	['https://API.example.com/v2', 'url_rejected'],
	["https://api.example.com/\xc3\xa9", 'url_rejected'],
]);

it('refuses operations that are not on the driver list before they are sent', function () {
	$sent  = new class implements \Somethingwithproof\Cloud\HttpTransport {
		public int $calls = 0;

		public function send(HttpRequest $request): \Somethingwithproof\Cloud\HttpResponse {
			$this->calls++;

			return new \Somethingwithproof\Cloud\HttpResponse(200, [], '');
		}
	};
	$guard = new GuardedTransport($sent, [
		new Operation('List droplets', 'GET', 'api.example.com', '#^/v2/droplets$#'),
		new Operation('Read metrics', 'POST', 'api.example.com', '#^/$#', '/^Action=GetMetricData(&|$)/'),
		new Operation('Page droplets', 'GET', 'api.example.com', '#^/v2/droplets$#', null, '/^page=\d+&per_page=\d+$/'),
	]);
	$form  = ['Content-Type' => 'application/x-www-form-urlencoded'];

	$guard->send(new HttpRequest('GET', 'https://api.example.com/v2/droplets'));
	$guard->send(new HttpRequest('POST', 'https://api.example.com/', $form, 'Action=GetMetricData&Version=2010-08-01'));
	$guard->send(new HttpRequest('GET', 'https://api.example.com/v2/droplets?page=2&per_page=200'));

	expect(
		cloud_kind(fn () => $guard->send(new HttpRequest('DELETE', 'https://api.example.com/v2/droplets')))
	)->toBe('operation_refused')
		->and(
			cloud_kind(fn () => $guard->send(new HttpRequest('GET', 'https://api.example.com/v2/droplets/1/actions')))
		)->toBe('operation_refused')
		->and(
			cloud_kind(fn () => $guard->send(new HttpRequest('POST', 'https://api.example.com/', [], 'Action=PutMetricAlarm')))
		)->toBe('operation_refused')
		->and(
			cloud_kind(fn () => $guard->send(new HttpRequest('GET', 'https://api.example.com/v2/droplets', [], 'x')))
		)->toBe('operation_refused')
		->and(
			cloud_kind(
				fn () => $guard->send(
					new HttpRequest('POST', 'https://api.example.com/', $form, 'Action=GetMetricData&Action=PutMetricAlarm')
				)
			)
		)->toBe('operation_refused')
		->and(
			cloud_kind(
				fn () => $guard->send(
					new HttpRequest(
						'POST',
						'https://api.example.com/',
						['CONTENT-TYPE' => 'Application/X-WWW-Form-Urlencoded; charset=utf-8'],
						'Action=GetMetricData&Action=PutMetricAlarm'
					)
				)
			)
		)->toBe('operation_refused')
		->and(
			cloud_kind(
				fn () => $guard->send(
					new HttpRequest(
						'POST',
						'https://api.example.com/',
						$form + ['CONTENT-TYPE' => 'text/plain'],
						'Action=GetMetricData&Action=PutMetricAlarm'
					)
				)
			)
		)->toBe('operation_refused')
		->and(
			cloud_kind(
				fn () => $guard->send(
					new HttpRequest(
						'POST',
						'https://api.example.com/',
						$form + ['content-type' => 'application/x-www-form-urlencoded'],
						'Action=GetMetricData'
					)
				)
			)
		)->toBe('operation_refused')
		->and(
			cloud_kind(
				fn () => $guard->send(
					new HttpRequest('POST', 'https://api.example.com/', $form, 'Action=GetMetricData&action=DeleteAlarms')
				)
			)
		)->toBe('operation_refused')
		->and(
			cloud_kind(
				fn () => $guard->send(
					new HttpRequest('POST', 'https://api.example.com/?Action=PutMetricAlarm', $form, 'Action=GetMetricData')
				)
			)
		)->toBe('operation_refused')
		->and(
			cloud_kind(
				fn () => $guard->send(new HttpRequest('GET', 'https://api.example.com/v2/droplets?page=1&page=2&per_page=1'))
			)
		)->toBe('operation_refused')
		->and(
			cloud_kind(fn () => $guard->send(new HttpRequest('GET', 'https://api.example.com/v2/droplets?tag_name=x')))
		)->toBe('operation_refused')
		->and($sent->calls)->toBe(3);
});
