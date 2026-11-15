# Metric catalogs

Research for [#21](https://github.com/somethingwithproof/plugin_cloud/issues/21), run 2026-11-14. Question: for each phase 1 to 4 resource type, which metrics does the plugin collect by default, and with what statistic, period, unit, conversion, data source name, and RRD bounds?

**Result.** One table per resource type follows, built from each provider's metric reference. Agent-free defaults are five series for a DigitalOcean Droplet, 14 for an EC2 instance, 9 for a Compute Engine instance, and 8 for an Azure VM. DigitalOcean documents no period or delay for any metric, and AWS and Azure document no publication delay, so those values come from the [live checks](provider-facts.md#live-checks) before templates fix an RRD step. All 77 data source names meet Cacti's limit (below).

## Conventions

- **DS name**: proposed Cacti data source name, 19 characters or fewer, `[a-zA-Z0-9_]` only. See [Data source name check](#data-source-name-check).
- **Cacti value**: how the observation cache converts the provider value. "GAUGE" means the cache stores an already-computed absolute or per-second rate and the RRD DS type is GAUGE. The plugin computes rates itself because Cacti polls a script server value, not a provider counter, so COUNTER and DERIVE are not used unless stated.
- **Delay**: the provider's documented publication delay, or "not documented".
- **Agent-only** metrics are marked **AGENT**. They are not in the default set.
- RRD min and max are proposals. `U` means unbounded. Where max would be a physical ceiling it is `U`, because instance sizes and link speeds vary.
- "Index" is the Cacti data query index the metric belongs to.

---

## DigitalOcean

Reference: OpenAPI spec, Monitoring section, `https://docs.digitalocean.com/reference/api/reference/openapi.json`. Requests are one GET per metric. Native period and delay are **not documented** for any DigitalOcean metric (examples in the spec show 120 s spacing, which is an example only). Live test required before choosing an RRD step.

### Droplets (index: Droplet ID, passed as `host_id`)

| Provider metric (path under `/v2/monitoring/metrics/droplet/`) | Dimensions / labels / filter | Stat or aligner | Native period | Delay | Provider unit | Conversion to Cacti value | DS name | RRD min / max |
|---|---|---|---|---|---|---|---|---|
| `cpu` | label `mode` (example shows idle, iowait, irq, nice, softirq, steal; the example is cut off, so the full mode list is unverified) | none (raw series, one per mode) | not documented | not documented | cumulative seconds per mode (inferred from the example, the spec does not state it) | `100 * (delta total - delta idle) / delta total` over the two newest samples, summed over modes. GAUGE percent. If live data shows values are not cumulative, change the conversion. | `do_cpu_pct` | 0 / 100 |
| `bandwidth` | `interface=public`, `direction=inbound` | none | not documented | not documented | megabits per second (spec states Mbps) | value * 1,000,000 = bits per second, GAUGE | `do_bw_pub_in` | 0 / U |
| `bandwidth` | `interface=public`, `direction=outbound` | none | not documented | not documented | Mbps | same | `do_bw_pub_out` | 0 / U |
| `bandwidth` | `interface=private`, `direction=inbound` | none | not documented | not documented | Mbps | same | `do_bw_prv_in` | 0 / U |
| `bandwidth` | `interface=private`, `direction=outbound` | none | not documented | not documented | Mbps | same | `do_bw_prv_out` | 0 / U |
| `load_1` **AGENT** | none | none | not documented | not documented | load average (unitless; spec text does not state a unit) | absolute, GAUGE | `do_load_1` | 0 / U |
| `load_5` **AGENT** | none | none | not documented | not documented | load average | absolute, GAUGE | `do_load_5` | 0 / U |
| `load_15` **AGENT** | none | none | not documented | not documented | load average | absolute, GAUGE | `do_load_15` | 0 / U |
| `memory_total` **AGENT** | none | none | not documented | not documented | bytes (inferred; example only for filesystem) | absolute, GAUGE | `do_mem_total` | 0 / U |
| `memory_available` **AGENT** | none | none | not documented | not documented | bytes (inferred) | absolute, GAUGE | `do_mem_avail` | 0 / U |
| `memory_free` **AGENT** | none | none | not documented | not documented | bytes (inferred) | absolute, GAUGE | `do_mem_free` | 0 / U |
| `memory_cached` **AGENT** | none | none | not documented | not documented | bytes (inferred) | absolute, GAUGE | `do_mem_cached` | 0 / U |
| `filesystem_free` **AGENT** | labels `device`, `fstype`, `mountpoint` | none | not documented | not documented | bytes (example value 25832407040) | absolute, GAUGE, one index per mountpoint | `do_fs_free` | 0 / U |
| `filesystem_size` **AGENT** | same | none | not documented | not documented | bytes | absolute, GAUGE | `do_fs_size` | 0 / U |

Default set (agent-free): `do_cpu_pct`, `do_bw_pub_in`, `do_bw_pub_out`, `do_bw_prv_in`, `do_bw_prv_out`. That is five requests per Droplet per collection, matching the plan. Packet counts and disk I/O are **not available through the API** (no endpoint exists), although the control panel shows disk I/O.

### Load balancers (index: load balancer ID, passed as `lb_id` query parameter)

Paths are under `/v2/monitoring/metrics/load_balancer/`. The spec gives only a one-line description per metric and no response examples for these paths, so label sets are **unknown** and must be recorded in the live test. Units below come from the one-line descriptions.

| Provider metric | Dimensions / labels | Stat | Native period | Delay | Provider unit (from spec text) | Conversion to Cacti value | DS name | RRD min / max |
|---|---|---|---|---|---|---|---|---|
| `frontend_connections_current` | unknown | none | not documented | not documented | count ("total current active connections") | absolute, GAUGE | `dolb_conn_cur` | 0 / U |
| `frontend_connections_limit` | unknown | none | not documented | not documented | count ("max connections limit") | absolute, GAUGE | `dolb_conn_lim` | 0 / U |
| `frontend_cpu_utilization` | unknown | none | not documented | not documented | percent ("average percentage CPU utilization") | absolute, GAUGE | `dolb_cpu_pct` | 0 / 100 |
| `frontend_http_requests_per_second` | unknown | none | not documented | not documented | requests per second | already a rate, GAUGE | `dolb_http_rps` | 0 / U |
| `frontend_http_responses` | unknown, expected a response-code label | none | not documented | not documented | "rate of response code" (rate per code, unit not stated) | rate per code class, GAUGE; one DS per class would need the label values from the live test | `dolb_http_resp` | 0 / U |
| `frontend_network_throughput_http` | unknown | none | not documented | not documented | bytes per second | bytes/s, GAUGE | `dolb_http_Bps` | 0 / U |
| `frontend_network_throughput_tcp` | unknown | none | not documented | not documented | bytes per second | bytes/s, GAUGE | `dolb_tcp_Bps` | 0 / U |
| `frontend_network_throughput_udp` | unknown | none | not documented | not documented | bytes per second | bytes/s, GAUGE | `dolb_udp_Bps` | 0 / U |
| `frontend_nlb_tcp_network_throughput` | unknown | none | not documented | not documented | bytes per second (network load balancers) | bytes/s, GAUGE | `dolb_nlbtcp_Bps` | 0 / U |
| `frontend_nlb_udp_network_throughput` | unknown | none | not documented | not documented | bytes per second (network load balancers) | bytes/s, GAUGE | `dolb_nlbudp_Bps` | 0 / U |
| `droplets_health_checks` | unknown | none | not documented | not documented | "health check status" (value meaning not stated) | absolute, GAUGE | `dolb_hc_status` | 0 / U |
| `droplets_downtime` | unknown | none | not documented | not documented | "downtime status" (value meaning not stated) | absolute, GAUGE | `dolb_downtime` | 0 / U |
| `droplets_http_response_time_avg` | unknown | none | not documented | not documented | seconds | seconds, GAUGE | `dolb_resp_avg_s` | 0 / U |
| `droplets_http_response_time_95p` | unknown | none | not documented | not documented | seconds | seconds, GAUGE | `dolb_resp_p95_s` | 0 / U |
| `droplets_connections` | unknown | none | not documented | not documented | count ("active connections") | absolute, GAUGE | `dolb_back_conn` | 0 / U |
| `droplets_queue_size` | unknown | none | not documented | not documented | count | absolute, GAUGE | `dolb_queue` | 0 / U |

Other LB paths exist and are left out of the default set: `frontend_tls_connections_current`, `frontend_tls_connections_limit`, `frontend_tls_connections_exceeding_rate_limit`, `frontend_firewall_dropped_bytes` and `_packets` (network LBs only), `droplets_http_session_duration_*`, `droplets_http_response_time_50p` and `_99p`, `droplets_http_responses`.

Request cost: one request per metric per load balancer. Fifteen of the proposed LB metrics per load balancer is 15 requests per collection against the 5,000 per hour limit.

---

## AWS

Reference pages: EC2 `https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/viewing_metrics_with_cloudwatch.html`, ALB `https://docs.aws.amazon.com/elasticloadbalancing/latest/application/load-balancer-cloudwatch-metrics.html`, NLB `https://docs.aws.amazon.com/elasticloadbalancing/latest/network/load-balancer-cloudwatch-metrics.html`.

Query shape for all rows: `GetMetricData` with one `MetricStat` per row (one statistic, so one billed metric). Delay is **not documented** for any AWS metric in these pages. The newest period is partial and may change (cloudwatch_concepts page), so the window ends before the current period and the cache keeps the newest complete datapoint.

### EC2 instances (namespace `AWS/EC2`, dimension `InstanceId`, native period 300 s with basic monitoring, 60 s with detailed monitoring)

| Provider metric | Dimensions | Statistic | Native period | Delay | Provider unit | Conversion to Cacti value | DS name | RRD min / max |
|---|---|---|---|---|---|---|---|---|
| `CPUUtilization` | InstanceId | Average | 300 s | not documented | Percent | absolute, GAUGE | `ec2_cpu_pct` | 0 / 100 |
| `NetworkIn` | InstanceId | Sum | 300 s | not documented | Bytes | Sum / period seconds = bytes per second ("divide this number by 300"), GAUGE | `ec2_net_in_Bps` | 0 / U |
| `NetworkOut` | InstanceId | Sum | 300 s | not documented | Bytes | Sum / 300, GAUGE | `ec2_net_out_Bps` | 0 / U |
| `NetworkPacketsIn` | InstanceId | Sum | 300 s ("available for basic monitoring only (5-minute periods)") | not documented | Count | Sum / 300 = packets per second, GAUGE | `ec2_pkt_in_ps` | 0 / U |
| `NetworkPacketsOut` | InstanceId | Sum | 300 s | not documented | Count | Sum / 300, GAUGE | `ec2_pkt_out_ps` | 0 / U |
| `EBSReadBytes` (Nitro instances) | InstanceId | Sum | 300 s | not documented | Bytes | Sum / 300, GAUGE | `ec2_ebs_rd_Bps` | 0 / U |
| `EBSWriteBytes` (Nitro) | InstanceId | Sum | 300 s | not documented | Bytes | Sum / 300, GAUGE | `ec2_ebs_wr_Bps` | 0 / U |
| `EBSReadOps` (Nitro) | InstanceId | Sum | 300 s | not documented | Count | Sum / 300 = IOPS, GAUGE | `ec2_ebs_rd_ops` | 0 / U |
| `EBSWriteOps` (Nitro) | InstanceId | Sum | 300 s | not documented | Count | Sum / 300, GAUGE | `ec2_ebs_wr_ops` | 0 / U |
| `DiskReadBytes` (instance store only) | InstanceId | Sum | 300 s | not documented | Bytes | Sum / 300, GAUGE | `ec2_dsk_rd_Bps` | 0 / U |
| `DiskWriteBytes` (instance store only) | InstanceId | Sum | 300 s | not documented | Bytes | Sum / 300, GAUGE | `ec2_dsk_wr_Bps` | 0 / U |
| `DiskReadOps` (instance store only) | InstanceId | Sum | 300 s | not documented | Count | Sum / 300, GAUGE | `ec2_dsk_rd_ops` | 0 / U |
| `DiskWriteOps` (instance store only) | InstanceId | Sum | 300 s | not documented | Count | Sum / 300, GAUGE | `ec2_dsk_wr_ops` | 0 / U |
| `StatusCheckFailed` | InstanceId | Maximum | 60 s ("By default, this metric is available at a 1-minute frequency at no charge") | not documented | Count (0 or 1) | absolute, GAUGE | `ec2_status_fail` | 0 / 1 |

Notes:
- The EC2 page says disk metrics cover instance store volumes: "If there are no instance store volumes, either the value is 0 or the metric is not reported." On EBS-only instances the `EBS*` metrics are the useful ones. The EBS metrics' Nitro-only scope comes from the page's section heading "Amazon EBS metrics for Nitro-based instances".
- "No charge" for `StatusCheckFailed` refers to CloudWatch's publication; `GetMetricData` still bills per metric requested.
- Memory and disk-space metrics (`CWAgent` namespace) are **AGENT** and out of the default set.

### Application Load Balancers (namespace `AWS/ApplicationELB`, dimension `LoadBalancer`; target-level metrics also `TargetGroup`)

Native period 60 s: "Elastic Load Balancing measures and sends its metrics in 60-second intervals", only while requests flow. Many metrics list "Reporting criteria: There is a nonzero value", so an absent datapoint means zero for count metrics on an idle balancer; the plugin must map missing Sum datapoints to 0 and missing Average datapoints (response time) to unknown. Delay not documented.

| Provider metric | Dimensions | Statistic | Native period | Delay | Provider unit | Conversion to Cacti value | DS name | RRD min / max |
|---|---|---|---|---|---|---|---|---|
| `RequestCount` | LoadBalancer | Sum | 60 s | not documented | Count | Sum / 60 = requests per second; missing = 0 | `alb_req_ps` | 0 / U |
| `ActiveConnectionCount` | LoadBalancer | Sum ("The only meaningful statistic is Sum") | 60 s | not documented | Count | absolute, GAUGE; missing = 0 | `alb_conn_act` | 0 / U |
| `ProcessedBytes` | LoadBalancer | Sum | 60 s | not documented | Bytes | Sum / 60, GAUGE | `alb_bytes_ps` | 0 / U |
| `HTTPCode_Target_5XX_Count` | LoadBalancer | Sum | 60 s | not documented | Count | Sum / 60, missing = 0 | `alb_t5xx_ps` | 0 / U |
| `HTTPCode_ELB_5XX_Count` | LoadBalancer | Sum | 60 s | not documented | Count | Sum / 60, missing = 0 | `alb_e5xx_ps` | 0 / U |
| `TargetResponseTime` | LoadBalancer | Average | 60 s | not documented | Seconds | absolute, GAUGE; missing = unknown | `alb_resp_s` | 0 / U |
| `HealthyHostCount` | LoadBalancer + TargetGroup | Minimum | 60 s | not documented | Count | absolute, GAUGE; "Reported if there are registered targets" | `alb_healthy` | 0 / U |
| `UnHealthyHostCount` | LoadBalancer + TargetGroup | Maximum | 60 s | not documented | Count | absolute, GAUGE | `alb_unhealthy` | 0 / U |

The two host-count metrics need one query per target group, so the index for them is the target group, not the load balancer. Plan decision needed.

### Network Load Balancers (namespace `AWS/NetworkELB`, dimension `LoadBalancer`; target group metrics add `TargetGroup`)

Same 60 s cadence statement and "metric is not reported" behavior as ALB. Many NLB metrics are "Always reported".

| Provider metric | Dimensions | Statistic | Native period | Delay | Provider unit | Conversion to Cacti value | DS name | RRD min / max |
|---|---|---|---|---|---|---|---|---|
| `ActiveFlowCount` | LoadBalancer | Average | 60 s | not documented | Count | absolute, GAUGE | `nlb_flow_act` | 0 / U |
| `NewFlowCount` | LoadBalancer | Sum | 60 s | not documented | Count | Sum / 60 = new flows per second | `nlb_flow_new_ps` | 0 / U |
| `ProcessedBytes` | LoadBalancer | Sum | 60 s | not documented | Bytes | Sum / 60 | `nlb_bytes_ps` | 0 / U |
| `ProcessedPackets` | LoadBalancer | Sum | 60 s | not documented | Count | Sum / 60 | `nlb_pkts_ps` | 0 / U |
| `HealthyHostCount` | LoadBalancer + TargetGroup | Minimum | 60 s | not documented | Count | absolute, GAUGE | `nlb_healthy` | 0 / U |
| `UnHealthyHostCount` | LoadBalancer + TargetGroup | Maximum | 60 s | not documented | Count | absolute, GAUGE | `nlb_unhealthy` | 0 / U |
| `TCP_Target_Reset_Count` | LoadBalancer | Sum | 60 s | not documented | Count | Sum / 60 | `nlb_tgt_rst_ps` | 0 / U |

The statistics column for `ActiveFlowCount` follows the page text "most useful statistics are Average, Maximum, and Minimum". `HealthyHostCount` page text: "most useful statistics are Maximum and Minimum".

---

## Google Compute Engine instances

Reference: `https://docs.cloud.google.com/monitoring/api/metrics_gcp_c` (all metric type names below are prefixed `compute.googleapis.com/`). Monitored resource `gce_instance`, label `instance_name` (the resource label names used for `groupByFields`, such as `resource.label.instance_id`, were not checked against a page and are an assumption). Every row: "Sampled every 60 seconds. After sampling, data is not visible for up to 240 seconds."

Query shape: `timeSeries.list` with `filter = metric.type="..." `, `aggregation.alignmentPeriod` = the collection interval (300 s default), `perSeriesAligner` and, where a metric has extra labels, `crossSeriesReducer = REDUCE_SUM` with `groupByFields = ["resource.label.instance_id"]` so one series per instance is returned. Whether the reducer reduces the number of billed series is **not documented** (live test with `time_series_billed_for_queries_count`).

| Provider metric (`compute.googleapis.com/...`) | Labels / filter | Aligner (reducer) | Native period | Delay | Provider kind, type, unit | Conversion to Cacti value | DS name | RRD min / max |
|---|---|---|---|---|---|---|---|---|
| `instance/cpu/utilization` | `instance_name` | `ALIGN_MEAN` | 60 s | up to 240 s | GAUGE, DOUBLE, 10^2.% (fraction, "Charts display the values as a percentage"; "typically numbers between 0.0 and 1.0 (but some machine types allow bursting above 1.0)") | value * 100 = percent, GAUGE | `gce_cpu_pct` | 0 / U (bursting above 100 possible) |
| `instance/network/received_bytes_count` | `instance_name`, `loadbalanced` (BOOL) | `ALIGN_RATE`, `REDUCE_SUM` over `loadbalanced` | 60 s | up to 240 s | DELTA, INT64, By | already bytes per second after `ALIGN_RATE` ("rate = (y1 - y0)/(t1 - t0)"), GAUGE | `gce_net_in_Bps` | 0 / U |
| `instance/network/sent_bytes_count` | same | `ALIGN_RATE`, `REDUCE_SUM` | 60 s | up to 240 s | DELTA, INT64, By | bytes per second, GAUGE | `gce_net_out_Bps` | 0 / U |
| `instance/network/received_packets_count` | same | `ALIGN_RATE`, `REDUCE_SUM` | 60 s | up to 240 s | DELTA, INT64, 1 | packets per second, GAUGE | `gce_pkt_in_ps` | 0 / U |
| `instance/network/sent_packets_count` | same | `ALIGN_RATE`, `REDUCE_SUM` | 60 s | up to 240 s | DELTA, INT64, 1 | packets per second, GAUGE | `gce_pkt_out_ps` | 0 / U |
| `instance/disk/read_bytes_count` | `instance_name`, `device_name`, `storage_type`, `device_type` | `ALIGN_RATE`, `REDUCE_SUM` over disk labels | 60 s | up to 240 s | DELTA, INT64, By | bytes per second, GAUGE | `gce_dsk_rd_Bps` | 0 / U |
| `instance/disk/write_bytes_count` | same | `ALIGN_RATE`, `REDUCE_SUM` | 60 s | up to 240 s | DELTA, INT64, By | bytes per second, GAUGE | `gce_dsk_wr_Bps` | 0 / U |
| `instance/disk/read_ops_count` | same | `ALIGN_RATE`, `REDUCE_SUM` | 60 s | up to 240 s | DELTA, INT64, 1 | IOPS, GAUGE | `gce_dsk_rd_ops` | 0 / U |
| `instance/disk/write_ops_count` | same | `ALIGN_RATE`, `REDUCE_SUM` | 60 s | up to 240 s | DELTA, INT64, 1 | IOPS, GAUGE | `gce_dsk_wr_ops` | 0 / U |

Notes:
- The aligner definitions are quoted from the alert policy reference: "ALIGN_RATE ... valid for CUMULATIVE and DELTA metrics with numeric values" and "ALIGN_MEAN ... valid for GAUGE and DELTA metrics".
- The CPU page notes `compute.googleapis.com/instance/cpu/utilization` "is reported by the hypervisor for the VM and can differ from `agent.googleapis.com/cpu/utilization`, which is reported from inside the VM". The latter, and memory metrics (`agent.googleapis.com/memory/...`), are **AGENT** and not in the default set.
- Where an alignment period is shorter than the sampling period, results interpolate ("the aligned value for such a period is created by interpolation"), so the alignment period must be at least 60 s.
- All nine metrics need `monitoring.read`; one `timeSeries.list` call per metric type per project, as the plan states.

---

## Azure virtual machines

Reference: `https://learn.microsoft.com/en-us/azure/azure-monitor/reference/supported-metrics/microsoft-compute-virtualmachines-metrics` (namespace `Microsoft.Compute/virtualMachines`). Every row below has time grain PT1M and dimension `<none>`. Delay: **not documented** for these metrics; the troubleshooting page says only "the latency of processing metrics can be within a couple minutes range". Retention 93 days.

Query shape: `POST {region}.metrics.monitor.azure.com/subscriptions/{id}/metrics:getBatch?metricNamespace=microsoft.compute/virtualMachines&metricnames=...&aggregation=...&interval=PT1M&api-version=2023-10-01` with `resourceids` (up to 50). Multiple `metricnames` with one `aggregation` per call. Whether several metric names in one call count as one billed call is **unverified** (see [provider facts](provider-facts.md), AZ8). Because the aggregation is per call, Total metrics and Average metrics go in separate calls.

| Provider metric (REST name) | Dimensions | Aggregation | Native period | Delay | Provider unit | Conversion to Cacti value | DS name | RRD min / max |
|---|---|---|---|---|---|---|---|---|
| `Percentage CPU` | none | Average | PT1M | not documented | Percent | absolute, GAUGE | `azvm_cpu_pct` | 0 / 100 |
| `Network In Total` | none | Total (Sum) | PT1M | not documented | Bytes | Total / 60 = bytes per second, GAUGE | `azvm_net_in_Bps` | 0 / U |
| `Network Out Total` | none | Total (Sum) | PT1M | not documented | Bytes | Total / 60, GAUGE | `azvm_net_out_Bps` | 0 / U |
| `Disk Read Bytes` | none | Total (Sum) | PT1M | not documented | Bytes ("Bytes read from disk during monitoring period") | Total / 60, GAUGE | `azvm_dsk_rd_Bps` | 0 / U |
| `Disk Write Bytes` | none | Total (Sum) | PT1M | not documented | Bytes | Total / 60, GAUGE | `azvm_dsk_wr_Bps` | 0 / U |
| `Disk Read Operations/Sec` | none | Average | PT1M | not documented | CountPerSecond | already per second, GAUGE | `azvm_dsk_rd_ops` | 0 / U |
| `Disk Write Operations/Sec` | none | Average | PT1M | not documented | CountPerSecond | already per second, GAUGE | `azvm_dsk_wr_ops` | 0 / U |
| `VmAvailabilityMetric` (display "VM Availability Metric") | `Context` | Average, Minimum, Maximum | PT1M | not documented | Count | absolute, GAUGE (Minimum) | `azvm_avail` | 0 / 1 |
| `Available Memory Bytes` | none | Average | PT1M | not documented | Bytes | absolute, GAUGE | `azvm_mem_avail` | 0 / U |

Notes:
- **No packet-count metric** exists for virtual machines in the supported metrics page (the network rows are bytes and flow counts). Packet graphs for Azure VMs are not offered.
- `Available Memory Bytes` is on the platform metrics list, but the page does not say whether it needs the guest agent, so it is **not in the default set** until the live test reports data from an agent-free VM. Treat as possibly **AGENT**.
- The `Disk Read Operations/Sec` row name on this page also appears as a per-LUN data disk metric ("Data Disk Read Operations/Sec", dimension `LUN`); the table above uses the VM-level metric with no dimension.
- Deprecated metrics (`Network In`, `Network Out`) exist; use the `Total` forms.

---

## Data source name check

Cacti stores data source names in `varchar(19)` columns and validates template names with `^[a-zA-Z0-9_]{1,19}$` at both `release/1.2.31` and `1.2.x` ([Cacti core](cacti-core.md), data source name row). All 77 names above were checked by script against that pattern: all match, none repeats, and the longest is 16 characters.
