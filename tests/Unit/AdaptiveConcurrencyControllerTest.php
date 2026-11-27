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

use Somethingwithproof\Cloud\AdaptiveConcurrencyController;

it('tracks independent concurrency and CoDel state per provider and region', function () {
	$ctrl = new AdaptiveConcurrencyController(
		minConcurrency: 1,
		maxConcurrency: 12,
		initialConcurrency: 6,
		targetDelayMs: 100.0,
		intervalMs: 1000.0
	);

	// Establish baseline latencies on two regions of two providers.
	$ctrl->recordObservation('aws', 'us-east-1', 50.0, 200, 1000.0);
	$ctrl->recordObservation('aws', 'eu-west-1', 50.0, 200, 1000.0);
	$ctrl->recordObservation('gcp', 'us-central1', 40.0, 200, 1000.0);

	// Throttle aws:us-east-1 only; other regions and providers remain unaffected.
	$limit = $ctrl->recordObservation('aws', 'us-east-1', 350.0, 429, 1500.0, 10);

	expect($limit)->toBe(3)
		->and($ctrl->currentLimit('aws', 'us-east-1'))->toBe(3)
		->and($ctrl->currentLimit('aws', 'eu-west-1'))->toBe(6)
		->and($ctrl->currentLimit('gcp', 'us-central1'))->toBe(6)
		->and($ctrl->canDispatch('aws', 'us-east-1', 5000.0))->toBeFalse()
		->and($ctrl->canDispatch('aws', 'eu-west-1', 5000.0))->toBeTrue()
		->and($ctrl->canDispatch('gcp', 'us-central1', 5000.0))->toBeTrue();
});

it('ignores transient latency spikes shorter than CoDel intervalMs', function () {
	$ctrl = new AdaptiveConcurrencyController(
		minConcurrency: 1,
		maxConcurrency: 10,
		initialConcurrency: 8,
		targetDelayMs: 100.0,
		intervalMs: 1000.0
	);

	// Baseline minLatencyMs = 40ms.
	$ctrl->recordObservation('aws', 'us-east-1', 40.0, 200, 0.0);

	// Spike sojourn = 210ms (> target 100ms), lasting only 600ms (< interval 1000ms).
	$ctrl->recordObservation('aws', 'us-east-1', 250.0, 200, 200.0);
	$ctrl->recordObservation('aws', 'us-east-1', 260.0, 200, 800.0);

	expect($ctrl->currentLimit('aws', 'us-east-1'))->toBe(8)
		->and($ctrl->snapshot('aws', 'us-east-1')['dropping'])->toBeFalse();

	// Latency returns below target before full intervalMs elapses.
	$ctrl->recordObservation('aws', 'us-east-1', 60.0, 200, 950.0);

	expect($ctrl->currentLimit('aws', 'us-east-1'))->toBe(8)
		->and($ctrl->snapshot('aws', 'us-east-1')['dropping'])->toBeFalse();
});

it('enters CoDel dropping mode after persistent sojourn above target and applies inverse-sqrt control law', function () {
	$ctrl = new AdaptiveConcurrencyController(
		minConcurrency: 1,
		maxConcurrency: 16,
		initialConcurrency: 16,
		targetDelayMs: 100.0,
		intervalMs: 1000.0,
		decreaseFactor: 0.5
	);

	// Baseline minLatencyMs = 50ms.
	$ctrl->recordObservation('azure', 'eastus', 50.0, 200, 0.0);

	// First observation above target (sojourn = 200ms) at t = 1000ms starts the interval.
	$ctrl->recordObservation('azure', 'eastus', 250.0, 200, 1000.0);
	expect($ctrl->currentLimit('azure', 'eastus'))->toBe(16);

	// Still above target at t = 1999ms (< 1000ms elapsed): no drop yet.
	$ctrl->recordObservation('azure', 'eastus', 250.0, 200, 1999.0);
	expect($ctrl->currentLimit('azure', 'eastus'))->toBe(16);

	// At t = 2000ms (full 1000ms interval elapsed): enter dropping mode, count = 1, limit 16 -> 8.
	// Next drop scheduled at 2000 + 1000 / sqrt(1) = 3000ms.
	$ctrl->recordObservation('azure', 'eastus', 250.0, 200, 2000.0);
	$snap1 = $ctrl->snapshot('azure', 'eastus');

	expect($snap1['limit'])->toBe(8)
		->and($snap1['dropping'])->toBeTrue()
		->and($snap1['drop_count'])->toBe(1)
		->and($snap1['drop_next_ms'])->toBe(3000.0);

	// Before drop_next_ms (2999ms): still dropping, no second decrease yet.
	$ctrl->recordObservation('azure', 'eastus', 260.0, 200, 2999.0);
	expect($ctrl->currentLimit('azure', 'eastus'))->toBe(8);

	// At t = 3000ms: count = 2, limit 8 -> 4, next drop at 3000 + 1000 / sqrt(2) ~= 3707.11ms.
	$ctrl->recordObservation('azure', 'eastus', 260.0, 200, 3000.0);
	$snap2 = $ctrl->snapshot('azure', 'eastus');

	expect($snap2['limit'])->toBe(4)
		->and($snap2['drop_count'])->toBe(2)
		->and($snap2['drop_next_ms'])->toEqualWithDelta(3000.0 + (1000.0 / sqrt(2.0)), 0.01);

	// At t = 3708ms: count = 3, limit 4 -> 2, next drop at 3708 + 1000 / sqrt(3) ~= 4285.35ms.
	$ctrl->recordObservation('azure', 'eastus', 260.0, 200, 3708.0);
	$snap3 = $ctrl->snapshot('azure', 'eastus');

	expect($snap3['limit'])->toBe(2)
		->and($snap3['drop_count'])->toBe(3)
		->and($snap3['drop_next_ms'])->toEqualWithDelta(3708.0 + (1000.0 / sqrt(3.0)), 0.01);

	// Recovery below target exits dropping mode immediately.
	$ctrl->recordObservation('azure', 'eastus', 70.0, 200, 4000.0);
	expect($ctrl->snapshot('azure', 'eastus')['dropping'])->toBeFalse();
});

it('handles HTTP 429 and 529 immediately with Retry-After and non-blocking backoff', function () {
	$ctrl = new AdaptiveConcurrencyController(
		minConcurrency: 1,
		maxConcurrency: 16,
		initialConcurrency: 8,
		defaultThrottleBackoffMs: 4000
	);

	// HTTP 429 with Retry-After: 12 seconds.
	$after429 = $ctrl->recordObservation('aws', 'us-west-2', 120.0, 429, 10000.0, 12);
	expect($after429)->toBe(4)
		->and($ctrl->canDispatch('aws', 'us-west-2', 10000.0))->toBeFalse()
		->and($ctrl->retryAfterMs('aws', 'us-west-2', 15000.0))->toBe(7000.0)
		->and($ctrl->canDispatch('aws', 'us-west-2', 22000.0))->toBeTrue()
		->and($ctrl->retryAfterMs('aws', 'us-west-2', 22000.0))->toBe(0.0);

	// HTTP 529 without Retry-After uses defaultThrottleBackoffMs.
	$after529 = $ctrl->recordObservation('aws', 'us-west-2', 150.0, 529, 25000.0);
	expect($after529)->toBe(2)
		->and($ctrl->retryAfterMs('aws', 'us-west-2', 26000.0))->toBe(3000.0)
		->and($ctrl->canDispatch('aws', 'us-west-2', 29000.0))->toBeTrue();
});

it('applies additive increase (+1 per uncongested interval) up to maxConcurrency', function () {
	$ctrl = new AdaptiveConcurrencyController(
		minConcurrency: 1,
		maxConcurrency: 6,
		initialConcurrency: 4,
		targetDelayMs: 100.0,
		intervalMs: 1000.0
	);

	$ctrl->recordObservation('digitalocean', 'global', 60.0, 200, 0.0);
	expect($ctrl->currentLimit('digitalocean', 'global'))->toBe(4);

	// Within the first interval: no increase yet.
	$ctrl->recordObservation('digitalocean', 'global', 65.0, 200, 500.0);
	expect($ctrl->currentLimit('digitalocean', 'global'))->toBe(4);

	// After 1000ms uncongested: 4 -> 5.
	$ctrl->recordObservation('digitalocean', 'global', 62.0, 200, 1000.0);
	expect($ctrl->currentLimit('digitalocean', 'global'))->toBe(5);

	// After another 1000ms uncongested: 5 -> 6 (capped at maxConcurrency).
	$ctrl->recordObservation('digitalocean', 'global', 61.0, 200, 2000.0);
	expect($ctrl->currentLimit('digitalocean', 'global'))->toBe(6);

	// Never exceeds maxConcurrency.
	$ctrl->recordObservation('digitalocean', 'global', 60.0, 200, 4000.0);
	expect($ctrl->currentLimit('digitalocean', 'global'))->toBe(6);
});

it('refreshes the windowed minimum baseline after baselineWindowMs', function () {
	$ctrl = new AdaptiveConcurrencyController(
		minConcurrency: 1,
		maxConcurrency: 10,
		initialConcurrency: 6,
		targetDelayMs: 50.0,
		intervalMs: 1000.0,
		baselineWindowMs: 5000.0
	);

	// Initial baseline = 20ms at t = 0.
	$ctrl->recordObservation('gcp', 'europe-west1', 20.0, 200, 0.0);
	expect($ctrl->snapshot('gcp', 'europe-west1')['min_latency_ms'])->toBe(20.0);

	// After baselineWindowMs (t = 6000ms), network route shifts to 120ms baseline.
	$ctrl->recordObservation('gcp', 'europe-west1', 120.0, 200, 6000.0);
	expect($ctrl->snapshot('gcp', 'europe-west1')['min_latency_ms'])->toBe(120.0);
});

it('sizes concurrency with Little\'s Law (L = lambda * W) and projects cycle budget fit', function () {
	$ctrl = new AdaptiveConcurrencyController(
		minConcurrency: 1,
		maxConcurrency: 12,
		initialConcurrency: 8,
		ewmaAlpha: 1.0
	);

	// Exact EWMA service time W = 250ms.
	$ctrl->recordObservation('aws', 'us-east-1', 250.0, 200, 1000.0);

	// 100 pending requests in 5000ms budget => lambda = 0.02 req/ms => L = ceil(0.02 * 250) = 5.
	expect($ctrl->recommendedConcurrency('aws', 'us-east-1', 100, 5000.0))->toBe(5);

	$fits = $ctrl->projectCycle('aws', 'us-east-1', 100, 5000.0, 1100.0);
	expect($fits['required_concurrency'])->toBe(5)
		->and($fits['recommended_concurrency'])->toBe(5)
		->and($fits['estimated_duration_ms'])->toBe(5000.0)
		->and($fits['fits_budget'])->toBeTrue();

	// 400 pending requests in 5000ms budget => L = 20, clamped to currentLimit 8 => will not fit.
	$overflow = $ctrl->projectCycle('aws', 'us-east-1', 400, 5000.0, 1100.0);
	expect($overflow['required_concurrency'])->toBe(20)
		->and($overflow['recommended_concurrency'])->toBe(8)
		->and($overflow['fits_budget'])->toBeFalse();

	// Zero pending requests returns minConcurrency and fits budget.
	$idle = $ctrl->projectCycle('aws', 'us-east-1', 0, 5000.0, 1100.0);
	expect($idle['recommended_concurrency'])->toBe(1)
		->and($idle['fits_budget'])->toBeTrue();

	// Active throttle backoff consumes effective budget cleanly without sleeping.
	$ctrl->recordObservation('aws', 'us-east-1', 250.0, 429, 2000.0, 10);
	$throttled = $ctrl->projectCycle('aws', 'us-east-1', 10, 5000.0, 2500.0);
	expect($throttled['backoff_ms'])->toBe(9500.0)
		->and($throttled['effective_budget_ms'])->toBe(0.0)
		->and($throttled['fits_budget'])->toBeFalse();
});

it('normalizes empty scopes and returns default snapshots for unseen scopes', function () {
	$ctrl = new AdaptiveConcurrencyController(
		minConcurrency: 2,
		maxConcurrency: 10,
		initialConcurrency: 5
	);

	expect(AdaptiveConcurrencyController::scopeKey(' AWS ', ' US-EAST-1 '))->toBe('aws:us-east-1')
		->and(AdaptiveConcurrencyController::scopeKey('', ''))->toBe('unknown:global')
		->and($ctrl->canDispatch('aws', 'ap-south-1', 0.0))->toBeTrue()
		->and($ctrl->retryAfterMs('aws', 'ap-south-1', 0.0))->toBe(0.0)
		->and($ctrl->snapshot('aws', 'ap-south-1'))->toMatchArray([
			'scope'           => 'aws:ap-south-1',
			'limit'           => 5,
			'min_latency_ms'  => null,
			'ewma_latency_ms' => null,
			'dropping'        => false,
			'drop_count'      => 0,
			'samples'         => 0,
		]);
});
