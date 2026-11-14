# 0007. Never store a cloud credential in the clear in the Cacti database

Status: Accepted (2026-11-14)

## Context

Cloud credentials are long-lived secrets: DigitalOcean tokens, AWS access keys, Azure client secrets, and Google service account private keys. Cacti database dumps are routinely copied for backups, upgrades, and support. plugin_cloudflare already solved the same problem for Cloudflare API tokens with an encrypted vault keyed by a file outside the database, a token-file option, and strict file checks ([plugin_cloudflare security](https://github.com/somethingwithproof/plugin_cloudflare/blob/main/docs/security.md)).

Workloads that run inside a provider can also use the provider's instance identity instead of a stored secret: AWS instance roles through IMDSv2 ([IMDS](https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/configuring-instance-metadata-service.html)), the Google Compute Engine metadata server ([metadata](https://docs.cloud.google.com/compute/docs/metadata/querying-metadata)), and Azure managed identities ([managed identity](https://learn.microsoft.com/en-us/entra/identity/managed-identities-azure-resources/how-to-use-vm-token)).

## Decision

Each account takes its credential from exactly one source:

| Source | Where the secret is | Notes |
|---|---|---|
| Vault | Cacti database, encrypted with AES-256-GCM by a 32-byte key read from a file outside the database | The plugin never creates or writes the key file. Additional authenticated data binds each ciphertext to its account. |
| Credential file | A file on the poller host, named by absolute path | Checked when read: not a symbolic link, a regular file of bounded size, not readable or writable by others. |
| Instance identity | Nowhere; the provider's metadata service issues short-lived credentials | Opt-in per account. Only that account's driver may reach the metadata address. |

- Stored secrets are never sent back to the browser, logged, or included in exceptions, health output, or configuration exports.
- Short-lived tokens obtained from a credential (OAuth access tokens, AWS session credentials) are cached in memory for one collector run, or in the vault when the provider limits how often they can be issued.
- The vault design, file checks, and redaction rules follow plugin_cloudflare's and are reviewed again for this plugin before phase 1 ships.

## Consequences

- A database dump alone does not reveal stored credentials. An attacker who can read both the database and the key file, for example as the web server user, can.
- Operators must create and protect a key file to use the vault, or manage credential files themselves.
- Instance identity gives every process on the host the same cloud access, so the documentation describes the trade-off.

## Alternatives rejected

- **Plaintext in plugin tables.** Every backup becomes a credential leak.
- **Cacti's own settings storage.** Stores values in the clear in the `settings` table.
- **An external secret manager.** Each one needs its own client and credentials, which brings back the dependency problem this plugin avoids.
