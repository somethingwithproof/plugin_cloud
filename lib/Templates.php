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
 * Installs the shipped Cacti templates (REQ-OBJ-001, REQ-OBJ-002): copies
 * each data query definition into Cacti's resource directory, then imports
 * the package when it changed since the last import or an object is
 * missing. The package's fixed hashes make a re-import update the objects
 * it already created, never duplicate them.
 *
 * Install and upgrade call this. A failure is logged and returns false; the
 * caller does not hold back the install, because retrying a failing import
 * on every page would cost more than it could fix.
 */
final class Templates {
	/**
	 * Filename of the shipped Cacti XML template bundle.
	 */
	public const PACKAGE = 'cloud_digitalocean.xml';

	/**
	 * @param string $source       the plugin's templates directory
	 * @param string $resourcePath <path_cacti>/resource/script_server
	 * @param \Closure(string, int): bool $import runs Cacti's importer on the
	 *        package XML with a data source profile, true when every object saved
	 */
	public function __construct(
		private readonly TemplateRepository $repository,
		private readonly string $source,
		private readonly string $resourcePath,
		private readonly \Closure $import,
	) {
	}

	/**
	 * True when the shipped package is the one last imported. Cheap enough
	 * for the upgrade check: one file read and one settings row.
	 */
	public function isCurrent(): bool {
		$xml = @file_get_contents($this->source . '/' . self::PACKAGE);

		return $xml !== false && $this->repository->recordedChecksum() === hash('sha256', $xml);
	}

	/**
	 * Acquire the import lock and install or refresh shipped data queries and templates.
	 *
	 * @param int $lockTimeout lock acquisition timeout in seconds
	 * @return bool true when templates and resources are current after installation
	 */
	public function install(int $lockTimeout = 10): bool {
		if (!is_dir($this->resourcePath)) {
			Log::error('Templates not installed: the Cacti resource directory is missing');

			return false;
		}

		if (!$this->repository->lock($lockTimeout)) {
			Log::warning('Template import deferred: another process holds the template lock');

			return false;
		}

		try {
			return $this->installLocked();
		} catch (\Throwable $e) {
			Log::error('Template import failed (' . $e::class . ')');

			return false;
		} finally {
			$this->repository->unlock();
		}
	}

	/**
	 * Perform resource XML copy and template import while holding the import lock.
	 *
	 * @return bool true when all resources and template objects are present
	 */
	private function installLocked(): bool {
		foreach (glob($this->source . '/resource/*.xml') ?: [] as $file) {
			if (!$this->copy($file, $this->resourcePath . '/' . basename($file))) {
				return false;
			}
		}

		$xml = @file_get_contents($this->source . '/' . self::PACKAGE);

		if ($xml === false) {
			Log::error('Template import failed: cannot read the package');

			return false;
		}

		$sha = hash('sha256', $xml);

		if ($this->repository->recordedChecksum() === $sha && $this->repository->missing() === []) {
			return true;
		}

		$profile = $this->repository->defaultProfile();

		if ($profile === 0) {
			Log::error('Template import skipped: no default data source profile');

			return false;
		}

		if (!($this->import)($xml, $profile)) {
			Log::error('Template import failed: the importer reported an error');

			return false;
		}

		$missing = $this->repository->missing();

		if ($missing !== []) {
			Log::error('Template import failed: ' . count($missing) . ' objects missing after import');

			return false;
		}

		$this->repository->recordChecksum($sha);

		return true;
	}

	/**
	 * Writes a temporary file beside the target and renames it over it, so
	 * the poller never reads a half-written definition. Mode 'x' refuses an
	 * existing name, so a planted link at the temporary path is not followed.
	 */
	private function copy(string $from, string $to): bool {
		$content = @file_get_contents($from);

		if ($content === false) {
			Log::error('Template import failed: cannot read ' . basename($from));

			return false;
		}

		if (!is_link($to) && is_file($to) && @file_get_contents($to) === $content) {
			return true;
		}

		$temp   = dirname($to) . '/.' . basename($to) . '.' . bin2hex(random_bytes(8));
		$handle = @fopen($temp, 'xb');

		if ($handle === false) {
			Log::error('Template import failed: cannot write ' . basename($to));

			return false;
		}

		$written = fwrite($handle, $content);
		$closed  = fclose($handle);

		// fopen() honours the umask, and the poller may run as another user.
		if ($written !== strlen($content) || !$closed || !@chmod($temp, 0644) || !@rename($temp, $to)) {
			@unlink($temp);
			Log::error('Template import failed: cannot write ' . basename($to));

			return false;
		}

		return true;
	}
}
