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
 * The recorded result of Verify. Each status has one fixed message, so the
 * account page never shows provider error text (REQ-ACCT-004).
 */
enum VerifyStatus: string {
	case Never       = 'never';
	case Ok          = 'ok';
	case Invalid     = 'invalid';
	case Permission  = 'permission';
	case Unreachable = 'unreachable';
	case Throttled   = 'throttled';
	case Malformed   = 'malformed';
	case Config      = 'config';

	public static function fromError(ErrorClass $error): self {
		return match ($error) {
			ErrorClass::Auth       => self::Invalid,
			ErrorClass::Permission => self::Permission,
			ErrorClass::Throttled  => self::Throttled,
			ErrorClass::Transient  => self::Unreachable,
			ErrorClass::Malformed  => self::Malformed,
			ErrorClass::Config     => self::Config,
		};
	}

	public function message(): string {
		return match ($this) {
			self::Never       => __('Not verified yet.', 'cloud'),
			self::Ok          => __('The credential works and has the permissions this account needs.', 'cloud'),
			self::Invalid     => __('The provider rejected the credential. It may be wrong, expired, or revoked.', 'cloud'),
			self::Permission  => __('The credential works but lacks a permission this account needs.', 'cloud'),
			self::Unreachable => __('The provider could not be reached. Check the network path and outbound policy from the poller.', 'cloud'),
			self::Throttled   => __('The provider is throttling requests. Try again later.', 'cloud'),
			self::Malformed   => __('The provider sent a response the plugin could not read.', 'cloud'),
			self::Config      => __('The account or its credential is not configured correctly.', 'cloud'),
		};
	}
}
