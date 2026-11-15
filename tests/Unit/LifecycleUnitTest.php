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

use Somethingwithproof\Cloud\Pages;
use Somethingwithproof\Cloud\Schema;
use Somethingwithproof\Cloud\Settings;

it('applies documented defaults to settings that were never saved', function () {
	$settings = Settings::fromRow([]);

	expect($settings->vaultKeyFile)->toBe(Settings::DEFAULT_KEY_FILE)
		->and($settings->collectorBudget)->toBe(Settings::DEFAULT_BUDGET)
		->and($settings->keepTablesOnUninstall)->toBeTrue();
});

it('drops tables on uninstall only after the box was cleared and saved', function () {
	expect(Settings::fromRow(['cloud_uninstall_keep_tables' => ''])->keepTablesOnUninstall)->toBeFalse()
		->and(Settings::fromRow(['cloud_uninstall_keep_tables' => null])->keepTablesOnUninstall)->toBeFalse()
		->and(Settings::fromRow(['cloud_uninstall_keep_tables' => 'on'])->keepTablesOnUninstall)->toBeTrue();
});

it('bounds the collector budget', function (mixed $stored, int $expected) {
	expect(Settings::fromRow(['cloud_collector_budget' => $stored])->collectorBudget)->toBe($expected);
})->with([
	['120', 120],
	['9', Settings::DEFAULT_BUDGET],
	['-5', Settings::DEFAULT_BUDGET],
	['abc', Settings::DEFAULT_BUDGET],
	['', Settings::DEFAULT_BUDGET],
	['99999', 3600],
]);

it('gives every setting in the form a default that fromRow agrees with', function () {
	$form = Settings::form();

	foreach (Settings::NAMES as $name) {
		expect($form)->toHaveKey($name);
	}

	$defaults = Settings::fromRow([
		'cloud_vault_key_file'        => $form['cloud_vault_key_file']['default'],
		'cloud_collector_budget'      => $form['cloud_collector_budget']['default'],
		'cloud_uninstall_keep_tables' => $form['cloud_uninstall_keep_tables']['default'],
	]);

	expect($defaults)->toEqual(Settings::fromRow([]));
});

it('puts every page in exactly one realm', function () {
	$files = Pages::files();

	expect($files)->toBe(array_values(array_unique($files)));

	foreach (Pages::realms() as $realm) {
		expect($realm['files'])->not->toBe([]);
	}
});

it('lists every page script the plugin ships in a realm', function () {
	$root    = dirname(__DIR__, 2);
	$scripts = array_map('basename', glob($root . '/cloud*.php'));

	expect(Pages::files())->not->toBeEmpty();

	foreach ($scripts as $script) {
		expect(in_array($script, Pages::files(), true))->toBeTrue($script);
	}
});

it('gives every menu page a breadcrumb under the console', function () {
	$nav = Pages::navigation();

	foreach (array_keys(Pages::menu()) as $file) {
		expect($nav[$file . ':']['mapping'])->toBe('index.php:')
			->and($nav[$file . ':']['url'])->toBe($file);
	}
});

it('names every hook function that setup.php defines', function () {
	foreach (Schema::HOOKS as $function) {
		expect(function_exists($function))->toBeTrue($function);
	}
});

it('defines plugin tables with the plugin prefix, a primary key, and no secret columns in the clear', function () {
	foreach (Schema::tables() as $table => $definition) {
		expect($table)->toStartWith('plugin_cloud_')
			->and($definition)->toHaveKey('primary')
			->and($definition['type'])->toBe('InnoDB');

		foreach ($definition['columns'] as $column) {
			expect($column['name'])->not->toMatch('/token|password|secret|private_key/');
		}
	}
});
