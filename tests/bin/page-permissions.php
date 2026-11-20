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
 * Then it saves an account with a vault credential through the form and
 * searches every response, the Cacti log, and every plugin table for the
 * credential (#32). Last (#45), it views a cloud graph as users allowed and
 * denied by Cacti's graph and device permissions, checks that Cloud Health
 * lists the graph's account only to viewers allowed its device, checks that
 * ss_cloud.php serves nothing over HTTP, and revokes each realm from a
 * logged-in user.
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
$admin = (int) db_fetch_cell(
	"SELECT id + 100 FROM plugin_realms WHERE plugin = 'cloud' AND file = 'cloud_accounts.php'"
);

db_execute_prepared(
	"UPDATE user_auth SET password = ?, must_change_password = '', password_change = '', enabled = 'on', locked = '', " .
		"failed_attempts = 0 WHERE id = 1",
	array(password_hash($password, PASSWORD_DEFAULT))
);

/**
 * A user with these realms, every graph policy allow (1) or deny (2).
 *
 * @return int the user id
 */
function perm_user($name, $realms, $policy = 1) {
	global $password;

	db_execute_prepared('DELETE FROM user_auth WHERE username = ?', array($name));
	db_execute_prepared(
		"INSERT INTO user_auth (username, password, realm, full_name, must_change_password, password_change, " .
			"policy_graphs, policy_trees, policy_hosts, policy_graph_templates, enabled) VALUES (?, ?, 0, ?, '', '', ?, ?, ?, " .
			"?, 'on')",
		array($name, password_hash($password, PASSWORD_DEFAULT), $name, $policy, $policy, $policy, $policy)
	);
	$id = (int) db_fetch_insert_id();

	foreach ($realms as $realm) {
		db_execute_prepared('INSERT INTO user_auth_realm (realm_id, user_id) VALUES (?, ?)', array($realm, $id));
	}

	return $id;
}

// Realm 8 is Console access, realm 7 View Graphs.
perm_user('viewer', array(8, $view));
perm_user('norealm', array(8));

function perm_expect($ok, $message) {
	global $failures;

	print ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
	$failures += $ok ? 0 : 1;
}

function perm_http($url, $jar, $post = null) {
	$ch = curl_init($url);

	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_COOKIEJAR => $jar,
			CURLOPT_COOKIEFILE => $jar,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT => 60
		)
	);

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
	perm_http(
		$root . 'index.php',
		$jar,
		array(
			'__csrf_magic' => perm_token($login),
			'action' => 'login',
			'login_username' => $user,
			'login_password' => $password
		)
	);

	return $jar;
}

// Markers that only the rendered plugin page contains.
$pages = array(
	'cloud_accounts.php' => 'name="action" value="save"|No cloud accounts yet|linkEditMain',
	'cloud_health.php' => 'Cloud Warnings'
);

db_execute_prepared(
	"INSERT INTO plugin_cloud_account (provider, name, credential_source, admin_state) VALUES ('digitalocean', ?, " .
		"'vault', 'disabled')",
	array('<b id="cloudprobe">Probe</b>')
);
$probe = (int) db_fetch_insert_id();

foreach (array(
	'admin' => array('cloud_accounts.php' => true, 'cloud_health.php' => true),
	'viewer' => array('cloud_accounts.php' => false, 'cloud_health.php' => true),
	'norealm' => array('cloud_accounts.php' => false, 'cloud_health.php' => false)
) as $user => $expect) {
	$jar = perm_login($user);

	foreach ($expect as $page => $allowed) {
		list($code, $body) = perm_http($base . $page, $jar);
		$shown = preg_match('/' . $pages[$page] . '/', $body) === 1;

		perm_expect(
			$shown === $allowed && preg_match('/(Fatal error|Parse error|Warning:|Uncaught)/', $body) !== 1,
			"$user " . ($allowed ? 'sees' : 'is denied') . " $page (HTTP $code)"
		);

		// Credential state is an account detail for Cloud Admin only.
		if ($page === 'cloud_health.php' && $allowed) {
			$credential = strpos($body, '<th>Credential</th>') !== false;
			perm_expect(
				$credential === ($user === 'admin'),
				"$user " . ($user === 'admin' ? 'sees' : 'does not see') . ' credential state on cloud_health.php'
			);
		}
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
	perm_expect(
		strpos($body, '<b id="cloudprobe">') === false && strpos($body, '&lt;b id=&quot;cloudprobe&quot;&gt;') !== false,
		"$page escapes the account name"
	);
}

perm_http($base . 'cloud_accounts.php?action=delete&id=' . $probe, $jar);
perm_expect($exists(), 'a delete sent as GET has no effect');

perm_http($base . 'cloud_accounts.php', $jar, array('action' => 'delete', 'id' => $probe));
perm_expect($exists(), 'a delete POST without the csrf-magic token has no effect');

list(, $list) = perm_http($base . 'cloud_accounts.php', $jar);
perm_http(
	$base . 'cloud_accounts.php',
	$jar,
	array('__csrf_magic' => perm_token($list), 'action' => 'delete', 'id' => $probe)
);
perm_expect(!$exists(), 'the same delete as a POST with the token removes the account');

// A credential saved through the form must come back in no response, no
// log line, and no plugin table.
$keyFile = sys_get_temp_dir() . '/cloud-perm-' . bin2hex(random_bytes(4)) . '.key';
file_put_contents($keyFile, bin2hex(random_bytes(32)));
chmod($keyFile, 0600);
db_execute_prepared("REPLACE INTO settings (name, value) VALUES ('cloud_vault_key_file', ?)", array($keyFile));

$secret = 'dop_v1_' . bin2hex(random_bytes(32));
$probeName = 'Secret probe ' . bin2hex(random_bytes(3));

// Each response counts as evidence only if the request did what it should:
// [label, HTTP code, body, text the page must show].
$responses = array();

list($code, $form) = perm_http($base . 'cloud_accounts.php?action=edit', $jar);
$responses[] = array('form', $code, $form, 'name="credential"');
list($code, $saved) = perm_http($base . 'cloud_accounts.php', $jar, array(
	'__csrf_magic' => perm_token($form), 'action' => 'save', 'id' => 0, 'provider' => 'digitalocean',
	'name' => $probeName, 'poller_id' => 1,
	'credential_source' => 'vault', 'credential' => $secret, 'collect_interval' => 300,
	'discover_interval' => 3600, 'request_share' => 50, 'enabled' => 'on',
));
$responses[] = array('save', $code, $saved, 'The account was saved.');

$account = (int) db_fetch_cell_prepared('SELECT id FROM plugin_cloud_account WHERE name = ?', array($probeName));
perm_expect(
	$account > 0 && (int) db_fetch_cell_prepared(
		'SELECT COUNT(*) FROM plugin_cloud_secret WHERE account_id = ?',
		array($account)
	) === 1,
	'an account saved through the form stores its credential in the vault'
);

foreach (array(
	'list' => array('cloud_accounts.php', 'Stored in the vault'),
	'edit' => array('cloud_accounts.php?action=edit&id=' . $account, 'A credential is stored.'),
	'health' => array('cloud_health.php', $probeName)
) as $label => $page) {
	list($code, $body) = perm_http($base . $page[0], $jar);
	$responses[] = array($label, $code, $body, $page[1]);
}

// CI runs with CLOUD_OFFLINE=1, so Verify's requests are refused before
// they leave the host and it records the configuration status.
list($code, $verified) = perm_http(
	$base . 'cloud_accounts.php',
	$jar,
	array('__csrf_magic' => perm_token($body), 'action' => 'verify', 'id' => $account)
);
$responses[] = array('verify', $code, $verified, 'The account or its credential is not configured correctly.');

foreach ($responses as $response) {
	list($label, $code, $body, $marker) = $response;
	perm_expect(
		$code === 200 && strpos($body, $marker) !== false,
		"the $label request succeeded (HTTP $code) and its page shows what it should"
	);
}

$leaks = array();

foreach ($responses as $response) {
	if (strpos($response[2], $secret) !== false) {
		$leaks[] = $response[0] . ' response';
	}
}

$log = (string) @file_get_contents($config['base_path'] . '/log/cacti.log');

if (strpos($log, $secret) !== false) {
	$leaks[] = 'cacti.log';
}

foreach (db_fetch_assoc(
	"SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND (TABLE_NAME LIKE " .
		"'plugin\\_cloud\\_%' OR TABLE_NAME = 'settings')"
) as $table) {
	foreach (db_fetch_assoc('SELECT * FROM `' . $table['t'] . '`') as $row) {
		if (strpos(implode("\0", array_map('strval', $row)), $secret) !== false) {
			$leaks[] = $table['t'];
		}
	}
}

perm_expect(
	count($responses) === 6 && $leaks === array(),
	'the credential appears in no response, log, or table' . ($leaks === array() ? '' : ': ' . implode(
		', ',
		array_unique($leaks)
	))
);

// Cloud graphs follow Cacti's graph and device permissions, with no plugin
// layer (REQ-PERM-002): a graph on a scope device, viewed through Cacti.
include_once($config['base_path'] . '/plugins/cloud/setup.php');
include_once($config['base_path'] . '/lib/api_data_source.php');
include_once($config['base_path'] . '/lib/api_graph.php');
include_once($config['base_path'] . '/lib/data_query.php');
include_once($config['base_path'] . '/lib/template.php');
include_once($config['base_path'] . '/lib/utility.php');
cloud_boot();

// Permissive: a graph is visible when its graph, device, or template is.
db_execute("REPLACE INTO settings (name, value) VALUES ('graph_auth_method', '1')");
db_execute_prepared(
	"INSERT INTO plugin_cloud_account (provider, name, credential_source) VALUES ('digitalocean', ?, 'vault')",
	array('Graph probe')
);
$graphAccount = (int) db_fetch_insert_id();
$resourceName = 'graph-probe-' . bin2hex(random_bytes(3));
db_execute_prepared(
	"INSERT INTO plugin_cloud_resource (account_id, type, provider_id, name, lifecycle, first_seen, last_seen)
		VALUES (?, 'droplet', '7001', ?, 'active', UNIX_TIMESTAMP(), UNIX_TIMESTAMP())",
	array($graphAccount, $resourceName)
);
$host      = cloud_scope_devices()->create(cloud_accounts()->find($graphAccount));
$templates = new \Somethingwithproof\Cloud\TemplateRepository(false);
$query     = (int) $templates->id('snmp_query', 'data_query/droplet');
$suggested = array();
$created   = create_complete_graph_from_template(
	$templates->id('graph_templates', 'graph_template/do_cpu'),
	$host,
	array(
		'snmp_query_id'       => $query,
		'snmp_index_on'       => get_best_data_query_index_type($host, $query),
		'snmp_query_graph_id' => $templates->id('snmp_query_graph', 'data_query_graph/do_cpu'),
		'snmp_index'          => '7001',
	),
	$suggested
);
$graph = (int) $created['local_graph_id'];
perm_expect($host > 0 && $graph > 0, "a cloud graph exists on scope device $host (graph $graph)");

perm_user('graphs', array(7));
perm_user('nographs', array(7), 2);
db_execute_prepared(
	'INSERT INTO user_auth_perms (user_id, item_id, type) VALUES (?, ?, 3)',
	array(perm_user('deviceonly', array(7), 2), $host)
);

foreach (array('graphs' => true, 'nographs' => false, 'deviceonly' => true) as $user => $allowed) {
	$graphJar = perm_login($user);
	list($code, $body) = perm_http($root . 'graph.php?action=view&rra_id=all&local_graph_id=' . $graph, $graphJar);
	$shown = strpos($body, 'local_graph_id=' . $graph) !== false && strpos($body, 'permission_denied') === false
		&& stripos($body, 'Permission Denied') === false;

	perm_expect($shown === $allowed, "$user " . ($allowed ? 'sees' : 'is denied') . " the cloud graph (HTTP $code)");
	unlink($graphJar);
}

// Cloud Health, its warnings and its estimate list an account to a viewer
// only when Cacti's device permissions allow its scope device.
perm_user('healthdenied', array(8, $view), 2);
db_execute_prepared(
	'INSERT INTO user_auth_perms (user_id, item_id, type) VALUES (?, ?, 3)',
	array(perm_user('healthdevice', array(8, $view), 2), $host)
);

foreach (array('admin' => true, 'viewer' => true, 'healthdevice' => true, 'healthdenied' => false) as $user => $allowed) {
	$healthJar = $user === 'admin' ? $jar : perm_login($user);
	list($code, $body) = perm_http($base . 'cloud_health.php', $healthJar);

	perm_expect(
		strpos($body, 'Cloud Warnings') !== false && strpos($body, 'Cloud Request Estimate') !== false
			&& (strpos($body, 'Graph probe') !== false) === $allowed,
		"$user " . ($allowed ? 'sees' : 'does not see') . " the scope device's account on cloud_health.php (HTTP $code)"
	);

	if ($healthJar !== $jar) {
		unlink($healthJar);
	}
}

// The script server entry point serves nothing over the web, even to an administrator.
list($code, $body) = perm_http($base . 'ss_cloud.php?argv[]=' . $host . '&argv[]=droplet&argv[]=index', $jar);
// Cacti's cli_check.php answers 404, so the file must exist for the 404 to count.
perm_expect(
	is_file($config['base_path'] . '/plugins/cloud/ss_cloud.php')
		&& strpos($body, '7001') === false && strpos($body, $resourceName) === false,
	"ss_cloud.php serves no data over HTTP (HTTP $code)"
);

// Access revoked while logged in (REQ-PERM-001): Cacti's user page removes
// the realm and changes reset_perms, so the open session loses the page.
foreach (array('cloud_health.php' => array('revoked', $view), 'cloud_accounts.php' => array('revokedadmin', $admin)) as
	$page => $revoke) {
	list($user, $realm) = $revoke;
	$id          = perm_user($user, array(8, $realm));
	$revokedJar  = perm_login($user);
	list(, $before) = perm_http($base . $page, $revokedJar);

	db_execute_prepared('DELETE FROM user_auth_realm WHERE user_id = ? AND realm_id = ?', array($id, $realm));
	db_execute_prepared('UPDATE user_auth SET reset_perms = ? WHERE id = ?', array(random_int(1, 2147483647), $id));

	$after = array();

	foreach (array(1, 2) as $attempt) {
		list(, $after[]) = perm_http($base . $page, $revokedJar);
	}

	perm_expect(
		preg_match('/' . $pages[$page] . '/', $before) === 1
			&& preg_match('/' . $pages[$page] . '/', $after[0]) !== 1
			&& preg_match('/' . $pages[$page] . '/', $after[1]) !== 1,
		"$user loses $page in the same session once the realm is revoked"
	);

	// A form taken before the revocation changes nothing after it.
	if ($page === 'cloud_accounts.php') {
		perm_http(
			$base . 'cloud_accounts.php',
			$revokedJar,
			array('__csrf_magic' => perm_token($before), 'action' => 'delete', 'id' => $graphAccount)
		);
		perm_expect(
			(int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_cloud_account WHERE id = ?', array($graphAccount)) === 1,
			'a revoked administrator\'s delete has no effect'
		);
	}

	unlink($revokedJar);
}

db_execute("DELETE FROM settings WHERE name = 'cloud_vault_key_file'");
unlink($keyFile);
unlink($jar);

exit($failures > 0 ? 1 : 0);
