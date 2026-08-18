<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

/**
 * Manual http/https tracking URLs. Rejects javascript/data/file and other unsafe schemes.
 */
final class TrackingUrl {

	public const MAX_LENGTH = 500;

	private function __construct() {
	}

	public static function normalize( string $raw ): ?string {
		$url = trim( $raw );

		if ( '' === $url ) {
			return null;
		}

		if ( strlen( $url ) > self::MAX_LENGTH ) {
			throw new \InvalidArgumentException( 'tracking_url_too_long' );
		}

		if ( preg_match( '/[\s<>"\']/', $url ) ) {
			throw new \InvalidArgumentException( 'tracking_url_invalid' );
		}

		$parts = parse_url( $url );

		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			throw new \InvalidArgumentException( 'tracking_url_invalid' );
		}

		$scheme = strtolower( (string) $parts['scheme'] );

		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			throw new \InvalidArgumentException( 'tracking_url_unsafe' );
		}

		$host = strtolower( (string) $parts['host'] );

		if ( '' === $host || str_contains( $host, '..' ) ) {
			throw new \InvalidArgumentException( 'tracking_url_invalid' );
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			throw new \InvalidArgumentException( 'tracking_url_invalid' );
		}

		if ( function_exists( 'esc_url_raw' ) ) {
			$cleaned = esc_url_raw( $url, [ 'http', 'https' ] );

			if ( '' === $cleaned ) {
				throw new \InvalidArgumentException( 'tracking_url_invalid' );
			}

			return $cleaned;
		}

		return $url;
	}

	public static function is_safe_http_url( ?string $url ): bool {
		if ( null === $url || '' === trim( $url ) ) {
			return false;
		}

		try {
			return null !== self::normalize( $url );
		} catch ( \InvalidArgumentException ) {
			return false;
		}
	}
}
