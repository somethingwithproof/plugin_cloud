# 0008. Ship providers in phases that each release, DigitalOcean first

Status: Accepted (2026-11-14)

## Context

The core (accounts, credentials, transport, collector, cache, Cacti objects) must be proven against a real provider before it is generalized. The four first providers differ in how hard their protocols are:

| Provider | Authentication | Metrics API |
|---|---|---|
| DigitalOcean | Bearer token | REST `GET` per resource |
| AWS | Signature Version 4, optional STS `AssumeRole` | CloudWatch Query API, up to 500 queries per `GetMetricData` call |
| Google Cloud | RS256 JWT exchanged for an access token | Cloud Monitoring `timeSeries.list`, one metric type per call |
| Azure | Entra client credentials, two token audiences | ARM per-resource metrics or regional `metrics:getBatch` |

## Decision

- Phase 1 ships the core with the DigitalOcean driver. It has the simplest authentication, so failures in phase 1 are failures of the core.
- Phase 2 adds AWS, the hardest signing and the highest metric cost, which tests the driver interface and cost controls.
- Phase 3 adds Google Cloud and phase 4 adds Azure.
- Phase 5 adds remote data collectors.
- Every phase is a release that operators can run. A later phase is re-estimated after the one before it.
- Other providers are triaged in research issues and get a phase only when one is proposed with evidence.

## Consequences

- The driver interface is designed with all four providers' facts in hand, but proven on one before it is frozen. Phase 2 may change it; phase 3 and later should not need to.
- Operators who only use AWS, Google Cloud, or Azure wait for later phases.

## Alternatives rejected

- **All four providers in the first release.** Four untested drivers on an untested core make every failure ambiguous.
- **AWS first.** It is the most used provider, but its signing and cost model would dominate the first release and hide core defects.
