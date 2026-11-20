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
		$message = __(
			'The Cloud plugin requires PHP %s or later. This server runs PHP %s, so the plugin was not installed or enabled.',
			'8.2.0',
			PHP_VERSION,
			'cloud'
		);
	} elseif (cloud_missing_extensions() !== array()) {
		$message = __(
			'The Cloud plugin requires the PHP extensions %s, which this server does not load, so the plugin was not installed or enabled.',
			implode(', ', cloud_missing_extensions()),
			'cloud'
		);
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

	plugin_cloud_upgrade(true);
}

function plugin_cloud_check_config(): bool {
	if (cloud_refuse_unsupported()) {
		return false;
	}

	return plugin_cloud_upgrade(true);
}

/**
 * Common start after include/auth.php establishes the user. Recheck the
 * realm here because cookie and Basic login restore the user after the
 * alternate-realms hook. Disabled plugins and unsupported runtimes refuse.
 */
function cloud_page_start(): bool {
	if (cloud_boot() && api_plugin_is_enabled('cloud')) {
		if (cloud_page_allowed()) {
			return true;
		}
		http_response_code(403);

		return false;
	}

	top_header();

	html_start_box(__('Cloud', 'cloud'), '100%', '', '3', 'center', '');

	print '<tr><td>' . html_escape(
		cloud_php_supported()
			? __('The Cloud plugin is not enabled.', 'cloud')
			: __('The Cloud plugin requires PHP %s or later.', '8.2.0', 'cloud')
	) . '</td></tr>';

	html_end_box();

	bottom_footer();

	return false;
}

/**
 * State-changing actions are accepted only as POST (REQ-UI-004). A GET that
 * names one is sent back to the page without effect.
 *
 * @param list<string> $actions
 */
function cloud_require_post(string $page, array $actions): void {
	$action = (string) get_nfilter_request_var('action');

	if (in_array($action, $actions, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
		header('Location: ' . $page);
		exit;
	}
}

function cloud_credentials(): \Somethingwithproof\Cloud\CredentialStore {
	return new \Somethingwithproof\Cloud\CredentialStore(
		new \Somethingwithproof\Cloud\SecretRepository(false, new \Somethingwithproof\Cloud\SystemClock()),
		cloud_settings()->vaultKeyFile,
		cloud_settings()->credentialDir
	);
}

function cloud_accounts(): \Somethingwithproof\Cloud\AccountRepository {
	return new \Somethingwithproof\Cloud\AccountRepository(false, new \Somethingwithproof\Cloud\SystemClock());
}

/**
 * The accounts whose scope device the current user may see under Cacti's
 * device permissions. An account without a scope device is left out: only
 * Cloud Admin sees unmapped accounts.
 *
 * @return list<\Somethingwithproof\Cloud\Account>
 */
function cloud_device_allowed_accounts(): array {
	$devices  = new \Somethingwithproof\Cloud\DeviceRepository(false, new \Somethingwithproof\Cloud\SystemClock());
	$accounts = array();

	foreach (cloud_accounts()->all() as $account) {
		$host = $devices->hostFor($account->id);

		if ($host !== null && is_device_allowed($host)) {
			$accounts[] = $account;
		}
	}

	return $accounts;
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
 *
 * $grant gives newly registered realms to the current user. Only install and
 * check_config pass it: both run from the Plugins page, which needs plugin
 * management. A repair triggered from config_arrays can run for anyone who
 * opens a plugin page, so it must never grant anything.
 */
function plugin_cloud_upgrade(bool $grant = false): bool {
	if (!cloud_boot()) {
		return false;
	}

	$info   = plugin_cloud_version();
	$schema = new \Somethingwithproof\Cloud\Schema(false);

	if (
		$schema->isCurrent()
		&& $schema->recordedPluginVersion() === $info['version']
		&& $schema->hooksRegistered()
		&& cloud_templates()->isCurrent()
	) {
		return true;
	}

	if (!$schema->migrate()) {
		return false;
	}

	$schema->trackTables();

	cloud_setup_hooks($grant);

	// A failed import is logged and does not hold back the version (see Templates).
	cloud_setup_templates();

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
function cloud_setup_hooks(bool $grant = false): void {
	global $config;

	// Registering a hook replicates plugin config, which needs core lib/poller.php.
	if (!function_exists('replicate_out')) {
		include_once($config['library_path'] . '/poller.php');
	}

	$enabled = (new \Somethingwithproof\Cloud\Schema(false))->pluginEnabled();

	foreach (\Somethingwithproof\Cloud\Schema::HOOKS as $hook => $function) {
		api_plugin_register_hook('cloud', $hook, $function, 'setup.php', $enabled);
	}

	foreach (\Somethingwithproof\Cloud\Pages::realms() as $realm) {
		api_plugin_register_realm('cloud', implode(',', $realm['files']), $realm['name'], $grant && $realm['files'] !== \Somethingwithproof\Cloud\Pages::OPERATOR);
	}
}

/**
 * Import the shipped device, data query, data and graph templates.
 */
function cloud_setup_templates(int $lockTimeout = 10): bool {
	global $config;

	// templates_import.php loads the same three: import.php calls
	// retemplate_graphs() and update_replication_crc() from the other two.
	$libraries = array(
		'import_xml_data'        => 'import.php',
		'retemplate_graphs'      => 'template.php',
		'update_replication_crc' => 'utility.php',
	);

	foreach ($libraries as $function => $file) {
		if (!function_exists($function)) {
			include_once($config['library_path'] . '/' . $file);
		}
	}

	return cloud_templates()->install($lockTimeout);
}

function cloud_templates(): \Somethingwithproof\Cloud\Templates {
	global $config;

	return new \Somethingwithproof\Cloud\Templates(
		new \Somethingwithproof\Cloud\TemplateRepository(false),
		__DIR__ . '/templates',
		$config['base_path'] . '/resource/script_server',
		function (string $xml, int $profile): bool {
			return cloud_import_templates($xml, $profile);
		}
	);
}

/**
 * import_xml_data() reports problems three ways, none of them through
 * is_error_message(): it returns false or an empty array, it queues
 * messages in the global $import_messages, and it marks an object it could
 * not save with a result other than 'success'. All three are checked here;
 * Templates then checks that every object resolves by hash.
 */
function cloud_import_templates(string $xml, int $profile): bool {
	global $import_messages;

	$import_messages = array();
	$results         = import_xml_data($xml, false, $profile);

	if (!is_array($results) || $results === array() || count($import_messages) > 0) {
		return false;
	}

	foreach ($results as $type => $items) {
		if ($type === 'files' || !is_array($items)) {
			continue;
		}

		foreach ($items as $item) {
			if (!is_array($item) || !isset($item['result']) || $item['result'] !== 'success') {
				return false;
			}
		}
	}

	return true;
}

/**
 * Uninstall never touches cloud resources or provider accounts. Core drops
 * the tracked plugin tables afterwards unless the administrator left "Keep
 * Plugin Tables on Uninstall" checked, which is the default.
 */
function plugin_cloud_uninstall(): void {
	if (!cloud_boot()) {
		include_once(__DIR__ . '/include/unbooted-uninstall.php');
		cloud_uninstall_unbooted();

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

	if (PHP_SAPI !== 'cli' && !api_plugin_user_realm_auth('cloud_accounts.php')) {
		return;
	}

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
	global $current_user;

	if (!cloud_boot()) {
		return $nav;
	}

	// Refresh settings after core restores cookie or Basic authentication.
	if (get_current_page() === 'settings.php' && (PHP_SAPI === 'cli' || ($current_user['enabled'] ?? '') === 'on')) {
		cloud_config_settings();
	}

	return array_merge($nav, \Somethingwithproof\Cloud\Pages::navigation());
}

/**
 * poller_bottom hook: start this poller's collector in the background
 * (REQ-COLL-001). The collector itself skips accounts of other pollers.
 */
/**
 * Cacti is deleting devices: forget any that were scope devices. Runs
 * before the rows go, and receives the ids as an array.
 *
 * @param mixed $ids
 * @return mixed the ids, unchanged, for the next plugin
 */
function cloud_device_remove($ids) {
	if (!cloud_boot() || !is_array($ids)) {
		return $ids;
	}

	$devices = new \Somethingwithproof\Cloud\DeviceRepository(false, new \Somethingwithproof\Cloud\SystemClock());

	foreach ($ids as $id) {
		if (is_numeric($id)) {
			$devices->unlink((int) $id);
		}
	}

	return $ids;
}

/**
 * Cacti is about to save a device: remember a scope device's reindex
 * methods, which the save resets (see ScopeReindex).
 *
 * @param mixed $save
 * @return mixed the device fields, unchanged, for the save
 */
function cloud_device_save($save) {
	if (cloud_boot() && is_array($save) && isset($save['id']) && is_numeric($save['id']) && $save['id'] > 0) {
		cloud_scope_reindex()->remember((int) $save['id']);
	}

	return $save;
}

/**
 * Cacti saved a device, new or edited: put back the reindex methods of a
 * scope device that the save reset.
 *
 * @param mixed $save
 * @return mixed the device fields, unchanged
 */
function cloud_device_saved($save) {
	if (cloud_boot() && is_array($save) && isset($save['id']) && is_numeric($save['id']) && $save['id'] > 0) {
		cloud_scope_reindex()->restore((int) $save['id']);
	}

	return $save;
}

/**
 * One instance per request, so the save hook's memory reaches the hook
 * that runs after the save.
 */
function cloud_scope_reindex(): \Somethingwithproof\Cloud\ScopeReindex {
	global $config;

	static $keeper = null;

	if ($keeper === null) {
		foreach (array('api_device.php', 'data_query.php', 'poller.php', 'snmp.php') as $file) {
			include_once($config['library_path'] . '/' . $file);
		}

		$keeper = new \Somethingwithproof\Cloud\ScopeReindex(
			new \Somethingwithproof\Cloud\DeviceRepository(false, new \Somethingwithproof\Cloud\SystemClock()),
			function (int $host, int $query, int $method): void {
				api_device_dq_change($host, $query, $method);
			}
		);
	}

	return $keeper;
}

function cloud_estimate_view(): \Somethingwithproof\Cloud\EstimateView {
	return new \Somethingwithproof\Cloud\EstimateView(
		new \Somethingwithproof\Cloud\CostPreview(
			new \Somethingwithproof\Cloud\ResourceRepository(false, new \Somethingwithproof\Cloud\SystemClock()),
			new \Somethingwithproof\Cloud\SeriesRepository(false)
		),
		\Somethingwithproof\Cloud\Drivers::production()
	);
}

/**
 * New Graphs, for a scope device: what graphing everything would cost, and
 * a link to the per-graph estimate (REQ-COST-001). Cached data only.
 */
function cloud_graphs_new_top_links(): void {
	global $config;

	$host = get_request_var('host_id');

	if (!is_numeric($host) || !cloud_boot() || !api_user_realm_auth('cloud_health.php')
		|| (!api_user_realm_auth('cloud_accounts.php') && !is_device_allowed((int) $host))) {
		return;
	}

	$clock   = new \Somethingwithproof\Cloud\SystemClock();
	$id      = (new \Somethingwithproof\Cloud\DeviceRepository(false, $clock))->accountFor((int) $host);
	$account = $id === null ? null : cloud_accounts()->find($id);
	$driver  = $account === null ? null : \Somethingwithproof\Cloud\Drivers::production()->for($account);

	if ($account === null || $driver === null) {
		return;
	}

	$view = cloud_estimate_view();
	$note = $view->budgetNote($account, $driver);

	print "<span class='linkMarker'>*</span><a class='hyperLink' href='"
		. html_escape(
			$config['url_path'] . 'plugins/cloud/cloud_health.php#'
				. \Somethingwithproof\Cloud\EstimateView::ANCHOR . $account->id
		)
		. "'>" . __esc('Cloud Request Estimate', 'cloud') . '</a><br>';

	if ($note !== null) {
		print '<span' . ($note[1] ? " class='textError'" : '') . '>' . html_escape($note[0]) . '</span><br>';
	}
}

function cloud_poller_bottom(): void {
	global $config;

	if (!cloud_boot()) {
		return;
	}

	if (!function_exists('exec_background')) {
		include_once($config['library_path'] . '/poller.php');
	}

	// exec_background() silently does nothing for a binary that does not
	// exist, so a bare 'php' fallback would never start the collector.
	$php = trim((string) read_config_option('path_php_binary'));

	if ($php === '' || !is_file($php) || !is_executable($php)) {
		cacti_log(
			'ERROR: The cloud collector was not started because the PHP binary path setting is empty '
				. 'or not an executable file',
			false,
			'CLOUD'
		);

		return;
	}

	// Core accepts an argument array and escapes each element, although its
	// docblock declares a string.
	// @phpstan-ignore argument.type
	exec_background($php, array('-q', __DIR__ . '/poller_cloud.php', '--poller=' . (int) $config['poller_id']));
}

function cloud_scope_devices(int $lockTimeout = 10): \Somethingwithproof\Cloud\ScopeDevices {
	global $config;

	// The libraries host.php loads to save a device and run its data queries.
	foreach (array(
		'api_data_source.php', 'api_device.php', 'api_graph.php', 'data_query.php',
		'ping.php', 'poller.php', 'snmp.php', 'template.php', 'utility.php'
	) as $file) {
		include_once($config['library_path'] . '/' . $file);
	}

	return new \Somethingwithproof\Cloud\ScopeDevices(
		new \Somethingwithproof\Cloud\DeviceRepository(false, new \Somethingwithproof\Cloud\SystemClock()),
		new \Somethingwithproof\Cloud\TemplateRepository(false),
		function (\Somethingwithproof\Cloud\Account $account, int $template): int {
			$id = call_user_func_array(
				'api_device_save',
				\Somethingwithproof\Cloud\ScopeDevices::deviceFields($account, $template)
			);

			return is_numeric($id) ? (int) $id : 0;
		},
		function (int $host, int $query, int $method): void {
			api_device_dq_change($host, $query, $method);
		},
		function (int $host, int $query): bool {
			return (bool) run_data_query($host, $query);
		},
		function (int $host, int $query): void {
			// Cacti's own cache update, with nothing present: it marks the
			// query's rows stale and deletes them.
			$none = array();
			data_query_update_host_cache_from_buffer($host, $query, $none, $none);
		},
		function (int $host): void {
			api_device_remove($host);
		},
		$lockTimeout
	);
}

function cloud_script_server(): \Somethingwithproof\Cloud\ScriptServer {
	$clock = new \Somethingwithproof\Cloud\SystemClock();

	return new \Somethingwithproof\Cloud\ScriptServer(
		new \Somethingwithproof\Cloud\DeviceRepository(false, $clock),
		cloud_accounts(),
		new \Somethingwithproof\Cloud\ResourceRepository(false, $clock),
		new \Somethingwithproof\Cloud\ObservationRepository(false),
		new \Somethingwithproof\Cloud\FreshnessEvaluator($clock),
		$clock
	);
}

function cloud_collector(int $pollerId): \Somethingwithproof\Cloud\Collector {
	$clock = new \Somethingwithproof\Cloud\SystemClock();

	return new \Somethingwithproof\Cloud\Collector(
		cloud_accounts(),
		\Somethingwithproof\Cloud\Drivers::production(),
		cloud_credentials(),
		new \Somethingwithproof\Cloud\LeaseRepository(false, $clock),
		new \Somethingwithproof\Cloud\ResourceRepository(false, $clock),
		new \Somethingwithproof\Cloud\SeriesRepository(false),
		new \Somethingwithproof\Cloud\ObservationRepository(false),
		new \Somethingwithproof\Cloud\HealthRepository(false),
		new \Somethingwithproof\Cloud\Redactor(),
		$clock,
		'poller' . $pollerId . '-' . getmypid() . '-' . bin2hex(random_bytes(4)),
		// Wait briefly for a device being created; the next discovery retries.
		cloud_scope_devices(1)
	);
}

/** Resolve role inheritance against Cacti's current user and group grants. */
function cloud_auth_alternate_realms(): void {
	global $user_auth_realm_filenames;

	static $deferred = array();

	foreach ($deferred as $file => $realm) {
		$user_auth_realm_filenames[$file] = $realm;
	}
	$deferred = array();

	if (!api_plugin_is_enabled('cloud')) {
		return;
	}

	if (empty($_SESSION['sess_user_id'])) {
		// Core restores cookie and Basic authentication after this hook. Only
		// these entrypoints defer the realm check to their post-auth guard.
		$page = get_current_page();

		if (in_array($page, array('cloud_health.php', 'cloud_operator.php'), true) && isset($user_auth_realm_filenames[$page])) {
			$deferred[$page]                  = $user_auth_realm_filenames[$page];
			$user_auth_realm_filenames[$page] = -1;
		}

		return;
	}
	$operator = 'cloud_operator.php';
	$admin = 'cloud_accounts.php';
	$files = array('cloud_health.php');
	$realm = $user_auth_realm_filenames[$admin] ?? 0;
	if ($realm > 0 && is_realm_allowed($realm)) {
		$files[] = $operator;
	} else {
		$realm = $user_auth_realm_filenames[$operator] ?? 0;
		if ($realm <= 0 || !is_realm_allowed($realm)) {
			return;
		}
	}
	foreach ($files as $file) {
		$user_auth_realm_filenames[$file] = $realm;
	}
}

/** Recheck the authenticated user before a plugin page reads data or acts. */
function cloud_page_allowed(): bool {
	global $current_user;

	cloud_auth_alternate_realms();
	$userId    = $current_user['id'] ?? null;
	$sessionId = $_SESSION['sess_user_id'] ?? null;

	return (is_int($userId) || (is_string($userId) && ctype_digit($userId)))
		&& (is_int($sessionId) || (is_string($sessionId) && ctype_digit($sessionId)))
		&& (int) $userId > 0 && (int) $userId === (int) $sessionId
		&& api_plugin_is_enabled('cloud')
		&& ($current_user['enabled'] ?? '') === 'on'
		&& api_plugin_user_realm_auth(get_current_page());
}
