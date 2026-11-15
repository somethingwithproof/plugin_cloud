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
 * A secret value: a token, key, or credential document.
 *
 * It refuses serialization and hides itself from var_dump(), print_r(), and
 * var_export() (REQ-CRED-006). It has no __toString(), so it cannot reach a
 * string context by accident; reveal() is the one, greppable, way out.
 */
final class Secret {
	public function __construct(#[\SensitiveParameter] private readonly string $value) {
	}

	public function reveal(): string {
		return $this->value;
	}

	public function isEmpty(): bool {
		return $this->value === '';
	}

	public function equals(self $other): bool {
		return hash_equals($this->value, $other->value);
	}

	/**
	 * @return array<string, string>
	 */
	public function __debugInfo(): array {
		return ['value' => '[redacted]'];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function __serialize(): array {
		throw new \LogicException('A secret cannot be serialized');
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function __unserialize(array $data): void {
		throw new \LogicException('A secret cannot be unserialized');
	}

	/**
	 * @param array<string, mixed> $state
	 */
	public static function __set_state(array $state): never {
		throw new \LogicException('A secret cannot be exported');
	}
}
