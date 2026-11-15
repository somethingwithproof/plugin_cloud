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
 * A resource as discovery reports it. Provider strings are untrusted and
 * escaped wherever they are printed. The type and provider id are its
 * identity, so a value that does not fit its column, or holds control
 * characters, is refused rather than altered: two resources must never be
 * made to share one row. Display fields are trimmed to fit.
 */
final readonly class CloudResource {
	public string $type;
	public string $providerId;
	public string $name;
	public string $region;

	public function __construct(string $type, string $providerId, string $name, string $region = '') {
		$this->type       = self::identity($type, 64, 'type');
		$this->providerId = self::identity($providerId, 255, 'provider id');
		$this->name       = self::bounded($name, 255);
		$this->region     = self::bounded($region, 64);
	}

	/**
	 * @throws DriverError
	 */
	private static function identity(string $value, int $length, string $what): string {
		if ($value === '' || mb_strlen($value) > $length || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
			throw new DriverError(ErrorClass::Malformed, 'resource ' . $what . ' is empty, longer than ' . $length . ' characters, or holds control characters');
		}

		return $value;
	}

	private static function bounded(string $value, int $length): string {
		return mb_substr((string) preg_replace('/[\x00-\x1f\x7f]/', '', $value), 0, $length);
	}
}
