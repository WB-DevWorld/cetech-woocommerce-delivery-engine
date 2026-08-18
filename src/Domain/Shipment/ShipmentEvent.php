<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Shipment;

use CetechDeliveryEngine\Domain\Enum\ShipmentEventSource;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventType;
use CetechDeliveryEngine\Domain\Enum\ShipmentStatus;

/**
 * Append-only shipment history row. Status fields are machine codes.
 *
 * event_type stores the persisted machine code. Known codes expose typed semantics
 * via ShipmentEventCode::knownType(); unknown codes remain readable.
 */
final class ShipmentEvent {

	public function __construct(
		public readonly int $id,
		public readonly int $shipment_id,
		public readonly ShipmentEventCode $event_type,
		public readonly ?ShipmentStatus $from_status,
		public readonly ?ShipmentStatus $to_status,
		public readonly ?string $public_note,
		public readonly ?string $internal_note,
		public readonly ?int $actor_user_id,
		public readonly ShipmentEventSource $source,
		public readonly string $event_at,
		public readonly string $created_at
	) {
		if ( $shipment_id <= 0 ) {
			throw new \InvalidArgumentException( 'Shipment events require a positive shipment_id.' );
		}
	}

	public static function create(
		int $shipment_id,
		ShipmentEventType $event_type,
		ShipmentEventSource $source = ShipmentEventSource::System,
		?ShipmentStatus $from_status = null,
		?ShipmentStatus $to_status = null,
		?string $public_note = null,
		?string $internal_note = null,
		?int $actor_user_id = null,
		?string $event_at = null,
		int $id = 0,
		string $created_at = ''
	): self {
		$at = $event_at ?? gmdate( 'Y-m-d H:i:s' );

		return new self(
			$id,
			$shipment_id,
			ShipmentEventCode::fromKnown( $event_type ),
			$from_status,
			$to_status,
			$public_note,
			$internal_note,
			$actor_user_id,
			$source,
			$at,
			$created_at
		);
	}
}
