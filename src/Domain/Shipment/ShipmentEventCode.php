<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Shipment;

use CetechDeliveryEngine\Domain\Enum\ShipmentEventType;

/**
 * Stable shipment-event machine code.
 *
 * Known codes may resolve to ShipmentEventType. Unknown codes remain representable
 * so historical rows can be read without being rewritten or treated as a workflow action.
 */
final class ShipmentEventCode {

	public readonly string $value;

	public function __construct( string $value ) {
		$value = trim( $value );

		if ( '' === $value ) {
			throw new \InvalidArgumentException( 'Shipment event type code must not be empty.' );
		}

		if ( strlen( $value ) > 64 ) {
			throw new \InvalidArgumentException( 'Shipment event type code exceeds storage length.' );
		}

		$this->value = $value;
	}

	public static function fromKnown( ShipmentEventType $type ): self {
		return new self( $type->value );
	}

	public static function fromPersisted( string $code ): self {
		return new self( $code );
	}

	public function knownType(): ?ShipmentEventType {
		return ShipmentEventType::tryFrom( $this->value );
	}

	public function isKnown(): bool {
		return null !== $this->knownType();
	}

	public function is( ShipmentEventType $type ): bool {
		return $this->value === $type->value;
	}
}
