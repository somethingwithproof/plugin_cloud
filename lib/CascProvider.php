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
 * Configuration-as-Code (CasC) provider for Cloud accounts.
 *
 * Exports deterministic YAML or XML with credentials redacted to vault
 * references (vault:account:<name>), validates declarative manifests against
 * provider constraints while refusing raw secret fields, computes a
 * deterministic SHA-256 plan hash, and requires exact plan-hash confirmation
 * before applying mutations.
 */
final class CascProvider {
	public const SCHEMA_VERSION = 1;

	/** Field names that must never appear in a declarative CasC document. */
	private const FORBIDDEN_SECRET_FIELDS = [
		'secret',
		'secrets',
		'token',
		'access_token',
		'refresh_token',
		'password',
		'private_key',
		'access_key_id',
		'secret_access_key',
		'session_token',
		'client_secret',
		'credential',
		'credentials',
		'vault_key',
	];

	public function __construct(
		private readonly AccountRepository $accounts,
		private readonly Replication $replication,
		private readonly ?CredentialStore $credentials = null,
	) {
	}

	/**
	 * Build the provider descriptor for the casc_register_providers hook.
	 *
	 * @return array<string, mixed>
	 */
	public function descriptor(): array {
		return [
			'id'             => 'cloud',
			'name'           => 'Cloud Accounts',
			'schema_version' => self::SCHEMA_VERSION,
			'formats'        => ['yaml', 'xml'],
			'export'         => fn (string $format = 'yaml'): string => $this->export($format),
			'validate'       => fn (string $content, string $format = 'yaml'): array => $this->validate($content, $format),
			'plan'           => fn (string $content, string $format = 'yaml'): array => $this->plan($content, $format),
			'apply'          => fn (string $content, string $confirmHash, int $userId = 0, string $format = 'yaml'): array => $this->apply(
				$content,
				$confirmHash,
				$userId,
				$format
			),
		];
	}

	/**
	 * Export current accounts in deterministic order with credentials redacted
	 * into vault references.
	 */
	public function export(string $format = 'yaml'): string {
		$accounts = $this->accounts->all();
		usort($accounts, static fn (Account $a, Account $b): int => strcmp($a->name, $b->name));

		$items = [];

		foreach ($accounts as $account) {
			$scope = $account->scope;
			ksort($scope);

			$entry = [
				'name'              => $account->name,
				'provider'          => $account->provider->value,
				'poller_id'         => $account->pollerId,
				'credential_source' => $account->credentialSource->value,
				'credential_ref'    => self::credentialRef($account),
				'collect_interval'  => $account->collectInterval,
				'discover_interval' => $account->discoverInterval,
				'request_share'     => $account->requestShare,
				'monthly_ceiling'   => $account->monthlyCeiling,
				'enabled'           => $account->enabled(),
				'scope'             => $scope,
			];

			if ($account->credentialSource === CredentialSource::File) {
				$entry['credential_file'] = $account->credentialFile;
			}

			$items[] = $entry;
		}

		$fmt = strtolower(trim($format));

		if ($fmt === 'xml') {
			return self::renderXml($items);
		}

		if ($fmt !== 'yaml' && $fmt !== 'yml') {
			throw new \InvalidArgumentException('Unsupported export format: ' . $format);
		}

		return self::renderYaml($items);
	}

	/**
	 * Validate a YAML or XML manifest against Cloud account rules.
	 *
	 * @return array{valid: bool, errors: list<string>, accounts: list<Account>}
	 */
	public function validate(string $content, string $format = 'yaml'): array {
		$parsed = $this->parseManifest($content, $format);

		if ($parsed['errors'] !== []) {
			return ['valid' => false, 'errors' => $parsed['errors'], 'accounts' => []];
		}

		$errors   = [];
		$accounts = [];
		$seen     = [];
		$pollers  = $this->accounts->enabledPollerIds();
		$allowed  = $pollers !== [] ? $pollers : null;

		foreach ($parsed['items'] as $idx => $item) {
			$label = 'accounts[' . $idx . ']';

			foreach ($item as $key => $val) {
				if (in_array(strtolower((string) $key), self::FORBIDDEN_SECRET_FIELDS, true)) {
					$errors[] = $label . ': raw secret field "' . (string) $key . '" is forbidden; use vault:account:<name>';
				}
			}

			$name = is_string($item['name'] ?? null) ? trim($item['name']) : '';

			if ($name !== '') {
				if (isset($seen[$name])) {
					$errors[] = $label . ': duplicate account name "' . $name . '"';

					continue;
				}

				$seen[$name] = true;
			}

			$source = is_string($item['credential_source'] ?? null) ? trim($item['credential_source']) : '';
			$ref    = is_string($item['credential_ref'] ?? null) ? trim($item['credential_ref']) : '';

			if ($source === CredentialSource::Vault->value && $ref !== '' && !str_starts_with($ref, 'vault:account:')) {
				$errors[] = $label . ': credential_ref for vault accounts must use vault:account:<name>';
			}

			$enabledVal = self::parseEnabled($item['enabled'] ?? true);

			if ($enabledVal === null) {
				$errors[] = $label . ' (enabled): must be a boolean (true or false)';

				continue;
			}

			$input = [
				'provider'          => $item['provider'] ?? '',
				'name'              => $name,
				'poller_id'         => self::scalarString($item['poller_id'] ?? null, (string) Account::MAIN_POLLER),
				'credential_source' => $source,
				'credential_file'   => is_string($item['credential_file'] ?? null) ? $item['credential_file'] : '',
				'collect_interval'  => self::scalarString($item['collect_interval'] ?? null, (string) AccountInput::DEFAULT_COLLECT),
				'discover_interval' => self::scalarString($item['discover_interval'] ?? null, (string) AccountInput::DEFAULT_DISCOVER),
				'request_share'     => self::scalarString($item['request_share'] ?? null, (string) AccountInput::DEFAULT_SHARE),
				'monthly_ceiling'   => self::scalarString($item['monthly_ceiling'] ?? null, ''),
				'enabled'           => $enabledVal ? 'on' : '',
			];

			$scope = is_array($item['scope'] ?? null) ? $item['scope'] : [];

			foreach ($scope as $sKey => $sVal) {
				if (in_array(strtolower((string) $sKey), self::FORBIDDEN_SECRET_FIELDS, true)) {
					$errors[] = $label . ': raw secret field "' . (string) $sKey . '" is forbidden in scope';

					continue;
				}

				$input['scope_' . (string) $sKey] = is_scalar($sVal) ? (string) $sVal : '';
			}

			$result = AccountInput::parse($input, 0, Provider::released(), $allowed);

			if ($result->errors !== [] || $result->account === null) {
				foreach ($result->errors as $field => $msg) {
					$errors[] = $label . ' (' . $field . '): ' . $msg;
				}

				continue;
			}

			$accounts[] = $result->account;
		}

		if ($errors !== []) {
			return ['valid' => false, 'errors' => $errors, 'accounts' => []];
		}

		usort($accounts, static fn (Account $a, Account $b): int => strcmp($a->name, $b->name));

		return ['valid' => true, 'errors' => [], 'accounts' => $accounts];
	}

	/**
	 * Compute a deterministic diff and SHA-256 plan hash against current state.
	 *
	 * @return array{
	 *     valid: bool,
	 *     errors: list<string>,
	 *     plan_hash: string,
	 *     actions: list<array{action: string, name: string, provider: string, changes: list<string>}>
	 * }
	 */
	public function plan(string $content, string $format = 'yaml'): array {
		$validated = $this->validate($content, $format);

		if (!$validated['valid']) {
			return [
				'valid'     => false,
				'errors'    => $validated['errors'],
				'plan_hash' => '',
				'actions'   => [],
			];
		}

		$existingByName = [];

		foreach ($this->accounts->all() as $existing) {
			$existingByName[$existing->name] = $existing;
		}

		$desiredByName = [];

		foreach ($validated['accounts'] as $desired) {
			$desiredByName[$desired->name] = $desired;
		}

		$allNames = array_values(array_unique(array_merge(array_keys($existingByName), array_keys($desiredByName))));
		sort($allNames, SORT_STRING);

		$actions   = [];
		$canonical = [];

		foreach ($allNames as $name) {
			$before = $existingByName[$name] ?? null;
			$after  = $desiredByName[$name] ?? null;

			if ($before === null && $after !== null) {
				$changes   = array_keys($after->toRow());
				sort($changes, SORT_STRING);
				$actions[] = [
					'action'   => 'create',
					'name'     => $name,
					'provider' => $after->provider->value,
					'changes'  => $changes,
				];
				$canonical[] = ['action' => 'create', 'name' => $name, 'row' => $after->toRow()];
			} elseif ($before !== null && $after === null) {
				$actions[] = [
					'action'   => 'delete',
					'name'     => $name,
					'provider' => $before->provider->value,
					'changes'  => [],
				];
				$canonical[] = ['action' => 'delete', 'name' => $name, 'id' => $before->id];
			} elseif ($before !== null && $after !== null) {
				if ($before->provider !== $after->provider) {
					return [
						'valid'     => false,
						'errors'    => ['account "' . $name . '": cannot change provider from ' . $before->provider->value . ' to ' . $after->provider->value],
						'plan_hash' => '',
						'actions'   => [],
					];
				}

				$changed = $after->changedFields($before);

				if ($changed !== []) {
					sort($changed, SORT_STRING);
					$actions[] = [
						'action'   => 'update',
						'name'     => $name,
						'provider' => $after->provider->value,
						'changes'  => $changed,
					];
					$canonical[] = ['action' => 'update', 'name' => $name, 'id' => $before->id, 'row' => $after->toRow()];
				}
			}
		}

		try {
			$planHash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
		} catch (\JsonException) {
			$planHash = hash('sha256', serialize($canonical));
		}

		return [
			'valid'     => true,
			'errors'    => [],
			'plan_hash' => $planHash,
			'actions'   => $actions,
		];
	}

	/**
	 * Apply validated manifest changes only when $confirmHash matches the exact
	 * SHA-256 plan hash computed from live state.
	 *
	 * @return array{applied: bool, plan_hash: string, created: int, updated: int, deleted: int, errors: list<string>}
	 */
	public function apply(string $content, string $confirmHash, int $userId = 0, string $format = 'yaml'): array {
		$plan = $this->plan($content, $format);

		if (!$plan['valid']) {
			return [
				'applied'   => false,
				'plan_hash' => '',
				'created'   => 0,
				'updated'   => 0,
				'deleted'   => 0,
				'errors'    => $plan['errors'],
			];
		}

		$expected = $plan['plan_hash'];

		if ($confirmHash === '' || !hash_equals($expected, trim($confirmHash))) {
			return [
				'applied'   => false,
				'plan_hash' => $expected,
				'created'   => 0,
				'updated'   => 0,
				'deleted'   => 0,
				'errors'    => ['Confirmation hash mismatch; run plan and pass --confirm=' . $expected],
			];
		}

		$validated      = $this->validate($content, $format);
		$existingByName = [];

		foreach ($this->accounts->all() as $existing) {
			$existingByName[$existing->name] = $existing;
		}

		$desiredByName = [];

		foreach ($validated['accounts'] as $desired) {
			$desiredByName[$desired->name] = $desired;
		}

		$created = 0;
		$updated = 0;
		$deleted = 0;
		$errors  = [];

		foreach ($plan['actions'] as $step) {
			$name = $step['name'];

			if ($step['action'] === 'create' && isset($desiredByName[$name])) {
				if ($this->accounts->save($desiredByName[$name], $userId) > 0) {
					$created++;
				} else {
					$errors[] = 'Failed to create account "' . $name . '"';
				}
			} elseif ($step['action'] === 'update' && isset($existingByName[$name], $desiredByName[$name])) {
				$target = $desiredByName[$name];
				$merged = new Account(
					id: $existingByName[$name]->id,
					provider: $target->provider,
					name: $target->name,
					pollerId: $target->pollerId,
					scope: $target->scope,
					credentialSource: $target->credentialSource,
					credentialFile: $target->credentialFile,
					collectInterval: $target->collectInterval,
					discoverInterval: $target->discoverInterval,
					requestShare: $target->requestShare,
					monthlyCeiling: $target->monthlyCeiling,
					adminState: $target->adminState,
					verifyStatus: $existingByName[$name]->verifyStatus,
					verifiedAt: $existingByName[$name]->verifiedAt,
				);

				if ($this->accounts->save($merged, $userId) > 0) {
					if (
						$this->credentials !== null
						&& $merged->credentialSource !== CredentialSource::Vault
						&& $this->credentials->hasVaultCredential($merged->id)
					) {
						$this->credentials->forgetUnlessVault($merged->id);
					}

					$updated++;
				} else {
					$errors[] = 'Failed to update account "' . $name . '"';
				}
			} elseif ($step['action'] === 'delete' && isset($existingByName[$name])) {
				if ($this->accounts->delete($existingByName[$name]->id, $userId)) {
					$deleted++;
				} else {
					$errors[] = 'Failed to delete account "' . $name . '"';
				}
			}
		}

		return [
			'applied'   => $errors === [],
			'plan_hash' => $expected,
			'created'   => $created,
			'updated'   => $updated,
			'deleted'   => $deleted,
			'errors'    => $errors,
		];
	}

	/**
	 * Read-only health summary across accounts, safe for CLI output.
	 *
	 * @return array<string, mixed>
	 */
	public function healthSummary(?int $pollerId = null): array {
		return $this->replication->remoteHealth($pollerId, null);
	}

	private static function parseEnabled(mixed $value): ?bool {
		if (is_bool($value)) {
			return $value;
		}

		if (is_string($value)) {
			$lower = strtolower(trim($value));

			if (in_array($lower, ['true', '1', 'on', 'yes'], true)) {
				return true;
			}

			if (in_array($lower, ['false', '0', 'off', 'no'], true)) {
				return false;
			}
		}

		return null;
	}

	private static function scalarString(mixed $value, string $default): string {
		if (!is_scalar($value)) {
			return $default;
		}

		$str = (string) $value;

		return $str === '' ? $default : $str;
	}

	private static function credentialRef(Account $account): string {
		return match ($account->credentialSource) {
			CredentialSource::Vault    => 'vault:account:' . $account->name,
			CredentialSource::File     => 'file:account:' . $account->name,
			CredentialSource::Instance => 'instance:poller:' . $account->pollerId,
		};
	}

	/**
	 * @param list<array<string, mixed>> $items
	 */
	private static function renderYaml(array $items): string {
		$lines = [
			'schema_version: ' . self::SCHEMA_VERSION,
			'accounts:' . ($items === [] ? ' []' : ''),
		];

		foreach ($items as $item) {
			$lines[] = '  - name: ' . self::yamlScalar($item['name']);
			$lines[] = '    provider: ' . self::yamlScalar($item['provider']);
			$lines[] = '    poller_id: ' . (int) (is_numeric($item['poller_id']) ? $item['poller_id'] : 1);
			$lines[] = '    credential_source: ' . self::yamlScalar($item['credential_source']);
			$lines[] = '    credential_ref: ' . self::yamlScalar($item['credential_ref']);

			if (isset($item['credential_file']) && is_string($item['credential_file']) && $item['credential_file'] !== '') {
				$lines[] = '    credential_file: ' . self::yamlScalar($item['credential_file']);
			}

			$lines[] = '    collect_interval: ' . (int) (is_numeric($item['collect_interval']) ? $item['collect_interval'] : 300);
			$lines[] = '    discover_interval: ' . (int) (is_numeric($item['discover_interval']) ? $item['discover_interval'] : 3600);
			$lines[] = '    request_share: ' . (int) (is_numeric($item['request_share']) ? $item['request_share'] : 50);
			$lines[] = '    monthly_ceiling: ' . ($item['monthly_ceiling'] === null ? 'null' : (string) (is_numeric($item['monthly_ceiling']) ? $item['monthly_ceiling'] : 0));
			$lines[] = '    enabled: ' . ($item['enabled'] ? 'true' : 'false');

			$scope = is_array($item['scope'] ?? null) ? $item['scope'] : [];

			if ($scope === []) {
				$lines[] = '    scope: {}';
			} else {
				$lines[] = '    scope:';

				foreach ($scope as $k => $v) {
					$lines[] = '      ' . (string) $k . ': ' . self::yamlScalar($v);
				}
			}
		}

		return implode("\n", $lines) . "\n";
	}

	private static function yamlScalar(mixed $value): string {
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}

		if ($value === null) {
			return 'null';
		}

		$str = is_scalar($value) ? (string) $value : '';

		if ($str === '' || preg_match('/[^A-Za-z0-9._:@\/-]/', $str) === 1 || in_array(strtolower($str), ['true', 'false', 'null', 'yes', 'no'], true)) {
			return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $str) . '"';
		}

		return $str;
	}

	/**
	 * @param list<array<string, mixed>> $items
	 */
	private static function renderXml(array $items): string {
		$xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><cloud_config/>');
		$xml->addAttribute('schema_version', (string) self::SCHEMA_VERSION);
		$accountsEl = $xml->addChild('accounts');

		foreach ($items as $item) {
			$acc = $accountsEl->addChild('account');
			$acc->addChild('name', self::xmlEscape((string) (is_scalar($item['name']) ? $item['name'] : '')));
			$acc->addChild('provider', self::xmlEscape((string) (is_scalar($item['provider']) ? $item['provider'] : '')));
			$acc->addChild('poller_id', (string) (is_numeric($item['poller_id']) ? (int) $item['poller_id'] : 1));
			$acc->addChild('credential_source', self::xmlEscape((string) (is_scalar($item['credential_source']) ? $item['credential_source'] : '')));
			$acc->addChild('credential_ref', self::xmlEscape((string) (is_scalar($item['credential_ref']) ? $item['credential_ref'] : '')));

			if (isset($item['credential_file']) && is_string($item['credential_file']) && $item['credential_file'] !== '') {
				$acc->addChild('credential_file', self::xmlEscape($item['credential_file']));
			}

			$acc->addChild('collect_interval', (string) (is_numeric($item['collect_interval']) ? (int) $item['collect_interval'] : 300));
			$acc->addChild('discover_interval', (string) (is_numeric($item['discover_interval']) ? (int) $item['discover_interval'] : 3600));
			$acc->addChild('request_share', (string) (is_numeric($item['request_share']) ? (int) $item['request_share'] : 50));

			if ($item['monthly_ceiling'] !== null && is_numeric($item['monthly_ceiling'])) {
				$acc->addChild('monthly_ceiling', (string) $item['monthly_ceiling']);
			}

			$acc->addChild('enabled', $item['enabled'] ? 'true' : 'false');
			$scopeEl = $acc->addChild('scope');
			$scope   = is_array($item['scope'] ?? null) ? $item['scope'] : [];

			foreach ($scope as $k => $v) {
				$scopeEl->addChild((string) $k, self::xmlEscape(is_scalar($v) ? (string) $v : ''));
			}
		}

		$dom                     = new \DOMDocument('1.0', 'UTF-8');
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput       = true;
		$raw                     = $xml->asXML();

		if (is_string($raw) && $dom->loadXML($raw, LIBXML_NONET)) {
			$formatted = $dom->saveXML();

			if (is_string($formatted)) {
				return $formatted;
			}
		}

		return is_string($raw) ? $raw : '';
	}

	private static function xmlEscape(string $value): string {
		return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}

	/**
	 * @return array{items: list<array<string, mixed>>, errors: list<string>}
	 */
	private function parseManifest(string $content, string $format): array {
		$fmt = strtolower(trim($format));

		if ($fmt === 'xml') {
			return self::parseXml($content);
		}

		if ($fmt === 'yaml' || $fmt === 'yml') {
			return self::parseYaml($content);
		}

		return ['items' => [], 'errors' => ['Unsupported format: ' . $format]];
	}

	/**
	 * @return array{items: list<array<string, mixed>>, errors: list<string>}
	 */
	private static function parseXml(string $content): array {
		if (trim($content) === '') {
			return ['items' => [], 'errors' => ['Empty XML manifest']];
		}

		$prev = libxml_use_internal_errors(true);

		try {
			$xml = simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET);

			if (!$xml instanceof \SimpleXMLElement || $xml->getName() !== 'cloud_config') {
				return ['items' => [], 'errors' => ['Invalid XML manifest: root element must be <cloud_config>']];
			}

			if (!isset($xml->accounts)) {
				return ['items' => [], 'errors' => ['Invalid XML manifest: missing required <accounts> element']];
			}

			$items = [];

			if (isset($xml->accounts->account)) {
				foreach ($xml->accounts->account as $acc) {
					$item = [];

					foreach ($acc->children() as $child) {
						$key = $child->getName();

						if ($key === 'scope') {
							$scope = [];

							foreach ($child->children() as $sChild) {
								$scope[$sChild->getName()] = trim((string) $sChild);
							}

							$item['scope'] = $scope;
						} else {
							$item[$key] = trim((string) $child);
						}
					}

					$items[] = $item;
				}
			}

			return ['items' => $items, 'errors' => []];
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($prev);
		}
	}

	/**
	 * Minimal deterministic YAML parser for Cloud CasC manifests.
	 *
	 * @return array{items: list<array<string, mixed>>, errors: list<string>}
	 */
	private static function parseYaml(string $content): array {
		if (trim($content) === '') {
			return ['items' => [], 'errors' => ['Empty YAML manifest']];
		}

		$lines         = preg_split('/\r?\n/', $content);
		$lines         = is_array($lines) ? $lines : [];
		$inAccounts    = false;
		$explicitEmpty = false;
		$inScope       = false;
		$items         = [];
		$current       = null;
		$errors        = [];

		foreach ($lines as $lineNo => $rawLine) {
			$trimmed = trim($rawLine);

			if ($trimmed === '' || str_starts_with($trimmed, '#')) {
				continue;
			}

			$indent = strlen($rawLine) - strlen(ltrim($rawLine, ' '));

			if (str_contains($rawLine, "\t")) {
				$errors[] = 'Line ' . ($lineNo + 1) . ': tabs are not allowed in YAML indentation';

				continue;
			}

			if ($indent === 0) {
				if ($current !== null) {
					$items[] = $current;
					$current = null;
				}

				$inScope = false;

				if ($trimmed === 'accounts: []') {
					$inAccounts    = true;
					$explicitEmpty = true;

					continue;
				}

				if ($trimmed === 'accounts:') {
					$inAccounts    = true;
					$explicitEmpty = false;

					continue;
				}

				if (preg_match('/^schema_version:\s*(\d+)$/', $trimmed, $m) === 1) {
					if ((int) $m[1] !== self::SCHEMA_VERSION) {
						$errors[] = 'Unsupported schema_version: ' . $m[1];
					}

					continue;
				}

				$inAccounts = false;
				$errors[]   = 'Line ' . ($lineNo + 1) . ': unexpected top-level entry "' . $trimmed . '"';

				continue;
			}

			if (!$inAccounts) {
				$errors[] = 'Line ' . ($lineNo + 1) . ': unexpected indented line outside accounts';

				continue;
			}

			if (preg_match('/^  -\s+([A-Za-z0-9_]+):\s*(.*)$/', $rawLine, $m) === 1) {
				if ($current !== null) {
					$items[] = $current;
				}

				$current          = ['scope' => []];
				$inScope          = false;
				$current[$m[1]]   = self::parseYamlValue($m[2]);

				continue;
			}

			if ($current === null) {
				$errors[] = 'Line ' . ($lineNo + 1) . ': account property before list item';

				continue;
			}

			if ($indent === 4 && preg_match('/^    ([A-Za-z0-9_]+):\s*(.*)$/', $rawLine, $m) === 1) {
				$key = $m[1];
				$val = trim($m[2]);

				if ($key === 'scope') {
					$current['scope'] = [];
					$inScope          = $val === '';

					if ($val !== '' && $val !== '{}') {
						$errors[] = 'Line ' . ($lineNo + 1) . ': scope must be a mapping or {}';
					}

					continue;
				}

				$inScope        = false;
				$current[$key]  = self::parseYamlValue($m[2]);

				continue;
			}

			if ($indent === 6 && $inScope && preg_match('/^      ([A-Za-z0-9_]+):\s*(.*)$/', $rawLine, $m) === 1) {
				if (!is_array($current['scope'])) {
					$current['scope'] = [];
				}

				$current['scope'][$m[1]] = self::parseYamlValue($m[2]);

				continue;
			}

			$errors[] = 'Line ' . ($lineNo + 1) . ': malformed YAML entry';
		}

		if ($current !== null) {
			$items[] = $current;
		}

		if (!$inAccounts && $items === [] && $errors === []) {
			$errors[] = 'Missing required top-level "accounts:" section';
		} elseif ($inAccounts && !$explicitEmpty && $items === [] && $errors === []) {
			$errors[] = 'Empty "accounts:" block must be written explicitly as "accounts: []"';
		}

		return ['items' => $items, 'errors' => $errors];
	}

	private static function parseYamlValue(string $raw): mixed {
		$v = trim($raw);

		if ($v === 'null' || $v === '~') {
			return null;
		}

		if ($v === 'true') {
			return true;
		}

		if ($v === 'false') {
			return false;
		}

		if (
			strlen($v) >= 2
			&& (($v[0] === '"' && $v[strlen($v) - 1] === '"') || ($v[0] === "'" && $v[strlen($v) - 1] === "'"))
		) {
			$inner = substr($v, 1, -1);

			return $v[0] === '"' ? str_replace(['\\"', '\\\\'], ['"', '\\'], $inner) : $inner;
		}

		return $v;
	}
}
