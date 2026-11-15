# Request and cost model

Research for [#22](https://github.com/somethingwithproof/plugin_cloud/issues/22), prices checked 2026-11-14. Question: for a selection of resources and metrics and a collection interval, how many provider requests does the plugin make, and what do they cost at list price?

**Result.** Each provider's formula follows from the confirmed facts in [provider facts](provider-facts.md) and the [metric catalogs](metric-catalogs.md). AWS cost scales with series and frequency; Google Cloud with series returned, after a free million per billing account; Azure with calls, after a free million per subscription; DigitalOcean has no price, only a rate limit. One input is unsettled: whether an Azure `metrics:getBatch` call for 50 resources bills as one call. The estimator shows both readings until the live check on [#19](https://github.com/somethingwithproof/plugin_cloud/issues/19) settles it. No formula has yet been compared with a measured bill; that check is part of each provider's live suite.

## Notation

- `I`: collection interval in seconds (default 300).
- `C = 2,592,000 / I`: collections in a 30-day month (8,640 at 300 s).
- `S`: series, one resource and one metric field (one data source item).
- `M`: AWS metric requests. For each resource and metric, `ceil(statistics / 5)`, because AWS bills up to five statistics of one metric as one metric request. With one statistic per metric, as in the [catalog](metric-catalogs.md), `M = S`.
- `R`: resources of one type in one scope.
- Prices are list prices in USD with no discounts or taxes, and carry their check date into the estimator.

## Formulas

**DigitalOcean.** One `GET` per series per collection.

- Requests per hour: `S × 3600 / I`, plus discovery: `ceil(R / 200)` list requests per resource type per discovery run (hourly by default).
- Limits per token: 5,000 an hour and 250 a minute. The collector budgets `share × 5,000` an hour (default share 50 percent) and spreads requests so no minute exceeds 250.
- Capacity at the defaults: `0.5 × 5,000 / 12 = 208` series at 300 s, which is 41 Droplets with the five agent-free series. At 100 percent share it is 416 series, or 83 Droplets.
- Cost: none found. No page states DigitalOcean API calls are free, so the plugin reports "no charge documented", not "free".

**AWS.** `GetMetricData` bills each metric requested, counting up to five statistics of one metric as one.

- Calls per collection: `ceil(S_region / 500)` per region, because each query item returns one series.
- Monthly cost: `Σ_region M_region × C × p_region`, with `p` = $0.00001 per metric in most regions, $0.000013 in GovCloud, $0.000014 in `sa-east-1`. There is no free tier for `GetMetricData`.
- At 1,000 metric requests, 300 s, us-east-1: `1,000 × 8,640 × 0.00001 = $86.40` a month.
- Inventory (`DescribeRegions`, `DescribeInstances`, ELB describe calls) has no documented charge and is shown as such. `ListMetrics` is not needed by the plan; if used it costs $0.01 per 1,000 after 1 million free requests a month.

**Google Cloud.** `timeSeries.list` bills series returned, at least one per call.

- Calls per collection: one per metric type per project, plus pages (`nextPageToken`).
- Billed series per collection: `Σ_metric-type max(1, series returned)`. With one series per instance per metric, this is `S`.
- Monthly cost: `max(0, S × C − F) × $0.50 / 1,000,000`, where `F` is the free million per billing account, shared with every other tool on that billing account. The estimator assumes `F` is fully available and says so.
- At 1,000 series, 300 s: `(8,640,000 − 1,000,000) × 0.0000005 = $3.82` a month.
- Unsettled: whether `crossSeriesReducer` bills series before or after reduction. Per-disk and per-label metrics in the catalog use it, so the estimator counts their series before reduction (the higher figure) until the live check on [#18](https://github.com/somethingwithproof/plugin_cloud/issues/18).

**Azure.** `metrics:getBatch` takes up to 50 resources of one subscription, region, and type per call, and a comma-separated list of aggregations ([reference](https://learn.microsoft.com/en-us/rest/api/monitor/metrics-batch/batch?view=rest-monitor-2023-10-01)), so one call per batch covers every metric and aggregation the type needs.

- Calls per collection, optimistic reading (one call per batch): `Σ_(subscription, region, type) ceil(R / 50)`.
- Calls per collection, pessimistic reading (one call per resource): `Σ R`.
- Monthly cost: `max(0, calls × C − 1,000,000) × $0.01 / 1,000` per subscription; $0.0125 per 1,000 in US Government regions; six newer regions list no free tier.
- At 1,000 VMs in one subscription and region, 300 s: optimistic `20 × 8,640 = 172,800` calls, inside the free million; pessimistic `1,000 × 8,640 = 8,640,000` calls, `(8,640,000 − 1,000,000) × 0.00001 = $76.40` a month.
- Inventory through Resource Graph is free and throttled.

## Prices

| Provider | Operation | Price | Free tier | Unit | Source |
|---|---|---|---|---|---|
| DigitalOcean | All API calls | None documented | n/a | 5,000 requests an hour, 250 a minute, per token | [OpenAPI spec](https://docs.digitalocean.com/reference/api/reference/openapi.json), "Rate Limit" |
| AWS | `GetMetricData` | $0.01 per 1,000 metrics (us-east-1) | None | Metric requested; up to five statistics of one metric count as one | [Pricing](https://aws.amazon.com/cloudwatch/pricing/); Price List API offer `AmazonCloudWatch` version 20261104213532, usage type `CW:GMD-Metrics` |
| AWS | `ListMetrics`, `GetMetricStatistics` | $0.01 per 1,000 requests | 1 million a month | Request | Same, usage type `CW:Requests` |
| Google Cloud | `timeSeries.list` | $0.50 per million series returned | 1 million per billing account a month | Series returned, minimum one per call | [Pricing](https://cloud.google.com/products/observability/pricing), effective 2025-10-02; [billing](https://docs.cloud.google.com/stackdriver/estimating-bills) |
| Azure | Metrics queries | $0.01 per 1,000 calls | 1 million per subscription a month | API call (batch billing unsettled) | [Retail Prices API](https://prices.azure.com/api/retail/prices), meter "Native Metric Queries API Calls", effective 2023-11-01; [pricing FAQ](https://azure.microsoft.com/en-us/pricing/details/monitor/) |
| Azure | Resource Graph | Free | n/a | Throttled | [Resource Graph overview](https://learn.microsoft.com/en-us/azure/governance/resource-graph/overview) |

Regional exceptions, from the same feeds: AWS GovCloud $0.013 and `sa-east-1` $0.014 per 1,000 metrics; Azure `usgovarizona`, `usgovtexas`, and `usgovvirginia` $0.0125 per 1,000 calls; Azure `belgiumcentral`, `denmarkeast`, `saudiarabiaeast`, `southeastasia3`, `spaincentral`, and `taiwannorthwest` list $0.01 with no free-tier row.

## Not covered

Data transfer from the poller, credential storage services, discounts, and free-tier use by other tools in the same account. The estimator states these exclusions next to every figure.
