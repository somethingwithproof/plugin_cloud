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
 * One cloud provider account (REQ-ACCT-001). Holds no secret: a vault
 * credential lives in plugin_cloud_secret, a file credential on the poller.
 */
final readonly class Account {
	/**
	 * Identifier of Cacti's default main poller.
	 */
	public const MAIN_POLLER = 1;

	/**
	 * @param array<string, string> $scope validated scope fields, see Provider::scopeFields()
	 */
	public function __construct(
		public int $id,
		public Provider $provider,
		public string $name,
		public int $pollerId,
		public array $scope,
		public CredentialSource $credentialSource,
		public string $credentialFile,
		public int $collectInterval,
		public int $discoverInterval,
		public int $requestShare,
		public ?float $monthlyCeiling,
		public AdminState $adminState,
		public VerifyStatus $verifyStatus = VerifyStatus::Never,
		public int $verifiedAt = 0,
	) {
	}

	/**
	 * A row of plugin_cloud_account, or null when the row cannot be an account:
	 * no id, or a provider or credential source this code does not know.
	 * Every other loose value falls back to its documented default, pinned by
	 * tests/Unit/AccountTest.php.
	 *
	 * @param array<array-key, mixed> $row
	 */
	public static function fromRow(array $row): ?self {
		$id       = Row::int($row, 'id', 0, 1);
		$provider = Provider::tryFrom(Row::string($row, 'provider'));
		$source   = CredentialSource::tryFrom(Row::string($row, 'credential_source'));

		if ($id === 0 || $provider === null || $source === null) {
			return null;
		}

		$ceiling = Row::float($row, 'monthly_ceiling');

		return new self(
			id: $id,
			provider: $provider,
			name: Row::string($row, 'name'),
			pollerId: Row::int($row, 'poller_id', self::MAIN_POLLER, 1),
			scope: self::decodeScope(Row::string($row, 'scope'), $provider),
			credentialSource: $source,
			credentialFile: Row::string($row, 'credential_file'),
			collectInterval: Row::int($row, 'collect_interval', AccountInput::DEFAULT_COLLECT, AccountInput::MIN_COLLECT),
			discoverInterval: Row::int($row, 'discover_interval', AccountInput::DEFAULT_DISCOVER, AccountInput::MIN_DISCOVER),
			requestShare: min(Row::int($row, 'request_share', AccountInput::DEFAULT_SHARE, 1), 100),
			monthlyCeiling: $ceiling !== null && $ceiling >= 0 ? $ceiling : null,
			adminState: AdminState::tryFrom(Row::string($row, 'admin_state')) ?? AdminState::Disabled,
			verifyStatus: VerifyStatus::tryFrom(Row::string($row, 'verify_status')) ?? VerifyStatus::Never,
			verifiedAt: Row::int($row, 'verified_at', 0, 0),
		);
	}

	/**
	 * Only the provider's own scope fields survive, and only values that still
	 * match their pattern, so a hand-edited row cannot put text into a URL.
	 *
	 * @return array<string, string>
	 */
	private static function decodeScope(string $json, Provider $provider): array {
		$decoded = json_decode($json, true, 4);
		$scope   = [];

		if (!is_array($decoded)) {
			return [];
		}

		foreach ($provider->scopeFields() as $field => $rule) {
			$value = $decoded[$field] ?? null;

			if (is_string($value) && AccountInput::scopeValueValid($value, $rule)) {
				$scope[$field] = $value;
			}
		}

		return $scope;
	}

	/**
	 * Column values for plugin_cloud_account, without id and timestamps.
	 *
	 * @return array<string, string|int|float|null>
	 */
	public function toRow(): array {
		return [
			'provider'          => $this->provider->value,
			'name'              => $this->name,
			'poller_id'         => $this->pollerId,
			'scope'             => (string) json_encode((object) $this->scope),
			'credential_source' => $this->credentialSource->value,
			'credential_file'   => $this->credentialFile,
			'collect_interval'  => $this->collectInterval,
			'discover_interval' => $this->discoverInterval,
			'request_share'     => $this->requestShare,
			'monthly_ceiling'   => $this->monthlyCeiling,
			'admin_state'       => $this->adminState->value,
		];
	}

	/**
	 * Names of the columns that differ from $before, for the audit log.
	 *
	 * @return list<string>
	 */
	public function changedFields(?self $before): array {
		$now  = $this->toRow();
		$then = $before?->toRow() ?? [];

		return array_keys(array_filter(
			$now,
			static fn ($value, $key) => !array_key_exists($key, $then) || $then[$key] !== $value,
			ARRAY_FILTER_USE_BOTH
		));
	}

	/**
	 * Report whether this account is administratively enabled for collection.
	 *
	 * @return bool true when adminState is Enabled
	 */
	public function enabled(): bool {
		return $this->adminState === AdminState::Enabled;
	}
}
