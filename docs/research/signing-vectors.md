# Signing test vectors

Research for [#23](https://github.com/somethingwithproof/plugin_cloud/issues/23), run 2026-11-14. Question: which published examples can each signer and token request be checked against, under what license, and does a prototype pass them?

**Result.** Each provider's credential step has a published example that a PHP prototype reproduces exactly, using only `hash_hmac()`, `openssl_sign()`, and `http_build_query()`:

| Signer | Source | Vectors | Prototype result |
|---|---|---|---|
| AWS Signature Version 4 | AWS signing test suite in `awslabs/aws-c-auth` | 38 header-signed requests, checked at canonical request, string to sign, and signature | 38 of 38 pass |
| AWS signing key derivation | IAM User Guide example | 1 | Pass |
| Google service account JWT (RS256) | RFC 7515 Appendix A.2 | 1 signature, plus verification | Exact signature reproduced; `openssl_verify()` accepts it and rejects a changed input |
| Entra client credentials | Microsoft's client credentials flow page | 1 request body | Byte-identical |

No vector files are copied into this repository. Each check reads its source from a path given on the command line, so the sources' licenses never apply to plugin files.

## Sources and licenses

| Source | Pinned version | License | Use |
|---|---|---|---|
| [`awslabs/aws-c-auth` `tests/aws-signing-test-suite/v4`](https://github.com/awslabs/aws-c-auth/tree/ac5652e57911b1cd7a0cea2f3c5f7e34561a6891/tests/aws-signing-test-suite/v4) | commit `ac5652e` | Apache-2.0 (repository license) | Read at test time from a download |
| [IAM User Guide: create a signed request](https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_sigv-create-signed-request.html) | checked 2026-11-14 | AWS documentation | The expected key `f4780e2d…404d` is a single published value |
| [RFC 7515](https://www.rfc-editor.org/rfc/rfc7515.txt), Appendix A.2 | RFC text | IETF Trust Legal Provisions | Read at test time from the RFC text |
| [Microsoft identity platform: client credentials flow](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-client-creds-grant-flow) | page dated 2026-01-30 | Microsoft documentation | Read at test time from the saved page |

These vector scripts remain standalone research checks and are not wired into the current CI workflow. Before implementing an AWS signer, decide whether to download the AWS suite at the pinned commit in CI or vendor it under tests/fixtures/ with its Apache-2.0 notice. Apache-2.0 files can be distributed with GPL-2.0-or-later test code but are not GPL-2.0-only compatible, so vendored files stay out of release archives.

## Method

All scripts are in `tests/signing-vectors/` and run on PHP 8.2 or later with ext-openssl.

```sh
php tests/signing-vectors/run.php <aws-c-auth>/tests/aws-signing-test-suite/v4
php tests/signing-vectors/jwt-rs256.php rfc7515.txt
php tests/signing-vectors/entra-form.php client-creds-grant-flow.html
```

**AWS.** `AwsSigV4.php` is a pure function from method, path, query, headers, body, credentials, region, service, and time to the canonical request, string to sign, signature, and `Authorization` value. `run.php` parses each vector's `request.txt` (folded header lines included), applies the vector's `context.json` flags (`normalize`, `sign_body`, `omit_session_token`), and compares all three intermediate outputs, so a failure names the stage that diverged. The 38 vectors cover path normalization and its absence, dot segments, repeated slashes, spaces and UTF-8 in paths and queries, query ordering by key and case, duplicate and folded headers, value trimming, session tokens signed and attached after signing, and form-encoded bodies.

**Mutation check.** Three deliberate faults show what the suite catches:

| Fault | Vectors failed |
|---|---|
| Wrong last element in the key derivation (`aws4_requesT`) | 38 of 39 |
| Header values not trimmed or collapsed | 1 of 39 |
| Duplicate query keys not ordered by value | 0 of 39 |

No vector repeats a query key, so ordering by value is untested by the suite. CloudWatch's Query API sends its parameters in a form-encoded `POST` body, not the query string, and EC2 and STS calls carry no repeated keys, so this gap does not affect the planned calls. Milestone 5 adds a plugin-owned vector for it if a driver ever builds a repeated query key.

**Google.** RS256 is RSASSA-PKCS1-v1_5 with SHA-256, which is deterministic. `jwt-rs256.php` builds the RFC's private key from its JWK parameters with `openssl_pkey_new()`, signs the RFC's signing input with `openssl_sign(..., OPENSSL_ALGO_SHA256)`, and compares the base64url result with the RFC's value. It then verifies the RFC signature with the public half and checks that a changed input is rejected. Google's token endpoint accepts the same RS256 JWT form ([service accounts](https://developers.google.com/identity/protocols/oauth2/service-account)), so the plan's statement that `openssl_sign()` is enough holds. The RFC check covers the signature only, not Google's header and claims (`alg`/`typ`, and `iss`, `scope`, `aud`, `iat`, `exp` no more than one hour after `iat`). Milestone 6 tests the generated assertion by verifying its signature with the key's public half and checking those fields. A live token exchange is still part of [#18](https://github.com/somethingwithproof/plugin_cloud/issues/18).

**Azure.** The client secret request is a form `POST` with no signature. `entra-form.php` extracts the documented example body, builds the same body from its fields with `http_build_query(..., PHP_QUERY_RFC3986)`, and compares bytes. Certificate credentials, which need PS256, remain research as the plan states.

## Runs

| Check | PHP | OpenSSL | Result |
|---|---|---|---|
| AWS suite and IAM example | 8.5.12 | 4.0.3 | 39 passed, 0 failed |
| RFC 7515 A.2 | 8.5.12 | 4.0.3 | 3 passed |
| Entra form | 8.5.12 | 4.0.3 | 1 passed |

Exit criteria for #23: vector sources are listed with licenses, and the signer prototype passes every AWS example.
