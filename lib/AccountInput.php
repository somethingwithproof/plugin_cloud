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
 * Validation of the account form. The result is either an Account or a
 * message per field, each naming the field it is about.
 */
final readonly class AccountInput {
	/**
	 * Minimum allowed collection interval in seconds.
	 */
	public const MIN_COLLECT      = 60;

	/**
	 * Default collection interval in seconds.
	 */
	public const DEFAULT_COLLECT  = 300;

	/**
	 * Minimum allowed resource discovery interval in seconds.
	 */
	public const MIN_DISCOVER     = 300;

	/**
	 * Default resource discovery interval in seconds.
	 */
	public const DEFAULT_DISCOVER = 3600;

	/**
	 * Default percentage share of a provider's token rate limit.
	 */
	public const DEFAULT_SHARE    = 50;

	/**
	 * Maximum allowed collection or discovery interval in seconds.
	 */
	public const MAX_INTERVAL     = 86400;

	/**
	 * @param array<string, string> $errors field => message
	 */
	private function __construct(public ?Account $account, public array $errors) {
	}

	/**
	 * @param array<string, mixed> $input          form fields as posted
	 * @param list<Provider>       $offered        providers new accounts may use
	 * @param list<int>|null       $allowedPollers optional allowlist of enabled poller IDs; null allows any poller ID >= 1
	 */
	public static function parse(array $input, int $id, array $offered, ?array $allowedPollers = null): self {
		$errors   = [];
		$provider = Provider::tryFrom(Row::string($input, 'provider'));

		if ($provider === null || ($id <= 0 && !in_array($provider, $offered, true))) {
			return new self(null, ['provider' => __('Provider: choose one of the listed providers.', 'cloud')]);
		}

		$name = Row::string($input, 'name');

		if ($name === '' || mb_strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
			$errors['name'] = __('Name: enter 1 to 100 characters without control characters.', 'cloud');
		}

		$poller = Row::int($input, 'poller_id', 0);

		if ($allowedPollers === [Account::MAIN_POLLER] && $poller !== Account::MAIN_POLLER) {
			$errors['poller_id'] = __('Poller: only the main poller can collect cloud accounts in this release.', 'cloud');
		} elseif ($poller < 1 || ($allowedPollers !== null && !in_array($poller, $allowedPollers, true))) {
			$errors['poller_id'] = 'Poller: choose an enabled poller ID.';
		}

		$scope = [];

		foreach ($provider->scopeFields() as $field => $rule) {
			$value = strtolower(str_replace(' ', '', Row::string($input, 'scope_' . $field)));

			if (!self::scopeValueValid($value, $rule)) {
				$errors['scope_' . $field] = __(
					'%s: the value does not have the form this provider uses.',
					self::scopeLabel($field),
					'cloud'
				);
			} else {
				$scope[$field] = $value;
			}
		}

		$source = CredentialSource::tryFrom(Row::string($input, 'credential_source'));

		if ($source === null || !in_array($source, $provider->credentialSources(), true)) {
			$errors['credential_source'] = __('Credential Source: choose one this provider supports.', 'cloud');
		}

		$file = Row::string($input, 'credential_file');

		if ($source === CredentialSource::File && !CredentialFile::pathAcceptable($file)) {
			$errors['credential_file'] = __(
				'Credential File: enter an absolute path without control characters, at most 512 characters.',
				'cloud'
			);
		}

		$collect  = Row::int($input, 'collect_interval', 0);
		$discover = Row::int($input, 'discover_interval', 0);

		if ($collect < self::MIN_COLLECT || $collect > self::MAX_INTERVAL) {
			$errors['collect_interval'] = __(
				'%s: enter whole seconds from %d to %d.',
				__('Collection Interval', 'cloud'),
				self::MIN_COLLECT,
				self::MAX_INTERVAL,
				'cloud'
			);
		}

		if ($discover < self::MIN_DISCOVER || $discover > self::MAX_INTERVAL) {
			$errors['discover_interval'] = __(
				'%s: enter whole seconds from %d to %d.',
				__('Discovery Interval', 'cloud'),
				self::MIN_DISCOVER,
				self::MAX_INTERVAL,
				'cloud'
			);
		}
		$share    = Row::int($input, 'request_share', 0);

		if ($share < 1 || $share > 100) {
			$errors['request_share'] = __('Request Share: enter a whole percentage from 1 to 100.', 'cloud');
		}

		$ceiling = null;

		if (Row::string($input, 'monthly_ceiling') !== '') {
			$ceiling = Row::float($input, 'monthly_ceiling');

			if ($ceiling === null || $ceiling < 0) {
				$errors['monthly_ceiling'] = __(
					'Monthly Ceiling: enter an amount of zero or more, or leave it blank for none.',
					'cloud'
				);
			}
		}

		if ($errors !== [] || $source === null) {
			return new self(null, $errors);
		}

		return new self(new Account(
			id: $id,
			provider: $provider,
			name: $name,
			pollerId: $poller,
			scope: $scope,
			credentialSource: $source,
			credentialFile: $source === CredentialSource::File ? $file : '',
			collectInterval: $collect,
			discoverInterval: $discover,
			requestShare: $share,
			monthlyCeiling: $ceiling,
			adminState: Row::bool($input, 'enabled') ? AdminState::Enabled : AdminState::Disabled,
		), []);
	}

	/**
	 * @param array{pattern: string, list: bool} $rule
	 */
	public static function scopeValueValid(string $value, array $rule): bool {
		if ($value === '' || strlen($value) > 512) {
			return false;
		}

		foreach ($rule['list'] ? explode(',', $value) : [$value] as $part) {
			if (preg_match($rule['pattern'], $part) !== 1) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Return the translated UI label for a provider scope field key.
	 *
	 * @param string $field scope field key
	 * @return string translated field label
	 */
	public static function scopeLabel(string $field): string {
		return match ($field) {
			'account_id'   => __('AWS Account ID', 'cloud'),
			'regions'      => __('Regions', 'cloud'),
			'project'      => __('Project ID', 'cloud'),
			'tenant'       => __('Tenant ID', 'cloud'),
			'subscription' => __('Subscription ID', 'cloud'),
			default        => $field,
		};
	}
}
