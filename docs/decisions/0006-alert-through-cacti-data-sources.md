# 0006. Alert through polled Cacti data sources, not a plugin alert engine

Status: Accepted (2026-11-14)

## Context

Operators want one alerting path for SNMP devices and cloud resources. Thold evaluates values that reach it through the `poller_output` hook ([plugin_thold setup.php](https://github.com/Cacti/plugin_thold/blob/develop/setup.php)), and Maint suppresses those notifications for devices in maintenance: Thold calls Maint's `plugin_maint_check_cacti_host()` directly when Maint is enabled ([plugin_thold includes/polling.php](https://github.com/Cacti/plugin_thold/blob/develop/includes/polling.php), [plugin_maint includes/functions.php](https://github.com/Cacti/plugin_maint/blob/develop/includes/functions.php)). The record first named Maint's `is_device_in_maintenance` hook, which nothing calls; corrected on 2026-11-14 ([Cacti core](../research/cacti-core.md)). Each provider also has its own alarm service.

Provider metrics arrive late. A datapoint for a period is published some time after the period ends, and the latest value can still change ([CloudWatch concepts](https://docs.aws.amazon.com/AmazonCloudWatch/latest/monitoring/cloudwatch_concepts.html); Google documents up to 240 seconds for Compute Engine CPU, [GCP metrics](https://docs.cloud.google.com/monitoring/api/metrics_gcp_c)).

## Decision

- The collector stores the latest complete datapoint per resource and metric, with the datapoint's own timestamp, in the plugin's observation cache.
- Cacti data sources read the cache through a script server function. It prints the value when the datapoint is recent enough for that metric and `U` otherwise, never `0`. Each resource also has an `age` value, always numeric, so Thold can alert on stale collection.
- Thold and Maint work on these data sources without changes to either plugin.
- The plugin does not import, create, or mirror provider alarms.

## Consequences

- One notification path covers SNMP and cloud data.
- Cacti records each value at poll time, not at the datapoint's timestamp, so cloud graphs lag the provider's own graphs by the metric's publication delay. The documentation states the delay per provider.
- A late correction to a datapoint already polled never re-triggers or clears a Thold alert.

## Alternatives rejected

- **Plugin-owned alert engine.** Duplicates Thold and Maint and adds a third notification path.
- **Writing datapoints into RRD files at their provider timestamps.** Bypasses Cacti's poller and conflicts with boost.
- **Mirroring provider alarms into Cacti.** Needs write or broader read permissions, and keeps two sets of thresholds.
