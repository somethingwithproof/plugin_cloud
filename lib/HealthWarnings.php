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
 * The health page's warnings (REQ-HEALTH-002): a vault key file that is
 * missing or refused, a credential file that fails its checks, and a
 * collection interval the poller interval cannot honour.
 *
 * File checks run on the server rendering the page; a collector on another
 * poller reads its own copies. Credential file warnings name an account's
 * configuration, so they are for Cloud Admin only (REQ-HEALTH-004).
 */
final class HealthWarnings {
	/**
	 * @param \Closure(string): void $keyCheck  throws CredentialError for an unusable key file
	 * @param \Closure(string, string): void $fileCheck throws CredentialError for an unusable credential
	 *        file, given its path and the credential directory
	 */
	public function __construct(
		private readonly \Closure $keyCheck,
		private readonly \Closure $fileCheck,
	) {
	}

	public static function production(): self {
		return new self(
			static function (string $path): void {
				VaultKey::fromFile($path);
			},
			static function (string $path, string $directory): void {
				CredentialFile::check($path, $directory);
			},
		);
	}

	/**
	 * @param list<Account> $accounts
	 *
	 * @return list<array{message: string, admin: bool}>
	 */
	public function for(Settings $settings, array $accounts, int $pollerInterval): array {
		$warnings = [];

		try {
			($this->keyCheck)($settings->vaultKeyFile);
		} catch (CredentialError $e) {
			$warnings[] = [
				'message' => __(
					'The vault key file cannot be used: %s. Accounts that store credentials in the vault cannot be verified or collected here.',
					$e->getMessage(),
					'cloud'
				),
				'admin' => false
			];
		}

		foreach ($accounts as $account) {
			if (!$account->enabled()) {
				continue;
			}

			if ($account->credentialSource === CredentialSource::File) {
				try {
					($this->fileCheck)($account->credentialFile, $settings->credentialDir);
				} catch (CredentialError $e) {
					$warnings[] = [
						'message' => __('%s: the credential file cannot be used: %s.', $account->name, $e->getMessage(), 'cloud'),
						'admin' => true
					];
				}
			}

			$interval = $this->intervalWarning($account, $pollerInterval);

			if ($interval !== null) {
				$warnings[] = ['message' => $interval, 'admin' => false];
			}
		}

		return $warnings;
	}

	/**
	 * The collector runs once per poller cycle, so it collects an account
	 * at the first whole number of poller intervals that reaches the
	 * collector's due threshold for it (Collector::dueAfter()).
	 */
	private function intervalWarning(Account $account, int $pollerInterval): ?string {
		if ($pollerInterval < 1) {
			return null;
		}

		$effective = max(1, (int) ceil(Collector::dueAfter($account->collectInterval) / $pollerInterval)) * $pollerInterval;

		if ($effective === $account->collectInterval) {
			return null;
		}

		return $account->collectInterval < $pollerInterval
			? __(
				'%s: the collection interval of %d seconds is shorter than the poller interval of %d seconds, so it is collected every %d seconds.',
				$account->name,
				$account->collectInterval,
				$pollerInterval,
				$effective,
				'cloud'
			)
			: __(
				'%s: the collection interval of %d seconds is not a multiple of the poller interval of %d seconds, so it is collected every %d seconds.',
				$account->name,
				$account->collectInterval,
				$pollerInterval,
				$effective,
				'cloud'
			);
	}
}
