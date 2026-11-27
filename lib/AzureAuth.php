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
 * Microsoft Azure authentication: Entra ID OAuth2 client-credentials flow
 * (client_secret only) and Azure IMDS managed-identity token acquisition.
 * Certificate assertions (PS256) are intentionally excluded.
 */
final class AzureAuth {
	public const LOGIN_HOST     = 'login.microsoftonline.com';
	public const IMDS_HOST      = '169.254.169.254';
	public const ARM_SCOPE      = 'https://management.azure.com/.default';
	public const METRICS_SCOPE  = 'https://metrics.monitor.azure.com/.default';
	public const ARM_RESOURCE   = 'https://management.azure.com/';
	public const METRICS_RES    = 'https://metrics.monitor.azure.com/';

	private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

	/**
	 * Build the RFC 3986 URL-encoded client credentials request body.
	 */
	public static function buildClientCredentialsBody(
		string $clientId,
		#[\SensitiveParameter] string $clientSecret,
		string $scope,
	): string {
		return http_build_query([
			'client_id'     => $clientId,
			'scope'         => $scope,
			'client_secret' => $clientSecret,
			'grant_type'    => 'client_credentials',
		], '', '&', PHP_QUERY_RFC3986);
	}

	/**
	 * Acquire a bearer token from Entra ID using a client_id and client_secret.
	 *
	 * @return array{token: Secret, expiresAt: int}
	 * @throws DriverError
	 */
	public static function acquireClientCredentialsToken(
		HttpTransport $http,
		string $tenantId,
		string $clientId,
		#[\SensitiveParameter] string $clientSecret,
		string $scope,
		int $now,
	): array {
		if (
			preg_match(self::UUID_RE, $tenantId) !== 1
			|| $clientId === ''
			|| $clientSecret === ''
			|| $scope === ''
		) {
			throw new DriverError(ErrorClass::Config, 'incomplete Azure Entra ID credentials');
		}

		$url  = 'https://' . self::LOGIN_HOST . '/' . $tenantId . '/oauth2/v2.0/token';
		$body = self::buildClientCredentialsBody($clientId, $clientSecret, $scope);

		try {
			$response = $http->send(new HttpRequest(
				'POST',
				$url,
				[
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept'       => 'application/json',
				],
				$body
			));
		} catch (TransportError $e) {
			throw DriverError::fromTransport($e);
		}

		return self::parseTokenResponse($response, $now);
	}

	/**
	 * Acquire a managed-identity bearer token from Azure IMDS.
	 *
	 * @return array{token: Secret, expiresAt: int}
	 * @throws DriverError
	 */
	public static function acquireManagedIdentityToken(
		HttpTransport $http,
		string $resource,
		int $now,
		?string $clientId = null,
	): array {
		$query = [
			'api-version' => '2018-02-01',
			'resource'    => $resource,
		];

		if ($clientId !== null && $clientId !== '') {
			if (preg_match(self::UUID_RE, $clientId) !== 1) {
				throw new DriverError(ErrorClass::Config, 'invalid Azure managed identity client_id');
			}

			$query['client_id'] = $clientId;
		}

		$url = 'http://' . self::IMDS_HOST . '/metadata/identity/oauth2/token?'
			. http_build_query($query, '', '&', PHP_QUERY_RFC3986);

		try {
			$response = $http->send(new HttpRequest(
				'GET',
				$url,
				[
					'Metadata' => 'true',
					'Accept'   => 'application/json',
				]
			));
		} catch (TransportError $e) {
			throw DriverError::fromTransport($e);
		}

		return self::parseTokenResponse($response, $now);
	}

	/**
	 * @return array{token: Secret, expiresAt: int}
	 * @throws DriverError
	 */
	private static function parseTokenResponse(HttpResponse $response, int $now): array {
		if ($response->status !== 200) {
			$class = match (true) {
				$response->status === 400 || $response->status === 401 => ErrorClass::Auth,
				$response->status === 403                              => ErrorClass::Permission,
				$response->status === 429 || $response->status === 529 => ErrorClass::Throttled,
				$response->status >= 500                               => ErrorClass::Transient,
				default                                                => ErrorClass::Malformed,
			};

			throw new DriverError($class, 'HTTP ' . $response->status);
		}

		try {
			$decoded = json_decode($response->body, true, 16, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new DriverError(ErrorClass::Malformed, 'Azure token response is not JSON');
		}

		if (!is_array($decoded) || !is_string($decoded['access_token'] ?? null) || $decoded['access_token'] === '') {
			throw new DriverError(ErrorClass::Malformed, 'Azure token response missing access_token');
		}

		$expiresIn = is_numeric($decoded['expires_in'] ?? null) ? (int) $decoded['expires_in'] : 3600;
		$expiresIn = max(60, min(86400, $expiresIn));

		return [
			'token'     => new Secret($decoded['access_token']),
			'expiresAt' => $now + $expiresIn,
		];
	}
}
