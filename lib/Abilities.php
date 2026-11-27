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
 * Strictly read-only MCP/AI abilities registered via abilities_register.
 *
 * Every ability checks Cloud View realm authorization via DB lookup for the
 * explicit user ID, applies Cacti device scoping for non-admin callers, and
 * returns zero credentials, vault keys, or credential file paths.
 */
final class Abilities {
	/**
	 * Ability category slug for Cloud abilities.
	 */
	public const CATEGORY = 'cloud';

	/**
	 * Ability category display label.
	 */
	public const CATEGORY_LABEL = 'Cloud';

	/**
	 * List accounts ability identifier.
	 */
	public const LIST_ACCOUNTS = 'cloud-list-accounts';

	/**
	 * List resources ability identifier.
	 */
	public const LIST_RESOURCES = 'cloud-list-resources';

	/**
	 * Get health ability identifier.
	 */
	public const GET_HEALTH = 'cloud-get-health';

	/**
	 * Required MCP read-only annotations.
	 */
	private const ANNOTATIONS = [
		'readOnly'    => true,
		'destructive' => false,
		'idempotent'  => true,
	];

	/**
	 * Construct the Cloud MCP abilities handler.
	 *
	 * @param \Closure(int): bool          $canView         checks Cloud View realm permission for $userId
	 * @param \Closure(int): list<Account> $visibleAccounts returns accounts $userId may access
	 * @param ResourceRepository           $resources       discovered resource store
	 * @param HealthRepository             $health          per-account collector health store
	 * @param Clock                        $clock           wall clock
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
	 * Build canonical MCP ability definitions keyed by ability ID.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function definitions(): array {
		$permission = fn (int $userId): bool => ($this->canView)($userId);

		return [
			self::LIST_ACCOUNTS => [
				'label'        => 'List Cloud Accounts',
				'description'  => 'List configured cloud accounts visible to the caller without any credential values.',
				'category'     => self::CATEGORY,
				'input_schema' => [
					'type'                 => 'object',
					'properties'           => [
						'provider'  => ['type' => 'string', 'maxLength' => 64],
						'poller_id' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000000],
					],
					'additionalProperties' => false,
				],
				'output_schema' => [
					'type'                 => 'object',
					'properties'           => [
						'accounts' => [
							'type'     => 'array',
							'maxItems' => 500,
							'items'    => [
								'type'                 => 'object',
								'properties'           => [
									'id'                => ['type' => 'integer'],
									'name'              => ['type' => 'string'],
									'provider'          => ['type' => 'string'],
									'poller_id'         => ['type' => 'integer'],
									'admin_state'       => ['type' => 'string'],
									'credential_source' => ['type' => 'string'],
									'collect_interval'  => ['type' => 'integer'],
									'discover_interval' => ['type' => 'integer'],
									'verify_status'     => ['type' => 'string'],
									'verified_at'       => ['type' => 'integer'],
								],
								'required'             => ['id', 'name', 'provider', 'poller_id', 'admin_state', 'credential_source', 'collect_interval', 'discover_interval', 'verify_status', 'verified_at'],
								'additionalProperties' => false,
							],
						],
					],
					'required'             => ['accounts'],
					'additionalProperties' => false,
				],
				'annotations' => self::ANNOTATIONS,
				'permission'  => $permission,
				'execute'     => fn (int $userId, \stdClass|array $args = []): array => $this->listAccounts(is_array($args) ? $args : (array) $args, $userId),
			],
			self::LIST_RESOURCES => [
				'label'        => 'List Cloud Resources',
				'description'  => 'List discovered cloud resources for accounts visible to the caller.',
				'category'     => self::CATEGORY,
				'input_schema' => [
					'type'                 => 'object',
					'properties'           => [
						'account_id' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000000],
						'type'       => ['type' => 'string', 'maxLength' => 64],
						'lifecycle'  => ['type' => 'string', 'maxLength' => 32],
					],
					'additionalProperties' => false,
				],
				'output_schema' => [
					'type'                 => 'object',
					'properties'           => [
						'resources' => [
							'type'     => 'array',
							'maxItems' => 1000,
							'items'    => [
								'type'                 => 'object',
								'properties'           => [
									'id'           => ['type' => 'integer'],
									'account_id'   => ['type' => 'integer'],
									'account_name' => ['type' => 'string'],
									'provider'     => ['type' => 'string'],
									'type'         => ['type' => 'string'],
									'provider_id'  => ['type' => 'string'],
									'name'         => ['type' => 'string'],
									'region'       => ['type' => 'string'],
									'lifecycle'    => ['type' => 'string'],
									'first_seen'   => ['type' => 'integer'],
									'last_seen'    => ['type' => 'integer'],
								],
								'required'             => ['id', 'account_id', 'account_name', 'provider', 'type', 'provider_id', 'name', 'region', 'lifecycle', 'first_seen', 'last_seen'],
								'additionalProperties' => false,
							],
						],
					],
					'required'             => ['resources'],
					'additionalProperties' => false,
				],
				'annotations' => self::ANNOTATIONS,
				'permission'  => $permission,
				'execute'     => fn (int $userId, \stdClass|array $args = []): array => $this->listResources(is_array($args) ? $args : (array) $args, $userId),
			],
			self::GET_HEALTH => [
				'label'        => 'Get Cloud Health',
				'description'  => 'Read collector and discovery health status for visible cloud accounts.',
				'category'     => self::CATEGORY,
				'input_schema' => [
					'type'                 => 'object',
					'properties'           => [
						'account_id' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000000],
					],
					'additionalProperties' => false,
				],
				'output_schema' => [
					'type'                 => 'object',
					'properties'           => [
						'checked_at' => ['type' => 'integer'],
						'accounts'   => [
							'type'     => 'array',
							'maxItems' => 500,
							'items'    => [
								'type'                 => 'object',
								'properties'           => [
									'account_id'    => ['type' => 'integer'],
									'name'          => ['type' => 'string'],
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
									'resources'     => [
										'type'                 => 'object',
										'properties'           => [
											'active'  => ['type' => 'integer'],
											'missing' => ['type' => 'integer'],
											'deleted' => ['type' => 'integer'],
										],
										'required'             => ['active', 'missing', 'deleted'],
										'additionalProperties' => false,
									],
								],
								'required'             => ['account_id', 'name', 'provider', 'poller_id', 'enabled', 'collection', 'discovery', 'error_class', 'run_started', 'run_finished', 'discovered_at', 'retry_at', 'requests', 'series_read', 'series_total', 'resources'],
								'additionalProperties' => false,
							],
						],
					],
					'required'             => ['checked_at', 'accounts'],
					'additionalProperties' => false,
				],
				'annotations' => self::ANNOTATIONS,
				'permission'  => $permission,
				'execute'     => fn (int $userId, \stdClass|array $args = []): array => $this->getHealth(is_array($args) ? $args : (array) $args, $userId),
			],
		];
	}

	/**
	 * Register Cloud ability category and definitions onto an AbilitiesRegistrar object.
	 *
	 * @param object $registrar host registrar providing register() and optional registerCategory()
	 * @return void
	 */
	public function registerOn(object $registrar): void {
		if (!method_exists($registrar, 'register')) {
			return;
		}

		if (method_exists($registrar, 'registerCategory')) {
			try {
				$registrar->registerCategory(self::CATEGORY, self::CATEGORY_LABEL);
			} catch (\Throwable) {
				// Category already registered in the host registry.
			}
		}

		foreach ($this->definitions() as $id => $def) {
			$registrar->register($id, $def);
		}
	}

	/**
	 * List configured cloud accounts visible to $userId.
	 *
	 * @param array<string, mixed> $args
	 * @param int                  $userId
	 * @return array<string, mixed>
	 */
	public function listAccounts(array $args = [], int $userId = 0): array {
		if (!($this->canView)($userId)) {
			return ['accounts' => []];
		}

		$providerFilter = is_string($args['provider'] ?? null) ? trim($args['provider']) : '';
		$pollerFilter   = isset($args['poller_id']) && is_numeric($args['poller_id']) ? (int) $args['poller_id'] : null;
		$out            = [];

		foreach (($this->visibleAccounts)($userId) as $account) {
			if ($providerFilter !== '' && $account->provider->value !== $providerFilter) {
				continue;
			}

			if ($pollerFilter !== null && $account->pollerId !== $pollerFilter) {
				continue;
			}

			$out[] = [
				'id'                => $account->id,
				'name'              => $account->name,
				'provider'          => $account->provider->value,
				'poller_id'         => $account->pollerId,
				'admin_state'       => $account->adminState->value,
				'credential_source' => $account->credentialSource->value,
				'collect_interval'  => $account->collectInterval,
				'discover_interval' => $account->discoverInterval,
				'verify_status'     => $account->verifyStatus->value,
				'verified_at'       => $account->verifiedAt,
			];
		}

		return ['accounts' => array_slice($out, 0, 500)];
	}

	/**
	 * List discovered cloud resources for accounts visible to $userId.
	 *
	 * @param array<string, mixed> $args
	 * @param int                  $userId
	 * @return array<string, mixed>
	 */
	public function listResources(array $args = [], int $userId = 0): array {
		if (!($this->canView)($userId)) {
			return ['resources' => []];
		}

		$accountFilter = isset($args['account_id']) && is_numeric($args['account_id']) ? (int) $args['account_id'] : null;
		$typeFilter    = is_string($args['type'] ?? null) && $args['type'] !== '' ? trim($args['type']) : null;
		$lifeFilter    = is_string($args['lifecycle'] ?? null) && $args['lifecycle'] !== '' ? trim($args['lifecycle']) : null;
		$out           = [];

		foreach (($this->visibleAccounts)($userId) as $account) {
			if ($accountFilter !== null && $account->id !== $accountFilter) {
				continue;
			}

			foreach ($this->resources->forAccount($account->id, $typeFilter, $lifeFilter) as $res) {
				$out[] = $res + ['account_name' => $account->name, 'provider' => $account->provider->value];
			}
		}

		return ['resources' => array_slice($out, 0, 1000)];
	}

	/**
	 * Read collector and discovery health status for accounts visible to $userId.
	 *
	 * @param array<string, mixed> $args
	 * @param int                  $userId
	 * @return array<string, mixed>
	 */
	public function getHealth(array $args = [], int $userId = 0): array {
		if (!($this->canView)($userId)) {
			return ['checked_at' => $this->clock->now(), 'accounts' => []];
		}

		$accountFilter = isset($args['account_id']) && is_numeric($args['account_id']) ? (int) $args['account_id'] : null;
		$out           = [];

		foreach (($this->visibleAccounts)($userId) as $account) {
			if ($accountFilter !== null && $account->id !== $accountFilter) {
				continue;
			}

			$h      = $this->health->find($account->id);
			$counts = $this->resources->counts($account->id);

			$out[] = [
				'account_id'    => $account->id,
				'name'          => $account->name,
				'provider'      => $account->provider->value,
				'poller_id'     => $account->pollerId,
				'enabled'       => $account->enabled(),
				'collection'    => $h->collection->value,
				'discovery'     => $h->discovery->value,
				'error_class'   => $h->error->value ?? '',
				'run_started'   => $h->runStarted,
				'run_finished'  => $h->runFinished,
				'discovered_at' => $h->discoveredAt,
				'retry_at'      => $h->retryAt,
				'requests'      => $h->requests,
				'series_read'   => $h->seriesRead,
				'series_total'  => $h->seriesTotal,
				'resources'     => [
					'active'  => $counts['active'] ?? 0,
					'missing' => $counts['missing'] ?? 0,
					'deleted' => $counts['deleted'] ?? 0,
				],
			];
		}

		return [
			'checked_at' => $this->clock->now(),
			'accounts'   => array_slice($out, 0, 500),
		];
	}
}
