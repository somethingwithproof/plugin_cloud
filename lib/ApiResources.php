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
 * Decoupled Google AIP resource descriptors registered via api_register_resources.
 *
 * Exposes strictly read-only resource metadata and callbacks for
 * cloud.cacti.net/Account, cloud.cacti.net/Resource, and cloud.cacti.net/Health
 * using plain PHP arrays with zero cross-plugin imports and zero secrets.
 */
final class ApiResources {
	/**
	 * Google AIP type name for Cloud accounts.
	 */
	public const TYPE_ACCOUNT = 'cloud.cacti.net/Account';

	/**
	 * Google AIP type name for discovered Cloud resources.
	 */
	public const TYPE_RESOURCE = 'cloud.cacti.net/Resource';

	/**
	 * Google AIP type name for Cloud collector health snapshots.
	 */
	public const TYPE_HEALTH = 'cloud.cacti.net/Health';

	/**
	 * Build the AIP resource provider backed by Cloud repositories.
	 *
	 * @param \Closure(): bool          $canView         checks Cloud View realm permission
	 * @param \Closure(): list<Account> $visibleAccounts returns accounts visible to the caller
	 * @param ResourceRepository        $resources       discovered resource store
	 * @param HealthRepository          $health          per-account collector health store
	 * @param Clock                     $clock           clock for snapshot timestamps
	 */
	public function __construct(
		private readonly \Closure $canView,
		private readonly \Closure $visibleAccounts,
		private readonly ResourceRepository $resources,
		private readonly HealthRepository $health,
		private readonly Clock $clock,
	) {
	}

	/**
	 * Return plain-array Google AIP resource descriptors keyed by type name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function descriptors(): array {
		$permission = fn (): bool => ($this->canView)();

		return [
			self::TYPE_ACCOUNT => [
				'type'                => self::TYPE_ACCOUNT,
				'plugin'              => 'cloud',
				'pattern'             => 'accounts/{account}',
				'singular'            => 'account',
				'plural'              => 'accounts',
				'read_only'           => true,
				'operations'          => ['list', 'get'],
				'permission_callback' => $permission,
				'fields'              => [
					'name'              => ['type' => 'string', 'description' => 'AIP resource name (accounts/{account}).'],
					'account_id'        => ['type' => 'integer'],
					'display_name'      => ['type' => 'string'],
					'provider'          => ['type' => 'string'],
					'poller_id'         => ['type' => 'integer'],
					'admin_state'       => ['type' => 'string'],
					'credential_source' => ['type' => 'string'],
					'collect_interval'  => ['type' => 'integer'],
					'discover_interval' => ['type' => 'integer'],
					'request_share'     => ['type' => 'integer'],
					'monthly_ceiling'   => ['type' => 'number', 'nullable' => true],
					'verify_status'     => ['type' => 'string'],
					'verified_at'       => ['type' => 'integer'],
				],
				'callbacks'           => [
					'list' => fn (array $params = []): array => $this->listAccounts($params),
					'get'  => fn (array $params = []): ?array => $this->getAccount($params),
				],
			],
			self::TYPE_RESOURCE => [
				'type'                => self::TYPE_RESOURCE,
				'plugin'              => 'cloud',
				'pattern'             => 'accounts/{account}/resources/{resource}',
				'singular'            => 'resource',
				'plural'              => 'resources',
				'read_only'           => true,
				'operations'          => ['list', 'get'],
				'permission_callback' => $permission,
				'fields'              => [
					'name'         => ['type' => 'string', 'description' => 'AIP resource name (accounts/{account}/resources/{resource}).'],
					'resource_id'  => ['type' => 'integer'],
					'account_id'   => ['type' => 'integer'],
					'account_name' => ['type' => 'string'],
					'provider'     => ['type' => 'string'],
					'type'         => ['type' => 'string'],
					'provider_id'  => ['type' => 'string'],
					'display_name' => ['type' => 'string'],
					'region'       => ['type' => 'string'],
					'lifecycle'    => ['type' => 'string'],
					'first_seen'   => ['type' => 'integer'],
					'last_seen'    => ['type' => 'integer'],
				],
				'callbacks'           => [
					'list' => fn (array $params = []): array => $this->listResources($params),
					'get'  => fn (array $params = []): ?array => $this->getResource($params),
				],
			],
			self::TYPE_HEALTH => [
				'type'                => self::TYPE_HEALTH,
				'plugin'              => 'cloud',
				'pattern'             => 'accounts/{account}/health',
				'singular'            => 'health',
				'plural'              => 'health',
				'read_only'           => true,
				'operations'          => ['list', 'get'],
				'permission_callback' => $permission,
				'fields'              => [
					'name'          => ['type' => 'string', 'description' => 'AIP resource name (accounts/{account}/health).'],
					'account_id'    => ['type' => 'integer'],
					'account_name'  => ['type' => 'string'],
					'provider'      => ['type' => 'string'],
					'poller_id'     => ['type' => 'integer'],
					'enabled'       => ['type' => 'boolean'],
					'collection'    => ['type' => 'string'],
					'discovery'     => ['type' => 'string'],
					'error_class'   => ['type' => 'string'],
					'run_started'   => ['type' => 'integer'],
					'run_finished'  => ['type' => 'integer'],
					'discovered_at' => ['type' => 'integer'],
					'retry_at'      => ['type' => 'integer'],
					'requests'      => ['type' => 'integer'],
					'series_read'   => ['type' => 'integer'],
					'series_total'  => ['type' => 'integer'],
					'checked_at'    => ['type' => 'integer'],
				],
				'callbacks'           => [
					'list' => fn (array $params = []): array => $this->listHealth($params),
					'get'  => fn (array $params = []): ?array => $this->getHealth($params),
				],
			],
		];
	}

	/**
	 * List redacted Cloud accounts visible to the caller.
	 *
	 * @param array<string, mixed> $params optional filters: provider, poller_id
	 * @return list<array<string, mixed>>
	 */
	public function listAccounts(array $params = []): array {
		if (!($this->canView)()) {
			return [];
		}

		$providerFilter = is_string($params['provider'] ?? null) ? trim($params['provider']) : '';
		$pollerFilter   = isset($params['poller_id']) && is_numeric($params['poller_id']) ? (int) $params['poller_id'] : null;
		$items          = [];

		foreach (($this->visibleAccounts)() as $account) {
			if ($providerFilter !== '' && $account->provider->value !== $providerFilter) {
				continue;
			}

			if ($pollerFilter !== null && $account->pollerId !== $pollerFilter) {
				continue;
			}

			$items[] = $this->formatAccount($account);
		}

		return $items;
	}

	/**
	 * Retrieve one redacted Cloud account by account_id.
	 *
	 * @param array<string, mixed> $params parameters containing account or account_id
	 * @return array<string, mixed>|null
	 */
	public function getAccount(array $params = []): ?array {
		if (!($this->canView)()) {
			return null;
		}

		$id = $this->intParam($params, 'account_id', 'account');

		if ($id === null) {
			return null;
		}

		foreach (($this->visibleAccounts)() as $account) {
			if ($account->id === $id) {
				return $this->formatAccount($account);
			}
		}

		return null;
	}

	/**
	 * List discovered Cloud resources visible to the caller.
	 *
	 * @param array<string, mixed> $params optional filters: account_id/account, type, lifecycle
	 * @return list<array<string, mixed>>
	 */
	public function listResources(array $params = []): array {
		if (!($this->canView)()) {
			return [];
		}

		$accountFilter = $this->intParam($params, 'account_id', 'account');
		$typeFilter    = is_string($params['type'] ?? null) && trim($params['type']) !== '' ? trim($params['type']) : null;
		$lifeFilter    = is_string($params['lifecycle'] ?? null) && trim($params['lifecycle']) !== ''
			? trim($params['lifecycle'])
			: null;
		$items         = [];

		foreach (($this->visibleAccounts)() as $account) {
			if ($accountFilter !== null && $account->id !== $accountFilter) {
				continue;
			}

			foreach ($this->resources->forAccount($account->id, $typeFilter, $lifeFilter) as $resource) {
				$items[] = $this->formatResource($account, $resource);
			}
		}

		return $items;
	}

	/**
	 * Retrieve one discovered Cloud resource by account_id and resource_id.
	 *
	 * @param array<string, mixed> $params parameters containing account_id/account and resource_id/resource
	 * @return array<string, mixed>|null
	 */
	public function getResource(array $params = []): ?array {
		if (!($this->canView)()) {
			return null;
		}

		$resourceId = $this->intParam($params, 'resource_id', 'resource');

		if ($resourceId === null) {
			return null;
		}

		foreach ($this->listResources($params) as $item) {
			if ($item['resource_id'] === $resourceId) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * List collector health snapshots for visible Cloud accounts.
	 *
	 * @param array<string, mixed> $params optional account_id/account filter
	 * @return list<array<string, mixed>>
	 */
	public function listHealth(array $params = []): array {
		if (!($this->canView)()) {
			return [];
		}

		$accountFilter = $this->intParam($params, 'account_id', 'account');
		$checkedAt     = $this->clock->now();
		$items         = [];

		foreach (($this->visibleAccounts)() as $account) {
			if ($accountFilter !== null && $account->id !== $accountFilter) {
				continue;
			}

			$items[] = $this->formatHealth($account, $this->health->find($account->id), $checkedAt);
		}

		return $items;
	}

	/**
	 * Retrieve collector health for one Cloud account by account_id.
	 *
	 * @param array<string, mixed> $params parameters containing account_id or account
	 * @return array<string, mixed>|null
	 */
	public function getHealth(array $params = []): ?array {
		if (!($this->canView)()) {
			return null;
		}

		$accountId = $this->intParam($params, 'account_id', 'account');

		if ($accountId === null) {
			return null;
		}

		foreach (($this->visibleAccounts)() as $account) {
			if ($account->id === $accountId) {
				return $this->formatHealth($account, $this->health->find($account->id), $this->clock->now());
			}
		}

		return null;
	}

	/**
	 * Format an Account into a redacted AIP Account representation.
	 *
	 * @param Account $account domain account entity
	 * @return array<string, mixed>
	 */
	private function formatAccount(Account $account): array {
		return [
			'name'              => 'accounts/' . $account->id,
			'account_id'        => $account->id,
			'display_name'      => $account->name,
			'provider'          => $account->provider->value,
			'poller_id'         => $account->pollerId,
			'admin_state'       => $account->adminState->value,
			'credential_source' => $account->credentialSource->value,
			'collect_interval'  => $account->collectInterval,
			'discover_interval' => $account->discoverInterval,
			'request_share'     => $account->requestShare,
			'monthly_ceiling'   => $account->monthlyCeiling,
			'verify_status'     => $account->verifyStatus->value,
			'verified_at'       => $account->verifiedAt,
		];
	}

	/**
	 * Format a resource repository row into an AIP Resource representation.
	 *
	 * @param Account              $account  owning account
	 * @param array<string, mixed> $resource resource row from ResourceRepository::forAccount()
	 * @return array<string, mixed>
	 */
	private function formatResource(Account $account, array $resource): array {
		$id = Row::int($resource, 'id', 0, 0);

		return [
			'name'         => 'accounts/' . $account->id . '/resources/' . $id,
			'resource_id'  => $id,
			'account_id'   => $account->id,
			'account_name' => $account->name,
			'provider'     => $account->provider->value,
			'type'         => Row::string($resource, 'type'),
			'provider_id'  => Row::string($resource, 'provider_id'),
			'display_name' => Row::string($resource, 'name'),
			'region'       => Row::string($resource, 'region'),
			'lifecycle'    => Row::string($resource, 'lifecycle'),
			'first_seen'   => Row::int($resource, 'first_seen', 0, 0),
			'last_seen'    => Row::int($resource, 'last_seen', 0, 0),
		];
	}

	/**
	 * Format an AccountHealth record into an AIP Health representation.
	 *
	 * @param Account       $account   owning account
	 * @param AccountHealth $health    collector health snapshot
	 * @param int           $checkedAt Unix timestamp of the read
	 * @return array<string, mixed>
	 */
	private function formatHealth(Account $account, AccountHealth $health, int $checkedAt): array {
		return [
			'name'          => 'accounts/' . $account->id . '/health',
			'account_id'    => $account->id,
			'account_name'  => $account->name,
			'provider'      => $account->provider->value,
			'poller_id'     => $account->pollerId,
			'enabled'       => $account->enabled(),
			'collection'    => $health->collection->value,
			'discovery'     => $health->discovery->value,
			'error_class'   => $health->error->value ?? '',
			'run_started'   => $health->runStarted,
			'run_finished'  => $health->runFinished,
			'discovered_at' => $health->discoveredAt,
			'retry_at'      => $health->retryAt,
			'requests'      => $health->requests,
			'series_read'   => $health->seriesRead,
			'series_total'  => $health->seriesTotal,
			'checked_at'    => $checkedAt,
		];
	}

	/**
	 * Parse a positive integer parameter from primary or fallback keys.
	 *
	 * @param array<string, mixed> $params   request parameter map
	 * @param string               $primary  primary parameter key
	 * @param string               $fallback secondary parameter key
	 * @return int|null positive integer when present and valid, null otherwise
	 */
	private function intParam(array $params, string $primary, string $fallback): ?int {
		foreach ([$primary, $fallback] as $key) {
			if (isset($params[$key]) && is_numeric($params[$key]) && (int) $params[$key] > 0) {
				return (int) $params[$key];
			}
		}

		return null;
	}
}
