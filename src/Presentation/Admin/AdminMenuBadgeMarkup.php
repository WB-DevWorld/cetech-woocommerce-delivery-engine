<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

/**
 * WordPress-style numeric admin-menu badges. Visible digits plus screen-reader text.
 */
final class AdminMenuBadgeMarkup {

	public static function append( string $label, int $count, string $screen_reader ): string {
		if ( $count < 1 ) {
			return $label;
		}

		$display = $count > 99 ? '99+' : (string) $count;
		$class   = 'count-' . min( $count, 99 );

		return $label
			. ' <span class="awaiting-mod ' . esc_attr( $class ) . '">'
			. '<span class="pending-count" aria-hidden="true">' . esc_html( $display ) . '</span>'
			. '<span class="screen-reader-text"> ' . esc_html( $screen_reader ) . '</span>'
			. '</span>';
	}

	public static function needs_attention_screen_reader( int $count ): string {
		return sprintf(
			/* translators: %d: number of unresolved Needs Attention items */
			_n( '%d item needs attention', '%d items need attention', $count, 'cetech-woocommerce-delivery-engine' ),
			$count
		);
	}

	public static function shipments_activity_screen_reader( int $count ): string {
		return sprintf(
			/* translators: %d: number of shipments with new unreviewed activity */
			_n( '%d shipment with new activity', '%d shipments with new activity', $count, 'cetech-woocommerce-delivery-engine' ),
			$count
		);
	}
}
