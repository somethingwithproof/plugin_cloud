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
 * One series to read: a stored resource and a metric from the driver's
 * catalog.
 */
final readonly class MetricRequest {
	/**
	 * Construct an immutable metric collection request for one resource series.
	 *
	 * @param int    $resourceId   internal plugin_cloud_resource ID
	 * @param string $resourceType provider resource type
	 * @param string $providerId   provider-scoped resource identifier
	 * @param string $metric       catalog metric name
	 */
	public function __construct(
		public int $resourceId,
		public string $resourceType,
		public string $providerId,
		public string $metric,
	) {
	}
}
