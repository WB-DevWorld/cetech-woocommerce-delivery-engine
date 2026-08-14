<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Admin;

/**
 * Neutral, store-currency-aware example copy for normal staff UI.
 *
 * Do not hard-code Ghana / Accra / GHS in reusable admin surfaces.
 */
final class StoreAwareExamples {

	public static function currency_code(): string {
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$code = strtoupper( trim( (string) get_woocommerce_currency() ) );
			if ( '' !== $code ) {
				return $code;
			}
		}

		return 'USD';
	}

	public static function format_amount( string $amount ): string {
		return AdminMoneyFormatter::display( $amount, self::currency_code() );
	}

	public static function charge_list_example(): string {
		return sprintf(
			/* translators: %s: formatted money amount with currency */
			__( 'City centre + Standard Delivery = %s', 'cetech-woocommerce-delivery-engine' ),
			self::format_amount( '25.00' )
		);
	}

	public static function charge_editor_name_example(): string {
		return sprintf(
			/* translators: %s: formatted money amount with currency */
			__( 'City Centre Standard Delivery — Flat amount per delivery — %s', 'cetech-woocommerce-delivery-engine' ),
			self::format_amount( '60.00' )
		);
	}

	public static function area_help(): string {
		return __( 'The area where this delivery fee applies, such as your main city or a suburb.', 'cetech-woocommerce-delivery-engine' );
	}

	public static function area_list_example(): string {
		return __( 'City Centre, North District, Metro Area', 'cetech-woocommerce-delivery-engine' );
	}

	public static function area_name_example(): string {
		return __( 'Example: City Centre', 'cetech-woocommerce-delivery-engine' );
	}

	public static function supplier_origin_example(): string {
		return __( 'Supplier: ABC Wholesale · Origin: Main Warehouse', 'cetech-woocommerce-delivery-engine' );
	}
}
