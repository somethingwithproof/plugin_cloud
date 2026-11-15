# 0010. Cloud resources as data query indexes on one device per account scope

Status: Accepted (2026-11-15)

## Context

Cloud resources need Cacti objects that graph, permission, and alert like any other Cacti data. An account can hold many resources, and autoscaled fleets create and delete them continually. Thold and Maint act on Cacti data sources and devices ([ADR 0006](0006-alert-through-cacti-data-sources.md)), so the choice of device decides what Maint can suppress.

The decision was meant to go to Cacti maintainers through the upstream proposal (#15). The plugin owner decided it instead, so milestone 3 can build the objects; #15 is not being pursued for now.

## Decision

- Each account and scope gets one Cacti device, created from the plugin's "Cloud Scope" device template through `api_device_save()`, with availability None and the account's poller, only when the operator confirms. A scope is a DigitalOcean team, an AWS account and region, a Google Cloud project, or an Azure subscription and region.
- Each resource type is a script server data query on that device, for example "Cloud: DigitalOcean Droplets". Its indexes are provider resource IDs, and its descriptions are resource names. Cacti's normal reindexing adds, renames, and removes resources.
- Data and graph templates per resource type are generated from the metric catalog and shipped as template XML.
- The plugin creates and changes Cacti objects only through Cacti's APIs, never by writing Cacti tables.

## Consequences

- Device counts follow accounts, not resources, so autoscaling does not churn devices or the tree.
- Maint suppresses a whole scope, not one resource. Per-resource suppression would need a Thold-level filter or a later decision.
- A virtual machine's cloud graphs sit on its scope device, not on the Cacti device that polls the same machine over SNMP.
- Device-based graph permissions cover a whole scope; finer control uses Cacti's per-graph or per-template permissions.

## Alternatives rejected

- **A device per resource.** Gives per-resource Maint and tree placement, but devices churn with autoscaled fleets, and every create or delete runs Cacti's device lifecycle.
- **Graphs on existing Cacti devices.** Keeps cloud and SNMP graphs together, but needs a resource-to-device mapping the operator maintains, and resources with no Cacti device would have nowhere to go. It remains possible as a later, optional mapping.
