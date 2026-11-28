<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

/**
 * The staff wording for where a Delivery option's customer-visible estimate comes from.
 *
 * It restates {@see \CetechDeliveryEngine\Application\Selector\DeliveryEstimatePrecedence} in
 * plain words, in one place, so the Delivery Options form, Site-wide Defaults and the product
 * form cannot describe the rule differently: the Estimated delivery saved for the item (variation,
 * product, then Site-wide Defaults) is shown for every Delivery Option; an option's own day counts
 * are shown only when no such estimate is saved; the option's free-text estimate is a staff note.
 */
final class DeliveryEstimateHelpText {

	private function __construct() {
	}

	/**
	 * Help for the free-text "Estimated delivery" field of a Delivery Option.
	 */
	public static function option_text_field(): string {
		return __( 'For staff only: customers never see this text. It appears in the Delivery Options list when no day counts are set under Advanced details. Customers see the Estimated delivery saved in Site-wide Defaults, or on the product if it has its own.', 'cetech-woocommerce-delivery-engine' );
	}

	/**
	 * Note above the processing / transit / final-mile day counts of a Delivery Option.
	 */
	public static function option_day_counts(): string {
		return __( 'Processing, transit and final-mile days are added together into this option’s own estimate, for example “2–4 business days”. Customers see it only when no Estimated delivery is saved in Site-wide Defaults or on the product.', 'cetech-woocommerce-delivery-engine' );
	}

	/**
	 * Help for the "Estimated delivery" field of Site-wide Defaults.
	 */
	public static function site_wide_field(): string {
		return __( 'Shown to customers for every Delivery Option ticked above, in place of each option’s own day counts. Store Pickup uses the Pickup Location’s readiness instead.', 'cetech-woocommerce-delivery-engine' );
	}
}
