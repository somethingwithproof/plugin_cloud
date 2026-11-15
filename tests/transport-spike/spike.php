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
 * Runs every transport case against local servers and prints one JSON line
 * per case: php spike.php <cert dir>
 * Each case states the behavior the plan requires, so "pass" means the
 * stream wrapper transport met the requirement, not that the code ran.
 */

require __DIR__ . '/StreamTransport.php';
require __DIR__ . '/SocketTransport.php';
require __DIR__ . '/CurlTransport.php';

$certs = $argv[1] ?? '';
$kind = $argv[2] ?? 'stream';
$class = array('stream' => 'StreamTransport', 'socket' => 'SocketTransport', 'curl' => 'CurlTransport')[$kind];
$connectTimeout = 2.0;
$totalTimeout = 4.0;

function freePort(): int {
	$s = stream_socket_server('tcp://127.0.0.1:0');
	$name = stream_socket_get_name($s, false);
	fclose($s);

	return (int) substr($name, strrpos($name, ':') + 1);
}

$servers = array();
function start(array $args): int {
	global $servers;
	$port = freePort();
	$cmd = array_merge(array(PHP_BINARY, __DIR__ . '/server.php'), array($args[0], (string) $port), array_slice($args, 1));
	$servers[] = proc_open($cmd, array(0 => array('file', '/dev/null', 'r'), 1 => STDERR, 2 => STDERR), $pipes);
	for ($i = 0; $i < 60; $i++) {
		$fp = @fsockopen('127.0.0.1', $port, $e, $s, 0.1);
		if ($fp !== false) {
			fclose($fp);

			return $port;
		}
		usleep(50000);
	}
	fwrite(STDERR, "server did not start: {$args[0]}\n");
	exit(1);
}

$good = start(array('tls', "$certs/good.pem"));
// The test server is sequential, so a case that stalls it gets its own.
$fresh = fn () => 'https://127.0.0.1:' . start(array('tls', $GLOBALS['certs'] . '/good.pem'));
$wrong = start(array('tls', "$certs/wrongname.pem"));
$self = start(array('tls', "$certs/selfsigned.pem"));
$silent = start(array('silent'));
$noread = start(array('noread', "$certs/good.pem"));

$t = new $class("$certs/ca.pem", 1048576);
$base = "https://127.0.0.1:$good";

/**
 * @param callable(): array $run returns [observed, pass]
 */
function check(string $name, string $requirement, callable $run): void {
	$start = microtime(true);
	try {
		[$observed, $pass] = $run();
	} catch (TransportError $e) {
		[$observed, $pass] = array($e->getMessage(), null);
	}
	$row = array(
		'transport'   => $GLOBALS['kind'],
		'php'         => PHP_VERSION,
		'case'        => $name,
		'requirement' => $requirement,
		'observed'    => $observed,
		'elapsed_s'   => round(microtime(true) - $start, 2),
		'pass'        => $pass,
	);
	echo json_encode($row, JSON_UNESCAPED_SLASHES), "\n";
}

/** Runs $call and expects a TransportError of $kind. */
function expectError(callable $call, string $kind, ?float $maxSeconds = null): array {
	$start = microtime(true);
	try {
		$r = $call();

		return array('no error, status ' . $r['status'], false);
	} catch (TransportError $e) {
		$elapsed = microtime(true) - $start;
		$pass = $e->kind === $kind && ($maxSeconds === null || $elapsed <= $maxSeconds);

		return array($e->getMessage(), $pass);
	}
}

$get = fn (string $url) => fn () => $t->send('GET', $url, array(), null, $connectTimeout, $totalTimeout);

check('trusted certificate', 'request succeeds, status and headers readable', function () use (
	$t,
	$base,
	$connectTimeout,
	$totalTimeout
) {
	$r = $t->send('GET', "$base/ok", array('Accept' => 'application/json'), null, $connectTimeout, $totalTimeout);
	$pass = $r['status'] === 200 && $r['body'] === '{"ok":true}' && ($r['headers']['x-spike'] ?? '') === 'one'
		&& ($r['headers']['ratelimit-remaining'] ?? '') === '4999';

	return array("status {$r['status']}, x-spike={$r['headers']['x-spike']}, body {$r['body']}", $pass);
});

check(
	'untrusted certificate',
	'refused before any request is sent',
	fn () => expectError($get("https://127.0.0.1:$self/ok"), 'tls_verify')
);

check(
	'host name mismatch',
	'refused before any request is sent',
	fn () => expectError($get("https://127.0.0.1:$wrong/ok"), 'tls_name')
);

check('redirect', 'not followed; target never requested', function () use (
	$t,
	$base,
	$get,
	$connectTimeout,
	$totalTimeout
) {
	$t->send('GET', "$base/log", array(), null, $connectTimeout, $totalTimeout);
	[$observed, $pass] = expectError($get("$base/redirect"), 'redirect_refused');
	$log = json_decode($t->send('GET', "$base/log", array(), null, $connectTimeout, $totalTimeout)['body'], true);
	$followed = in_array('GET /redirect-target', $log, true);

	return array($observed . '; server log ' . json_encode($log), $pass && !$followed);
});

check('HTTP error body', '4xx status and body returned to the caller', function () use (
	$t,
	$base,
	$connectTimeout,
	$totalTimeout
) {
	$r = $t->send('GET', "$base/error", array(), null, $connectTimeout, $totalTimeout);

	return array(
		"status {$r['status']}, body {$r['body']}",
		$r['status'] === 403 && str_contains($r['body'], 'missing scope')
	);
});

check('POST body and headers', 'method, headers and body reach the server unchanged', function () use (
	$t,
	$base,
	$connectTimeout,
	$totalTimeout
) {
	$form = 'Action=GetMetricData&Version=2010-08-01';
	$r = $t->send(
		'POST',
		"$base/echo",
		array('Content-Type' => 'application/x-www-form-urlencoded', 'X-Amz-Date' => '20261114T000000Z'),
		$form,
		$connectTimeout,
		$totalTimeout
	);
	$e = json_decode($r['body'], true);
	$pass = $e['method'] === 'POST' && $e['body'] === $form && $e['headers']['x-amz-date'] === '20261114T000000Z'
		&& $e['headers']['content-length'] === (string) strlen($form) && !isset($e['headers']['accept-encoding']);

	return array(
		'server saw ' . json_encode(array(
			'method' => $e['method'],
			'content-length' => $e['headers']['content-length'] ?? null,
			'host' => $e['headers']['host'] ?? null
		)),
		$pass
	);
});

check('PUT with empty body', 'IMDSv2 token request shape (PUT, no body) is sent', function () use (
	$t,
	$base,
	$connectTimeout,
	$totalTimeout
) {
	$r = $t->send(
		'PUT',
		"$base/echo",
		array('X-aws-ec2-metadata-token-ttl-seconds' => '21600'),
		null,
		$connectTimeout,
		$totalTimeout
	);
	$e = json_decode($r['body'], true);

	return array(
		'server saw ' . json_encode(array(
			'method' => $e['method'],
			'content-length' => $e['headers']['content-length'] ?? null
		)),
		$e['method'] === 'PUT'
	);
});

check('chunked response', 'decoded body returned', function () use ($t, $base, $connectTimeout, $totalTimeout) {
	$r = $t->send('GET', "$base/chunked", array(), null, $connectTimeout, $totalTimeout);

	return array('body ' . json_encode($r['body']), $r['body'] === 'hello world');
});

// The server declares the size and withholds the body, so only a refusal
// from the header can finish inside the limit.
check(
	'oversized, Content-Length declared',
	'refused from the header without reading the body',
	fn () => expectError($get($fresh() . "/big-withheld?n=20000000"), 'too_large', 1.0)
);

check(
	'oversized, no Content-Length',
	'cut off at the cap',
	fn () => expectError($get("$base/big?n=20000000&len=0"), 'too_large', 2.0)
);

check(
	'stalled body',
	'aborted at the total timeout',
	fn () => expectError($get($fresh() . "/stall"), 'total_timeout', $totalTimeout + 0.5)
);

check(
	'slow body (1 byte per 0.5 s)',
	'aborted at the total timeout',
	fn () => expectError($get($fresh() . "/slow-body"), 'total_timeout', $totalTimeout + 0.5)
);

check('slow headers (1 byte per 0.5 s)', 'aborted at the total timeout', function () use ($get, $totalTimeout) {
	$start = microtime(true);
	try {
		$r = $get($GLOBALS['fresh']() . '/slow-headers')();
		$elapsed = microtime(true) - $start;

		return array(
			sprintf('completed with status %d after %.1f s', $r['status'], $elapsed),
			$elapsed <= $totalTimeout + 0.5
		);
	} catch (TransportError $e) {
		$elapsed = microtime(true) - $start;

		return array(sprintf('%s after %.1f s', $e->getMessage(), $elapsed), $elapsed <= $totalTimeout + 0.5);
	}
});

check('TLS handshake never answered', 'aborted within the connect timeout', function () use (
	$get,
	$silent,
	$connectTimeout
) {
	$start = microtime(true);
	[$observed] = expectError($get("https://127.0.0.1:$silent/ok"), 'connect_timeout');
	$elapsed = microtime(true) - $start;

	return array($observed, $elapsed <= $connectTimeout + 0.5);
});

check('total timeout shorter than connect timeout', 'aborted at the total timeout', function () use (
	$t,
	$silent,
	$connectTimeout
) {
	$start = microtime(true);
	try {
		$t->send('GET', "https://127.0.0.1:$silent/ok", array(), null, $connectTimeout, 0.25);
		$observed = 'no error';
		$kind = null;
	} catch (TransportError $e) {
		$observed = $e->getMessage();
		$kind = $e->kind;
	}
	$elapsed = microtime(true) - $start;

	// A timeout kind after waiting at least most of the budget; an immediate
	// unrelated error must not count.
	$timedOut = in_array($kind, array('connect_timeout', 'total_timeout'), true);

	return array(sprintf('%s after %.2f s', $observed, $elapsed), $timedOut && $elapsed >= 0.2 && $elapsed <= 0.75);
});

check('peer stops reading the request', 'aborted at the total timeout', function () use (
	$t,
	$noread,
	$connectTimeout,
	$totalTimeout
) {
	$start = microtime(true);
	try {
		$t->send(
			'POST',
			"https://127.0.0.1:$noread/echo",
			array('Content-Type' => 'application/octet-stream'),
			str_repeat('u', 32 * 1048576),
			$connectTimeout,
			$totalTimeout
		);
		$observed = 'no error';
		$kind = null;
	} catch (TransportError $e) {
		$observed = $e->getMessage();
		$kind = $e->kind;
	}
	$elapsed = microtime(true) - $start;

	// The handshake completes at once, so only a write held until the total
	// timeout can produce total_timeout after waiting most of the budget.
	return array(
		sprintf('%s after %.1f s', $observed, $elapsed),
		$kind === 'total_timeout' && $elapsed >= $totalTimeout - 0.5 && $elapsed <= $totalTimeout + 0.5
	);
});

check('connect to an address that drops packets', 'aborted within the connect timeout', function () use (
	$get,
	$connectTimeout
) {
	$start = microtime(true);
	[$observed] = expectError($get('https://192.0.2.1/ok'), 'connect_timeout');
	$elapsed = microtime(true) - $start;

	return array($observed, $elapsed <= $connectTimeout + 0.5);
});

check('1 MiB response header', 'refused or bounded; no unbounded memory', function () use (
	$t,
	$fresh,
	$connectTimeout,
	$totalTimeout
) {
	// The peak is reset so an earlier case cannot hide this one's growth.
	memory_reset_peak_usage();
	$before = memory_get_usage();
	try {
		$r = $t->send('GET', $fresh() . "/huge-header?n=1048576", array(), null, $connectTimeout, $totalTimeout);
		$observed = sprintf('status %d, x-huge %d bytes', $r['status'], strlen($r['headers']['x-huge'] ?? ''));
	} catch (TransportError $e) {
		$observed = $e->getMessage();
	}
	$grew = memory_get_peak_usage() - $before;

	return array($observed . sprintf('; peak memory grew %.1f MiB', $grew / 1048576), $grew < 4 * 1048576);
});

check('20,000 response headers', 'refused or bounded', function () use ($t, $fresh, $connectTimeout, $totalTimeout) {
	// The peak is reset so an earlier case cannot hide this one's growth.
	memory_reset_peak_usage();
	$before = memory_get_usage();
	try {
		$r = $t->send('GET', $fresh() . "/many-headers?n=20000", array(), null, $connectTimeout, $totalTimeout);
		$observed = sprintf('status %d, %d headers parsed', $r['status'], count($r['headers']));
	} catch (TransportError $e) {
		$observed = $e->getMessage();
	}
	$grew = memory_get_peak_usage() - $before;

	return array($observed . sprintf('; peak memory grew %.1f MiB', $grew / 1048576), $grew < 4 * 1048576);
});

check('proxy environment variables', 'ignored; the request goes direct', function () use (
	$t,
	$base,
	$connectTimeout,
	$totalTimeout
) {
	putenv('HTTPS_PROXY=http://127.0.0.1:9');
	putenv('https_proxy=http://127.0.0.1:9');
	putenv('ALL_PROXY=http://127.0.0.1:9');
	$r = $t->send('GET', "$base/ok", array(), null, $connectTimeout, $totalTimeout);
	putenv('HTTPS_PROXY');
	putenv('https_proxy');
	putenv('ALL_PROXY');

	return array("status {$r['status']} with proxy variables pointing at a closed port", $r['status'] === 200);
});

check('allow_url_fopen=0', 'stream: detected and reported; socket: request still succeeds', function () use (
	$certs,
	$good,
	$class
) {
	$code = 'require ' . var_export(
		__DIR__ . '/SocketTransport.php',
		true
	) . '; require ' . var_export(__DIR__ . '/CurlTransport.php', true) . ';'
		. '$t = new ' . $class . '(' . var_export("$certs/ca.pem", true) . ');'
		. 'try { $r = $t->send("GET", "https://127.0.0.1:' . $good . '/ok", array(), null, 2, 4); '
		. 'echo "sent:", $r["status"]; } catch (TransportError $e) { echo $e->kind; }'
		. 'echo @fopen("https://127.0.0.1:' . $good . '/ok", "rb") === false ? " fopen:refused" : " fopen:opened";';
	$p = proc_open(
		array(PHP_BINARY, '-d', 'allow_url_fopen=0', '-r', $code),
		array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
		$pipes
	);
	$out = trim(stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
	proc_close($p);
	$expect = $class === 'StreamTransport' ? 'allow_url_fopen_off fopen:refused' : 'sent:200 fopen:refused';

	return array($out, $out === $expect);
});

check('103 Early Hints before the final response', 'final status and body returned', function () use (
	$t,
	$base,
	$connectTimeout,
	$totalTimeout
) {
	$r = $t->send('GET', "$base/early-hints", array(), null, $connectTimeout, $totalTimeout);

	return array(
		'status ' . $r['status'] . ', body ' . json_encode($r['body']),
		$r['status'] === 200 && $r['body'] === 'final'
	);
});

check('chunked body without its final empty line', 'reported as an error, not returned as data', function () use (
	$t,
	$fresh,
	$connectTimeout,
	$totalTimeout
) {
	try {
		$r = $t->send('GET', $fresh() . '/bad-terminator', array(), null, $connectTimeout, $totalTimeout);

		return array('status ' . $r['status'] . ', body ' . json_encode($r['body']), false);
	} catch (TransportError $e) {
		return array($e->getMessage(), true);
	}
});

check('malformed chunk size', 'reported as an error, not returned as data', function () use (
	$t,
	$fresh,
	$connectTimeout,
	$totalTimeout
) {
	try {
		$r = $t->send('GET', $fresh() . '/bad-chunk', array(), null, $connectTimeout, $totalTimeout);

		return array('status ' . $r['status'] . ', body ' . json_encode($r['body']), false);
	} catch (TransportError $e) {
		return array($e->getMessage(), true);
	}
});

check('conflicting Content-Length', 'refused', function () use ($t, $fresh, $connectTimeout, $totalTimeout) {
	try {
		$r = $t->send('GET', $fresh() . '/dup-length', array(), null, $connectTimeout, $totalTimeout);

		return array('status ' . $r['status'] . ', body ' . strlen($r['body']) . ' bytes', false);
	} catch (TransportError $e) {
		return array($e->getMessage(), true);
	}
});

check('64 MiB of response headers', 'bounded; refused without buffering it all', function () use (
	$t,
	$fresh,
	$connectTimeout,
	$totalTimeout
) {
	// The peak is reset so an earlier case cannot hide this one's growth.
	memory_reset_peak_usage();
	$before = memory_get_usage();
	try {
		$r = $t->send('GET', $fresh() . '/header-flood', array(), null, $connectTimeout, $totalTimeout);
		$observed = 'status ' . $r['status'] . ', ' . count($r['headers']) . ' headers';
	} catch (TransportError $e) {
		$observed = $e->getMessage();
	}
	$grew = memory_get_peak_usage() - $before;

	return array($observed . sprintf('; peak memory grew %.1f MiB', $grew / 1048576), $grew < 4 * 1048576);
});

check('CRLF in a request header value', 'refused before sending', function () use (
	$t,
	$base,
	$connectTimeout,
	$totalTimeout
) {
	try {
		$r = $t->send('GET', "$base/echo", array('X-Test' => "a\r\nX-Injected: 1"), null, $connectTimeout, $totalTimeout);
		$e = json_decode($r['body'], true);

		return array('sent; server saw x-injected=' . json_encode($e['headers']['x-injected'] ?? null), false);
	} catch (TransportError $e) {
		return array($e->getMessage(), true);
	}
});

foreach ($servers as $p) {
	proc_terminate($p, 9);
}
