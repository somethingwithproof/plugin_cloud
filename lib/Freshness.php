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
 * Freshness classification for a cached cloud datapoint.
 */
enum Freshness: string {
	case Current   = 'current';
	case Delayed   = 'delayed';
	case Stale     = 'stale';
	case Never     = 'never';
	case ClockSkew = 'clock_skew';

	/**
	 * Only a current or delayed value is served; everything else reads as
	 * unknown, never zero (REQ-STATE-004).
	 */
	public function usable(): bool {
		return $this === self::Current || $this === self::Delayed;
	}
}
