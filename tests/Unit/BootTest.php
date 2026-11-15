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

use Somethingwithproof\Cloud\Log;

beforeEach(function () {
	$GLOBALS['__cloud_log'] = [];
});

it('reads its metadata from INFO', function () {
	$info = plugin_cloud_version();

	expect($info['name'])->toBe('cloud')
		->and($info['compat'])->toBe('1.2.31')
		->and($info['capabilities'])->toContain('remote_collect:1')
		->and($info['capabilities'])->not->toContain('remote_poller');
});

it('boots and loads plugin classes on a supported runtime', function () {
	expect(cloud_php_supported())->toBeTrue()
		->and(cloud_missing_extensions())->toBe([])
		->and(cloud_boot())->toBeTrue()
		->and(class_exists(Log::class))->toBeTrue();
});

it('does not refuse or log on a supported runtime', function () {
	expect(cloud_refuse_unsupported())->toBeFalse()
		->and($GLOBALS['__cloud_log'])->toBe([]);
});

it('ignores classes outside its namespace and nested names', function () {
	expect(class_exists('Somethingwithproof\\Cloud\\Nested\\Log'))->toBeFalse()
		->and(class_exists('Somethingwithproof\\CloudLog'))->toBeFalse();
});

it('logs under the CLOUD tag at the right verbosity', function () {
	Log::debug('d');
	Log::info('i');
	Log::warning('w');
	Log::error('e');

	expect($GLOBALS['__cloud_log'])->toBe([
		['message' => 'DEBUG: d', 'environ' => 'CLOUD', 'level' => POLLER_VERBOSITY_DEBUG],
		['message' => 'NOTE: i', 'environ' => 'CLOUD', 'level' => POLLER_VERBOSITY_MEDIUM],
		['message' => 'WARNING: w', 'environ' => 'CLOUD', 'level' => ''],
		['message' => 'ERROR: e', 'environ' => 'CLOUD', 'level' => ''],
	]);
});
