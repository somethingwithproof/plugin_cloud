# 0009. Use a minimal socket client for HTTPS

Status: Accepted (2026-11-14)

## Context

Every provider call goes through one transport, which must verify certificates and host names, refuse redirects, bound connect and total time, cap the response size, and expose status, headers, and error bodies ([plan: HTTP transport](../PLAN.md#http-transport)). Cacti 1.2.31 requires `ext-openssl` but not `ext-curl` ([Cacti core](../research/cacti-core.md)), and ADR 0003 rules out requiring extensions Cacti does not.

The [transport spike](../research/transport.md) ([#24](https://github.com/somethingwithproof/plugin_cloud/issues/24)) built all three options and ran 27 cases against each on PHP 8.2 and 8.4, and 23 of them on PHP 8.5:

- PHP's HTTPS stream wrapper failed six requirements on every version: a server sending headers slowly held a request for 30 s against a 4 s budget, a total budget shorter than the connect timeout was ignored, a request held by a peer that stopped reading came back as a generic failure, not a timeout, 64 MiB of headers were buffered, and malformed or truncated chunked bodies were returned as data. These happen inside `fopen()`, before plugin code can intervene. It also stops working when `allow_url_fopen` is off.
- stream_socket_client() with a small HTTP/1.1 reader passed every case. The production parser and regression tests now live in [lib/SocketTransport.php](../../lib/SocketTransport.php) and [tests/Transport/SocketTransportTest.php](../../tests/Transport/SocketTransportTest.php); the [spike notes](../research/transport.md) record the comparison that led to the choice.
- `ext-curl` met the requirements, except that libcurl 8.14.1 accepted conflicting `Content-Length` values. Stock Debian 13 and Ubuntu 22.04 and 24.04 `php-cli` installs do not include it.

## Decision

- The transport opens connections with `stream_socket_client('tls://host:port')` and PHP's OpenSSL context options (`verify_peer`, `verify_peer_name`, `peer_name`, `cafile`, SNI). It does not use `fopen()` on URLs, `file_get_contents()` on URLs, or ext-curl.
- It writes HTTP/1.1 requests with `Connection: close` and no compression, and refuses header names that are not tokens and values containing CR, LF, or NUL.
- It reads responses with a deadline checked between reads, a 64 KiB head limit, a body cap, a strict chunked decoder, and strict `Content-Length` handling. It never follows redirects.
- Plain HTTP is allowed only to the provider instance metadata addresses, and only for accounts using instance identity.
- `OutboundPolicy` from plugin_cloudflare checks every URL before a name is resolved or a socket opened.
- The parser is tested with truncated, oversized, and malformed heads and chunk streams, in addition to the spike's cases.

## Consequences

- The plugin works with `allow_url_fopen` off and without ext-curl, on every distribution checked.
- The plugin owns an HTTP/1.1 response parser. It is limited to status line, headers, and two framing modes, and is a security review item for phase 1.
- HTTP/2 and connection reuse are not available. Each request pays a TLS handshake; at the plan's request rates this is small next to provider latency, and milestone 3 measures it.

## Alternatives rejected

- **HTTPS stream wrapper.** Cannot bound total time or header size, passes malformed chunking through, and depends on `allow_url_fopen`.
- **ext-curl.** Meets the requirements but adds an extension Cacti does not require, absent from stock Debian and Ubuntu installs, and its framing strictness depends on the libcurl version.
