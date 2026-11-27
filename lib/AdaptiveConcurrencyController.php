<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_cloud, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_cloud
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

namespace Somethingwithproof\Cloud;

/**
 * Adaptive per-provider, per-region concurrency controller grounded in
 * Nichols & Jacobson (CoDel: Controlling Queue Delay, ACM Queue 2012) and
 * Little's Law (L = lambda * W).
 *
 * Tracks a windowed minimum baseline latency per provider:region scope,
 * derives queueing sojourn delay (observedLatencyMs - minLatencyMs), ignores
 * transient latency spikes shorter than intervalMs, and enters CoDel dropping
 * mode only when sojourn delay persists above targetDelayMs for a full
 * intervalMs using the inverse-square-root control law interval / sqrt(count).
 *
 * HTTP 429 and 529 responses trigger immediate multiplicative decrease and
 * non-blocking retryUntilMs tracking without ever calling sleep() or usleep().
 */
final class AdaptiveConcurrencyController {
	/**
	 * Default lower bound for concurrency per provider:region scope.
	 */
	public const DEFAULT_MIN_CONCURRENCY = 1;

	/**
	 * Default upper bound for concurrency per provider:region scope.
	 */
	public const DEFAULT_MAX_CONCURRENCY = 16;

	/**
	 * Default starting concurrency limit for a newly seen provider:region scope.
	 */
	public const DEFAULT_INITIAL_CONCURRENCY = 4;

	/**
	 * Default CoDel target standing queue delay in milliseconds.
	 */
	public const DEFAULT_TARGET_DELAY_MS = 100.0;

	/**
	 * Default CoDel observation interval in milliseconds.
	 */
	public const DEFAULT_INTERVAL_MS = 1000.0;

	/**
	 * Default sliding window in milliseconds for resetting the minimum baseline RTT.
	 */
	public const DEFAULT_BASELINE_WINDOW_MS = 30000.0;

	/**
	 * Default EWMA smoothing factor alpha for Little's Law service time W.
	 */
	public const DEFAULT_EWMA_ALPHA = 0.25;

	/**
	 * Default non-blocking backoff in milliseconds when HTTP 429/529 omits Retry-After.
	 */
	public const DEFAULT_THROTTLE_BACKOFF_MS = 5000;

	/**
	 * Default multiplicative decrease factor applied on congestion or throttling.
	 */
	public const DEFAULT_DECREASE_FACTOR = 0.5;

	/**
	 * Default assumed per-request service time W in milliseconds prior to samples.
	 */
	public const DEFAULT_SERVICE_TIME_MS = 200.0;

	/**
	 * Minimum allowed concurrency limit.
	 *
	 * @var int
	 */
	private readonly int $minConcurrency;

	/**
	 * Maximum allowed concurrency limit.
	 *
	 * @var int
	 */
	private readonly int $maxConcurrency;

	/**
	 * Initial concurrency limit assigned to each new scope.
	 *
	 * @var int
	 */
	private readonly int $initialConcurrency;

	/**
	 * CoDel target queue sojourn delay in milliseconds.
	 *
	 * @var float
	 */
	private readonly float $targetDelayMs;

	/**
	 * CoDel observation interval in milliseconds.
	 *
	 * @var float
	 */
	private readonly float $intervalMs;

	/**
	 * Window duration in milliseconds before refreshing the minimum latency baseline.
	 *
	 * @var float
	 */
	private readonly float $baselineWindowMs;

	/**
	 * EWMA smoothing factor in (0, 1] for service time W.
	 *
	 * @var float
	 */
	private readonly float $ewmaAlpha;

	/**
	 * Fallback non-blocking throttle backoff in milliseconds when Retry-After is absent.
	 *
	 * @var int
	 */
	private readonly int $defaultThrottleBackoffMs;

	/**
	 * Multiplicative decrease factor in (0, 1) applied on congestion or throttling.
	 *
	 * @var float
	 */
	private readonly float $decreaseFactor;

	/**
	 * Independent CoDel and Little's Law state per provider:region scope.
	 *
	 * @var array<string, array{
	 *     limit: int,
	 *     minLatencyMs: ?float,
	 *     minLatencyObservedAtMs: float,
	 *     ewmaLatencyMs: ?float,
	 *     firstAboveTimeMs: ?float,
	 *     dropping: bool,
	 *     dropCount: int,
	 *     dropNextMs: float,
	 *     lastIncreaseMs: float,
	 *     retryUntilMs: float,
	 *     samples: int
	 * }>
	 */
	private array $states = [];

	/**
	 * Create an adaptive concurrency controller with bounded CoDel parameters.
	 *
	 * @param int   $minConcurrency           minimum concurrency floor (>= 1)
	 * @param int   $maxConcurrency           maximum concurrency ceiling (>= minConcurrency)
	 * @param int   $initialConcurrency       starting concurrency clamped to [min, max]
	 * @param float $targetDelayMs            CoDel target queue sojourn delay in milliseconds (> 0)
	 * @param float $intervalMs               CoDel persistence interval in milliseconds (> 0)
	 * @param float $baselineWindowMs         baseline minimum RTT window in milliseconds (> 0)
	 * @param float $ewmaAlpha                EWMA smoothing weight in (0, 1]
	 * @param int   $defaultThrottleBackoffMs default non-blocking backoff in ms on 429/529 (>= 0)
	 * @param float $decreaseFactor           multiplicative decrease factor in (0, 1)
	 */
	public function __construct(
		int $minConcurrency = self::DEFAULT_MIN_CONCURRENCY,
		int $maxConcurrency = self::DEFAULT_MAX_CONCURRENCY,
		int $initialConcurrency = self::DEFAULT_INITIAL_CONCURRENCY,
		float $targetDelayMs = self::DEFAULT_TARGET_DELAY_MS,
		float $intervalMs = self::DEFAULT_INTERVAL_MS,
		float $baselineWindowMs = self::DEFAULT_BASELINE_WINDOW_MS,
		float $ewmaAlpha = self::DEFAULT_EWMA_ALPHA,
		int $defaultThrottleBackoffMs = self::DEFAULT_THROTTLE_BACKOFF_MS,
		float $decreaseFactor = self::DEFAULT_DECREASE_FACTOR,
	) {
		$this->minConcurrency           = max(1, $minConcurrency);
		$this->maxConcurrency           = max($this->minConcurrency, $maxConcurrency);
		$this->initialConcurrency       = max($this->minConcurrency, min($this->maxConcurrency, $initialConcurrency));
		$this->targetDelayMs            = $targetDelayMs > 0.0 ? $targetDelayMs : self::DEFAULT_TARGET_DELAY_MS;
		$this->intervalMs               = $intervalMs > 0.0 ? $intervalMs : self::DEFAULT_INTERVAL_MS;
		$this->baselineWindowMs         = $baselineWindowMs > 0.0 ? $baselineWindowMs : self::DEFAULT_BASELINE_WINDOW_MS;
		$this->ewmaAlpha                = ($ewmaAlpha > 0.0 && $ewmaAlpha <= 1.0) ? $ewmaAlpha : self::DEFAULT_EWMA_ALPHA;
		$this->defaultThrottleBackoffMs = max(0, $defaultThrottleBackoffMs);
		$this->decreaseFactor           = ($decreaseFactor > 0.0 && $decreaseFactor < 1.0)
			? $decreaseFactor
			: self::DEFAULT_DECREASE_FACTOR;
	}

	/**
	 * Build the canonical provider:region key for independent state tracking.
	 *
	 * @param string $provider provider identifier (for example aws, gcp, azure, digitalocean)
	 * @param string $region   region identifier (defaults to "global" when empty)
	 * @return string normalized "provider:region" key
	 */
	public static function scopeKey(string $provider, string $region): string {
		$p = strtolower(trim($provider));
		$r = strtolower(trim($region));

		return ($p !== '' ? $p : 'unknown') . ':' . ($r !== '' ? $r : 'global');
	}

	/**
	 * Record a completed HTTP request observation and update concurrency limits.
	 *
	 * Updates the windowed minimum latency baseline and EWMA service time W,
	 * applies immediate multiplicative decrease plus non-blocking retryUntilMs
	 * backoff on HTTP 429 or 529, evaluates CoDel sojourn delay against
	 * targetDelayMs over intervalMs, and applies additive increase (+1) during
	 * uncongested intervals up to maxConcurrency.
	 *
	 * @param string   $provider          provider identifier
	 * @param string   $region            region identifier
	 * @param float    $observedLatencyMs measured round-trip latency in milliseconds
	 * @param int      $statusCode        HTTP response status code
	 * @param float    $nowMs             monotonic timestamp in milliseconds
	 * @param int|null $retryAfterSeconds optional HTTP Retry-After header in seconds
	 * @return int updated concurrency limit for this provider:region scope
	 */
	public function recordObservation(
		string $provider,
		string $region,
		float $observedLatencyMs,
		int $statusCode,
		float $nowMs,
		?int $retryAfterSeconds = null
	): int {
		$key     = self::scopeKey($provider, $region);
		$latency = max(0.0, $observedLatencyMs);

		if (!isset($this->states[$key])) {
			$this->states[$key] = [
				'limit'                  => $this->initialConcurrency,
				'minLatencyMs'           => null,
				'minLatencyObservedAtMs' => $nowMs,
				'ewmaLatencyMs'          => null,
				'firstAboveTimeMs'       => null,
				'dropping'               => false,
				'dropCount'              => 0,
				'dropNextMs'             => 0.0,
				'lastIncreaseMs'         => $nowMs,
				'retryUntilMs'           => 0.0,
				'samples'                => 0,
			];
		}

		$state = &$this->states[$key];
		$state['samples']++;

		if (
			$state['minLatencyMs'] === null
			|| $latency <= $state['minLatencyMs']
			|| ($nowMs - $state['minLatencyObservedAtMs']) >= $this->baselineWindowMs
		) {
			$state['minLatencyMs']           = $latency;
			$state['minLatencyObservedAtMs'] = $nowMs;
		}

		$state['ewmaLatencyMs'] = $state['ewmaLatencyMs'] === null
			? $latency
			: ($this->ewmaAlpha * $latency) + ((1.0 - $this->ewmaAlpha) * $state['ewmaLatencyMs']);

		// HTTP 429 (Too Many Requests) and 529 (Site Overloaded) require immediate backoff.
		if ($statusCode === 429 || $statusCode === 529) {
			$backoffMs = ($retryAfterSeconds !== null && $retryAfterSeconds > 0)
				? (float) $retryAfterSeconds * 1000.0
				: (float) $this->defaultThrottleBackoffMs;

			$state['limit']            = $this->decreaseLimit($state['limit']);
			$state['retryUntilMs']     = max($state['retryUntilMs'], $nowMs + $backoffMs);
			$state['dropping']         = true;
			$state['dropCount']        = max(1, $state['dropCount'] + 1);
			$state['dropNextMs']       = $nowMs + ($this->intervalMs / sqrt((float) $state['dropCount']));
			$state['firstAboveTimeMs'] = $nowMs;
			$state['lastIncreaseMs']   = $nowMs;

			return $state['limit'];
		}

		$sojournMs = max(0.0, $latency - $state['minLatencyMs']);

		if ($sojournMs < $this->targetDelayMs) {
			$state['firstAboveTimeMs'] = null;
			$state['dropping']         = false;

			if ($nowMs >= $state['retryUntilMs'] && ($nowMs - $state['lastIncreaseMs']) >= $this->intervalMs) {
				$state['limit']          = min($this->maxConcurrency, $state['limit'] + 1);
				$state['lastIncreaseMs'] = $nowMs;
			}
		} elseif ($state['dropping']) {
			if ($nowMs >= $state['dropNextMs']) {
				$state['dropCount']++;
				$state['limit']          = $this->decreaseLimit($state['limit']);
				$state['dropNextMs']     = $nowMs + ($this->intervalMs / sqrt((float) $state['dropCount']));
				$state['lastIncreaseMs'] = $nowMs;
			}
		} else {
			if ($state['firstAboveTimeMs'] === null) {
				$state['firstAboveTimeMs'] = $nowMs;
			} elseif (($nowMs - $state['firstAboveTimeMs']) >= $this->intervalMs) {
				$state['dropping']       = true;
				$state['dropCount']      = 1;
				$state['limit']          = $this->decreaseLimit($state['limit']);
				$state['dropNextMs']     = $nowMs + ($this->intervalMs / sqrt((float) $state['dropCount']));
				$state['lastIncreaseMs'] = $nowMs;
			}
		}

		return $state['limit'];
	}

	/**
	 * Return the current concurrency limit for a provider:region scope.
	 *
	 * @param string $provider provider identifier
	 * @param string $region   region identifier
	 * @return int current concurrency limit in [minConcurrency, maxConcurrency]
	 */
	public function currentLimit(string $provider, string $region): int {
		$key = self::scopeKey($provider, $region);

		return $this->states[$key]['limit'] ?? $this->initialConcurrency;
	}

	/**
	 * Return whether a provider:region scope may dispatch requests at nowMs.
	 *
	 * @param string $provider provider identifier
	 * @param string $region   region identifier
	 * @param float  $nowMs    monotonic timestamp in milliseconds
	 * @return bool true when no non-blocking throttle backoff is active
	 */
	public function canDispatch(string $provider, string $region, float $nowMs): bool {
		return $this->retryAfterMs($provider, $region, $nowMs) <= 0.0;
	}

	/**
	 * Return remaining non-blocking backoff duration in milliseconds.
	 *
	 * @param string $provider provider identifier
	 * @param string $region   region identifier
	 * @param float  $nowMs    monotonic timestamp in milliseconds
	 * @return float remaining wait in milliseconds (0.0 when ready to dispatch)
	 */
	public function retryAfterMs(string $provider, string $region, float $nowMs): float {
		$key = self::scopeKey($provider, $region);

		if (!isset($this->states[$key])) {
			return 0.0;
		}

		return max(0.0, $this->states[$key]['retryUntilMs'] - $nowMs);
	}

	/**
	 * Compute Little's Law concurrency sizing L = ceil((pending / budget) * W).
	 *
	 * Uses the observed EWMA service time W when available, falling back to
	 * defaultServiceTimeMs prior to the first observation. The result is clamped
	 * to [minConcurrency, currentLimit].
	 *
	 * @param string $provider             provider identifier
	 * @param string $region               region identifier
	 * @param int    $pendingRequests      number of requests remaining in the cycle
	 * @param float  $remainingBudgetMs    remaining poller cycle budget in milliseconds
	 * @param float  $defaultServiceTimeMs fallback service time W in milliseconds
	 * @return int recommended concurrency in [minConcurrency, currentLimit]
	 */
	public function recommendedConcurrency(
		string $provider,
		string $region,
		int $pendingRequests,
		float $remainingBudgetMs,
		float $defaultServiceTimeMs = self::DEFAULT_SERVICE_TIME_MS
	): int {
		$key   = self::scopeKey($provider, $region);
		$limit = $this->states[$key]['limit'] ?? $this->initialConcurrency;

		if ($pendingRequests <= 0) {
			return $this->minConcurrency;
		}

		if ($remainingBudgetMs <= 0.0) {
			return $limit;
		}

		$serviceTimeMs = $this->serviceTimeForKey($key, $defaultServiceTimeMs);
		$lambda        = $pendingRequests / $remainingBudgetMs;
		$required      = (int) ceil($lambda * $serviceTimeMs);

		return max($this->minConcurrency, min($limit, $required));
	}

	/**
	 * Project whether pending work fits in the remaining poller cycle budget.
	 *
	 * Accounts for active non-blocking retryUntilMs backoff and applies Little's
	 * Law (L = lambda * W) against the active concurrency cap.
	 *
	 * @param string $provider             provider identifier
	 * @param string $region               region identifier
	 * @param int    $pendingRequests      number of requests remaining in the cycle
	 * @param float  $remainingBudgetMs    remaining poller cycle budget in milliseconds
	 * @param float  $nowMs                monotonic timestamp in milliseconds
	 * @param float  $defaultServiceTimeMs fallback service time W in milliseconds
	 * @return array{
	 *     scope: string,
	 *     pending_requests: int,
	 *     current_limit: int,
	 *     service_time_ms: float,
	 *     backoff_ms: float,
	 *     effective_budget_ms: float,
	 *     required_concurrency: int,
	 *     recommended_concurrency: int,
	 *     estimated_duration_ms: float,
	 *     fits_budget: bool
	 * }
	 */
	public function projectCycle(
		string $provider,
		string $region,
		int $pendingRequests,
		float $remainingBudgetMs,
		float $nowMs,
		float $defaultServiceTimeMs = self::DEFAULT_SERVICE_TIME_MS
	): array {
		$scope           = self::scopeKey($provider, $region);
		$pending         = max(0, $pendingRequests);
		$budget          = max(0.0, $remainingBudgetMs);
		$state           = $this->states[$scope] ?? null;
		$currentLimit    = $state !== null ? $state['limit'] : $this->initialConcurrency;
		$serviceTimeMs   = $this->serviceTimeForKey($scope, $defaultServiceTimeMs);
		$backoffMs       = $state !== null ? max(0.0, $state['retryUntilMs'] - $nowMs) : 0.0;
		$effectiveBudget = max(0.0, $budget - $backoffMs);

		if ($pending === 0) {
			return [
				'scope'                   => $scope,
				'pending_requests'        => 0,
				'current_limit'           => $currentLimit,
				'service_time_ms'         => $serviceTimeMs,
				'backoff_ms'              => $backoffMs,
				'effective_budget_ms'     => $effectiveBudget,
				'required_concurrency'    => 0,
				'recommended_concurrency' => $this->minConcurrency,
				'estimated_duration_ms'   => $backoffMs,
				'fits_budget'             => $backoffMs <= $budget,
			];
		}

		$required    = $effectiveBudget > 0.0
			? max(1, (int) ceil(($pending / $effectiveBudget) * $serviceTimeMs))
			: $pending;
		$recommended = $effectiveBudget <= 0.0
			? $currentLimit
			: max($this->minConcurrency, min($currentLimit, $required));
		$batches     = (int) ceil($pending / max(1, $recommended));
		$estimatedMs = $backoffMs + ($batches * $serviceTimeMs);

		return [
			'scope'                   => $scope,
			'pending_requests'        => $pending,
			'current_limit'           => $currentLimit,
			'service_time_ms'         => $serviceTimeMs,
			'backoff_ms'              => $backoffMs,
			'effective_budget_ms'     => $effectiveBudget,
			'required_concurrency'    => $required,
			'recommended_concurrency' => $recommended,
			'estimated_duration_ms'   => $estimatedMs,
			'fits_budget'             => $effectiveBudget > 0.0 && $estimatedMs <= $budget,
		];
	}

	/**
	 * Return a read-only diagnostic snapshot for a provider:region scope.
	 *
	 * @param string $provider provider identifier
	 * @param string $region   region identifier
	 * @return array{
	 *     scope: string,
	 *     limit: int,
	 *     min_latency_ms: ?float,
	 *     ewma_latency_ms: ?float,
	 *     dropping: bool,
	 *     drop_count: int,
	 *     drop_next_ms: float,
	 *     retry_until_ms: float,
	 *     samples: int
	 * }
	 */
	public function snapshot(string $provider, string $region): array {
		$key = self::scopeKey($provider, $region);

		if (!isset($this->states[$key])) {
			return [
				'scope'           => $key,
				'limit'           => $this->initialConcurrency,
				'min_latency_ms'  => null,
				'ewma_latency_ms' => null,
				'dropping'        => false,
				'drop_count'      => 0,
				'drop_next_ms'    => 0.0,
				'retry_until_ms'  => 0.0,
				'samples'         => 0,
			];
		}

		$state = $this->states[$key];

		return [
			'scope'           => $key,
			'limit'           => $state['limit'],
			'min_latency_ms'  => $state['minLatencyMs'],
			'ewma_latency_ms' => $state['ewmaLatencyMs'],
			'dropping'        => $state['dropping'],
			'drop_count'      => $state['dropCount'],
			'drop_next_ms'    => $state['dropNextMs'],
			'retry_until_ms'  => $state['retryUntilMs'],
			'samples'         => $state['samples'],
		];
	}

	/**
	 * Resolve effective EWMA service time W in milliseconds for a normalized scope key.
	 *
	 * @param string $key                  normalized "provider:region" key
	 * @param float  $defaultServiceTimeMs fallback service time when no samples exist
	 * @return float positive service time estimate in milliseconds
	 */
	private function serviceTimeForKey(string $key, float $defaultServiceTimeMs): float {
		$fallback = $defaultServiceTimeMs > 0.0 ? $defaultServiceTimeMs : self::DEFAULT_SERVICE_TIME_MS;
		$ewma     = $this->states[$key]['ewmaLatencyMs'] ?? null;

		return ($ewma !== null && $ewma > 0.0) ? $ewma : $fallback;
	}

	/**
	 * Apply multiplicative decrease while respecting minConcurrency.
	 *
	 * @param int $currentLimit active concurrency limit
	 * @return int decreased concurrency limit >= minConcurrency
	 */
	private function decreaseLimit(int $currentLimit): int {
		if ($currentLimit <= $this->minConcurrency) {
			return $this->minConcurrency;
		}

		$reduced = (int) floor($currentLimit * $this->decreaseFactor);

		if ($reduced >= $currentLimit) {
			$reduced = $currentLimit - 1;
		}

		return max($this->minConcurrency, $reduced);
	}
}
