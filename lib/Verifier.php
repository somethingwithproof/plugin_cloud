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
 * Verify on the account page: resolve the credential, ask the driver, and
 * record one fixed status (REQ-ACCT-004). Exceptions never carry provider
 * text out of here; anything unexpected is a configuration status.
 */
final class Verifier {
	/**
	 * Construct a credential verifier service.
	 *
	 * @param Drivers           $drivers     provider driver registry
	 * @param CredentialStore   $credentials credential store
	 * @param AccountRepository $accounts    account repository for recording verify results
	 */
	public function __construct(
		private readonly Drivers $drivers,
		private readonly CredentialStore $credentials,
		private readonly AccountRepository $accounts,
	) {
	}

	/**
	 * Verify an account's credential with its provider driver and persist the result.
	 *
	 * @param Account $account target cloud account
	 * @param int     $userId  Cacti user ID initiating verification
	 * @return VerifyStatus recorded fixed verification status
	 */
	public function verify(Account $account, int $userId): VerifyStatus {
		$status = $this->status($account);

		$this->accounts->recordVerify($account->id, $status, $userId);

		return $status;
	}

	/**
	 * Resolve driver and credential, perform verification, and classify any error.
	 *
	 * @param Account $account target cloud account
	 * @return VerifyStatus normalized verification status
	 */
	private function status(Account $account): VerifyStatus {
		try {
			$driver = $this->drivers->for($account);

			if ($driver === null) {
				return VerifyStatus::Config;
			}

			return $driver->verify($account, $this->credentials->credentialFor($account));
		} catch (CredentialError) {
			return VerifyStatus::Config;
		} catch (DriverError $error) {
			return VerifyStatus::fromError($error->errorClass);
		} catch (TransportError $error) {
			return VerifyStatus::fromError($error->errorClass());
		} catch (\Throwable $error) {
			// The class only: an unexpected message may carry provider text.
			Log::warning(sprintf('Account %d: verify failed unexpectedly (%s)', $account->id, $error::class));

			return VerifyStatus::Config;
		}
	}
}
