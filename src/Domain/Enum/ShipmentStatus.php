<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Enum;

/**
 * Stable shipment status machine codes.
 *
 * Persist and compare these values only. Translated labels are presentation.
 */
enum ShipmentStatus: string {

	case AwaitingFulfilment = 'awaiting_fulfilment';
	case Processing         = 'processing';
	case Dispatched         = 'dispatched';
	case InTransit          = 'in_transit';
	case Delayed            = 'delayed';
	case Delivered          = 'delivered';
	case Cancelled          = 'cancelled';

	/**
	 * @return list<string>
	 */
	public static function values(): array {
		return array_map(
			static fn ( self $status ): string => $status->value,
			self::cases()
		);
	}

	public static function tryFromMachineCode( string $code ): ?self {
		return self::tryFrom( $code );
	}

	/**
	 * Presentation label only. Never persist or compare this string.
	 */
	public function label(): string {
		return match ( $this ) {
			self::AwaitingFulfilment => __( 'Awaiting fulfilment', 'cetech-woocommerce-delivery-engine' ),
			self::Processing         => __( 'Processing', 'cetech-woocommerce-delivery-engine' ),
			self::Dispatched         => __( 'Dispatched', 'cetech-woocommerce-delivery-engine' ),
			self::InTransit          => __( 'In transit', 'cetech-woocommerce-delivery-engine' ),
			self::Delayed            => __( 'Delayed', 'cetech-woocommerce-delivery-engine' ),
			self::Delivered          => __( 'Delivered', 'cetech-woocommerce-delivery-engine' ),
			self::Cancelled          => __( 'Cancelled', 'cetech-woocommerce-delivery-engine' ),
		};
	}
}
