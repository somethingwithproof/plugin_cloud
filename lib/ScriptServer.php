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
 * The script server side of the resource data queries (#44). Cacti's poller
 * and reindex call ss_cloud(), which hands its arguments here. Everything is
 * read from the plugin's cache; nothing here calls a provider (REQ-SS-002).
 *
 * A device sees only the resources of the account it is the scope device
 * of (REQ-SS-004). An unknown device, type, field or resource gets no
 * indexes and reads as U, so a stale poller item cannot read another
 * account's data.
 */
final class ScriptServer {
	public const UNKNOWN = 'U';

	private const COMMANDS = ['index', 'num_indexes', 'query', 'get'];

	public function __construct(
		private readonly DeviceRepository $devices,
		private readonly AccountRepository $accounts,
		private readonly ResourceRepository $resources,
		private readonly ObservationRepository $observations,
		private readonly FreshnessEvaluator $freshness,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @return string the lines to print for index, num_indexes and query;
	 *                the value to return for get
	 */
	public function run(int $hostId, string $type, string $command, string $field = '', string $index = ''): string {
		if (!in_array($command, self::COMMANDS, true)) {
			return '';
		}

		$fields  = self::fields($type);
		$account = $hostId > 0 && $fields !== [] ? $this->account($hostId) : null;
		$known   = in_array($field, $fields, true);

		if ($command === 'get') {
			return $account !== null && $known ? $this->get($account, $type, $field, $index) : self::UNKNOWN;
		}

		$resources = $account !== null ? $this->resources->served($account->id, $type) : [];

		if ($command === 'num_indexes') {
			return (string) count($resources);
		}

		if ($command === 'query' && (!$known || $account === null)) {
			return '';
		}

		$lines = '';

		foreach ($resources as $resource) {
			$lines .= $command === 'index'
				? $resource['providerId'] . "\n"
				: $resource['providerId'] . '!' . $this->value($account, $resource, $field) . "\n";
		}

		return $lines;
	}

	/**
	 * The data query fields of a resource type: its index and name, then
	 * every data source its graphs use. Empty for an unknown type.
	 *
	 * @return list<string>
	 */
	public static function fields(string $type): array {
		$fields = [];

		foreach (DigitalOceanCatalog::GRAPHS as $graph) {
			if ($graph[0] === $type) {
				$fields = array_merge($fields, $graph[4]);
			}
		}

		return $fields === [] ? [] : array_merge(['resourceId', 'resourceName'], $fields);
	}

	private function account(int $hostId): ?Account {
		$id = $this->devices->accountFor($hostId);

		return $id === null ? null : $this->accounts->find($id);
	}

	private function get(Account $account, string $type, string $field, string $index): string {
		foreach ($this->resources->served($account->id, $type) as $resource) {
			if ($resource['providerId'] === $index) {
				return $this->value($account, $resource, $field);
			}
		}

		return self::UNKNOWN;
	}

	/**
	 * @param array{id: int, providerId: string, name: string, firstSeen: int, lifecycle: string} $resource
	 */
	private function value(Account $account, array $resource, string $field): string {
		if ($field === 'resourceId') {
			return $resource['providerId'];
		}

		if ($field === 'resourceName') {
			return self::label($resource['name']);
		}

		$entries = $this->observations->forResource($resource['id']);

		if (in_array($field, DigitalOceanCatalog::AGE, true)) {
			return (string) $this->age($resource, $entries);
		}

		// Collection has stopped for a resource discovery no longer lists, so
		// its last value would read as current until it aged out.
		if ($resource['lifecycle'] === Lifecycle::Missing->value) {
			return self::UNKNOWN;
		}

		$entry = $entries[$field] ?? null;

		if (!$account->enabled() || $entry === null) {
			return self::UNKNOWN;
		}

		$state = $this->freshness->state($entry['observation'], $entry['status'], $account->collectInterval);

		if (!in_array($state, [Freshness::Current->value, Freshness::Delayed->value], true)) {
			return self::UNKNOWN;
		}

		return self::number((float) $entry['observation']->value);
	}

	/**
	 * Seconds since the newest datapoint with a value, or since discovery
	 * when there is none; never negative, always numeric (REQ-SS-003). A
	 * series skipped for budget keeps its last datapoint, and with it its
	 * age, whatever status the skip recorded.
	 *
	 * @param array{id: int, providerId: string, name: string, firstSeen: int, lifecycle: string} $resource
	 * @param array<string, array{observation: Observation, status: string}> $entries
	 */
	private function age(array $resource, array $entries): int {
		$newest = 0;

		foreach ($entries as $entry) {
			if ($entry['observation']->value !== null) {
				$newest = max($newest, $entry['observation']->observedAt);
			}
		}

		$since = $newest > 0 ? $newest : $resource['firstSeen'];

		return max(0, $this->clock->now() - $since);
	}

	/**
	 * A name as one output field: the delimiter, line breaks and other
	 * control characters would split or corrupt the line.
	 */
	public static function label(string $name): string {
		$clean = trim((string) preg_replace('/[!\x00-\x1F\x7F]+/', ' ', $name));

		return $clean === '' ? self::UNKNOWN : $clean;
	}

	/**
	 * A plain decimal, never exponent notation.
	 */
	public static function number(float $value): string {
		if (!is_finite($value)) {
			return self::UNKNOWN;
		}

		$text = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

		return $text === '-0' ? '0' : $text;
	}
}
