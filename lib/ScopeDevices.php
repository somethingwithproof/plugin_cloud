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
 * Creates each account's Cacti scope device (REQ-OBJ-010, ADR 0010) through
 * Cacti's own device API, never by writing Cacti tables (REQ-OBJ-012). The
 * device polls nothing itself: availability None, on the account's poller,
 * with the provider's device template, whose data queries list the
 * account's resources through ss_cloud().
 *
 * The accounts page calls this only when an administrator asks for it.
 */
final class ScopeDevices {
	/** api_device_save()'s availability method None (AVAIL_NONE). */
	private const AVAILABILITY_NONE = 0;

	/**
	 * Reindex on an index count change (DATA_QUERY_AUTOINDEX_INDEX_NUM_CHANGE):
	 * the poller compares ss_cloud()'s num_indexes with the cached count. The
	 * site default, uptime going backwards, needs SNMP, which a scope device
	 * does not have, so it would never reindex.
	 */
	public const REINDEX_METHOD = 2;

	/** The device template of each provider, as TemplateRepository keys. */
	private const TEMPLATES = [Provider::DigitalOcean->value => 'host_template/digitalocean'];

	/**
	 * @param \Closure(Account, int): int $save    api_device_save() for this account
	 *        and device template, returning the new device id or 0
	 * @param \Closure(int, int, int): void $reindex api_device_dq_change() for a device,
	 *        data query and reindex method, which also runs the data query
	 * @param \Closure(int): void          $remove  api_device_remove() for a device
	 * @param int                          $lockTimeout seconds to wait for another
	 *        request creating the same account's device
	 */
	public function __construct(
		private readonly DeviceRepository $devices,
		private readonly TemplateRepository $templates,
		private readonly \Closure $save,
		private readonly \Closure $reindex,
		private readonly \Closure $remove,
		private readonly int $lockTimeout = 10,
	) {
	}

	/**
	 * @return int the account's device id, created now or earlier; 0 when it
	 *             could not be created
	 */
	public function create(Account $account): int {
		$existing = $this->devices->hostFor($account->id);

		if ($existing !== null) {
			return $existing;
		}

		$key      = self::TEMPLATES[$account->provider->value] ?? null;
		$template = $key === null ? null : $this->templates->id('host_template', $key);

		if ($template === null) {
			Log::error(sprintf('Account %d: no Cacti device template; import the plugin templates first', $account->id));

			return 0;
		}

		// Two requests that both found no device would each save one.
		if (!$this->devices->lock($account->id, $this->lockTimeout)) {
			Log::error(sprintf('Account %d: another request is creating its scope device', $account->id));

			return 0;
		}

		try {
			return $this->createLocked($account, $template);
		} finally {
			$this->devices->unlock($account->id);
		}
	}

	private function createLocked(Account $account, int $template): int {
		$existing = $this->devices->hostFor($account->id);

		if ($existing !== null) {
			return $existing;
		}

		$host = ($this->save)($account, $template);

		if ($host <= 0) {
			Log::error(sprintf('Account %d: Cacti refused the scope device', $account->id));

			return 0;
		}

		// An unlinked device would look ready but serve nothing, and a retry
		// would make another; remove it so the retry starts clean.
		if (!$this->devices->link($host, $account->id)) {
			($this->remove)($host);
			Log::error(sprintf('Account %d: the scope device link was not recorded; removed device %d', $account->id, $host));

			return 0;
		}

		// Saving applied the template's data queries with the site's reindex
		// method and indexed them before the link existed, so they found
		// nothing; set the method and index them again now.
		foreach (TemplateRepository::objects()['snmp_query'] as $query) {
			$id = $this->templates->id('snmp_query', $query);

			if ($id !== null) {
				($this->reindex)($host, $id, self::REINDEX_METHOD);
			}
		}

		Log::info(sprintf('Account %d: created scope device %d', $account->id, $host));

		return $host;
	}

	/**
	 * The fields api_device_save() takes, in order, for an account's device.
	 *
	 * @return list<int|string>
	 */
	public static function deviceFields(Account $account, int $template): array {
		return [
			0,                              // id: a new device
			$template,
			$account->name,                 // description
			'cloud-account-' . $account->id, // hostname; nothing connects to it
			'',                             // snmp_community
			0,                              // snmp_version: none
			'', '', 161, 500,               // snmp username, password, port, timeout
			'',                             // disabled: no
			self::AVAILABILITY_NONE,
			1, 23, 400, 1,                  // ping method, port, timeout, retries: unused
			'Cloud scope device for account ' . $account->id . '; created by the Cloud plugin.',
			'', '', '', '', '',             // SNMPv3 fields
			5, 1,                           // max_oids, device_threads
			$account->pollerId,
			0,                              // site: none
			DeviceRepository::EXTERNAL_PREFIX . $account->id, // external_id; the link checks it
			'',                             // location
		];
	}
}
