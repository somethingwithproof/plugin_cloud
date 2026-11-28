<!--
SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
SPDX-License-Identifier: GPL-2.0-or-later
-->

# Performance Profile and Algorithmic Audit

This document records the algorithmic audit and PHP runtime profiling performed on `plugin_cloud` under PHP 8.4.25 (CLI, 512 MiB memory limit, Apple Silicon arm64) using `hrtime(true)` wall-clock timers and `memory_get_peak_usage(true)` peak RSS measurements across 15,000 to 50,000 cloud resources, metric series, and controller observations.

## Bottlenecks Found and Fixed

1. **`ResourceRepository::sync()` (`lib/ResourceRepository.php`)**
   - **Root cause:** Discovered resource keys were appended to a sequential list `$seen[]`, and every existing database row was checked against `$seen` using `in_array(..., $seen, true)`. At $N$ resources this produced $O(N^2)$ linear scans (doubling from 15,000 to 30,000 resources increased runtime 3.80x from 438 ms to 1,666 ms).
   - **Fix:** Indexed `$seen` as an associative hash set (`$seen[$key] = true`) and replaced `in_array()` with `isset($seen[$key])`, reducing reconciliation to $O(N)$.

2. **`Redactor::add()` (`lib/Redactor.php`)**
   - **Root cause:** `Collector` shares one `Redactor` instance across accounts. Every `add()` call performed linear `in_array()` deduplication and full `usort()` by length ($O(K^2 \log K)$ over $K$ secrets), even though `clean()` already collects all spans and merges overlapping ranges after sorting spans.
   - **Fix:** Stored known forms in an `array<string, int>` map (`form => byte length`) for $O(1)$ deduplication and $O(K)$ registration.

3. **Multi-Cloud Metric Collection Parsers (`lib/AwsDriver.php`, `lib/GcpDriver.php`, `lib/AzureDriver.php`, `lib/DigitalOceanDriver.php`)**
   - **Root cause:**
     - `AwsDriver::discover()` called `array_merge()` in the per-region loop, copying the accumulated resource array repeatedly.
     - `AwsDriver::collect()`, `GcpDriver::collect()`, and `AzureDriver::collect()` called `strtotime()` on every datapoint timestamp even though batched responses share the same handful of ISO-8601 window timestamps, recomputed `gmdate()` window strings per chunk/page, and checked configured regions with linear `in_array()`.
     - `DigitalOceanDriver::cpu()` intersected timestamp lists via `array_intersect()` on value arrays inside a loop instead of hash-key intersection.
   - **Fix:** Replaced loop `array_merge()` with `array_push()`, precomputed `array_flip($regions)` hash sets and ISO-8601 window strings once per `collect()`, memoized `strtotime()` results per timestamp string across each collection run, and switched `DigitalOceanDriver::cpu()` to `array_intersect_key()`.

4. **`AdaptiveConcurrencyController` (`lib/AdaptiveConcurrencyController.php`), `OutboundPolicy` (`lib/OutboundPolicy.php`), and `ScriptServer::fields()` (`lib/ScriptServer.php`)**
   - **Root cause:** `recordObservation()` copied state arrays out and wrote them back on every sample; `projectCycle()` recomputed `scopeKey()` (trim + lowercase + concatenation) seven times per call. `OutboundPolicy::check()` ran linear `in_array()` scans over the host and port allowlists on every request. `ScriptServer::fields()` rebuilt the field list with loop `array_merge()` on every call.
   - **Fix:** Mutated per-scope state in place in `recordObservation()`, computed `scopeKey()` once per `projectCycle()` / `recommendedConcurrency()`, added $O(1)$ flipped host/port sets in `OutboundPolicy`, and memoized `ScriptServer::fields()` by resource type.

## Before and After Benchmarks

Measured with `hrtime(true)` and `memory_get_peak_usage(true)` on PHP 8.4.25 (exact same synthetic scale workload before and after fixes):

| Workload | Before (ms) | After (ms) | Speedup | Peak RSS Before | Peak RSS After |
| --- | ---: | ---: | ---: | ---: | ---: |
| `ResourceRepository::sync()` (15,000 resources) | 438.23 | 14.73 | 29.8x | 28.00 MiB | 28.00 MiB |
| `ResourceRepository::sync()` (30,000 resources) | 1,666.15 | 30.58 | 54.5x | 54.00 MiB | 54.00 MiB |
| `AwsDriver::collect()` (20,000 series, 40 batches) | 319.05 | 131.76 | 2.42x | 46.00 MiB | 46.00 MiB |
| `AzureDriver::collect()` (20,000 series, 400 batches) | 279.21 | 95.29 | 2.93x | 60.00 MiB | 60.00 MiB |
| `GcpDriver::collect()` (20,000 series, 40 pages) | 239.67 | 54.89 | 4.37x | 66.00 MiB | 66.00 MiB |
| `AdaptiveConcurrencyController` (50,000 obs + 50,000 projections) | 112.16 | 55.45 | 2.02x | 60.00 MiB | 60.00 MiB |
| `OutboundPolicy::check()` (50,000 URL checks, 41 hosts) | 38.49 | 34.93 | 1.10x | 60.00 MiB | 60.00 MiB |
| `Redactor::add()` (1,500 secrets) + `clean()` (500 calls) | 2,808.45 | 92.74 | 30.3x | 60.00 MiB | 60.00 MiB |

## Complexity Summary

- Inventory reconciliation (`ResourceRepository::sync()`): $O(N^2) \rightarrow O(N)$, scaling linearly from 14.73 ms at 15,000 resources to 30.58 ms at 30,000 resources (2.08x time for 2.00x input).
- Secret registration (`Redactor::add()`): $O(K^2 \log K) \rightarrow O(K)$.
- Batched cloud driver collection (`AwsDriver`, `GcpDriver`, `AzureDriver`): 2.4x to 4.4x faster pure-PHP parsing at 20,000 series by removing per-datapoint `strtotime()` and linear region scans.
- Concurrency controller (`AdaptiveConcurrencyController`): 2.0x faster at 50,000 observations and cycle projections.
