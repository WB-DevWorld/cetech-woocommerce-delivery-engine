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

	public static function sanitize_per_page( int $value ): int {
		return in_array( $value, self::ALLOWED, true ) ? $value : self::DEFAULT_PER_PAGE;
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

		return self::sanitize_per_page( (int) get_user_option( self::OPTION ) );
	}
}
