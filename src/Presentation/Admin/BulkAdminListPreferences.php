<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

/**
 * Conservative wp-admin page sizes for Bulk Tools lists.
 * Job processing batch size is a separate concern.
 */
final class BulkAdminListPreferences {

	public const OPTION = 'cetech_de_bulk_list_per_page';

	public const DEFAULT_PER_PAGE = 25;

	/** @var list<int> */
	public const ALLOWED = [ 20, 25, 50, 100 ];

	public const MAX_PER_PAGE = 100;

	public const MIN_PER_PAGE = 20;

	/**
	 * Persist only 20 / 25 / 50 / 100. Oversized values clamp to 100.
	 * Zero, negative, and non-numeric values become 25.
	 */
	public static function sanitize_per_page( mixed $value ): int {
		if ( is_string( $value ) ) {
			$value = trim( $value );
			if ( '' === $value || ! is_numeric( $value ) ) {
				return self::DEFAULT_PER_PAGE;
			}
		}

		if ( is_bool( $value ) || ( ! is_int( $value ) && ! is_float( $value ) && ! is_numeric( $value ) ) ) {
			return self::DEFAULT_PER_PAGE;
		}

		$n = (int) $value;
		if ( $n < self::MIN_PER_PAGE ) {
			return self::DEFAULT_PER_PAGE;
		}

		if ( $n >= self::MAX_PER_PAGE ) {
			return self::MAX_PER_PAGE;
		}

		$nearest = self::ALLOWED[0];
		$best    = PHP_INT_MAX;
		foreach ( self::ALLOWED as $allowed ) {
			$distance = abs( $n - $allowed );
			if ( $distance < $best ) {
				$best    = $distance;
				$nearest = $allowed;
			}
		}

		return $nearest;
	}

	public static function clamp_query_limit( int $limit ): int {
		return max( 1, min( self::MAX_PER_PAGE, $limit ) );
	}

	public static function current_page( string $query_arg = 'paged' ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw = isset( $_GET[ $query_arg ] ) ? absint( wp_unslash( (string) $_GET[ $query_arg ] ) ) : 1;

		return max( 1, $raw );
	}

	public static function per_page_for_current_user(): int {
		if ( ! function_exists( 'get_user_option' ) ) {
			return self::DEFAULT_PER_PAGE;
		}

		return self::sanitize_per_page( get_user_option( self::OPTION ) );
	}
}
