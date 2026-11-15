# Estimates for phases 1 to 5

Research for [#25](https://github.com/somethingwithproof/plugin_cloud/issues/25), made 2026-11-14 after the milestone 1 documentation and spike work. Question: how large is each later milestone, and what gates it?

**Result.** About 15,000 to 20,000 lines of production PHP across milestones 2 to 8, with phase 1 (milestones 2 to 4) about two thirds of it. Size is the better-grounded figure. Elapsed time depends mostly on things outside the code: live provider accounts, security reviews, and maintainer decisions. Those gates are listed per milestone.

## Basis

Two plugins by the same author with the same layout and tooling:

| Plugin | Production lines | Test lines | Commit span | Scope |
|---|---|---|---|---|
| [plugin_cloudflare](https://github.com/somethingwithproof/plugin_cloudflare) | 10,324 | 19,767 | 2026-10-21 to 2026-10-23 | One provider: accounts, vault, curl transport, discovery, metrics, script server, templates |
| [plugin_smokeping](https://github.com/somethingwithproof/plugin_smokeping) | 13,249 | 10,020 | 2026-11-13 to 2026-11-14 | Observe mode, devices, data sources, smoke graphs, release qualification |

Lines are non-vendor PHP, shell, Perl, and Python on each `main` branch on 2026-11-14; production excludes `tests/` and `features/`. The commit span is when commits landed, not measured effort, and both were built with AI assistance. plugin_cloudflare's breakdown is the closest match for the core: pages and lifecycle about 3,300 lines, accounts 1,050, HTTP 610, storage and migrations 800, secrets 410, jobs 380, discovery 980, metrics 850.

## Per milestone

| Milestone | Production lines | Relative size | Grounding | Gates outside the code |
|---|---|---|---|---|
| 2. Foundation | 6,000 to 8,000 | Largest | plugin_cloudflare core areas, plus the socket transport parser (ADR 0009) and a provider-neutral driver contract | Test harness choice with maintainers |
| 3. DigitalOcean and Cacti objects | 3,000 to 4,000, plus template XML | Large | plugin_cloudflare discovery, metrics, script server, and templates | Live DigitalOcean account for [#16](https://github.com/somethingwithproof/plugin_cloud/issues/16) before the RRD step is fixed; object model decision |
| 4. Phase 1 release | 500 to 1,000, mostly tests and docs | Medium | plugin_smokeping M4 (release qualification, failure tests, packaging) | Security review; live pilot account |
| 5. AWS | 2,000 to 2,500 | Medium | Signer prototype (137 lines) and the catalog: SigV4, STS and IMDSv2, regional EC2 and ELB inventory, `GetMetricData` batching, cost ceiling | AWS test account for [#17](https://github.com/somethingwithproof/plugin_cloud/issues/17) and the live suite in two regions; security review |
| 6. Google Cloud | 1,000 to 1,500 | Small | RS256 check and the catalog: JWT and metadata token sources, `aggregatedList`, `timeSeries.list` | Google Cloud project for [#18](https://github.com/somethingwithproof/plugin_cloud/issues/18); security review |
| 7. Azure | 1,000 to 1,500 | Small | Entra form check and the catalog: client secret and managed identity, Resource Graph, `metrics:getBatch` | Azure subscription for [#19](https://github.com/somethingwithproof/plugin_cloud/issues/19), including the billing check; security review |
| 8. Remote collectors | 1,000 to 1,500 | Medium, highest uncertainty | plugin_cloudflare's collector protocol; Cacti's `replicate_out` and `remote_agent` behavior in [Cacti core](cacti-core.md) | A Cacti remote collector test bed; security review |

Tests are expected at one to two times production lines, as in the two plugins.

## Uncertainty

- Milestone 2 carries the most design work: the driver contract has to fit four providers whose shapes differ (one request per series, one per 500 series, one per metric type, one per 50 resources).
- Milestone 3's size depends on the object model decision. A device per resource instead of a scope device with data queries changes provisioning and reindexing.
- Milestone 8 has no direct precedent in either plugin's released code; offline and recovery modes need their own scenarios.
- None of the live checks has run. A provider fact that turns out wrong in a live check changes the milestone that depends on it, most likely 3, 5, or 7.
