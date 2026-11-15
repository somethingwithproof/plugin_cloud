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
 */
final class ScopeReindex {
	/** @var array<int, array<int, int>> host => data query => reindex method */
	private array $saved = [];

	/**
	 * @param \Closure(int, int, int): void $change api_device_dq_change() for a
	 *        device, data query and reindex method
	 */
	public function __construct(private readonly DeviceRepository $devices, private readonly \Closure $change) {
	}

	public function remember(int $hostId): void {
		$methods = $this->devices->reindexMethods($hostId);

		if ($methods !== []) {
			$this->saved[$hostId] = $methods;
		}
	}

	public function restore(int $hostId): void {
		$saved = $this->saved[$hostId] ?? [];
		unset($this->saved[$hostId]);

		if ($saved === []) {
			return;
		}

		$current = $this->devices->reindexMethods($hostId);

		foreach ($saved as $query => $method) {
			if (isset($current[$query]) && $current[$query] !== $method) {
				($this->change)($hostId, $query, $method);
			}
		}
	}
}
