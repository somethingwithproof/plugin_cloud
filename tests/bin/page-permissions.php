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
 * Page permissions and POST-only actions over HTTP (#28). Logs in to a
 * running Cacti as three users and checks each plugin page:
 *
 *   admin    Cloud View and Cloud Admin: both pages load
 *   viewer   Console and Cloud View: health loads, accounts is denied
 *   norealm  Console only: both pages are denied
 *
 * Then shows that a delete sent as GET, or as POST without the csrf-magic
 * token, has no effect, and that the same delete as a proper POST works.
 * Disposable databases only: it resets the admin password and adds users.
 * Must parse on PHP 8.0.
 *
 *   CLOUD_TEST_DATABASE=disposable php plugins/cloud/tests/bin/page-permissions.php http://127.0.0.1:8080/cacti/
 */

chdir(dirname(__DIR__, 4));

require('./include/cli_check.php');

if (getenv('CLOUD_TEST_DATABASE') !== 'disposable') {
	fwrite(STDERR, "Set CLOUD_TEST_DATABASE=disposable\n");
	exit(2);
}

$base     = rtrim($argv[1], '/') . '/plugins/cloud/';
$root     = rtrim($argv[1], '/') . '/';
$password = 'Cloud-' . bin2hex(random_bytes(8));
$failures = 0;

$view  = (int) db_fetch_cell("SELECT id + 100 FROM plugin_realms WHERE plugin = 'cloud' AND file = 'cloud_health.php'");
$admin = (int) db_fetch_cell("SELECT id + 100 FROM plugin_realms WHERE plugin = 'cloud' AND file = 'cloud_accounts.php'");

db_execute_prepared("UPDATE user_auth SET password = ?, must_change_password = '', password_change = '', enabled = 'on', locked = '', failed_attempts = 0 WHERE id = 1", array(password_hash($password, PASSWORD_DEFAULT)));

// Realm 8 is Console access.
foreach (array('viewer' => array(8, $view), 'norealm' => array(8)) as $name => $realms) {
	db_execute_prepared('DELETE FROM user_auth WHERE username = ?', array($name));
	db_execute_prepared("INSERT INTO user_auth (username, password, realm, full_name, must_change_password, password_change, policy_graphs, policy_trees, policy_hosts, policy_graph_templates, enabled) VALUES (?, ?, 0, ?, '', '', 1, 1, 1, 1, 'on')", array($name, password_hash($password, PASSWORD_DEFAULT), $name));
	$id = (int) db_fetch_insert_id();

	foreach ($realms as $realm) {
		db_execute_prepared('INSERT INTO user_auth_realm (realm_id, user_id) VALUES (?, ?)', array($realm, $id));
	}
}

function perm_expect($ok, $message) {
	global $failures;

	print ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
	$failures += $ok ? 0 : 1;
}

function perm_http($url, $jar, $post = null) {
	$ch = curl_init($url);

	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60));

	if ($post !== null) {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
	}

	$body = (string) curl_exec($ch);

	return array((int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $body);
}

function perm_token($body) {
	return preg_match('/csrfMagicToken\s*=\s*"([^"]+)"/', $body, $m) ? $m[1] : '';
}

function perm_login($user) {
	global $root, $password;

	$jar = tempnam(sys_get_temp_dir(), 'cloudperm');
	list(, $login) = perm_http($root . 'index.php', $jar);
	perm_http($root . 'index.php', $jar, array('__csrf_magic' => perm_token($login), 'action' => 'login', 'login_username' => $user, 'login_password' => $password));

	return $jar;
}

// Markers that only the rendered plugin page contains.
$pages = array('cloud_accounts.php' => 'name="action" value="save"|No cloud accounts yet|linkEditMain', 'cloud_health.php' => 'Credential Vault');

db_execute_prepared("INSERT INTO plugin_cloud_account (provider, name, credential_source, admin_state) VALUES ('digitalocean', ?, 'vault', 'disabled')", array('<b id="cloudprobe">Probe</b>'));
$probe = (int) db_fetch_insert_id();

foreach (array('admin' => array('cloud_accounts.php' => true, 'cloud_health.php' => true), 'viewer' => array('cloud_accounts.php' => false, 'cloud_health.php' => true), 'norealm' => array('cloud_accounts.php' => false, 'cloud_health.php' => false)) as $user => $expect) {
	$jar = perm_login($user);

	foreach ($expect as $page => $allowed) {
		list($code, $body) = perm_http($base . $page, $jar);
		$shown = preg_match('/' . $pages[$page] . '/', $body) === 1;

		perm_expect($shown === $allowed && preg_match('/(Fatal error|Parse error|Warning:|Uncaught)/', $body) !== 1, "$user " . ($allowed ? 'sees' : 'is denied') . " $page (HTTP $code)");
	}

	unlink($jar);
}

$jar = perm_login('admin');
$exists = function () use ($probe) {
	return (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_cloud_account WHERE id = ?', array($probe)) === 1;
};

// The probe's name is markup; every page that prints it must escape it.
foreach (array('cloud_accounts.php', 'cloud_accounts.php?action=edit&id=' . $probe) as $page) {
	list(, $body) = perm_http($base . $page, $jar);
	perm_expect(strpos($body, '<b id="cloudprobe">') === false && strpos($body, '&lt;b id=&quot;cloudprobe&quot;&gt;') !== false, "$page escapes the account name");
}

perm_http($base . 'cloud_accounts.php?action=delete&id=' . $probe, $jar);
perm_expect($exists(), 'a delete sent as GET has no effect');

perm_http($base . 'cloud_accounts.php', $jar, array('action' => 'delete', 'id' => $probe));
perm_expect($exists(), 'a delete POST without the csrf-magic token has no effect');

list(, $list) = perm_http($base . 'cloud_accounts.php', $jar);
perm_http($base . 'cloud_accounts.php', $jar, array('__csrf_magic' => perm_token($list), 'action' => 'delete', 'id' => $probe));
perm_expect(!$exists(), 'the same delete as a POST with the token removes the account');

unlink($jar);

exit($failures > 0 ? 1 : 0);
