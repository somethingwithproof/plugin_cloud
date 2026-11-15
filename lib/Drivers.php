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
 * Builds the driver for an account, wired to a transport that enforces the
 * account's outbound policy and the driver's operation list. No provider has
 * a driver yet: DigitalOcean's arrives with milestone 3, and until then an
 * account reports a configuration status instead of collecting.
 */
final class Drivers {
	/**
	 * @var array<string, \Closure(HttpTransport): ProviderDriver>
	 */
	private array $factories = [];

	/**
	 * @param \Closure(OutboundPolicy): HttpTransport $transport
	 */
	public function __construct(private readonly \Closure $transport) {
	}

	public static function production(): self {
		return new self(static fn (OutboundPolicy $policy): HttpTransport => new SocketTransport($policy));
	}

	/**
	 * @param \Closure(HttpTransport): ProviderDriver $factory
	 */
	public function register(Provider $provider, \Closure $factory): void {
		$this->factories[$provider->value] = $factory;
	}

	public function for(Account $account): ?ProviderDriver {
		$factory = $this->factories[$account->provider->value] ?? null;

		if ($factory === null) {
			return null;
		}

		// The driver is built twice: once unwired to learn its endpoints and
		// operations, then with the guarded transport it will actually use.
		$probe = $factory(new GuardedTransport(new RefusingTransport(), []));

		return $factory(new GuardedTransport(($this->transport)($probe->endpoints($account)), $probe->operations()));
	}
}
