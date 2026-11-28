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
 * Structured provider driver error carrying classification and optional Retry-After.
 */
final class DriverError extends \RuntimeException {
	/**
	 * Construct a DriverError with classification, optional reason, and retry delay.
	 *
	 * @param ErrorClass $errorClass normalized error category
	 * @param string     $reason     optional redacted error detail
	 * @param int        $retryAfter seconds the provider asked the plugin to wait, 0 when it did not
	 */
	public function __construct(
		public readonly ErrorClass $errorClass,
		string $reason = '',
		public readonly int $retryAfter = 0
	) {
		parent::__construct($errorClass->value . ($reason !== '' ? ': ' . $reason : ''));
	}

	/**
	 * Convert a low-level TransportError into a classified DriverError.
	 *
	 * @param TransportError $error transport failure
	 * @return self
	 */
	public static function fromTransport(TransportError $error): self {
		return new self($error->errorClass(), $error->kind);
	}
}
