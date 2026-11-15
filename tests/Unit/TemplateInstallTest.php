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


use Somethingwithproof\Cloud\TemplateRepository;
use Somethingwithproof\Cloud\Templates;

/*
 * Templates' decisions against the database stubs: when it imports, what it
 * records, and that it never records a package it could not fully import.
 * The import itself runs against a real Cacti in tests/Integration.
 */

function cloud_tpl_test_dir(): string {
	$dir = sys_get_temp_dir() . '/cloud-templates-' . bin2hex(random_bytes(6));
	mkdir($dir . '/source/resource', 0700, true);
	mkdir($dir . '/resource', 0700);
	file_put_contents($dir . '/source/' . Templates::PACKAGE, '<cacti></cacti>');
	file_put_contents($dir . '/source/resource/cloud_do_droplet.xml', '<interface></interface>');

	return $dir;
}

/**
 * @return array{0: Templates, 1: \ArrayObject<int, array{0: string, 1: int}>}
 */
function cloud_tpl_installer(string $dir, bool $imports = true, ?string $resource = null): array {
	$calls = new \ArrayObject();

	return [
		new Templates(
			new TemplateRepository(false),
			$dir . '/source',
			$resource ?? $dir . '/resource',
			function (string $xml, int $profile) use ($calls, $imports): bool {
				$calls[] = [$xml, $profile];

				return $imports;
			}
		),
		$calls,
	];
}

/**
 * @return list<array{hash: string}> every object hash, as the lookup returns them
 */
function cloud_tpl_all_present(): array {
	$rows = [];

	foreach (TemplateRepository::objects() as $keys) {
		foreach ($keys as $key) {
			$rows[] = ['hash' => TemplateRepository::hash($key)];
		}
	}

	return $rows;
}

function cloud_tpl_recorded(): array {
	return array_values(array_filter(
		$GLOBALS['__cloud_db_exec'],
		fn (array $s): bool => str_contains($s['sql'], 'REPLACE INTO settings')
	));
}

beforeEach(function () {
	$GLOBALS['__cloud_db_exec'] = [];
	$GLOBALS['__cloud_log']     = [];
	$GLOBALS['__cloud_db_rows'] = [
		'GET_LOCK'             => [['1']],
		'data_source_profiles' => [['1']],
	];
});

it('imports a new package with the default profile and records its checksum', function () {
	$dir = cloud_tpl_test_dir();
	[$templates, $calls] = cloud_tpl_installer($dir);

	$GLOBALS['__cloud_db_rows']['SELECT hash FROM'] = cloud_tpl_all_present();

	expect($templates->install())->toBeTrue()
		->and($calls->getArrayCopy())->toBe([['<cacti></cacti>', 1]])
		->and(cloud_tpl_recorded()[0]['params'])->toBe(['cloud_templates_sha256', hash('sha256', '<cacti></cacti>')])
		->and(file_get_contents($dir . '/resource/cloud_do_droplet.xml'))->toBe('<interface></interface>')
		->and(fileperms($dir . '/resource/cloud_do_droplet.xml') & 0777)->toBe(0644)
		->and(glob($dir . '/resource/.*.xml.*'))->toBe([]);
});

it('skips the import when the recorded checksum matches and every object is present', function () {
	$dir = cloud_tpl_test_dir();
	[$templates, $calls] = cloud_tpl_installer($dir);

	$GLOBALS['__cloud_db_rows']['SELECT value FROM settings'] = [[hash('sha256', '<cacti></cacti>')]];
	$GLOBALS['__cloud_db_rows']['SELECT hash FROM']           = cloud_tpl_all_present();

	expect($templates->install())->toBeTrue()
		->and($templates->isCurrent())->toBeTrue()
		->and(count($calls))->toBe(0)
		->and(cloud_tpl_recorded())->toBe([]);
});

it('imports again when the checksum matches but an object was deleted', function () {
	$dir = cloud_tpl_test_dir();
	[$templates, $calls] = cloud_tpl_installer($dir);
	$present = cloud_tpl_all_present();
	array_shift($present);

	$GLOBALS['__cloud_db_rows']['SELECT value FROM settings'] = [[hash('sha256', '<cacti></cacti>')]];
	$GLOBALS['__cloud_db_rows']['SELECT hash FROM']           = $present;

	// The stub answers every lookup the same way, so the object stays missing
	// after the import too, and nothing is recorded.
	expect($templates->install())->toBeFalse()
		->and(count($calls))->toBe(1)
		->and(cloud_tpl_recorded())->toBe([]);
});

it('records nothing when the importer reports an error', function () {
	[$templates, $calls] = cloud_tpl_installer(cloud_tpl_test_dir(), false);

	$GLOBALS['__cloud_db_rows']['SELECT hash FROM'] = cloud_tpl_all_present();

	expect($templates->install())->toBeFalse()
		->and(count($calls))->toBe(1)
		->and(cloud_tpl_recorded())->toBe([]);
});

it('does not import without a default data source profile', function () {
	[$templates, $calls] = cloud_tpl_installer(cloud_tpl_test_dir());

	$GLOBALS['__cloud_db_rows']['data_source_profiles'] = [];

	expect($templates->install())->toBeFalse()
		->and(count($calls))->toBe(0);
});

it('defers to a process holding the lock and leaves the files alone', function () {
	$dir = cloud_tpl_test_dir();
	[$templates, $calls] = cloud_tpl_installer($dir);

	$GLOBALS['__cloud_db_rows']['GET_LOCK'] = [['0']];

	expect($templates->install())->toBeFalse()
		->and(count($calls))->toBe(0)
		->and(file_exists($dir . '/resource/cloud_do_droplet.xml'))->toBeFalse()
		->and(array_column($GLOBALS['__cloud_log'], 'message'))
		->toContain('WARNING: Template import deferred: another process holds the template lock');
});

it('releases the lock after an import, whatever its outcome', function () {
	foreach ([true, false] as $imports) {
		$GLOBALS['__cloud_db_exec'] = [];
		[$templates] = cloud_tpl_installer(cloud_tpl_test_dir(), $imports);

		$templates->install();

		expect(array_column($GLOBALS['__cloud_db_exec'], 'sql'))->toContain('DO RELEASE_LOCK(?)');
	}
});

it('refuses when the Cacti resource directory is missing', function () {
	$dir = cloud_tpl_test_dir();
	[$templates, $calls] = cloud_tpl_installer($dir, true, $dir . '/no-such-dir');

	expect($templates->install())->toBeFalse()
		->and(count($calls))->toBe(0);
});

it('replaces a link at the target instead of writing through it', function () {
	$dir = cloud_tpl_test_dir();
	[$templates] = cloud_tpl_installer($dir);
	file_put_contents($dir . '/elsewhere.xml', 'kept');
	symlink($dir . '/elsewhere.xml', $dir . '/resource/cloud_do_droplet.xml');

	$GLOBALS['__cloud_db_rows']['SELECT hash FROM'] = cloud_tpl_all_present();
	$templates->install();

	expect(is_link($dir . '/resource/cloud_do_droplet.xml'))->toBeFalse()
		->and(file_get_contents($dir . '/resource/cloud_do_droplet.xml'))->toBe('<interface></interface>')
		->and(file_get_contents($dir . '/elsewhere.xml'))->toBe('kept');
});
