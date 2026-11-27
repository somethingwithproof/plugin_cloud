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
 * Refuses any request that is not one of the driver's listed operations
 * before it reaches the network, and before a signer sees it.
 */
final class GuardedTransport implements HttpTransport {
	/**
	 * @param list<Operation> $operations
	 */
	public function __construct(private readonly HttpTransport $inner, private readonly array $operations) {
	}

	/**
	 * Find the first allowlisted operation matching the request, or null if disallowed.
	 *
	 * @param HttpRequest $request outbound HTTP request
	 * @return Operation|null matched operation, or null when refused
	 */
	public function allows(HttpRequest $request): ?Operation {
		foreach ($this->operations as $operation) {
			if ($operation->matches($request)) {
				return $operation;
			}
		}

		return null;
	}

	/**
	 * Send an outbound request only after verifying it matches an allowlisted operation.
	 *
	 * @param HttpRequest $request outbound HTTP request
	 * @return HttpResponse provider HTTP response
	 * @throws TransportError when the operation is not allowlisted or the inner transport fails
	 */
	public function send(HttpRequest $request): HttpResponse {
		if ($this->allows($request) === null) {
			throw new TransportError(
				'operation_refused',
				$request->method . ' ' . (string) parse_url($request->url, PHP_URL_PATH)
			);
		}

		return $this->inner->send($request);
	}
}
