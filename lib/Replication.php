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
 * Remote data collector replication and read-only remote agent health
 * visibility. Replicates only poller-owned metadata and poller-owned
 * encrypted vault entries (never raw vault key files or other pollers' data).
 */
final class Replication {
	public const REMOTE_AGENT_ACTION = 'cloud_health';

	public function __construct(
		private readonly ReplicationRepository $replication,
		private readonly AccountRepository $accounts,
		private readonly HealthRepository $health,
		private readonly ResourceRepository $resources,
		private readonly Clock $clock,
	) {
	}

	/**
	 * Replicate poller-scoped cloud tables to a remote data collector.
	 *
	 * @param \Closure(list<array<array-key, mixed>>, string, int): void $replicateTable
	 * @return array<string, int> table => replicated row count
	 */
	public function replicateOut(int $pollerId, \Closure $replicateTable, bool $includeEncryptedSecrets = true): array {
		if ($pollerId <= Account::MAIN_POLLER) {
			return [];
		}

		$accounts = $this->replication->accountsForPoller($pollerId);
		$devices  = $this->replication->devicesForPoller($pollerId);
		$series   = $this->replication->seriesForPoller($pollerId);
		$secrets  = $includeEncryptedSecrets ? $this->replication->encryptedSecretsForPoller($pollerId) : [];

		if ($accounts === null || $devices === null || $series === null || $secrets === null) {
			Log::warning(sprintf('Aborted cloud metadata replication to poller %d because a source query failed', $pollerId));

			return [];
		}

		$tables = [
			'plugin_cloud_account' => $accounts,
			'plugin_cloud_device'  => $devices,
			'plugin_cloud_series'  => $series,
		];

		if ($includeEncryptedSecrets) {
			$tables['plugin_cloud_secret'] = $secrets;
		}

		$counts = [];

		foreach ($tables as $table => $rows) {
			$replicateTable($rows, $table, $pollerId);
			$counts[$table] = count($rows);
		}

		Log::info(sprintf(
			'Replicated cloud metadata to poller %d (accounts=%d, devices=%d, series=%d, encrypted_secrets=%d)',
			$pollerId,
			$counts['plugin_cloud_account'],
			$counts['plugin_cloud_device'],
			$counts['plugin_cloud_series'],
			$counts['plugin_cloud_secret'] ?? 0
		));

		return $counts;
	}

	/**
	 * Build a strictly read-only health summary payload for the remote_agent
	 * hook. Accepts integer identifiers only and emits zero credentials, file
	 * paths, or secrets.
	 *
	 * @return array{
	 *     generated_at: int,
	 *     poller_id: ?int,
	 *     accounts: list<array{
	 *         id: int,
	 *         name: string,
	 *         provider: string,
	 *         poller_id: int,
	 *         enabled: bool,
	 *         credential_source: string,
	 *         verify_status: string,
	 *         collection: string,
	 *         discovery: string,
	 *         error_class: string,
	 *         run_started: int,
	 *         run_finished: int,
	 *         discovered_at: int,
	 *         retry_at: int,
	 *         requests: int,
	 *         series_read: int,
	 *         series_total: int,
	 *         resources: array<string, int>
	 *     }>
	 * }
	 */
	public function remoteHealth(?int $pollerId = null, ?int $accountId = null): array {
		if ($accountId !== null && $accountId > 0) {
			$found    = $this->accounts->find($accountId);
			$accounts = $found !== null && ($pollerId === null || $found->pollerId === $pollerId) ? [$found] : [];
		} elseif ($pollerId !== null && $pollerId > 0) {
			$accounts = $this->accounts->forPoller($pollerId);
		} else {
			$accounts = $this->accounts->all();
		}

		$rows = [];

		foreach ($accounts as $account) {
			$h      = $this->health->find($account->id);
			$counts = $this->resources->counts($account->id);

			$rows[] = [
				'id'                => $account->id,
				'name'              => $account->name,
				'provider'          => $account->provider->value,
				'poller_id'         => $account->pollerId,
				'enabled'           => $account->enabled(),
				'credential_source' => $account->credentialSource->value,
				'verify_status'     => $account->verifyStatus->value,
				'collection'        => $h->collection->value,
				'discovery'         => $h->discovery->value,
				'error_class'       => $h->error->value ?? '',
				'run_started'       => $h->runStarted,
				'run_finished'      => $h->runFinished,
				'discovered_at'     => $h->discoveredAt,
				'retry_at'          => $h->retryAt,
				'requests'          => $h->requests,
				'series_read'       => $h->seriesRead,
				'series_total'      => $h->seriesTotal,
				'resources'         => $counts,
			];
		}

		return [
			'generated_at' => $this->clock->now(),
			'poller_id'    => $pollerId,
			'accounts'     => $rows,
		];
	}
}
