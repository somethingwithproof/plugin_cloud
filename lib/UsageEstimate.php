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
 * What collecting a set of series costs (REQ-COST-001). The cost is at list
 * price, before discounts and shared free tiers (REQ-COST-003), and names
 * the page the price came from and the date it was checked.
 */
final readonly class UsageEstimate {
	/**
	 * Construct an immutable provider API request and cost usage estimate.
	 *
	 * @param int    $requestsPerCollection API requests per collection cycle
	 * @param int    $requestsPerMonth      projected API requests over 30 days
	 * @param float  $monthlyCost           estimated monthly list cost in USD
	 * @param string $priceSource           reference pricing URL
	 * @param string $priceChecked          date pricing was verified (YYYY-MM-DD)
	 */
	public function __construct(
		public int $requestsPerCollection,
		public int $requestsPerMonth,
		public float $monthlyCost,
		public string $priceSource = '',
		public string $priceChecked = '',
	) {
	}
}
