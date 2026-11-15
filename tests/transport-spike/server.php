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
 * Test server for the transport spike:
 *   php server.php tls <port> <pem>   HTTPS, one connection at a time
 *   php server.php silent <port>      accepts TCP and never answers
 *   php server.php noread <port> <pem> completes TLS, never reads
 * Sequential on purpose: the client runs one case at a time, and a slow
 * case ends when the client hangs up and a write fails. The 300 second
 * cap only stops an orphan left by a crashed run.
 */

$mode = $argv[1] ?? '';
$port = (int) ($argv[2] ?? 0);
$deadline = time() + 300;

if ($mode === 'noread') {
	// Completes the TLS handshake, then never reads the request.
	$ctx = stream_context_create(array('ssl' => array('local_cert' => $argv[3] ?? '')));
	$server = stream_socket_server('ssl://127.0.0.1:' . $port, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
	$held = array();
	while (time() < $deadline) {
		$conn = @stream_socket_accept($server, 1);
		if ($conn !== false) {
			$held[] = $conn;
		}
	}
	exit(0);
}

if ($mode === 'silent') {
	$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
	$held = array();
	while (time() < $deadline) {
		$conn = @stream_socket_accept($server, 1);
		if ($conn !== false) {
			$held[] = $conn;
		}
	}
	exit(0);
}

$ctx = stream_context_create(array('ssl' => array('local_cert' => $argv[3] ?? '')));
$server = stream_socket_server('ssl://127.0.0.1:' . $port, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if ($server === false) {
	fwrite(STDERR, "listen failed: $errstr\n");
	exit(1);
}

$log = array();

/** Writes and reports whether the client is still there. */
function send($conn, string $bytes): bool {
	return @fwrite($conn, $bytes) !== false && !feof($conn);
}

/** Writes one byte every $interval seconds for at most $seconds. */
function drip($conn, string $bytes, float $interval, float $seconds): void {
	$stop = microtime(true) + $seconds;
	foreach (str_split($bytes) as $b) {
		if (microtime(true) > $stop || !send($conn, $b)) {
			return;
		}
		usleep((int) ($interval * 1e6));
	}
}

while (time() < $deadline) {
	// A client that refuses the certificate aborts the handshake and
	// accept() returns false; keep serving.
	$conn = @stream_socket_accept($server, 1);
	if ($conn === false) {
		continue;
	}
	stream_set_timeout($conn, 5);

	$head = '';
	while (!str_contains($head, "\r\n\r\n") && strlen($head) < 65536) {
		$line = fgets($conn, 8192);
		if ($line === false) {
			break;
		}
		$head .= $line;
	}
	if ($head === '') {
		fclose($conn);
		continue;
	}

	[$requestLine] = explode("\r\n", $head, 2);
	[$method, $target] = explode(' ', $requestLine) + array('', '', '');
	$path = (string) parse_url($target, PHP_URL_PATH);
	parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

	$headers = array();
	foreach (array_slice(explode("\r\n", trim($head)), 1) as $h) {
		[$name, $value] = explode(':', $h, 2) + array('', '');
		$headers[strtolower(trim($name))] = trim($value);
	}
	$body = '';
	$length = (int) ($headers['content-length'] ?? 0);
	while (strlen($body) < $length && !feof($conn)) {
		$body .= (string) fread($conn, $length - strlen($body));
	}

	$log[] = $method . ' ' . $path;

	$ok = "HTTP/1.1 200 OK\r\nConnection: close\r\n";
	switch ($path) {
		case '/ok':
			$b = '{"ok":true}';
			send($conn, $ok . "Content-Type: application/json\r\nX-Spike: one\r\nRatelimit-Remaining: 4999\r\nContent-Length: " . strlen($b) . "\r\n\r\n" . $b);
			break;
		case '/head':
			// Headers only, with a length the body never sends: correct for
			// a HEAD response.
			send($conn, $ok . "Content-Length: 100\r\n\r\n");
			break;
		case '/no-content':
			send($conn, "HTTP/1.1 204 No Content\r\nConnection: close\r\nContent-Length: 50\r\n\r\n");
			break;
		case '/log':
			$b = json_encode($log);
			send($conn, $ok . 'Content-Length: ' . strlen($b) . "\r\n\r\n" . $b);
			$log = array();
			break;
		case '/echo':
			$b = json_encode(array('method' => $method, 'headers' => $headers, 'body' => $body));
			send($conn, $ok . 'Content-Length: ' . strlen($b) . "\r\n\r\n" . $b);
			break;
		case '/redirect':
			send($conn, "HTTP/1.1 302 Found\r\nLocation: https://127.0.0.1:$port/redirect-target\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
			break;
		case '/redirect-target':
			send($conn, $ok . "Content-Length: 6\r\n\r\nfollow");
			break;
		case '/error':
			$b = '{"id":"forbidden","message":"missing scope"}';
			send($conn, "HTTP/1.1 403 Forbidden\r\nConnection: close\r\nContent-Length: " . strlen($b) . "\r\n\r\n" . $b);
			break;
		case '/big':
			$n = (int) ($query['n'] ?? 0);
			$withLength = ($query['len'] ?? '1') === '1';
			if (send($conn, $ok . ($withLength ? "Content-Length: $n\r\n" : '') . "\r\n")) {
				$chunk = str_repeat('a', 65536);
				for ($sent = 0; $sent < $n; $sent += 65536) {
					if (!send($conn, substr($chunk, 0, min(65536, $n - $sent)))) {
						break;
					}
				}
			}
			break;
		case '/chunked':
			send($conn, $ok . "Transfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n1\r\n \r\n5\r\nworld\r\n0\r\n\r\n");
			break;
		case '/slow-headers':
			drip($conn, $ok . "X-Pad: " . str_repeat('p', 200) . "\r\nContent-Length: 2\r\n\r\nok", 0.5, 30);
			break;
		case '/slow-body':
			if (send($conn, $ok . "Content-Length: 200\r\n\r\n")) {
				drip($conn, str_repeat('b', 200), 0.5, 30);
			}
			break;
		case '/stall':
			if (send($conn, $ok . "Content-Length: 10\r\n\r\n")) {
				sleep(15);
			}
			break;
		case '/huge-header':
			$n = (int) ($query['n'] ?? 0);
			send($conn, "HTTP/1.1 200 OK\r\nConnection: close\r\nX-Huge: " . str_repeat('h', $n) . "\r\nContent-Length: 2\r\n\r\nok");
			break;
		case '/many-headers':
			$n = (int) ($query['n'] ?? 0);
			$h = '';
			for ($i = 0; $i < $n; $i++) {
				$h .= "X-H$i: v\r\n";
			}
			send($conn, "HTTP/1.1 200 OK\r\nConnection: close\r\n" . $h . "Content-Length: 2\r\n\r\nok");
			break;
		case '/big-withheld':
			// Declares a large body, then sends none of it.
			if (send($conn, $ok . 'Content-Length: ' . (int) ($query['n'] ?? 0) . "\r\n\r\n")) {
				sleep(15);
			}
			break;
		case '/early-hints':
			send($conn, "HTTP/1.1 103 Early Hints\r\nLink: </style.css>; rel=preload\r\n\r\n" . $ok . "Content-Length: 5\r\n\r\nfinal");
			break;
		case '/bad-terminator':
			// Last chunk without the empty line that ends the trailer section.
			send($conn, $ok . "Transfer-Encoding: chunked\r\n\r\n1\r\nx\r\n0\r\n");
			break;
		case '/bad-chunk':
			send($conn, $ok . "Transfer-Encoding: chunked\r\n\r\nzz\r\nhello\r\n0\r\n\r\n");
			break;
		case '/dup-length':
			send($conn, "HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Length: 2\r\nContent-Length: 40\r\n\r\nok" . str_repeat('x', 38));
			break;
		case '/header-flood':
			// 64 MiB of header lines, sent as fast as the client reads.
			if (send($conn, "HTTP/1.1 200 OK\r\nConnection: close\r\n")) {
				$block = str_repeat("X-Flood: " . str_repeat('f', 1000) . "\r\n", 64);
				for ($i = 0; $i < 1024 && send($conn, $block); $i++) {
				}
				send($conn, "Content-Length: 2\r\n\r\nok");
			}
			break;
		default:
			send($conn, "HTTP/1.1 404 Not Found\r\nConnection: close\r\nContent-Length: 0\r\n\r\n");
	}
	@fclose($conn);
}
