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

use Somethingwithproof\Cloud\HealthRepository;
use Somethingwithproof\Cloud\Verifier;
use Somethingwithproof\Cloud\Drivers;

/** @return list<array{id: string, name: string, state: string, observed: int, interval: int, detail: string}> */
function cloud_operator_rows(): array {
	$rows   = [];
	$health = new HealthRepository(false);

	foreach (cloud_device_allowed_accounts() as $account) {
		$h      = $health->find($account->id);
		$rows[] = [
			'id' => (string) $account->id,
			'name' => $account->name,
			'state' => $account->enabled() ? $h->collection->value : 'disabled',
			'observed' => $h->runFinished,
			'interval' => $account->collectInterval,
			'detail'       => ($h->error->value ?? '') . ' / ' . $account->verifyStatus->message() . ' ' . __('Check the account graphs and collection freshness. Verify tests the stored credential; it cannot replace it or change the provider configuration.', 'cloud')
		];
	}

	return $rows;
}

/** Verification cannot replace the stored credential or edit the account. */
function cloud_operator_verify(int $id, int $user): void {
	$accounts = cloud_accounts();
	$account  = $accounts->find($id);

	if ($account === null) {
		throw new RuntimeException('Account unavailable');
	}
	$status = (new Verifier(Drivers::production(), cloud_credentials(), $accounts))->verify($account, $user);
	raise_message('cloud_operator_verify', $status->message(), MESSAGE_LEVEL_INFO);
}
