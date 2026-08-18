<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Domain\Shipment\Shipment;

final class ShipmentTrackingSaveResult {

	public function __construct(
		public readonly bool $ok,
		public readonly string $message,
		public readonly bool $unchanged = false,
		public readonly ?Shipment $shipment = null
	) {
	}

	public static function success( Shipment $shipment, string $message, bool $unchanged = false ): self {
		return new self( true, $message, $unchanged, $shipment );
	}

	public static function failure( string $message ): self {
		return new self( false, $message );
	}
}
