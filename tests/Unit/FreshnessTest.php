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

use Somethingwithproof\Cloud\FixedClock;
use Somethingwithproof\Cloud\Freshness;
use Somethingwithproof\Cloud\FreshnessEvaluator;
use Somethingwithproof\Cloud\Observation;

it('classifies datapoints by their provider time against period, delay, and interval', function (int $age, Freshness $expected) {
	$now   = 1_800_000_000;
	$fresh = new FreshnessEvaluator(new FixedClock($now));

	// Window: 60 s period + 120 s delay + 300 s interval = 480 s.
	expect($fresh->evaluate(new Observation(1, 'cpu', 1.0, $now - $age, $now), 60, 120, 300))->toBe($expected);
})->with([
	[0, Freshness::Current],
	[480, Freshness::Current],
	[481, Freshness::Delayed],
	[960, Freshness::Delayed],
	[961, Freshness::Stale],
	[-120, Freshness::Current],
	[-121, Freshness::ClockSkew],
]);

it('never counts the fetch time as a measurement', function () {
	$now = 1_800_000_000;

	expect((new FreshnessEvaluator(new FixedClock($now)))->evaluate(new Observation(1, 'cpu', 1.0, $now - 86400, $now), 60, 0, 300))->toBe(Freshness::Stale);
});

it('reports never-observed series as never, and only current or delayed as usable', function () {
	$fresh = new FreshnessEvaluator(new FixedClock(100));

	expect($fresh->evaluate(null, 60, 0, 300))->toBe(Freshness::Never)
		->and($fresh->evaluate(new Observation(1, 'cpu', null, 0, 100), 60, 0, 300))->toBe(Freshness::Never)
		->and(array_map(fn (Freshness $f) => $f->usable(), Freshness::cases()))->toBe([true, true, false, false, false]);
});

it('pins observation rows with NULL, empty, and malformed values', function () {
	expect(Observation::fromRow(['resource_id' => '3', 'metric' => 'cpu', 'value' => null, 'observed_at' => '', 'fetched_at' => 'x']))
		->toEqual(new Observation(3, 'cpu', null, 0, 0))
		->and(Observation::fromRow(['resource_id' => '3', 'metric' => 'cpu', 'value' => 'U']))->toEqual(new Observation(3, 'cpu', null, 0, 0))
		->and(Observation::fromRow(['resource_id' => '3', 'metric' => 'cpu', 'value' => '0']))->toEqual(new Observation(3, 'cpu', 0.0, 0, 0))
		->and(Observation::fromRow(['resource_id' => '0', 'metric' => 'cpu']))->toBeNull()
		->and(Observation::fromRow(['resource_id' => '3', 'metric' => '']))->toBeNull();
});

it('pins health rows with unknown states', function () {
	$health = \Somethingwithproof\Cloud\AccountHealth::fromRow(['account_id' => '2', 'collection' => 'weird', 'error_class' => 'nope', 'run_duration' => '-1']);

	expect($health->collection)->toBe(\Somethingwithproof\Cloud\CollectionState::Never)
		->and($health->error)->toBeNull()
		->and($health->runDuration)->toBe(0.0)
		->and(\Somethingwithproof\Cloud\AccountHealth::fromRow(['account_id' => '']))->toBeNull();
});
