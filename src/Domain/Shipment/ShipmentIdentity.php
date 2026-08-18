<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Shipment;

/**
 * Canonical shipment identity used for application and database idempotency.
 *
 * One shipment record per order delivery group: order_id + delivery_group_id.
 */
final class ShipmentIdentity {

	public function __construct(
		public readonly int $order_id,
		public readonly string $delivery_group_id
	) {
		if ( $order_id <= 0 ) {
			throw new \InvalidArgumentException( 'Shipment identity requires a positive order_id.' );
		}

		if ( '' === $delivery_group_id ) {
			throw new \InvalidArgumentException( 'Shipment identity requires a delivery_group_id.' );
		}
	}

	public function idempotency_key(): string {
		return self::key( $this->order_id, $this->delivery_group_id );
	}

	public static function key( int $order_id, string $delivery_group_id ): string {
		return $order_id . '|' . $delivery_group_id;
	}

	public static function stable_shipment_number( int $order_id, string $delivery_group_id ): string {
		return sprintf( 'DE-%d-%s', $order_id, substr( sha1( $delivery_group_id ), 0, 8 ) );
	}
}
