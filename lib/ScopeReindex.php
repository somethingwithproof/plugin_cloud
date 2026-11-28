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
 * Keeps a scope device's data query reindex methods across an edit. Its
 * SNMP version is none, and for such a device api_device_save() sets every
 * data query's reindex method to None and drops its reindex checks, so a
 * description change alone would stop Cacti from following resources
 * coming and going (ADR 0010). The api_device_save hook remembers the
 * methods before the save; the api_device_new hook, which runs after
 * every successful save, puts back any the save changed through
 * api_device_dq_change(), which also rebuilds the reindex checks.
 *
 * A hook cannot stop the save, so when the methods could not be read
 * before it, each data query the save left at None gets the method scope
 * devices are created with, and a warning says so: the device keeps
 * reindexing, though a different method an administrator chose is lost.
 */
final class ScopeReindex {
	/** @var array<int, array<int, int>|null> host => data query => reindex method, or null when unread */
	private array $saved = [];

	/**
	 * @param \Closure(int, int, int): void $change api_device_dq_change() for a
	 *        device, data query and reindex method
	 */
	public function __construct(private readonly DeviceRepository $devices, private readonly \Closure $change) {
	}

	/**
	 * Snapshot a scope device's data query reindex methods prior to api_device_save().
	 *
	 * @param int $hostId Cacti host ID
	 * @return void
	 */
	public function remember(int $hostId): void {
		$methods = $this->devices->reindexMethods($hostId);

		if ($methods !== []) {
			$this->saved[$hostId] = $methods;
		}
	}

	/**
	 * Restore any data query reindex methods reset during api_device_save().
	 *
	 * @param int $hostId Cacti host ID
	 * @return void
	 */
	public function restore(int $hostId): void {
		if (!array_key_exists($hostId, $this->saved)) {
			return;
		}

		$saved = $this->saved[$hostId];
		unset($this->saved[$hostId]);

		$current = $this->devices->reindexMethods($hostId);

		if ($current === null) {
			Log::error(sprintf('Device %d: reindex methods unreadable after the save; check its data queries', $hostId));

			return;
		}

		if ($saved === null) {
			if ($current !== []) {
				Log::warning(sprintf('Device %d: reindex methods were unreadable before the save; reset ones get the default', $hostId));
			}

			$saved = array_map(
				static fn (int $method): int => $method === 0 ? ScopeDevices::REINDEX_METHOD : $method,
				$current
			);
		}

		foreach ($saved as $query => $method) {
			if (isset($current[$query]) && $current[$query] !== $method) {
				($this->change)($hostId, $query, $method);
			}
		}
	}
}
