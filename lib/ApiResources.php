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
 * Exposes strictly read-only resource collections (cloudAccounts, cloudResources,
 * cloudHealth) using plain PHP arrays with zero cross-plugin imports and zero secrets.
 */
final class ApiResources {
	/**
	 * AIP collection identifier for Cloud accounts.
	 */
	public const COLLECTION_ACCOUNTS = 'cloudAccounts';

	/**
	 * AIP collection identifier for discovered Cloud resources.
	 */
	public const COLLECTION_RESOURCES = 'cloudResources';

	/**
	 * AIP collection identifier for Cloud collector health snapshots.
	 */
	public const COLLECTION_HEALTH = 'cloudHealth';

	/**
	 * Build the AIP resource provider backed by Cloud repositories.
	 *
	 * @param \Closure(int): bool          $canView         checks Cloud View realm permission for $userId
	 * @param \Closure(int): list<Account> $visibleAccounts returns accounts visible to $userId
	 * @param ResourceRepository           $resources       discovered resource store
	 * @param HealthRepository             $health          per-account collector health store
	 * @param Clock                        $clock           clock for snapshot timestamps
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
	 * Return plain-array Google AIP resource descriptors as a canonical list.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function descriptors(): array {
		return [
			[
				'plugin'        => 'cloud',
				'collection'    => self::COLLECTION_ACCOUNTS,
				'singleton'     => 'cloudAccount',
				'description'   => 'Read-only Cloud account inventory and polling configuration (no credentials).',
				'realm_read'    => 'cloud_accounts.php',
				'filter_fields' => ['id', 'name', 'display_name', 'provider', 'poller_id', 'admin_state', 'verify_status'],
				'order_fields'  => ['id', 'name', 'display_name', 'provider', 'poller_id'],
				'handlers'      => [
					'list' => fn (array $context = []): array => $this->listAccounts($context),
					'get'  => fn (int|string $id, array $context = []): ?array => $this->getAccount($id, $context),
				],
			],
			[
				'plugin'        => 'cloud',
				'collection'    => self::COLLECTION_RESOURCES,
				'singleton'     => 'cloudResource',
				'description'   => 'Read-only discovered Cloud resources across visible accounts.',
				'realm_read'    => 'cloud_accounts.php',
				'filter_fields' => ['id', 'name', 'account_id', 'provider', 'type', 'region', 'lifecycle'],
				'order_fields'  => ['id', 'name', 'account_id', 'type', 'last_seen'],
				'handlers'      => [
					'list' => fn (array $context = []): array => $this->listResources($context),
					'get'  => fn (int|string $id, array $context = []): ?array => $this->getResource($id, $context),
				],
			],
			[
				'plugin'        => 'cloud',
				'collection'    => self::COLLECTION_HEALTH,
				'singleton'     => 'cloudHealth',
				'description'   => 'Read-only collector and discovery health snapshots for Cloud accounts.',
				'realm_read'    => 'cloud_accounts.php',
				'filter_fields' => ['id', 'name', 'account_id', 'provider', 'collection', 'discovery', 'error_class'],
				'order_fields'  => ['id', 'name', 'account_id', 'run_started'],
				'handlers'      => [
					'list' => fn (array $context = []): array => $this->listHealth($context),
					'get'  => fn (int|string $id, array $context = []): ?array => $this->getHealth($id, $context),
				],
			],
		];
	}

	/**
	 * List redacted Cloud accounts visible to the caller.
	 *
	 * @param array<string, mixed> $context request context (user_id, query)
	 * @return list<array<string, mixed>>
	 */
	public function listAccounts(array $context = []): array {
		$userId = Row::int($context, 'user_id', 0, 0);

		if (!($this->canView)($userId)) {
			return [];
		}

		$query          = is_array($context['query'] ?? null) ? $context['query'] : $context;
		$providerFilter = is_string($query['provider'] ?? null) ? trim($query['provider']) : '';
		$pollerFilter   = isset($query['poller_id']) && is_numeric($query['poller_id']) ? (int) $query['poller_id'] : null;
		$items          = [];

		foreach (($this->visibleAccounts)($userId) as $account) {
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
	 * Retrieve one redacted Cloud account by ID.
	 *
	 * @param int|string           $id      account identifier
	 * @param array<string, mixed> $context request context (user_id)
	 * @return array<string, mixed>|null
	 */
	public function getAccount(int|string $id, array $context = []): ?array {
		$userId = Row::int($context, 'user_id', 0, 0);

		if (!($this->canView)($userId) || !is_numeric($id) || (int) $id <= 0) {
			return null;
		}

		$targetId = (int) $id;

		foreach (($this->visibleAccounts)($userId) as $account) {
			if ($account->id === $targetId) {
				return $this->formatAccount($account);
			}
		}

		return null;
	}

	/**
	 * List discovered Cloud resources visible to the caller.
	 *
	 * @param array<string, mixed> $context request context (user_id, query)
	 * @return list<array<string, mixed>>
	 */
	public function listResources(array $context = []): array {
		$userId = Row::int($context, 'user_id', 0, 0);

		if (!($this->canView)($userId)) {
			return [];
		}

		$query         = is_array($context['query'] ?? null) ? $context['query'] : $context;
		$accountFilter = $this->intParam($query, 'account_id', 'account');
		$typeFilter    = is_string($query['type'] ?? null) && trim($query['type']) !== '' ? trim($query['type']) : null;
		$lifeFilter    = is_string($query['lifecycle'] ?? null) && trim($query['lifecycle']) !== ''
			? trim($query['lifecycle'])
			: null;
		$items         = [];

		foreach (($this->visibleAccounts)($userId) as $account) {
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
	 * Retrieve one discovered Cloud resource by ID.
	 *
	 * @param int|string           $id      resource identifier
	 * @param array<string, mixed> $context request context (user_id)
	 * @return array<string, mixed>|null
	 */
	public function getResource(int|string $id, array $context = []): ?array {
		$userId = Row::int($context, 'user_id', 0, 0);

		if (!($this->canView)($userId) || !is_numeric($id) || (int) $id <= 0) {
			return null;
		}

		$targetId = (int) $id;

		foreach ($this->listResources($context) as $item) {
			if ($item['id'] === $targetId) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * List collector health snapshots for visible Cloud accounts.
	 *
	 * @param array<string, mixed> $context request context (user_id, query)
	 * @return list<array<string, mixed>>
	 */
	public function listHealth(array $context = []): array {
		$userId = Row::int($context, 'user_id', 0, 0);

		if (!($this->canView)($userId)) {
			return [];
		}

		$query         = is_array($context['query'] ?? null) ? $context['query'] : $context;
		$accountFilter = $this->intParam($query, 'account_id', 'account');
		$checkedAt     = $this->clock->now();
		$items         = [];

		foreach (($this->visibleAccounts)($userId) as $account) {
			if ($accountFilter !== null && $account->id !== $accountFilter) {
				continue;
			}

			$items[] = $this->formatHealth($account, $this->health->find($account->id), $checkedAt);
		}

		return $items;
	}

	/**
	 * Retrieve collector health for one Cloud account by ID.
	 *
	 * @param int|string           $id      account identifier
	 * @param array<string, mixed> $context request context (user_id)
	 * @return array<string, mixed>|null
	 */
	public function getHealth(int|string $id, array $context = []): ?array {
		$userId = Row::int($context, 'user_id', 0, 0);

		if (!($this->canView)($userId) || !is_numeric($id) || (int) $id <= 0) {
			return null;
		}

		$targetId = (int) $id;

		foreach (($this->visibleAccounts)($userId) as $account) {
			if ($account->id === $targetId) {
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
			'name'              => self::COLLECTION_ACCOUNTS . '/' . $account->id,
			'id'                => $account->id,
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
			'name'         => self::COLLECTION_RESOURCES . '/' . $id,
			'id'           => $id,
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
			'name'          => self::COLLECTION_HEALTH . '/' . $account->id,
			'id'            => $account->id,
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
	 * @param array<array-key, mixed> $params   request parameter map
	 * @param string                  $primary  primary parameter key
	 * @param string                  $fallback secondary parameter key
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
