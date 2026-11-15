# Decision records

Each record captures one architecture decision: its context, the decision, its consequences, and the alternatives rejected. Records are numbered and never renumbered. A reversed decision gets a new record that supersedes the old one.

| Record | Decision | Status |
|---|---|---|
| [0001](0001-one-plugin-with-provider-drivers.md) | One plugin with a driver per cloud provider | Accepted |
| [0002](0002-read-only-with-operation-allowlist.md) | Read-only access through an allowlist of provider operations | Accepted |
| [0003](0003-php-only-without-sdks.md) | Implement provider protocols in PHP, without SDKs or runtime dependencies | Accepted |
| [0004](0004-php-82-floor-with-80-parse-guard.md) | Require PHP 8.2, keep entry points parseable on PHP 8.0 | Accepted |
| [0005](0005-collect-only-what-is-graphed.md) | Collect only metrics that have a Cacti data source, and show the cost first | Accepted |
| [0006](0006-alert-through-cacti-data-sources.md) | Alert through polled Cacti data sources, not a plugin alert engine | Accepted |
| [0007](0007-credentials-outside-the-database-in-the-clear.md) | Never store a cloud credential in the clear in the Cacti database | Accepted |
| [0008](0008-providers-in-releasing-phases.md) | Ship providers in phases that each release, DigitalOcean first | Accepted |
| [0009](0009-http-transport.md) | Use a minimal socket client for HTTPS | Accepted |

Open decisions are tracked as issues labelled `type: decision`.
