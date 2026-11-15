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

function cloud_settings(): \Somethingwithproof\Cloud\Settings {
	return (new \Somethingwithproof\Cloud\SettingsRepository(false))->load();
}

/**
 * Bring the schema, hooks, and realms up to the code's version.
 *
 * Cacti 1.2.31 never calls this itself; check_config and the config_arrays
 * hook do. Fresh installs take the same path as upgrades. The version stored
 * in plugin_config changes only after every migration has succeeded, so an
 * interrupted run is finished by the next one.
 */
function plugin_cloud_upgrade(): bool {
	if (!cloud_boot()) {
		return false;
	}

	$info   = plugin_cloud_version();
	$schema = new \Somethingwithproof\Cloud\Schema(false);

	if ($schema->isCurrent() && $schema->recordedPluginVersion() === $info['version'] && $schema->hooksRegistered()) {
		return true;
	}

	if (!$schema->migrate()) {
		return false;
	}

	$schema->trackTables();

	cloud_setup_hooks();

	$schema->recordPluginVersion($info['version'], $info['author'], $info['homepage']);

	return true;
}

/**
 * Register hooks and realms.
 *
 * Kept procedural and named *_setup_* because api_plugin_register_hook() and
 * api_plugin_register_realm() refuse callers whose function name does not
 * contain install, upgrade, or setup.
 */
function cloud_setup_hooks(): void {
	global $config;

	// Registering a hook replicates plugin config, which needs core lib/poller.php.
	if (!function_exists('replicate_out')) {
		include_once($config['library_path'] . '/poller.php');
	}

	foreach (\Somethingwithproof\Cloud\Schema::HOOKS as $hook => $function) {
		api_plugin_register_hook('cloud', $hook, $function, 'setup.php');
	}

	foreach (\Somethingwithproof\Cloud\Pages::realms() as $realm) {
		api_plugin_register_realm('cloud', implode(',', $realm['files']), $realm['name'], true);
	}
}

/**
 * Uninstall never touches cloud resources or provider accounts. Core drops
 * the tracked plugin tables afterwards unless the administrator left "Keep
 * Plugin Tables on Uninstall" checked, which is the default.
 */
function plugin_cloud_uninstall(): void {
	if (!cloud_boot()) {
		return;
	}

	$schema = new \Somethingwithproof\Cloud\Schema(false);

	if (cloud_settings()->keepTablesOnUninstall) {
		$schema->untrackTables();

		\Somethingwithproof\Cloud\Log::info('Uninstall kept the plugin tables as configured');
	} else {
		$schema->forgetVersion();
	}
}

function cloud_config_arrays(): void {
	global $menu;

	if (!cloud_boot()) {
		return;
	}

	foreach (\Somethingwithproof\Cloud\Pages::menu() as $file => $title) {
		$menu[__('Management')]['plugins/cloud/' . $file] = $title;
	}

	if (in_array(get_current_page(), \Somethingwithproof\Cloud\Pages::upgradeTriggers(), true)) {
		plugin_cloud_upgrade();
	}
}

function cloud_config_settings(): void {
	global $tabs, $settings;

	if (!cloud_boot()) {
		return;
	}

	$tabs['cloud']     = __('Cloud', 'cloud');
	$settings['cloud'] = \Somethingwithproof\Cloud\Settings::form();
}

/**
 * @param array<string, mixed> $nav
 * @return array<string, mixed>
 */
function cloud_draw_navigation_text($nav) {
	if (!cloud_boot()) {
		return $nav;
	}

	return array_merge($nav, \Somethingwithproof\Cloud\Pages::navigation());
}
