# HTTP transport spike

Research for [#24](https://github.com/somethingwithproof/plugin_cloud/issues/24), run 2026-11-14. Question: can the plugin's one HTTP transport meet the plan's required behavior with PHP's HTTPS stream wrapper, and if not, which option does?

**Result.** The stream wrapper cannot enforce a total time limit or a header size limit, and passes malformed chunked bodies through as data. Those three gaps hold on PHP 8.2, 8.4, and 8.5 and cannot be closed from plugin code, because the wrapper reads the whole response head inside `fopen()`. A small `stream_socket_client()` client meets every required behavior, needs no `allow_url_fopen`, and needs no extension beyond those Cacti 1.2.31 requires. `ext-curl` also meets the requirements but is missing from stock Debian and Ubuntu PHP installs. The decision is recorded in [ADR 0009](../decisions/0009-http-transport.md) and on [#9](https://github.com/somethingwithproof/plugin_cloud/issues/9).

## Method

`sh tests/transport-spike/run.sh <work-dir>` runs everything in containers.

1. `make-certs.php` writes a test CA, a certificate for `127.0.0.1` signed by it, one for `other.example` signed by it, and a self-signed one, using ext-openssl.
2. `server.php` serves fixed HTTPS responses for each case, one connection at a time; cases that stall the server get their own instance. `server.php silent` accepts TCP and never answers, for the handshake case.
3. `spike.php <certs> <transport>` runs every case against one prototype and prints one JSON line per case. Each line states the required behavior, what happened, the time taken, and whether the requirement was met.
4. Three prototypes implement one `send()` contract with the same limits (1 MiB body cap, 2 s connect timeout, 4 s total timeout):
   - `StreamTransport.php`: `fopen()` with an `https://` URL and a stream context (`follow_location` 0, `ignore_errors`, peer and name verification, a CA file).
   - `SocketTransport.php`: `stream_socket_client('tls://…')` with the same TLS options, its own request writer, a response head capped at 64 KiB, strict `Content-Length` handling, and a strict chunked decoder.
   - `CurlTransport.php`: the options plugin_cloudflare's transport sets, with a 64 KiB header cap in the header callback.
5. The runner repeats every case on `php:8.2-cli` (PHP 8.2.35, OpenSSL 3.5.7, libcurl 8.14.1) and `php:8.4-cli` (PHP 8.4.27), and the same cases ran on the development host (PHP 8.5.12, OpenSSL 4.0.3, libcurl 8.23.0).
6. It then installs each distribution's stock `php-cli` package and records `allow_url_fopen` and whether ext-curl is loaded.

The URL allowlist is not part of the spike. plugin_cloudflare's `OutboundPolicy` already runs before any connection and is reused unchanged.

## Results

Each cell covers both container runs; a note marks where PHP 8.5 on the host differed. Times are shown where the case waits.

| Case | Required behavior | Stream wrapper | Socket client | ext-curl |
|---|---|---|---|---|
| Trusted certificate | Request succeeds, status and headers readable | pass | pass | pass |
| Untrusted certificate | Refused before any request is sent | pass | pass | pass |
| Host name mismatch | Refused before any request is sent | pass | pass | pass |
| Redirect | Not followed; target never requested | pass | pass | pass |
| HTTP error body | 4xx status and body returned to the caller | pass | pass | pass |
| POST body and headers | Method, headers and body reach the server unchanged | pass | pass | pass |
| PUT with empty body | IMDSv2 token request shape (PUT, no body) is sent | pass | pass | pass |
| Chunked response | Decoded body returned | pass | pass | pass |
| Oversized, Content-Length declared | Refused from the header without reading the body | pass | pass | pass |
| Oversized, no Content-Length | Cut off at the cap | pass | pass | pass |
| Stalled body | Aborted at the total timeout | pass (4.1 s) | pass (4.1 s) | pass (4.1 s) |
| Slow body (1 byte per 0.5 s) | Aborted at the total timeout | pass (4.1 s) | pass (4.1 s) | pass (4.1 s) |
| Slow headers (1 byte per 0.5 s) | Aborted at the total timeout | **fail** (30.3 s) | pass (4.1 s) | pass (4.1 s) |
| TLS handshake never answered | Aborted within the connect timeout | pass (2.0 s) | pass (2.0 s) | pass (2.0 s) |
| Connect to an address that drops packets | Aborted within the connect timeout | pass (2.0 s) | pass (2.0 s) | pass (2.0 s) |
| 1 MiB response header | Refused or bounded; no unbounded memory | pass | pass | pass |
| 20,000 response headers | Refused or bounded | pass | pass | pass |
| Proxy environment variables | Ignored; the request goes direct | pass | pass | pass |
| Allow_url_fopen=0 | Stream: detected and reported; socket: request still succeeds | pass | pass | pass |
| Malformed chunk size | Reported as an error, not returned as data | **fail** | pass | pass |
| Conflicting Content-Length | Refused | pass | pass | **fail**; pass on PHP 8.5 |
| 64 MiB of response headers | Bounded; refused without buffering it all | **fail** | pass | pass |
| CRLF in a request header value | Refused before sending | pass | pass | pass |

138 checks ran in containers (23 cases, three prototypes, two PHP versions); 130 met the requirement. Of the eight failures, six were the stream wrapper's three gaps on both versions, and two were libcurl 8.14.1 accepting two different `Content-Length` values (it used the second). libcurl 8.23.0 on the host refused them.

The stream wrapper failures in detail:

- **Slow headers.** A server sending one header byte every 0.5 s held the request for 30.3 s against a 4 s budget, until the server stopped. The wrapper's `timeout` applies to each read while `fopen()` reads the head, and the plugin gets control only after the head is complete. Each request can therefore run as long as a slow or hostile server chooses, which breaks the collector's time budget.
- **64 MiB of headers.** Peak memory grew by 77.8 MiB and the request succeeded. There is no header limit to set.
- **Malformed chunk size.** A body framed with chunk size `zz` came back as status 200 with the raw framing as the body. PHP's `dechunk` filter, which the wrapper uses, passes input it cannot parse through unchanged. The socket prototype therefore decodes chunks itself instead of using that filter.

The prototypes' own validation (request header names and values, conflicting `Content-Length`) applies equally to all three and is not a property of the transport.

**Distribution defaults** (stock `php-cli` package, 2026-11-14):

| Image | PHP | `allow_url_fopen` | ext-curl loaded |
|---|---|---|---|
| debian:13 | 8.4.27 | On | No (separate `php-curl` package) |
| ubuntu:24.04 | 8.3.6 | On | No |
| ubuntu:22.04 | 8.1.2 | On | No |
| almalinux:9 | 8.0.30 (default stream) | On | Yes |
| almalinux:10 | 8.3.36 | On | Yes |
| fedora:43 | 8.4.27 | On | Yes |

`allow_url_fopen` is `PHP_INI_SYSTEM`, so a plugin cannot turn it on at run time; the stream wrapper prototype reports `allow_url_fopen_off` before trying. The socket and curl prototypes are unaffected by it.

## What the socket client owns

Choosing `stream_socket_client()` means the plugin parses HTTP/1.1 responses itself. The spike bounds that surface:

- TLS, certificate checks, and SNI stay in PHP's OpenSSL layer, with the same context options the wrapper uses (`verify_peer`, `verify_peer_name`, `peer_name`, `cafile`).
- Requests are written by the plugin with `Connection: close` and no `Accept-Encoding`, so there is no connection reuse and no compressed body. Header names must be RFC 9110 tokens and values must not contain CR, LF, or NUL.
- The response head is read with a deadline check between reads and refused past 64 KiB. The status line must match `HTTP/1.x NNN`; header lines must contain a colon and must not start with whitespace (no obsolete line folding).
- Framing is one of: strict chunked decoding, an exact `Content-Length` (digits only, conflicting duplicates refused, short bodies refused), or read to close. Bodies are capped before decoding.
- The deadline is checked between reads, so a server sending one byte at a time is cut off at the total timeout (4.1 s against 4 s).

Milestone 2 adds what the spike did not need: skipping `1xx` interim responses, a TLS `close_notify` check, plain HTTP to the instance metadata addresses only (AWS, Google Cloud, and Azure metadata services do not use TLS), and parser tests that feed truncated and malformed heads and chunk streams.

## Limits

- The servers were local. Provider endpoints were not called; the live suites in later milestones exercise the transport against them.
- The connect timeout case used `192.0.2.1` (TEST-NET-1), which the test network dropped. On a network that rejects it instead, the case reports a refusal, not a timeout.
- Throughput and memory at volume were not measured; the plan's 1,000-series run is a milestone 3 measurement.
