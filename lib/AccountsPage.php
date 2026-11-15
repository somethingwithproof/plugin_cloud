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
 * The Cloud Accounts page. Every provider or user string is escaped where
 * it is printed; no stored credential is ever printed, only whether one is
 * stored (REQ-CRED-004). Every action that changes state is a POST form, and
 * csrf-magic adds its token to each.
 */
final class AccountsPage {
	public const FILE = 'cloud_accounts.php';

	public function __construct(
		private readonly AccountRepository $accounts,
		private readonly CredentialStore $credentials,
	) {
	}

	public function renderList(): void {
		html_start_box(__('Cloud Accounts', 'cloud'), '100%', '', '3', 'center', self::FILE . '?action=edit');

		print '<tr class="tableHeader"><th>' . implode('</th><th>', array_map('html_escape', [
			__('Name', 'cloud'), __('Provider', 'cloud'), __('Credential', 'cloud'), __('State', 'cloud'), __('Verify', 'cloud'), __('Actions', 'cloud'),
		])) . '</th></tr>';

		$accounts = $this->accounts->all();

		if ($accounts === []) {
			print '<tr><td colspan="6"><em>' . html_escape(__('No cloud accounts yet.', 'cloud')) . '</em></td></tr>';
		}

		foreach ($accounts as $account) {
			print '<tr class="tableRow">'
				. '<td><a class="linkEditMain" href="' . html_escape(self::FILE . '?action=edit&id=' . $account->id) . '">' . html_escape($account->name) . '</a></td>'
				. '<td>' . html_escape($account->provider->label()) . '</td>'
				. '<td>' . html_escape($this->credentialSummary($account)) . '</td>'
				. '<td>' . html_escape($account->enabled() ? __('Enabled', 'cloud') : __('Disabled', 'cloud')) . '</td>'
				. '<td title="' . html_escape($account->verifyStatus->message()) . '">' . html_escape($account->verifyStatus->message()) . '</td>'
				. '<td>' . $this->actionForm($account->id, 'verify', __('Verify', 'cloud')) . ' ' . $this->actionForm($account->id, 'delete', __('Delete', 'cloud')) . '</td>'
				. '</tr>';
		}

		html_end_box();
	}

	/**
	 * @param array<string, mixed>  $input  the rejected form values, to show again
	 * @param array<string, string> $errors field => message
	 */
	public function renderEdit(?Account $account, array $input = [], array $errors = []): void {
		$value = static function (string $field, string $current) use ($input): string {
			return array_key_exists($field, $input) ? Row::string($input, $field) : $current;
		};

		$provider = Provider::tryFrom($value('provider', $account?->provider->value ?? Provider::DigitalOcean->value)) ?? Provider::DigitalOcean;
		$offered  = $account === null ? Provider::released() : [$account->provider];

		print '<form method="post" action="' . html_escape(self::FILE) . '">';
		html_start_box($account === null ? __('New Cloud Account', 'cloud') : __('Cloud Account: %s', $account->name, 'cloud'), '100%', '', '3', 'center', '');

		foreach ($errors as $message) {
			print '<tr><td colspan="2" class="textError">' . html_escape($message) . '</td></tr>';
		}

		$this->select('provider', __('Provider', 'cloud'), array_combine(array_map(static fn (Provider $p): string => $p->value, $offered), array_map(static fn (Provider $p): string => $p->label(), $offered)), $provider->value);
		$this->text('name', __('Name', 'cloud'), $value('name', $account->name ?? ''), 100);

		foreach ($provider->scopeFields() as $field => $rule) {
			$this->text('scope_' . $field, AccountInput::scopeLabel($field), $value('scope_' . $field, $account?->scope[$field] ?? ''), 512);
		}

		$sources = $provider->credentialSources();
		$this->select('credential_source', __('Credential Source', 'cloud'), array_combine(array_map(static fn (CredentialSource $s): string => $s->value, $sources), array_map(static fn (CredentialSource $s): string => $s->label(), $sources)), $value('credential_source', $account?->credentialSource->value ?? CredentialSource::Vault->value));
		$this->text('credential_file', __('Credential File', 'cloud'), $value('credential_file', $account->credentialFile ?? ''), 512);

		// The credential is write-only: the box is always empty, and leaving it
		// empty keeps the stored value.
		print '<tr><td>' . html_escape(__('Credential', 'cloud')) . '<br><small>' . html_escape($account !== null && $this->credentials->hasVaultCredential($account->id) ? __('A credential is stored. Leave blank to keep it.', 'cloud') : __('Paste the token or key to store in the vault.', 'cloud')) . '</small></td>'
			. '<td><textarea name="credential" rows="4" cols="60" autocomplete="off" spellcheck="false"></textarea></td></tr>';

		$this->text('collect_interval', __('Collection Interval', 'cloud'), $value('collect_interval', (string) ($account->collectInterval ?? AccountInput::DEFAULT_COLLECT)), 5);
		$this->text('discover_interval', __('Discovery Interval', 'cloud'), $value('discover_interval', (string) ($account->discoverInterval ?? AccountInput::DEFAULT_DISCOVER)), 5);
		$this->text('request_share', __('Request Share', 'cloud'), $value('request_share', (string) ($account->requestShare ?? AccountInput::DEFAULT_SHARE)), 3);
		$this->text('monthly_ceiling', __('Monthly Ceiling', 'cloud'), $value('monthly_ceiling', $account?->monthlyCeiling === null ? '' : (string) $account->monthlyCeiling), 12);

		$enabled = array_key_exists('enabled', $input) ? Row::bool($input, 'enabled') : ($account?->enabled() ?? true);
		print '<tr><td>' . html_escape(__('Enabled', 'cloud')) . '</td><td><input type="checkbox" name="enabled" value="on"' . ($enabled ? ' checked' : '') . '></td></tr>';

		html_end_box();

		print '<input type="hidden" name="action" value="save">'
			. '<input type="hidden" name="poller_id" value="' . Account::MAIN_POLLER . '">'
			. '<input type="hidden" name="id" value="' . ($account->id ?? 0) . '">'
			. '<input type="submit" class="ui-button" value="' . html_escape(__('Save', 'cloud')) . '">'
			. '</form>';
	}

	private function credentialSummary(Account $account): string {
		return match ($account->credentialSource) {
			CredentialSource::Vault    => $this->credentials->hasVaultCredential($account->id) ? __('Stored in the vault', 'cloud') : __('None stored', 'cloud'),
			CredentialSource::File     => __('File on the poller', 'cloud'),
			CredentialSource::Instance => __('Instance identity', 'cloud'),
		};
	}

	private function actionForm(int $id, string $action, string $label): string {
		return '<form method="post" action="' . html_escape(self::FILE) . '" style="display:inline">'
			. '<input type="hidden" name="action" value="' . html_escape($action) . '">'
			. '<input type="hidden" name="id" value="' . $id . '">'
			. '<input type="submit" class="ui-button" value="' . html_escape($label) . '">'
			. '</form>';
	}

	private function text(string $name, string $label, string $value, int $length): void {
		print '<tr><td><label for="' . html_escape($name) . '">' . html_escape($label) . '</label></td>'
			. '<td><input type="text" id="' . html_escape($name) . '" name="' . html_escape($name) . '" value="' . html_escape($value) . '" maxlength="' . $length . '" size="' . min(60, $length) . '"></td></tr>';
	}

	/**
	 * @param array<string, string> $options
	 */
	private function select(string $name, string $label, array $options, string $current): void {
		print '<tr><td><label for="' . html_escape($name) . '">' . html_escape($label) . '</label></td><td><select id="' . html_escape($name) . '" name="' . html_escape($name) . '">';

		foreach ($options as $value => $text) {
			print '<option value="' . html_escape($value) . '"' . ($value === $current ? ' selected' : '') . '>' . html_escape($text) . '</option>';
		}

		print '</select></td></tr>';
	}
}
