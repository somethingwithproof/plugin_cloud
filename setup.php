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
 * Cacti includes this file on every request, on whatever PHP version the site
 * runs. It must parse on PHP 8.0 (CI lints it there), and nothing under lib/
 * is loaded until cloud_boot() has confirmed PHP 8.2 or later (ADR 0004).
 * Every hook and lifecycle function therefore starts with that check, so an
 * unsupported runtime gets a refusal instead of a parse error that would take
 * down the whole Cacti UI.
 */

function cloud_php_supported(): bool {
	return version_compare(PHP_VERSION, '8.2.0', '>=');
}

/**
 * Extensions the plugin needs that PHP can be built without. Cacti 1.2.31's
 * composer.json already requires each of them (REQ-COMPAT-006). ext-json is
 * always present since PHP 8.0, and ext-curl is deliberately not used
 * (ADR 0009): TLS goes through stream sockets, which need ext-openssl.
 *
 * @return list<string>
 */
function cloud_required_extensions(): array {
	return array('openssl');
}

/**
 * @return list<string>
 */
function cloud_missing_extensions(): array {
	$missing = array();

	foreach (cloud_required_extensions() as $extension) {
		if (!extension_loaded($extension)) {
			$missing[] = $extension;
		}
	}

	return $missing;
}

function cloud_boot(): bool {
	if (!cloud_php_supported() || cloud_missing_extensions() !== array()) {
		return false;
	}

	require_once(__DIR__ . '/include/autoload.php');

	return true;
}

/**
 * Refuse with a message naming what is missing.
 *
 * Used by the lifecycle functions only. Hook shims return silently instead,
 * because they run on every page and a refused plugin must not flood the log.
 */
function cloud_refuse_unsupported(): bool {
	if (!cloud_php_supported()) {
		$message = __('The Cloud plugin requires PHP %s or later. This server runs PHP %s, so the plugin was not installed or enabled.', '8.2.0', PHP_VERSION, 'cloud');
	} elseif (cloud_missing_extensions() !== array()) {
		$message = __('The Cloud plugin requires the PHP extensions %s, which this server does not load, so the plugin was not installed or enabled.', implode(', ', cloud_missing_extensions()), 'cloud');
	} else {
		return false;
	}

	cacti_log('ERROR: ' . $message, false, 'CLOUD');

	if (PHP_SAPI === 'cli') {
		fwrite(STDERR, $message . PHP_EOL);
	} elseif (function_exists('raise_message')) {
		raise_message('cloud_php', $message, MESSAGE_LEVEL_ERROR);
	}

	return true;
}

/**
 * @return array<string, string>
 */
function plugin_cloud_version(): array {
	$info = parse_ini_file(__DIR__ . '/INFO', true);

	if (!is_array($info) || !isset($info['info']) || !is_array($info['info'])) {
		return array();
	}

	$version = array();

	foreach ($info['info'] as $key => $value) {
		$version[(string) $key] = is_scalar($value) ? (string) $value : '';
	}

	return $version;
}

function plugin_cloud_install(): void {
	if (cloud_refuse_unsupported()) {
		return;
	}

	plugin_cloud_upgrade();
}

function plugin_cloud_check_config(): bool {
	if (cloud_refuse_unsupported()) {
		return false;
	}

	return plugin_cloud_upgrade();
}

/**
 * Bring the schema, hooks, and realms up to the code's version.
 *
 * Fresh installs take the same path as upgrades.
 */
function plugin_cloud_upgrade(): bool {
	if (!cloud_boot()) {
		return false;
	}

	return true;
}

function plugin_cloud_uninstall(): void {
	if (!cloud_boot()) {
		return;
	}
}
