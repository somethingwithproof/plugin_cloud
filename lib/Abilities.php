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
 * Every ability checks Cloud View realm authorization, applies Cacti device
 * scoping for non-admin callers, and returns zero credentials, vault keys,
 * or credential file paths.
 */
final class Abilities {
	/**
	 * @param \Closure(): bool          $canView         checks Cloud View realm permission
	 * @param \Closure(): list<Account> $visibleAccounts returns accounts the caller may access
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
	 * @return array<string, array<string, mixed>>
	 */
	public function definitions(): array {
		$permission = fn (): bool => ($this->canView)();

		return [
			'cloud-list-accounts' => [
				'name'                => 'cloud-list-accounts',
				'label'               => 'List Cloud Accounts',
				'description'         => 'List configured cloud accounts visible to the caller without any credential values.',
				'read_only'           => true,
				'permission_callback' => $permission,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'provider'  => ['type' => 'string'],
						'poller_id' => ['type' => 'integer'],
					],
				],
				'callback'            => fn (array $args = []): array => $this->listAccounts($args),
			],
			'cloud-list-resources' => [
				'name'                => 'cloud-list-resources',
				'label'               => 'List Cloud Resources',
				'description'         => 'List discovered cloud resources for accounts visible to the caller.',
				'read_only'           => true,
				'permission_callback' => $permission,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'account_id' => ['type' => 'integer'],
						'type'       => ['type' => 'string'],
						'lifecycle'  => ['type' => 'string'],
					],
				],
				'callback'            => fn (array $args = []): array => $this->listResources($args),
			],
			'cloud-get-health' => [
				'name'                => 'cloud-get-health',
				'label'               => 'Get Cloud Health',
				'description'         => 'Read collector and discovery health status for visible cloud accounts.',
				'read_only'           => true,
				'permission_callback' => $permission,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'account_id' => ['type' => 'integer'],
					],
				],
				'callback'            => fn (array $args = []): array => $this->getHealth($args),
			],
		];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function listAccounts(array $args = []): array {
		if (!($this->canView)()) {
			return ['error' => 'permission_denied', 'accounts' => []];
		}

		$providerFilter = is_string($args['provider'] ?? null) ? trim($args['provider']) : '';
		$pollerFilter   = isset($args['poller_id']) && is_numeric($args['poller_id']) ? (int) $args['poller_id'] : null;
		$out            = [];

		foreach (($this->visibleAccounts)() as $account) {
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

		return ['accounts' => $out];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function listResources(array $args = []): array {
		if (!($this->canView)()) {
			return ['error' => 'permission_denied', 'resources' => []];
		}

		$accountFilter = isset($args['account_id']) && is_numeric($args['account_id']) ? (int) $args['account_id'] : null;
		$typeFilter    = is_string($args['type'] ?? null) && $args['type'] !== '' ? trim($args['type']) : null;
		$lifeFilter    = is_string($args['lifecycle'] ?? null) && $args['lifecycle'] !== '' ? trim($args['lifecycle']) : null;
		$out           = [];

		foreach (($this->visibleAccounts)() as $account) {
			if ($accountFilter !== null && $account->id !== $accountFilter) {
				continue;
			}

			foreach ($this->resources->forAccount($account->id, $typeFilter, $lifeFilter) as $res) {
				$out[] = $res + ['account_name' => $account->name, 'provider' => $account->provider->value];
			}
		}

		return ['resources' => $out];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function getHealth(array $args = []): array {
		if (!($this->canView)()) {
			return ['error' => 'permission_denied', 'accounts' => []];
		}

		$accountFilter = isset($args['account_id']) && is_numeric($args['account_id']) ? (int) $args['account_id'] : null;
		$out           = [];

		foreach (($this->visibleAccounts)() as $account) {
			if ($accountFilter !== null && $account->id !== $accountFilter) {
				continue;
			}

			$h = $this->health->find($account->id);

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
				'resources'     => $this->resources->counts($account->id),
			];
		}

		return [
			'checked_at' => $this->clock->now(),
			'accounts'   => $out,
		];
	}
}
