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
 * A request the transport refused or could not complete. The kind is a fixed
 * code; the detail is diagnostic text from the local side only (never a
 * response body) and still passes through the Redactor before it is logged.
 */
final class TransportError extends \RuntimeException {
	public function __construct(public readonly string $kind, string $detail = '') {
		parent::__construct($kind . ($detail !== '' ? ': ' . $detail : ''));
	}

	public function errorClass(): ErrorClass {
		return match ($this->kind) {
			'url_rejected', 'host_refused', 'operation_refused', 'header_rejected', 'redirect_refused', 'tls_verify', 'tls_name' => ErrorClass::Config,
			'malformed', 'too_large', 'headers_too_large' => ErrorClass::Malformed,
			default => ErrorClass::Transient,
		};
	}
}
