<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionCatalogReading;

/**
 * Shared wording for the background-refreshed catalog count (M2.5).
 */
final class NeedsAttentionCatalogWording {

	public static function updating(): string {
		return __( 'Updating…', 'cetech-woocommerce-delivery-engine' );
	}

	/**
	 * "Last checked 4 mins ago", plus "updating" while a refresh is pending.
	 * Empty before the first complete check.
	 */
	public static function last_checked( ?NeedsAttentionCatalogReading $reading, ?int $now = null ): string {
		if ( null === $reading || null === $reading->computed_at ) {
			return null === $reading ? '' : __( 'Product checks are running in the background.', 'cetech-woocommerce-delivery-engine' );
		}

		$now  ??= time();
		$text = sprintf(
			/* translators: %s: how long ago, for example "4 mins" */
			__( 'Products last checked %s ago', 'cetech-woocommerce-delivery-engine' ),
			self::elapsed( $reading->computed_at, $now )
		);

		if ( ! $reading->fresh ) {
			$text .= ' · ' . __( 'updating', 'cetech-woocommerce-delivery-engine' );
		}

		return $text;
	}

	private static function elapsed( int $from, int $now ): string {
		if ( function_exists( 'human_time_diff' ) ) {
			return (string) human_time_diff( $from, max( $from, $now ) );
		}

		$minutes = max( 1, (int) floor( max( 0, $now - $from ) / 60 ) );

		return sprintf( '%d min', $minutes );
	}
}
