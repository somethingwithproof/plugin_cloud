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
 * Operator and CasC command-line interface (cli/cloud.php).
 *
 * Supports:
 *   export   --format=yaml|xml
 *   validate --file=<path> [--format=yaml|xml]
 *   plan     --file=<path> [--format=yaml|xml]
 *   apply    --file=<path> --confirm=<PLAN_HASH> [--format=yaml|xml]
 *   health   --format=text|json [--poller=<id>]
 */
final class Cli {
	public function __construct(private readonly CascProvider $casc) {
	}

	/**
	 * @param list<string>              $argv     command arguments excluding script name
	 * @param \Closure(string): ?string $readFile reads file contents or returns null on failure
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	public function run(array $argv, \Closure $readFile): array {
		if ($argv === [] || in_array($argv[0], ['-h', '--help', 'help'], true)) {
			return ['exitCode' => 0, 'stdout' => self::usage(), 'stderr' => ''];
		}

		$command = array_shift($argv);
		$opts    = $this->parseOptions($argv);

		if ($opts['error'] !== null) {
			return ['exitCode' => 2, 'stdout' => '', 'stderr' => 'ERROR: ' . $opts['error'] . "\n" . self::usage()];
		}

		return match ($command) {
			'export'   => $this->cmdExport($opts['values']),
			'validate' => $this->cmdValidate($opts['values'], $opts['positional'], $readFile),
			'plan'     => $this->cmdPlan($opts['values'], $opts['positional'], $readFile),
			'apply'    => $this->cmdApply($opts['values'], $opts['positional'], $readFile),
			'health'   => $this->cmdHealth($opts['values']),
			default    => [
				'exitCode' => 2,
				'stdout'   => '',
				'stderr'   => 'ERROR: Unknown command "' . $command . '"' . "\n" . self::usage(),
			],
		};
	}

	/**
	 * @param array<string, string> $opts
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdExport(array $opts): array {
		$format = $opts['format'] ?? 'yaml';

		try {
			return ['exitCode' => 0, 'stdout' => $this->casc->export($format), 'stderr' => ''];
		} catch (\InvalidArgumentException $e) {
			return ['exitCode' => 2, 'stdout' => '', 'stderr' => 'ERROR: ' . $e->getMessage() . "\n"];
		}
	}

	/**
	 * @param array<string, string>     $opts
	 * @param list<string>              $positional
	 * @param \Closure(string): ?string $readFile
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdValidate(array $opts, array $positional, \Closure $readFile): array {
		[$content, $format, $err] = $this->loadInput($opts, $positional, $readFile);

		if ($err !== null || $content === null) {
			return ['exitCode' => 2, 'stdout' => '', 'stderr' => 'ERROR: ' . ($err ?? 'unreadable input') . "\n"];
		}

		$res = $this->casc->validate($content, $format);

		if (!$res['valid']) {
			return [
				'exitCode' => 1,
				'stdout'   => '',
				'stderr'   => "Validation failed:\n- " . implode("\n- ", $res['errors']) . "\n",
			];
		}

		return [
			'exitCode' => 0,
			'stdout'   => sprintf("VALID: %d account(s) checked (%s)\n", count($res['accounts']), $format),
			'stderr'   => '',
		];
	}

	/**
	 * @param array<string, string>     $opts
	 * @param list<string>              $positional
	 * @param \Closure(string): ?string $readFile
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdPlan(array $opts, array $positional, \Closure $readFile): array {
		[$content, $format, $err] = $this->loadInput($opts, $positional, $readFile);

		if ($err !== null || $content === null) {
			return ['exitCode' => 2, 'stdout' => '', 'stderr' => 'ERROR: ' . ($err ?? 'unreadable input') . "\n"];
		}

		$plan = $this->casc->plan($content, $format);

		if (!$plan['valid']) {
			return [
				'exitCode' => 1,
				'stdout'   => '',
				'stderr'   => "Plan failed:\n- " . implode("\n- ", $plan['errors']) . "\n",
			];
		}

		$lines = [];

		if ($plan['actions'] === []) {
			$lines[] = 'No changes required.';
		} else {
			foreach ($plan['actions'] as $step) {
				$suffix  = $step['changes'] !== [] ? ' [' . implode(', ', $step['changes']) . ']' : '';
				$lines[] = strtoupper($step['action']) . ' ' . $step['name'] . ' (' . $step['provider'] . ')' . $suffix;
			}
		}

		$lines[] = 'PLAN_HASH=' . $plan['plan_hash'];

		return ['exitCode' => 0, 'stdout' => implode("\n", $lines) . "\n", 'stderr' => ''];
	}

	/**
	 * @param array<string, string>     $opts
	 * @param list<string>              $positional
	 * @param \Closure(string): ?string $readFile
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdApply(array $opts, array $positional, \Closure $readFile): array {
		[$content, $format, $err] = $this->loadInput($opts, $positional, $readFile);

		if ($err !== null || $content === null) {
			return ['exitCode' => 2, 'stdout' => '', 'stderr' => 'ERROR: ' . ($err ?? 'unreadable input') . "\n"];
		}

		$confirm = $opts['confirm'] ?? '';

		if ($confirm === '') {
			$plan = $this->casc->plan($content, $format);
			$hint = $plan['valid'] ? ' Pass --confirm=' . $plan['plan_hash'] : '';

			return [
				'exitCode' => 2,
				'stdout'   => '',
				'stderr'   => 'ERROR: apply requires --confirm=<PLAN_HASH>.' . $hint . "\n",
			];
		}

		$result = $this->casc->apply($content, $confirm, 0, $format);

		if (!$result['applied']) {
			$partial = ($result['created'] + $result['updated'] + $result['deleted']) > 0
				? sprintf(
					"Partial progress before failure (created=%d, updated=%d, deleted=%d)\n",
					$result['created'],
					$result['updated'],
					$result['deleted']
				)
				: '';

			return [
				'exitCode' => 1,
				'stdout'   => $partial,
				'stderr'   => "Apply refused:\n- " . implode("\n- ", $result['errors']) . "\n",
			];
		}

		return [
			'exitCode' => 0,
			'stdout'   => sprintf(
				"APPLIED plan %s (created=%d, updated=%d, deleted=%d)\n",
				$result['plan_hash'],
				$result['created'],
				$result['updated'],
				$result['deleted']
			),
			'stderr'   => '',
		];
	}

	/**
	 * @param array<string, string> $opts
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdHealth(array $opts): array {
		$format   = strtolower($opts['format'] ?? 'text');
		$pollerId = isset($opts['poller']) && ctype_digit($opts['poller']) && (int) $opts['poller'] > 0
			? (int) $opts['poller']
			: null;

		if (isset($opts['poller']) && $pollerId === null) {
			return ['exitCode' => 2, 'stdout' => '', 'stderr' => "ERROR: --poller must be a positive integer\n"];
		}

		if ($format !== 'text' && $format !== 'json') {
			return ['exitCode' => 2, 'stdout' => '', 'stderr' => "ERROR: --format must be text or json\n"];
		}

		$summary = $this->casc->healthSummary($pollerId);

		if ($format === 'json') {
			try {
				$json = json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			} catch (\JsonException) {
				return ['exitCode' => 1, 'stdout' => '', 'stderr' => "ERROR: Failed to encode health JSON\n"];
			}

			return ['exitCode' => 0, 'stdout' => $json . "\n", 'stderr' => ''];
		}

		$accounts = is_array($summary['accounts'] ?? null) ? $summary['accounts'] : [];

		if ($accounts === []) {
			return ['exitCode' => 0, 'stdout' => "No cloud accounts found.\n", 'stderr' => ''];
		}

		$lines = ["NAME\tPROVIDER\tPOLLER\tSTATE\tCOLLECTION\tSERIES\tREQUESTS"];

		foreach ($accounts as $row) {
			if (!is_array($row)) {
				continue;
			}

			$lines[] = sprintf(
				"%s\t%s\t%d\t%s\t%s\t%d/%d\t%d",
				is_string($row['name'] ?? null) ? $row['name'] : '',
				is_string($row['provider'] ?? null) ? $row['provider'] : '',
				is_int($row['poller_id'] ?? null) ? $row['poller_id'] : 1,
				($row['enabled'] ?? false) ? 'enabled' : 'disabled',
				is_string($row['collection'] ?? null) ? $row['collection'] : 'never',
				is_int($row['series_read'] ?? null) ? $row['series_read'] : 0,
				is_int($row['series_total'] ?? null) ? $row['series_total'] : 0,
				is_int($row['requests'] ?? null) ? $row['requests'] : 0
			);
		}

		return ['exitCode' => 0, 'stdout' => implode("\n", $lines) . "\n", 'stderr' => ''];
	}

	/**
	 * @param array<string, string>     $opts
	 * @param list<string>              $positional
	 * @param \Closure(string): ?string $readFile
	 * @return array{?string, string, ?string}
	 */
	private function loadInput(array $opts, array $positional, \Closure $readFile): array {
		$path = $opts['file'] ?? ($positional[0] ?? '');

		if ($path === '') {
			return [null, 'yaml', 'Missing --file=<path>'];
		}

		$content = $readFile($path);

		if ($content === null) {
			return [null, 'yaml', 'Unable to read file: ' . $path];
		}

		$format = $opts['format'] ?? (str_ends_with(strtolower($path), '.xml') ? 'xml' : 'yaml');

		return [$content, $format, null];
	}

	/**
	 * @param list<string> $args
	 * @return array{values: array<string, string>, positional: list<string>, error: ?string}
	 */
	private function parseOptions(array $args): array {
		$values     = [];
		$positional = [];

		foreach ($args as $arg) {
			if (str_starts_with($arg, '--')) {
				$eq = strpos($arg, '=');

				if ($eq === false) {
					return ['values' => [], 'positional' => [], 'error' => 'Option must use --key=value form: ' . $arg];
				}

				$key = substr($arg, 2, $eq - 2);
				$val = substr($arg, $eq + 1);

				if (!in_array($key, ['format', 'file', 'confirm', 'poller'], true)) {
					return ['values' => [], 'positional' => [], 'error' => 'Unknown option: --' . $key];
				}

				$values[$key] = $val;
			} else {
				$positional[] = $arg;
			}
		}

		return ['values' => $values, 'positional' => $positional, 'error' => null];
	}

	private static function usage(): string {
		return "Usage: php plugins/cloud/cli/cloud.php <command> [options]\n"
			. "Commands:\n"
			. "  export   [--format=yaml|xml]\n"
			. "  validate --file=<path> [--format=yaml|xml]\n"
			. "  plan     --file=<path> [--format=yaml|xml]\n"
			. "  apply    --file=<path> --confirm=<PLAN_HASH> [--format=yaml|xml]\n"
			. "  health   [--format=text|json] [--poller=<id>]\n";
	}
}
