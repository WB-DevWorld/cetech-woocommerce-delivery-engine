<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipping;

/**
 * Customer-facing shipping labels for selected-offer packages.
 */
final class SelectedOfferShippingMethodLabel {

	public static function default_delivery_label(): string {
		return __( 'Delivery', 'cetech-woocommerce-delivery-engine' );
	}
}
