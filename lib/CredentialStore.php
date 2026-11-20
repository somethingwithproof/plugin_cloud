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
 * Resolves an account's credential from its source, and stores replacements
 * in the vault. The vault key is loaded only when a vault value is used, so
 * accounts with credential files work without a key file.
 */
final class CredentialStore {
	private ?Vault $vault = null;

	public function __construct(
		private readonly SecretRepository $secrets,
		private readonly string $keyFile,
		private readonly string $credentialDir,
	) {
	}

	/**
	 * @throws CredentialError
	 */
	public function credentialFor(Account $account): Secret {
		return match ($account->credentialSource) {
			CredentialSource::File     => $this->fromFile($account->credentialFile),
			CredentialSource::Vault    => $this->fromVault($account->id),
			CredentialSource::Instance => throw CredentialError::because('instance identity is not available for this provider'),
		};
	}

	public function hasVaultCredential(int $accountId): bool {
		return $this->secrets->has($accountId, SecretRepository::CREDENTIAL);
	}

	/**
	 * @throws CredentialError
	 */
	public function storeVaultCredential(int $accountId, Secret $secret): void {
		if ($secret->isEmpty() || strlen($secret->reveal()) > CredentialFile::MAX_BYTES) {
			throw CredentialError::because('the credential is empty or too long');
		}

		if (!$this->secrets->store(
			$accountId,
			SecretRepository::CREDENTIAL,
			$this->vault()->encrypt($secret, $accountId, SecretRepository::CREDENTIAL)
		)) {
			throw CredentialError::because('the credential could not be stored');
		}
	}

	/**
	 * @return bool whether the delete ran; false means the credential may remain
	 */
	public function forget(int $accountId): bool {
		return $this->secrets->delete($accountId, SecretRepository::CREDENTIAL);
	}

	/**
	 * Drops the vault credential of an account that has moved to another
	 * source. An account that is on the vault again keeps it.
	 *
	 * @return bool whether the delete ran; false means the credential may remain
	 */
	public function forgetUnlessVault(int $accountId): bool {
		return $this->secrets->deleteUnlessVault($accountId, SecretRepository::CREDENTIAL);
	}

	/**
	 * @throws CredentialError
	 */
	/**
	 * @throws CredentialError
	 */
	private function fromFile(string $path): Secret {
		// The vault key may share the directory; it is never a credential.
		$file = realpath($path);

		if ($file !== false && $file === realpath($this->keyFile)) {
			throw CredentialError::because('the file is the vault key file');
		}

		return CredentialFile::read($path, $this->credentialDir);
	}

	private function fromVault(int $accountId): Secret {
		$entry = $this->secrets->load($accountId, SecretRepository::CREDENTIAL);

		if ($entry === null) {
			throw CredentialError::because('no credential is stored for this account');
		}

		return $this->vault()->decrypt($entry, $accountId, SecretRepository::CREDENTIAL);
	}

	private function vault(): Vault {
		return $this->vault ??= new Vault(VaultKey::fromFile($this->keyFile));
	}
}
