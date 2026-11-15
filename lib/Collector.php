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
 * One collector run on one poller (REQ-COLL-001 to REQ-COLL-006).
 *
 * It handles only enabled accounts owned by its poller, stops starting new
 * accounts once its time budget is spent, holds a lease for each account it
 * works on, runs discovery only when that is due and never reads metrics
 * there, and reads metrics only for the plugin's active data sources. An
 * interrupted run leaves every account either untouched or fully written for
 * the work it finished, and the next run continues.
 */
final class Collector {
	public const DUE_SLACK = 30;

	/**
	 * Seconds after a run's start when the account is due again: its
	 * interval less a slack of a tenth of it, at most DUE_SLACK.
	 */
	public static function dueAfter(int $collectInterval): int {
		return $collectInterval - min(self::DUE_SLACK, intdiv($collectInterval, 10));
	}

	public function __construct(
		private readonly AccountRepository $accounts,
		private readonly Drivers $drivers,
		private readonly CredentialStore $credentials,
		private readonly LeaseRepository $leases,
		private readonly ResourceRepository $resources,
		private readonly SeriesRepository $series,
		private readonly ObservationRepository $observations,
		private readonly HealthRepository $health,
		private readonly Redactor $redactor,
		private readonly Clock $clock,
		private readonly string $holder,
	) {
	}

	/**
	 * @return array{accounts: int, skipped: int, stopped_early: bool}
	 */
	public function run(int $pollerId, int $budgetSeconds): array {
		$started = $this->clock->now();
		$done    = 0;
		$skipped = 0;

		// Least recently attempted first, so an account that overruns the
		// budget on every run cannot keep the ones after it from running.
		// Attempts come from the lease, because an overrun can lose the lease
		// and with it the health write that would have recorded the run.
		$accounts = $this->accounts->enabledForPoller($pollerId);
		$lastRun  = [];

		foreach ($accounts as $account) {
			$lastRun[$account->id] = $this->leases->lastAcquired($account->id);
		}

		usort(
			$accounts,
			static fn (Account $a, Account $b): int => [$lastRun[$a->id], $a->id] <=> [$lastRun[$b->id], $b->id]
		);

		foreach ($accounts as $account) {
			if ($this->clock->now() - $started >= $budgetSeconds) {
				Log::warning(sprintf('Collector budget of %d seconds spent; remaining accounts resume next run', $budgetSeconds));

				return ['accounts' => $done, 'skipped' => $skipped, 'stopped_early' => true];
			}

			if (!$this->leases->acquire($account->id, $this->holder, $budgetSeconds + 60)) {
				$skipped++;

				continue;
			}

			try {
				$this->runAccount($account);
				$done++;
			} finally {
				$this->leases->release($account->id, $this->holder);
			}
		}

		return ['accounts' => $done, 'skipped' => $skipped, 'stopped_early' => false];
	}

	private function runAccount(Account $account): void {
		$now      = $this->clock->now();
		$start    = microtime(true);
		$previous = $this->health->find($account->id);

		// Each run starts a few seconds after its poller cycle, so the time
		// since the last start drifts around the interval. Without slack, an
		// interval equal to the poller interval would often read as not yet
		// due and collect only every other cycle.
		if ($previous->runStarted > 0 && $now - $previous->runStarted < self::dueAfter($account->collectInterval)) {
			return;
		}

		$driver = $this->drivers->for($account);

		if ($driver === null) {
			$this->health->save(
				new AccountHealth(
					$account->id,
					$now,
					$this->clock->now(),
					microtime(true) - $start,
					CollectionState::Failed,
					ErrorClass::Config,
					discoveredAt: $previous->discoveredAt,
					discovery: $previous->discovery
				),
				$this->holder,
				$this->clock->now()
			);

			return;
		}

		try {
			$credential = $this->credentials->credentialFor($account);
		} catch (CredentialError $e) {
			Log::warning(sprintf('Account %d: credential unavailable: %s', $account->id, $e->getMessage()));
			$this->health->save(
				new AccountHealth(
					$account->id,
					$now,
					$this->clock->now(),
					microtime(true) - $start,
					CollectionState::Failed,
					ErrorClass::Config,
					discoveredAt: $previous->discoveredAt,
					discovery: $previous->discovery
				),
				$this->holder,
				$this->clock->now()
			);

			return;
		}

		$this->redactor->add($credential);

		$requests     = 0;
		$discoveredAt = $previous->discoveredAt;
		$discovery    = $previous->discovery;

		if ($now - $previous->discoveredAt >= $account->discoverInterval) {
			try {
				$inventory    = $driver->discover($account, $credential);
				$requests    += $inventory->requests;
				$discovery    = $this->resources->sync($account->id, $inventory, $this->leases, $this->holder)
					? CollectionState::Ok
					: CollectionState::Partial;
				$discoveredAt = $now;
			} catch (DriverError|TransportError $e) {
				$discovery = CollectionState::Failed;
				Log::warning(sprintf('Account %d: discovery failed: %s', $account->id, $this->redactor->clean($e->getMessage())));
			}
		}

		$wanted = $this->series->enabledFor($account->id);
		$result = $wanted === [] ? new CollectResult([], 0) : $driver->collect($account, $credential, $wanted);
		$read   = 0;

		foreach ($result->observations as $observation) {
			$this->observations->write($account->id, $this->holder, $this->clock->now(), $observation);
			$read++;
		}

		$state = match (true) {
			$result->error !== null && $read === 0 => CollectionState::Failed,
			$result->error !== null || $read < count($wanted) => CollectionState::Partial,
			default => CollectionState::Ok,
		};

		$this->health->save(new AccountHealth(
			accountId: $account->id,
			runStarted: $now,
			runFinished: $this->clock->now(),
			runDuration: microtime(true) - $start,
			collection: $state,
			error: $result->error,
			requests: $requests + $result->requests,
			seriesTotal: count($wanted),
			seriesRead: $read,
			discoveredAt: $discoveredAt,
			discovery: $discovery,
		), $this->holder, $this->clock->now());
	}
}
