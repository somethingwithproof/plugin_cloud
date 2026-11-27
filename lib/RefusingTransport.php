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
 * A transport that never sends, for drivers that are only being inspected.
 */
final class RefusingTransport implements HttpTransport {
	/**
	 * Always refuse outbound network traffic.
	 *
	 * @param HttpRequest $request attempted HTTP request
	 * @return HttpResponse never returns
	 * @throws TransportError always thrown with operation_refused
	 */
	public function send(HttpRequest $request): HttpResponse {
		throw new TransportError('operation_refused', 'inspection only');
	}
}
