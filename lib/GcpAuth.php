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
 * Pure-PHP Google Cloud authentication: RS256 service-account JWT assertion
 * exchanged at oauth2.googleapis.com/token, plus GCE metadata server token
 * acquisition for instance-identity accounts.
 */
final class GcpAuth {
	public const TOKEN_HOST    = 'oauth2.googleapis.com';
	public const TOKEN_URL     = 'https://oauth2.googleapis.com/token';
	public const METADATA_HOST = 'metadata.google.internal';
	public const METADATA_URL  = 'http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token';
	public const GRANT_TYPE    = 'urn:ietf:params:oauth:grant-type:jwt-bearer';
	public const DEFAULT_SCOPE = 'https://www.googleapis.com/auth/compute.readonly https://www.googleapis.com/auth/monitoring.read';

	private const MIN_LIFETIME = 60;
	private const MAX_LIFETIME = 3600;

	public static function base64UrlEncode(string $data): string {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	/**
	 * Build and sign an RS256 JWT assertion using ext-openssl.
	 *
	 * @throws DriverError
	 */
	public static function buildJwt(
		string $clientEmail,
		#[\SensitiveParameter] string $privateKeyPem,
		string $scope,
		string $audience,
		int $now,
		int $lifetime = self::MAX_LIFETIME,
		?string $privateKeyId = null,
	): string {
		if ($clientEmail === '' || $privateKeyPem === '' || $scope === '' || $audience === '') {
			throw new DriverError(ErrorClass::Config, 'incomplete GCP service account credentials');
		}

		$ttl    = max(self::MIN_LIFETIME, min(self::MAX_LIFETIME, $lifetime));
		$header = ['alg' => 'RS256', 'typ' => 'JWT'];

		if ($privateKeyId !== null && $privateKeyId !== '') {
			$header['kid'] = $privateKeyId;
		}

		$claims = [
			'iss'   => $clientEmail,
			'scope' => $scope,
			'aud'   => $audience,
			'iat'   => $now,
			'exp'   => $now + $ttl,
		];

		try {
			$encodedHeader = self::base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
			$encodedClaims = self::base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
		} catch (\JsonException) {
			throw new DriverError(ErrorClass::Config, 'failed to encode JWT payload');
		}

		$signingInput = $encodedHeader . '.' . $encodedClaims;
		$key          = openssl_pkey_get_private($privateKeyPem);

		if ($key === false) {
			throw new DriverError(ErrorClass::Config, 'invalid GCP service account private key');
		}

		$signature = '';
		$signed    = openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);

		if (!$signed || !is_string($signature) || $signature === '') {
			throw new DriverError(ErrorClass::Config, 'RS256 signing failed');
		}

		return $signingInput . '.' . self::base64UrlEncode($signature);
	}

	/**
	 * Exchange a service-account JSON key document for an OAuth2 bearer token.
	 *
	 * @param array<array-key, mixed> $keyDoc
	 * @return array{token: Secret, expiresAt: int}
	 * @throws DriverError
	 */
	public static function exchangeServiceAccount(
		HttpTransport $http,
		array $keyDoc,
		string $scope,
		int $now,
	): array {
		$email   = is_string($keyDoc['client_email'] ?? null) ? trim($keyDoc['client_email']) : '';
		$pem     = is_string($keyDoc['private_key'] ?? null) ? $keyDoc['private_key'] : '';
		$keyId   = is_string($keyDoc['private_key_id'] ?? null) ? trim($keyDoc['private_key_id']) : null;
		$jwt     = self::buildJwt($email, $pem, $scope, self::TOKEN_URL, $now, self::MAX_LIFETIME, $keyId);
		$body    = http_build_query([
			'grant_type' => self::GRANT_TYPE,
			'assertion'  => $jwt,
		], '', '&', PHP_QUERY_RFC3986);

		try {
			$response = $http->send(new HttpRequest(
				'POST',
				self::TOKEN_URL,
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
	 * Fetch a bearer token from the GCE instance metadata server.
	 *
	 * @return array{token: Secret, expiresAt: int}
	 * @throws DriverError
	 */
	public static function fetchMetadataToken(HttpTransport $http, int $now): array {
		try {
			$response = $http->send(new HttpRequest(
				'GET',
				self::METADATA_URL,
				[
					'Metadata-Flavor' => 'Google',
					'Accept'          => 'application/json',
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
				$response->status === 429                              => ErrorClass::Throttled,
				$response->status >= 500                               => ErrorClass::Transient,
				default                                                => ErrorClass::Malformed,
			};

			throw new DriverError($class, 'HTTP ' . $response->status);
		}

		try {
			$decoded = json_decode($response->body, true, 16, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new DriverError(ErrorClass::Malformed, 'OAuth2 token response is not JSON');
		}

		if (!is_array($decoded) || !is_string($decoded['access_token'] ?? null) || $decoded['access_token'] === '') {
			throw new DriverError(ErrorClass::Malformed, 'OAuth2 token response missing access_token');
		}

		$expiresIn = is_numeric($decoded['expires_in'] ?? null) ? (int) $decoded['expires_in'] : 3600;
		$expiresIn = max(60, min(self::MAX_LIFETIME, $expiresIn));

		return [
			'token'     => new Secret($decoded['access_token']),
			'expiresAt' => $now + $expiresIn,
		];
	}
}
