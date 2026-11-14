# 0005. Collect only metrics that have a Cacti data source, and show the cost first

Status: Accepted (2026-11-14)

## Context

Reading metrics costs money on some providers, and the cost grows with how much is read and how often:

- AWS CloudWatch `GetMetricData` costs $0.01 per 1,000 metrics requested and is excluded from CloudWatch's free API requests ([CloudWatch pricing](https://aws.amazon.com/cloudwatch/pricing/), [GetMetricData billing](https://docs.aws.amazon.com/AmazonCloudWatch/latest/monitoring/metrics-classic-getdata.html)). Batching more metrics into one call does not lower the price.
- Google Cloud Monitoring charges $0.50 per million time series returned by reads, after one million free per billing account ([Observability pricing](https://cloud.google.com/products/observability/pricing)).
- Azure Monitor bills metrics query calls after one million free calls per month, batch calls included ([Azure Monitor pricing](https://azure.microsoft.com/pricing/details/monitor/), [batch API](https://learn.microsoft.com/en-us/azure/azure-monitor/metrics/migrate-to-batch-api)).
- DigitalOcean does not charge for API reads but limits the request rate.

Cacti polls every one to five minutes, so a plugin that reads every metric of every discovered resource can produce a large bill.

## Decision

- The collector reads a metric for a resource only when a Cacti data source created by the plugin exists for it and is enabled. Discovery lists resources; it never reads their metrics.
- Each account has a collection interval, which defaults to the metric's native period and cannot be shorter than it.
- Before graphs are created, the plugin shows the expected monthly request count for the selection and, for providers that charge, the expected cost at list price, with the price source and the date it was checked. The health page shows the same estimate for what is already collected.
- Each account can have a monthly request ceiling. When the collector's projection exceeds it, the collector stops reading that account's metrics, prints `U`, and reports the reason on the health page.

## Consequences

- Cost scales with what an operator chose to graph, and the operator sees it before choosing.
- Deleting a graph and its data sources stops the reads behind it.
- Estimates use list prices. Discounts, free tiers shared with other tools, and price changes make them approximate, and the page says so.
- Price tables are part of the code and need updating when providers change them.

## Alternatives rejected

- **Read everything discovered and let operators filter later.** The first collection run after adding a large account could cost more than the operator expected.
- **Read metrics on demand from the graph page.** Graphs need a continuous series, and on-demand reads bypass Cacti's poller and Thold.
