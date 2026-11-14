# 0002. Read-only access through an allowlist of provider operations

Status: Accepted (2026-11-14)

## Context

The plugin holds credentials for cloud accounts that may run production workloads. A credential with write permissions, a bug in a driver, or a compromised Cacti web user must not be able to change cloud resources through the plugin.

## Decision

- The plugin only reads. It never creates, changes, starts, stops, or deletes a cloud resource, and it never writes provider configuration such as alarms or dashboards.
- Each driver declares the fixed list of provider operations it may call: for example AWS `GetMetricData`, `ListMetrics`, `DescribeRegions`, and `DescribeInstances`; DigitalOcean `GET` requests under `/v2/droplets` and `/v2/monitoring/metrics`. The request builder refuses any operation outside that list before signing it.
- Operations that read through a `POST`, such as AWS Query API calls, Azure `metrics:getBatch`, and Azure Resource Graph queries, are listed by name like any other operation.
- The documentation gives a least-privilege credential setup for each provider and the plugin's health page reports the permissions it could verify.

## Consequences

- A credential with broader rights than needed still cannot be used for writes through the plugin.
- Adding a provider operation is a reviewed code change, never a setting.
- Features that need writes, such as creating provider alarms, are out of scope.

## Alternatives rejected

- **Rely on least-privilege credentials alone.** Operators reuse credentials that have more rights than the plugin needs. The plugin cannot enforce what the provider grants, but it can refuse to ask.
- **Allow writes behind an admin setting.** A setting is reachable by any Cacti administrator and turns a monitoring plugin into a control plane.
