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
 * A clock that stays where it is put, for tests and for replaying a run.
 */
final class FixedClock implements Clock {
	/**
	 * Initialize the fixed clock at a specific Unix timestamp.
	 *
	 * @param int $time initial Unix epoch seconds
	 */
	public function __construct(private int $time) {
	}

	/**
	 * Return the current simulated Unix timestamp.
	 *
	 * @return int Unix epoch seconds
	 */
	public function now(): int {
		return $this->time;
	}

	/**
	 * Move the simulated clock forward (or backward) by the given number of seconds.
	 *
	 * @param int $seconds delta in seconds
	 * @return void
	 */
	public function advance(int $seconds): void {
		$this->time += $seconds;
	}
}
