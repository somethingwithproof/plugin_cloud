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
 * Cloud providers. Values are stored in plugin_cloud_account.provider.
 *
 * A provider is offered for new accounts only once its release phase has
 * shipped (ADR 0008); stored rows of other providers still load, so a
 * downgrade never loses an account.
 */
enum Provider: string {
	case DigitalOcean = 'digitalocean';
	case Aws          = 'aws';
	case GoogleCloud  = 'gcp';
	case Azure        = 'azure';

	/**
	 * @return list<self>
	 */
	public static function released(): array {
		return [self::DigitalOcean, self::Aws, self::GoogleCloud, self::Azure];
	}

	public function label(): string {
		return match ($this) {
			self::DigitalOcean => __('DigitalOcean', 'cloud'),
			self::Aws          => __('Amazon Web Services', 'cloud'),
			self::GoogleCloud  => __('Google Cloud', 'cloud'),
			self::Azure        => __('Microsoft Azure', 'cloud'),
		};
	}

	/**
	 * Scope fields this provider's URLs are built from, each with the strict
	 * pattern a value must match before it is stored or used (REQ-ACCT-002).
	 * A list field holds comma-separated values that each match the pattern.
	 *
	 * DigitalOcean tokens are scoped to one team by DigitalOcean itself, so its
	 * URLs carry no account scope.
	 *
	 * @return array<string, array{pattern: string, list: bool}>
	 */
	public function scopeFields(): array {
		$uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

		return match ($this) {
			self::DigitalOcean => [],
			self::Aws          => [
				'account_id' => ['pattern' => '/^\d{12}$/', 'list' => false],
				'regions'    => ['pattern' => '/^[a-z]{2}(-gov|-iso[a-z]?)?-[a-z]+-\d{1,2}$/', 'list' => true],
			],
			self::GoogleCloud  => [
				'project' => ['pattern' => '/^[a-z][a-z0-9-]{4,28}[a-z0-9]$/', 'list' => false],
			],
			self::Azure        => [
				'tenant'       => ['pattern' => $uuid, 'list' => false],
				'subscription' => ['pattern' => $uuid, 'list' => false],
				'regions'      => ['pattern' => '/^[a-z][a-z0-9]{1,30}$/', 'list' => true],
			],
		};
	}

	/**
	 * @return list<CredentialSource>
	 */
	public function credentialSources(): array {
		return match ($this) {
			self::DigitalOcean => [CredentialSource::Vault, CredentialSource::File],
			default            => [CredentialSource::Vault, CredentialSource::File, CredentialSource::Instance],
		};
	}
}
