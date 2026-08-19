<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

/**
 * Staff-safe sentences for manual shipment creation from a WooCommerce order.
 */
final class ManualShipmentCreationMessages {

	private function __construct() {
	}

	public static function for_preview_code( string $code ): string {
		return match ( $code ) {
			ManualShipmentCreationPreview::CODE_ORDER_NOT_FOUND => __( 'Order not found.', 'cetech-woocommerce-delivery-engine' ),
			ManualShipmentCreationPreview::CODE_NO_SNAPSHOT => __( 'This order does not contain saved Delivery Engine details.', 'cetech-woocommerce-delivery-engine' ),
			ManualShipmentCreationPreview::CODE_PICKUP_ONLY => __( 'No delivery shipment is required for this order.', 'cetech-woocommerce-delivery-engine' ),
			ManualShipmentCreationPreview::CODE_ALREADY_EXISTS => __( 'Shipment already exists.', 'cetech-woocommerce-delivery-engine' ),
			ManualShipmentCreationPreview::CODE_INELIGIBLE => __( 'This order is not in a state where a delivery shipment can be started.', 'cetech-woocommerce-delivery-engine' ),
			ManualShipmentCreationPreview::CODE_FEATURE_DISABLED => __( 'Shipment records are not enabled, so a delivery shipment cannot be created.', 'cetech-woocommerce-delivery-engine' ),
			ManualShipmentCreationPreview::CODE_INVALID_SNAPSHOT => __( 'The saved Delivery Engine details on this order cannot be used to create a shipment.', 'cetech-woocommerce-delivery-engine' ),
			ManualShipmentCreationPreview::CODE_READY => __( 'Review the historical order details, then create the delivery shipment.', 'cetech-woocommerce-delivery-engine' ),
			default => __( 'A delivery shipment could not be prepared for this order.', 'cetech-woocommerce-delivery-engine' ),
		};
	}

	public static function created(): string {
		return __( 'Delivery shipment created from the historical order record.', 'cetech-woocommerce-delivery-engine' );
	}

	public static function already_exists(): string {
		return self::for_preview_code( ManualShipmentCreationPreview::CODE_ALREADY_EXISTS );
	}

	public static function persistence_failed(): string {
		return __( 'The delivery shipment could not be saved. The WooCommerce order was left unchanged.', 'cetech-woocommerce-delivery-engine' );
	}
}
