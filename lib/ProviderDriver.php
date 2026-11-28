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
 * One provider. A driver builds requests only for its listed operations and
 * sends them through the transport it was given, which refuses anything else
 * (ADR 0002). It reports failures as DriverError with an ErrorClass and never
 * passes provider text on.
 */
interface ProviderDriver {
	/**
	 * Identify the cloud provider implemented by this driver.
	 *
	 * @return Provider
	 */
	public function provider(): Provider;

	/**
	 * Hosts this account may reach, built from fixed tables and the account's
	 * validated scope fields.
	 */
	public function endpoints(Account $account): OutboundPolicy;

	/**
	 * Every operation the driver can call. Fixed per provider.
	 *
	 * @return list<Operation>
	 */
	public function operations(): array;

	/**
	 * Test the credential and the permissions the account needs.
	 */
	public function verify(Account $account, Secret $credential): VerifyStatus;

	/**
	 * @throws DriverError
	 */
	public function discover(Account $account, Secret $credential): Inventory;

	/**
	 * @param list<MetricRequest> $requests
	 */
	public function collect(Account $account, Secret $credential, array $requests): CollectResult;

	/**
	 * Requests and cost of collecting $requests once every $intervalSeconds,
	 * computed without calling the provider.
	 *
	 * @param list<MetricRequest> $requests
	 */
	public function estimate(array $requests, int $intervalSeconds): UsageEstimate;
}
