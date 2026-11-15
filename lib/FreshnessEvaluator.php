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
 * Freshness of a datapoint from its provider timestamp (REQ-STATE-002).
 *
 * A value is current within the metric's period plus its publication delay
 * plus the collection interval, delayed up to twice that, and stale beyond.
 * A datapoint stamped in the future by more than the allowance means the
 * poller's clock or the provider's disagrees, and the value is unknown with
 * reason clock_skew (REQ-STATE-005). The fetch time never counts: a recent
 * fetch of an old datapoint is still old.
 */
final class FreshnessEvaluator {
	public const SKEW_ALLOWANCE = 120;

	public function __construct(private readonly Clock $clock) {
	}

	/**
	 * The state of a cached datapoint as the health page counts it and the
	 * script server serves it. No catalog metric documents a period yet
	 * (#16), so the account's collection interval stands in for it.
	 *
	 * @return string a Freshness value, 'unknown', or the cache status
	 */
	public function state(Observation $observation, string $status, int $collectInterval): string {
		return match (true) {
			// A recent datapoint without a value is still no measurement.
			$observation->value === null => 'unknown',
			$status === 'ok'             => $this->evaluate($observation, $collectInterval, 0, $collectInterval)->value,
			default                      => $status,
		};
	}

	public function evaluate(?Observation $observation, int $period, int $delay, int $collectInterval): Freshness {
		if ($observation === null || $observation->observedAt <= 0) {
			return Freshness::Never;
		}

		$age = $this->clock->now() - $observation->observedAt;

		if ($age < -self::SKEW_ALLOWANCE) {
			return Freshness::ClockSkew;
		}

		$window = max(1, $period) + max(0, $delay) + max(1, $collectInterval);

		return match (true) {
			$age <= $window     => Freshness::Current,
			$age <= 2 * $window => Freshness::Delayed,
			default             => Freshness::Stale,
		};
	}
}
