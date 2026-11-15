# 0003. Implement provider protocols in PHP, without SDKs or runtime dependencies

Status: Accepted (2026-11-14)

## Context

Each provider publishes official SDKs. The AWS, Azure, and Google Cloud PHP SDKs are large Composer packages with their own dependency trees, and installing Composer packages into a Cacti plugin directory is not part of how Cacti plugins are installed. Cacti 1.2.31 already requires `ext-openssl`, `ext-json`, `ext-dom`, and `ext-xml` ([composer.json](https://github.com/Cacti/cacti/blob/release/1.2.31/composer.json)).

The protocols the plugin needs are documented and small:

- AWS Signature Version 4 is a chain of HMAC-SHA256 operations over a canonical request.
- Google service account tokens are an RS256-signed JWT exchanged at a token endpoint.
- Microsoft Entra client credentials are a form `POST` with a client secret, or a signed client assertion.
- DigitalOcean uses a bearer token.

## Decision

- Everything the plugin ships and runs is PHP. No Perl, Python, shell helpers, or provider CLIs run in production.
- The plugin has no Composer runtime dependencies and ships no vendored libraries. Development tools (PHPStan, Pest, php-cs-fixer) are not part of the release archive.
- Signing uses `hash_hmac()`, `hash()`, and `openssl_sign()`. JSON uses `json_decode()` with depth limits and `JSON_THROW_ON_ERROR`. XML responses are parsed with `ext-dom` with network access and entity loading disabled.
- The plugin requires no PHP extension beyond what Cacti 1.2.31 requires. `ext-curl` is not among them; the transport is decided in [ADR 0009](0009-http-transport.md).
- Each signer is tested against the provider's published examples or test vectors, and against the live provider before its phase is released.

## Consequences

- Installation is copying one directory, with nothing to resolve or build.
- The plugin owns its signing code. A provider protocol change needs a plugin release, and the tests are the only guard against a subtle signing bug.
- Features that exist only in an SDK, such as automatic credential chains, are implemented only where the plan lists them.

## Alternatives rejected

- **Provider SDKs through Composer.** Adds tens of thousands of lines of third-party code to the trust boundary, and an installation step Cacti plugins do not have.
- **Provider CLIs (`aws`, `gcloud`, `az`).** Adds a runtime outside PHP, per-host installation, and process spawning on the poller.
