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

use Somethingwithproof\Cloud\Account;
use Somethingwithproof\Cloud\CredentialError;
use Somethingwithproof\Cloud\CredentialFile;
use Somethingwithproof\Cloud\CredentialStore;
use Somethingwithproof\Cloud\FixedClock;
use Somethingwithproof\Cloud\Redactor;
use Somethingwithproof\Cloud\Secret;
use Somethingwithproof\Cloud\SecretRepository;
use Somethingwithproof\Cloud\Vault;
use Somethingwithproof\Cloud\VaultEntry;
use Somethingwithproof\Cloud\VaultKey;

const CLOUD_TEST_SECRET = 'dop_v1_0123456789abcdef0123456789abcdef0123456789abcdef0123456789ab';

beforeEach(function () {
	$this->dir = sys_get_temp_dir() . '/cloud-vault-' . bin2hex(random_bytes(4));
	mkdir($this->dir, 0700);
});

afterEach(function () {
	foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $path) {
		if (is_link($path) || is_file($path)) {
			unlink($path);
		} elseif (is_dir($path) && !in_array(basename($path), ['.', '..'], true)) {
			rmdir($path);
		}
	}

	rmdir($this->dir);
});

function cloud_file(string $dir, string $name, string $content, int $mode = 0600): string {
	$path = $dir . '/' . $name;
	file_put_contents($path, $content);
	chmod($path, $mode);

	return $path;
}

function cloud_refusal(callable $read): string {
	try {
		$read();
	} catch (CredentialError $e) {
		return $e->getMessage();
	}

	return 'accepted';
}

it('round-trips a secret through AES-256-GCM with a fresh nonce each time', function () {
	$vault = new Vault(VaultKey::fromBytes(random_bytes(32)));
	$one   = $vault->encrypt(new Secret(CLOUD_TEST_SECRET), 5, 'credential');
	$two   = $vault->encrypt(new Secret(CLOUD_TEST_SECRET), 5, 'credential');

	expect($vault->decrypt($one, 5, 'credential')->reveal())->toBe(CLOUD_TEST_SECRET)
		->and(strlen($one->nonce))->toBe(Vault::NONCE_BYTES)
		->and($one->nonce)->not->toBe($two->nonce)
		->and($one->ciphertext)->not->toContain(CLOUD_TEST_SECRET);
});

it('binds a ciphertext to its account and purpose', function () {
	$vault = new Vault(VaultKey::fromBytes(random_bytes(32)));
	$entry = $vault->encrypt(new Secret(CLOUD_TEST_SECRET), 5, 'credential');

	expect(cloud_refusal(fn () => $vault->decrypt($entry, 6, 'credential')))->toContain('integrity')
		->and(cloud_refusal(fn () => $vault->decrypt($entry, 5, 'token')))->toContain('integrity');
});

it('detects a tampered ciphertext or tag', function () {
	$vault = new Vault(VaultKey::fromBytes(random_bytes(32)));
	$entry = $vault->encrypt(new Secret(CLOUD_TEST_SECRET), 5, 'credential');

	$flipped = new VaultEntry($entry->nonce, $entry->ciphertext ^ "\x01", $entry->tag, $entry->keyId);
	$badTag  = new VaultEntry($entry->nonce, $entry->ciphertext, str_repeat("\0", 16), $entry->keyId);

	expect(cloud_refusal(fn () => $vault->decrypt($flipped, 5, 'credential')))->toContain('integrity')
		->and(cloud_refusal(fn () => $vault->decrypt($badTag, 5, 'credential')))->toContain('integrity');
});

it('reports a replaced key as such, not as corruption', function () {
	$entry = (new Vault(VaultKey::fromBytes(random_bytes(32))))->encrypt(new Secret(CLOUD_TEST_SECRET), 5, 'credential');

	expect(
		cloud_refusal(fn () => (new Vault(VaultKey::fromBytes(random_bytes(32))))->decrypt($entry, 5, 'credential'))
	)->toContain('different vault key');
});

it('reads a key file of 32 raw bytes or 64 hex characters', function () {
	$bytes = random_bytes(32);

	expect(VaultKey::fromFile(cloud_file($this->dir, 'raw', $bytes))->bytes())->toBe($bytes)
		->and(VaultKey::fromFile(cloud_file($this->dir, 'hex', bin2hex($bytes) . "\n"))->bytes())->toBe($bytes)
		->and(VaultKey::fromFile(cloud_file($this->dir, 'hex0640', bin2hex($bytes), 0640))->bytes())->toBe($bytes);
});

it('refuses key files that fail a check, naming the check', function (string $case, string $reason) {
	$dir = $this->dir;

	$path = match ($case) {
		'relative'   => 'etc/cloud.key',
		'missing'    => $dir . '/missing.key',
		'symlink'    => (function () use ($dir) {
			symlink(cloud_file($dir, 'target', bin2hex(random_bytes(32))), $dir . '/link');

			return $dir . '/link';
		})(),
		'directory'  => (function () use ($dir) {
			mkdir($dir . '/sub', 0700);

			return $dir . '/sub';
		})(),
		'world-read' => cloud_file($dir, 'wr', bin2hex(random_bytes(32)), 0604),
		'world-write'=> cloud_file($dir, 'ww', bin2hex(random_bytes(32)), 0602),
		'short'      => cloud_file($dir, 'short', random_bytes(31)),
		'long'       => cloud_file($dir, 'long', random_bytes(33)),
		'not hex'    => cloud_file($dir, 'nothex', str_repeat('g', 64)),
		'oversize'   => cloud_file($dir, 'big', str_repeat('a', 100)),
	};

	expect(cloud_refusal(fn () => VaultKey::fromFile($path)))->toContain($reason);
})->with([
	['relative', 'absolute path'],
	['missing', 'does not exist'],
	['symlink', 'symbolic link'],
	['directory', 'not a regular file'],
	['world-read', 'other users'],
	['world-write', 'other users'],
	['short', '32 bytes or 64 hexadecimal'],
	['long', '32 bytes or 64 hexadecimal'],
	['not hex', '32 bytes or 64 hexadecimal'],
	['oversize', 'larger than allowed'],
]);

it('refuses credential files that fail a check', function () {
	$dir = $this->dir;
	symlink(cloud_file($dir, 'real', CLOUD_TEST_SECRET), $dir . '/link');
	mkdir($dir . '/sub');
	$read = fn (string $path): Secret => CredentialFile::read($path, $dir);

	expect($read(cloud_file($dir, 'ok', CLOUD_TEST_SECRET . "\n"))->reveal())->toBe(CLOUD_TEST_SECRET)
		->and(cloud_refusal(fn () => $read('token')))->toContain('credential directory')
		->and(cloud_refusal(fn () => $read($dir . '/link')))->toContain('symbolic link')
		->and(cloud_refusal(fn () => $read($dir . '/sub')))->toContain('not a regular file')
		->and(cloud_refusal(fn () => $read(cloud_file($dir, 'open', CLOUD_TEST_SECRET, 0644))))->toContain('other users')
		->and(cloud_refusal(fn () => $read(cloud_file($dir, 'empty', "\n"))))->toContain('empty')
		->and(
			cloud_refusal(fn () => $read(cloud_file($dir, 'big', str_repeat('a', CredentialFile::MAX_BYTES + 1))))
		)->toContain('larger than allowed');

	rmdir($dir . '/sub');
});

it('reads credential files only inside the directory a full administrator set', function () {
	$dir   = $this->dir;
	$token = cloud_file($dir, 'token', CLOUD_TEST_SECRET);
	mkdir($dir . '/nested');
	$deep  = cloud_file($dir . '/nested', 'token', CLOUD_TEST_SECRET);
	mkdir($dir . '/other');
	$other = cloud_file($dir . '/other', 'token', CLOUD_TEST_SECRET);
	symlink($dir . '/other', $dir . '/nested/escape');

	$reason = 'the file is not in the credential directory set in the Cloud settings';

	expect(CredentialFile::read($token, $dir)->reveal())->toBe(CLOUD_TEST_SECRET)
		->and(CredentialFile::read($deep, $dir)->reveal())->toBe(CLOUD_TEST_SECRET)
		->and(CredentialFile::read($deep, $dir . '/nested/')->reveal())->toBe(CLOUD_TEST_SECRET)
		// No directory set, the root, a sibling, or a link out of it: refused.
		->and(cloud_refusal(fn () => CredentialFile::read($token, '')))->toBe($reason)
		->and(cloud_refusal(fn () => CredentialFile::read($token, '/')))->toBe($reason)
		->and(cloud_refusal(fn () => CredentialFile::read($other, $dir . '/nested')))->toBe($reason)
		->and(cloud_refusal(fn () => CredentialFile::read($dir . '/nested/escape/token', $dir . '/nested')))->toBe($reason)
		->and(cloud_refusal(fn () => CredentialFile::read($dir . '/nestedx/token', $dir . '/nested')))->toBe($reason)
		// Outside, a missing file and an unsafe one are refused alike.
		->and(cloud_refusal(fn () => CredentialFile::read($dir . '/other/none', $dir . '/nested')))->toBe($reason)
		->and(cloud_refusal(fn () => CredentialFile::check($other, $dir . '/nested')))->toBe($reason);

	foreach (['nested/escape', 'nested/token', 'other/token'] as $file) {
		unlink($dir . '/' . $file);
	}

	rmdir($dir . '/nested');
	rmdir($dir . '/other');
});

it('never uses the vault key file as a credential', function () {
	$key   = cloud_file($this->dir, 'vault.key', bin2hex(random_bytes(32)));
	$store = new CredentialStore(new SecretRepository(false, new FixedClock(1)), $key, $this->dir);
	$row   = [
		'id'                => 4,
		'provider'          => 'digitalocean',
		'name'              => 'Ops',
		'credential_source' => 'file',
		'credential_file'   => $key,
		'admin_state'       => 'enabled',
	];

	expect(cloud_refusal(fn () => $store->credentialFor(Account::fromRow($row))))->toBe('the file is the vault key file')
		->and($store->credentialFor(
			Account::fromRow(['credential_file' => cloud_file($this->dir, 'tok', CLOUD_TEST_SECRET)] + $row)
		)->reveal())->toBe(CLOUD_TEST_SECRET);
});

it('never puts secret material in a refusal message', function () {
	$path = cloud_file($this->dir, 'k', CLOUD_TEST_SECRET);

	expect(cloud_refusal(fn () => VaultKey::fromFile($path)))->not->toContain(CLOUD_TEST_SECRET);
});

it('hides secrets from dumps and refuses to serialize or export them', function () {
	$secret = new Secret(CLOUD_TEST_SECRET);
	$key    = VaultKey::fromBytes(str_repeat('k', 32));

	ob_start();
	var_dump($secret, $key);
	$dumped = (string) ob_get_clean();

	expect($dumped . print_r($secret, true) . print_r($key, true))->not->toContain(CLOUD_TEST_SECRET)
		->and($dumped)->not->toContain(str_repeat('k', 32))
		->and(fn () => serialize($secret))->toThrow(LogicException::class)
		->and(fn () => serialize($key))->toThrow(LogicException::class)
		->and(fn () => json_encode(['s' => $secret], JSON_THROW_ON_ERROR))->not->toThrow(Throwable::class)
		->and(json_encode(['s' => $secret]))->not->toContain(CLOUD_TEST_SECRET);
});

it('keeps secrets out of exception traces', function () {
	$trace = '';

	try {
		(static function (Secret $s, string $raw) {
			throw new RuntimeException('boom');
		})(new Secret(CLOUD_TEST_SECRET), 'x');
	} catch (RuntimeException $e) {
		$trace = $e->getTraceAsString();
	}

	expect($trace)->not->toContain(CLOUD_TEST_SECRET);
});

it('redacts known secrets in every encoding and authorization material by pattern', function () {
	$redactor = new Redactor();
	$redactor->add(new Secret('p@ss/word+with spaces'));
	$redactor->add(new Secret(CLOUD_TEST_SECRET));

	$text = implode("\n", [
		'token ' . CLOUD_TEST_SECRET,
		'url ?key=' . rawurlencode('p@ss/word+with spaces') . '&x=' . urlencode('p@ss/word+with spaces'),
		'json ' . json_encode(['v' => 'p@ss/word+with spaces']),
		'Authorization: AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20261114, Signature=abc123',
		'header Bearer eyJhbGciOi.payload.sig',
		'query X-Amz-Signature=deadbeef&X-Amz-Credential=AKID%2F2026',
		'body {"access_token":"ya29.a0Af","expires_in":3599}',
		"-----BEGIN PRIVATE KEY-----\nMIIEv\n-----END PRIVATE KEY-----",
	]);

	$clean = $redactor->clean($text);

	foreach ([
		'p@ss/word+with spaces',
		rawurlencode('p@ss/word+with spaces'),
		urlencode('p@ss/word+with spaces'),
		CLOUD_TEST_SECRET,
		'abc123',
		'eyJhbGciOi',
		'deadbeef',
		'AKID%2F2026',
		'ya29.a0Af',
		'MIIEv'
	] as $leak) {
		expect($clean)->not->toContain($leak);
	}

	expect($clean)->toContain('expires_in');
});

it('masks every accepted credential, however short', function () {
	$redactor = new Redactor();
	$redactor->add(new Secret('s3cr3t!'));
	$redactor->add(new Secret(''));

	expect($redactor->clean('credential rejected: s3cr3t!'))->toBe('credential rejected: [redacted]');
});

it('masks header values even when a known secret is part of the header name', function () {
	$redactor = new Redactor();
	$redactor->add(new Secret('auth'));

	expect($redactor->clean('authorization: fresh-secret-123'))->not->toContain('fresh-secret-123')
		->and($redactor->clean('token auth failed'))->toBe('token [redacted] failed');
});

it('masks a known secret whole when a pattern covers only part of it', function () {
	$redactor = new Redactor();
	$redactor->add(new Secret('first-half second-half'));

	$clean = $redactor->clean("Bearer first-half second-half\naccess_token=first-half second-half&x=1\nsignature=&y=2");

	expect($clean)->not->toContain('first-half')
		->and($clean)->not->toContain('second-half')
		->and($clean)->toContain('signature=[redacted]&y=2')
		->and($clean)->toContain('&x=1');
});

it('keeps secrets out of var_export, including inside a vault key', function () {
	$secret = new Secret('example-token-value');
	$key    = VaultKey::fromBytes(str_repeat('k', 32));

	expect(var_export($secret, true))->not->toContain('example-token-value')
		->and(var_export($key, true))->not->toContain(str_repeat('k', 32))
		->and(print_r($secret, true))->not->toContain('example-token-value')
		->and($secret->reveal())->toBe('example-token-value')
		->and(fn () => clone $secret)->toThrow(LogicException::class);
});

it('refuses a FIFO before opening it, which would block', function () {
	$fifo = $this->dir . '/fifo';

	$made = function_exists('posix_mkfifo') ? posix_mkfifo($fifo, 0600) : (
		exec('mkfifo -m 600 ' . escapeshellarg($fifo) . ' 2>/dev/null', $out, $rc) !== false && $rc === 0
	);

	if (!$made) {
		$this->markTestSkipped('posix_mkfifo is not available');
	}

	try {
		expect(cloud_refusal(fn () => VaultKey::fromFile($fifo)))->toContain('not a regular file');
	} finally {
		unlink($fifo);
	}
});

it('pins vault rows with missing, wrongly sized, and malformed fields', function () {
	$row = [
		'nonce' => str_repeat('n', 12),
		'ciphertext' => 'c',
		'tag' => str_repeat('t', 16),
		'key_id' => 'k1',
		'expires_at' => '1800000000'
	];

	expect(VaultEntry::fromRow($row))->toEqual(
		new VaultEntry(str_repeat('n', 12), 'c', str_repeat('t', 16), 'k1', 1800000000)
	)
		->and(VaultEntry::fromRow(['expires_at' => 'x', 'key_id' => null] + $row))->toEqual(
			new VaultEntry(str_repeat('n', 12), 'c', str_repeat('t', 16), '', 0)
		)
		->and(VaultEntry::fromRow(['nonce' => str_repeat('n', 11)] + $row))->toBeNull()
		->and(VaultEntry::fromRow(['tag' => str_repeat('t', 15)] + $row))->toBeNull()
		->and(VaultEntry::fromRow(['ciphertext' => null] + $row))->toBeNull()
		->and(VaultEntry::fromRow(['nonce' => 12] + $row))->toBeNull()
		->and(VaultEntry::fromRow([]))->toBeNull();
});
