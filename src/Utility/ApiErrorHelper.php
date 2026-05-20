<?php

namespace ContentTransfer\Utility;

use MediaWiki\Message\Message;

/**
 * Helper for extracting localized error messages from MediaWiki API responses
 * that use errorformat=raw.
 *
 * With errorformat=raw, the API returns error message keys and parameters
 * instead of pre-rendered text. This allows us to render the error message
 * locally in the user's language.
 */
class ApiErrorHelper {

	/**
	 * Extract a localized error message from a raw-format API error array.
	 *
	 * @param array $errors Array of error objects from the API response (errorformat=raw).
	 *   Each error is expected to have 'key' and optionally 'params'.
	 * @return string Localized error text, or the raw key if the message is not available locally.
	 */
	public static function extractLocalizedError( array $errors ): string {
		if ( empty( $errors ) ) {
			return '';
		}

		$firstError = (array)$errors[0];
		$key = $firstError['key'] ?? '';
		$params = $firstError['params'] ?? [];

		if ( $key === '' ) {
			return '';
		}

		$params = self::normalizeParams( $params );

		$msg = Message::newFromKey( $key )->params( $params );
		if ( $msg->exists() ) {
			return $msg->text();
		}

		// Fallback: message key not available on source wiki
		if ( $params ) {
			return $key . ': ' . implode( ', ', $params );
		}
		return $key;
	}

	/**
	 * Extract a localized error from a full API response array (associative).
	 *
	 * @param array $response Full decoded API response as associative array.
	 * @return string Localized error text, or empty string if no errors.
	 */
	public static function extractLocalizedErrorFromArray( array $response ): string {
		$errors = $response['errors'] ?? [];
		if ( empty( $errors ) ) {
			return '';
		}
		return self::extractLocalizedError( $errors );
	}

	/**
	 * Normalize params from raw API error format.
	 * Params may be plain values or objects with additional type info.
	 *
	 * @param array $params
	 * @return array
	 */
	private static function normalizeParams( array $params ): array {
		$normalized = [];
		foreach ( $params as $param ) {
			if ( is_array( $param ) || is_object( $param ) ) {
				$param = (array)$param;
				// Raw format params can be typed (e.g. {"key": "...", "value": "..."})
				$normalized[] = $param['value'] ?? $param['key'] ?? (string)json_encode( $param );
			} else {
				$normalized[] = (string)$param;
			}
		}
		return $normalized;
	}
}
