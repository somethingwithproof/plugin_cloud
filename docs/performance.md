# Performance

Measurements for [#47](https://github.com/somethingwithproof/plugin_cloud/issues/47), taken 2026-11-15 with [`tests/bin/perf.php`](../tests/bin/perf.php). CI runs the same script on every Cacti and PHP job. It fails the job only when 1,000 series don't finish within one poller interval, and prints its timings without comparing them, because runner hardware varies.

**Result.** With a local fixture server and no added latency, a collector run reads 1,000 series in about 3 seconds. That is about 3 ms of plugin and TLS work per request. The health page renders 10,000 cached series in 0.08 seconds. With 100 ms of simulated provider latency per request, the same run takes 106 seconds. Provider latency, not the plugin, decides whether 1,000 series fit in one poller interval.

## What runs

The script uses the real collector, the DigitalOcean driver, the guarded transport (operation allowlist) and the socket transport, with TLS verified against a test CA. Only the host is rewritten, after the operation check, so requests reach [`tests/bin/perf-server.php`](../tests/bin/perf-server.php) instead of `api.digitalocean.com`. That server replays the recorded fixtures with their timestamps moved to the current time, and sends the rate-limit headers the driver reads.

| Case | Setup | Measures |
|---|---|---|
| Collect | 4 accounts, 50 Droplets each, 5 agent-free series per Droplet: 1,000 series | One collector run on poller 1 (REQ-PERF-001) |
| Budget | 1 account, 200 Droplets, 5 series each: 1,000 series | The same run, where the request budget stops the account |
| Page | 10 accounts, 200 Droplets each, 5 cached series per Droplet: 10,000 series | `cloud_health.php` over HTTP as a logged-in administrator, the median of five loads after a warm-up (REQ-PERF-002) |

The collect case uses four accounts because one DigitalOcean token allows 250 requests a minute. At 100 percent request share, that is the most one account reads in a run (`RequestBudget::perRun`). The budget case shows the limit: the account reads 250 series, marks the other 750 unread with reason `budget`, and the health page reports it.

## Hardware and settings (REQ-PERF-003)

| | |
|---|---|
| Host | Apple M3 Max, 14 cores, 36 GiB |
| Runtime | OrbStack Linux VM, aarch64, 14 CPUs, 15.7 GiB, Docker image `cloud-ci:8.2` |
| PHP | 8.2.35, CLI for the collector, built-in web server for the page |
| Database | MariaDB 11.8.9, same VM |
| Cacti | 1.2.31 and the 1.2.x branch (1.2.32) |
| Poller interval | 300 seconds |
| Accounts | Collection interval 300 seconds, request share 100 percent, discovery not due during the run |
| Collector time budget | Poller interval less 10 seconds (290); the plugin's default setting is 240 |

## Results

Three runs on each Cacti version, no added latency:

| Case | Cacti 1.2.31 | Cacti 1.2.x |
|---|---|---|
| Collect, 1,000 series in 4 accounts | 2.83, 3.00, 3.17 s; 1,000 read with 1,000 requests | 3.42, 2.59, 2.61 s; 1,000 read with 1,000 requests |
| Budget, 1,000 series in 1 account | 1.20, 1.32, 1.10 s; 250 read, 750 left with reason `budget` | 1.24, 1.72, 1.10 s; 250 read, 750 left with reason `budget` |
| Health page, 10,000 series | 0.08 s median, 0.08 s slowest, all three runs | 0.08 s median, 0.09 s slowest at most |

With `perf.php <url> 100`, which adds 100 ms before each fixture response, the collect case took 106.26 seconds on Cacti 1.2.31 and read all 1,000 series. The budget case took 27.02 seconds.

The page result was checked by its content, not only its speed. Each of the 10 accounts showed 1,000 current series, and an estimate of 12,000 requests an hour (1,000 series every 300 seconds).

## Reading the results

- **Run time scales with provider latency.** The collector reads one series per request, one request at a time. A run takes about requests × (latency + 3 ms). With the default 240-second time budget, 1,000 series fit while the average round trip stays under about 237 ms. Above that, the collector stops starting new accounts at the budget, and the unread accounts are collected in the next run. DigitalOcean's real latency from a given poller is a [live check](research/provider-facts.md#live-checks) (#16).
- **DigitalOcean's limit is per token, not per plugin.** At the default 50 percent share, one account reads 125 series a run. Larger estates need more accounts, each with its own token, and the health page estimate shows how close each account is to its share.
- **The page stays cheap at 10,000 series.** It reads cached rows only and never calls a provider, so its cost grows with the plugin's tables, not with provider latency.
- **Not measured here:** RRD updates by Cacti's poller, boost, Spine, remote data collectors, and a database on a separate host. Each adds cost outside the plugin.

## Running it

On a disposable database, with the plugin installed and enabled and Cacti served at a URL:

```sh
CLOUD_TEST_DATABASE=disposable php plugins/cloud/tests/bin/perf.php http://127.0.0.1:8080/cacti/
CLOUD_TEST_DATABASE=disposable php plugins/cloud/tests/bin/perf.php http://127.0.0.1:8080/cacti/ 100
```

The second argument adds that many milliseconds of latency to each fixture response. The script deletes every plugin account, resource, series, and observation, and resets the admin password, so never run it against a database you keep.
