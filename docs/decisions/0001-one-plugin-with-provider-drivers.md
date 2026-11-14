# 0001. One plugin with a driver per cloud provider

Status: Accepted (2026-11-14)

## Context

The plugin reads resources and metrics from several cloud providers: DigitalOcean, AWS, Google Cloud, and Azure first, others later. Most of the work is the same for every provider: storing accounts and credentials, making bounded outbound HTTPS requests, scheduling collection on a poller, caching observations, printing them to Cacti's script server, creating Cacti devices, data sources, and graphs, and showing health. What differs is how a request is authenticated, how resources are listed, how metrics are fetched, and how results are paged.

A Cacti 1.2 plugin is one directory under `plugins/`, installed and enabled on its own. Plugin `INFO` files can name other plugins in `requires`, but Cacti checks only that the named plugin is present, not which version it is.

## Decision

- Ship one plugin, `cloud`, with a driver per provider behind one PHP interface.
- The core owns accounts, credentials, transport, scheduling, the observation cache, Cacti objects, pages, and health. A driver owns authentication, discovery, metric collection, cost estimation, and its endpoint list.
- A provider is available when its driver ships and passes its phase's exit evidence. Operators enable providers per account, not per installation.

## Consequences

- A fix to shared code is one change, one CI run, and one release.
- Operators install and upgrade one plugin.
- Code for providers an operator does not use is present but inactive. Drivers are loaded only for accounts that use them.
- A driver cannot be released on its own schedule. Each provider phase is a plugin release.

## Alternatives rejected

- **One plugin per provider plus a shared library plugin.** Every operator installs two plugins, and `requires` cannot keep their versions in step, so a library change can break a provider plugin that was not upgraded with it.
- **One plugin per provider with copied shared code.** Fixes to credentials, transport, or Cacti object handling would have to be repeated in every repository.
