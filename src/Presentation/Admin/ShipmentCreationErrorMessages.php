<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Domain\Enum\ShipmentCreationErrorCode;

/**
 * Administrator-facing copy for shipment creation error codes.
 */
final class ShipmentCreationErrorMessages {

	public static function describe( ShipmentCreationErrorCode $code ): string {
		return match ( $code ) {
			ShipmentCreationErrorCode::MissingSnapshot => __(
				'This paid order is missing a usable delivery snapshot, so delivery shipments could not be created.',
				'cetech-woocommerce-delivery-engine'
			),
			ShipmentCreationErrorCode::MalformedGroupSnapshot => __(
				'The saved delivery groups on this paid order could not be read safely, so delivery shipments were not created.',
				'cetech-woocommerce-delivery-engine'
			),
			ShipmentCreationErrorCode::MissingOrderItem => __(
				'A saved delivery group on this paid order does not match the order items, so delivery shipments were not created.',
				'cetech-woocommerce-delivery-engine'
			),
			ShipmentCreationErrorCode::GroupItemMismatch => __(
				'An order item does not match its saved delivery group, so delivery shipments were not created.',
				'cetech-woocommerce-delivery-engine'
			),
			ShipmentCreationErrorCode::ShippingAmountMismatch => __(
				'The saved delivery charge on this paid order does not match the WooCommerce shipping line, so delivery shipments were not created.',
				'cetech-woocommerce-delivery-engine'
			),
			ShipmentCreationErrorCode::RepositoryWriteFailed => __(
				'Delivery shipments could not be saved for this paid order. The order and payment were left unchanged.',
				'cetech-woocommerce-delivery-engine'
			),
			ShipmentCreationErrorCode::AggregateIncomplete => __(
				'A delivery shipment was only partly saved for this paid order and needs to be completed.',
				'cetech-woocommerce-delivery-engine'
			),
		};
	}
}
