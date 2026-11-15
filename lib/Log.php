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
 * Plugin log lines go through cacti_log() under the CLOUD tag, so they share
 * Cacti's log file, rotation, and verbosity filter. Callers pass messages
 * that hold no secrets; provider error text is reduced to an error class
 * before it reaches here.
 */
final class Log {
	public static function debug(string $message): void {
		cacti_log('DEBUG: ' . $message, false, 'CLOUD', POLLER_VERBOSITY_DEBUG);
	}

	public static function info(string $message): void {
		cacti_log('NOTE: ' . $message, false, 'CLOUD', POLLER_VERBOSITY_MEDIUM);
	}

	public static function warning(string $message): void {
		cacti_log('WARNING: ' . $message, false, 'CLOUD');
	}

	public static function error(string $message): void {
		cacti_log('ERROR: ' . $message, false, 'CLOUD');
	}
}
