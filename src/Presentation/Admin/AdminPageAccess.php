<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Integrations\WCFM\WcfmVendorIsolation;

/**
 * Consistent wp-admin access checks for Delivery Engine pages.
 *
 * Capability checks remain WordPress-native. Restricted WCFM vendors are
 * denied even when stale role metadata still contains Delivery Engine caps.
 */
final class AdminPageAccess {

	private static ?WcfmVendorIsolation $vendor_isolation = null;

	public static function bind( ?WcfmVendorIsolation $isolation ): void {
		self::$vendor_isolation = $isolation;
	}

	public static function current_user_is_restricted(): bool {
		return null !== self::$vendor_isolation && self::$vendor_isolation->denies_administrative_access();
	}

	public static function require_capability( string $capability ): void {
		if ( self::current_user_is_restricted() ) {
			self::deny();
		}

		if ( current_user_can( $capability ) ) {
			return;
		}

		self::deny();
	}

	private static function deny(): void {
		wp_die(
			esc_html__(
				'You do not have permission to access this page.',
				'cetech-woocommerce-delivery-engine'
			)
		);
	}
}
