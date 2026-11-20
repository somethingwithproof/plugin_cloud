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

/*
 * Cloud Accounts (Cloud Admin realm). This file must parse on PHP 8.0: Cacti
 * may include it on an unsupported runtime, and cloud_page_start() refuses
 * there before any plugin class loads.
 */

chdir('../../');
include('./include/auth.php');
include_once('./plugins/cloud/setup.php');

if (!cloud_page_start()) {
	exit;
}

cloud_require_post('cloud_accounts.php', array('save', 'delete', 'verify', 'create_device'));

$cloud_accounts    = cloud_accounts();
$cloud_credentials = cloud_credentials();
$cloud_page        = new \Somethingwithproof\Cloud\AccountsPage(
	$cloud_accounts,
	$cloud_credentials,
	new \Somethingwithproof\Cloud\DeviceRepository(false, new \Somethingwithproof\Cloud\SystemClock())
);
$cloud_user        = isset($_SESSION['sess_user_id']) && is_numeric($_SESSION['sess_user_id'])
	? (int) $_SESSION['sess_user_id']
	: 0;
$cloud_action      = (string) get_nfilter_request_var('action');

switch ($cloud_action) {
	case 'save':
		$cloud_id    = (int) get_filter_request_var('id');
		$cloud_input = array();

		foreach (array(
			'provider',
			'name',
			'poller_id',
			'credential_source',
			'credential_file',
			'collect_interval',
			'discover_interval',
			'request_share',
			'monthly_ceiling',
			'enabled',
			'scope_account_id',
			'scope_regions',
			'scope_project',
			'scope_tenant',
			'scope_subscription'
		) as $cloud_field) {
			$cloud_input[$cloud_field] = (string) get_nfilter_request_var($cloud_field);
		}

		$cloud_existing = $cloud_id > 0 ? $cloud_accounts->find($cloud_id) : null;

		if ($cloud_id > 0 && $cloud_existing === null) {
			header('Location: cloud_accounts.php');
			exit;
		}

		// An existing account keeps its provider; only new ones choose.
		if ($cloud_existing !== null) {
			$cloud_input['provider'] = $cloud_existing->provider->value;
		}

		$cloud_parsed = \Somethingwithproof\Cloud\AccountInput::parse(
			$cloud_input,
			$cloud_id,
			\Somethingwithproof\Cloud\Provider::released()
		);
		$cloud_errors = $cloud_parsed->errors;

		if ($cloud_parsed->account !== null && $cloud_accounts->nameTaken($cloud_parsed->account->name, $cloud_id)) {
			$cloud_errors['name'] = __('Name: another account already uses this name.', 'cloud');
		}

		if ($cloud_errors !== array() || $cloud_parsed->account === null) {
			top_header();
			$cloud_page->renderEdit($cloud_existing, $cloud_input, $cloud_errors);
			bottom_footer();
			exit;
		}

		$cloud_cleanup_failed = false;
		$cloud_saved          = $cloud_accounts->save($cloud_parsed->account, $cloud_user);
		$cloud_raw   = trim((string) get_nfilter_request_var('credential'));

		if ($cloud_saved > 0 && $cloud_parsed->account->credentialSource !== \Somethingwithproof\Cloud\CredentialSource::Vault) {
			// A credential kept in the vault would come back, unprompted, if the
			// account were switched back to it with the box left empty.
			if ($cloud_credentials->hasVaultCredential($cloud_saved)) {
				// The delete skips an account a concurrent save has moved
				// back to the vault, so only a credential that is gone is
				// recorded as removed.
				if (!$cloud_credentials->forgetUnlessVault($cloud_saved)) {
					$cloud_cleanup_failed = true;
				} elseif (!$cloud_credentials->hasVaultCredential($cloud_saved)) {
					$cloud_accounts->recordCredentialChange($cloud_saved, $cloud_user);
				}
			}
		} elseif ($cloud_saved > 0 && $cloud_raw !== '') {
			try {
				$cloud_credentials->storeVaultCredential($cloud_saved, new \Somethingwithproof\Cloud\Secret($cloud_raw));
				$cloud_accounts->recordCredentialChange($cloud_saved, $cloud_user);
			} catch (\Somethingwithproof\Cloud\CredentialError $cloud_error) {
				raise_message(
					'cloud_credential',
					__('The account was saved, but the credential was not stored: %s', $cloud_error->getMessage(), 'cloud'),
					MESSAGE_LEVEL_ERROR
				);
			}
		}

		unset($cloud_raw);

		if ($cloud_saved > 0 && $cloud_cleanup_failed) {
			raise_message(
				'cloud_saved',
				__('The account was saved, but the stored credential could not be removed.', 'cloud'),
				MESSAGE_LEVEL_ERROR
			);
		} elseif ($cloud_saved > 0) {
			raise_message('cloud_saved', __('The account was saved.', 'cloud'), MESSAGE_LEVEL_INFO);
		} else {
			raise_message('cloud_saved', __('The account could not be saved.', 'cloud'), MESSAGE_LEVEL_ERROR);
		}

		header('Location: cloud_accounts.php');
		exit;
	case 'delete':
		$cloud_id = (int) get_filter_request_var('id');

		// Deleting drops the stored credential and collected data for good,
		// so the first POST only asks; the confirmation form sends the second.
		if ((string) get_nfilter_request_var('confirm') !== '1') {
			$cloud_account = $cloud_accounts->find($cloud_id);

			if ($cloud_account !== null) {
				top_header();
				$cloud_page->renderDeleteConfirm($cloud_account);
				bottom_footer();
				exit;
			}

			header('Location: cloud_accounts.php');
			exit;
		}

		if ($cloud_accounts->delete($cloud_id, $cloud_user)) {
			raise_message(
				'cloud_deleted',
				__('The account and its collected data were deleted. Its Cacti devices and graphs were left in place.', 'cloud'),
				MESSAGE_LEVEL_INFO
			);
		}

		header('Location: cloud_accounts.php');
		exit;
	case 'verify':
		$cloud_account = $cloud_accounts->find((int) get_filter_request_var('id'));

		if ($cloud_account !== null) {
			$cloud_status = (new \Somethingwithproof\Cloud\Verifier(
				\Somethingwithproof\Cloud\Drivers::production(),
				$cloud_credentials,
				$cloud_accounts
			))->verify($cloud_account, $cloud_user);

			raise_message(
				'cloud_verify',
				$cloud_status->message(),
				$cloud_status === \Somethingwithproof\Cloud\VerifyStatus::Ok ? MESSAGE_LEVEL_INFO : MESSAGE_LEVEL_WARN
			);
		}

		header('Location: cloud_accounts.php');
		exit;
	case 'create_device':
		$cloud_account = $cloud_accounts->find((int) get_filter_request_var('id'));

		if ($cloud_account !== null) {
			$cloud_host = cloud_scope_devices()->create($cloud_account);

			raise_message(
				'cloud_device',
				$cloud_host > 0
					? __('The Cacti device for %s is ready.', $cloud_account->name, 'cloud')
					: __('The Cacti device was not created; see the Cacti log.', 'cloud'),
				$cloud_host > 0 ? MESSAGE_LEVEL_INFO : MESSAGE_LEVEL_ERROR
			);
		}

		header('Location: cloud_accounts.php');
		exit;
	case 'edit':
		$cloud_id = (int) get_filter_request_var('id');

		top_header();
		$cloud_page->renderEdit($cloud_id > 0 ? $cloud_accounts->find($cloud_id) : null);
		bottom_footer();
		exit;
	default:
		top_header();
		$cloud_page->renderList();
		bottom_footer();
}
