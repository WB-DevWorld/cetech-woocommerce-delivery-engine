<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Domain\Enum\ShipmentCreationErrorCode;
use CetechDeliveryEngine\Domain\Enum\ShipmentCreationOutcome;
use CetechDeliveryEngine\Domain\Shipment\Shipment;

/**
 * Outcome of a paid-order shipment creation attempt.
 */
final class ShipmentCreationResult {

	/**
	 * @param list<Shipment> $shipments
	 */
	public function __construct(
		public readonly ShipmentCreationOutcome $outcome,
		public readonly array $shipments = [],
		public readonly ?ShipmentCreationErrorCode $error_code = null
	) {
	}

	public static function of( ShipmentCreationOutcome $outcome, array $shipments = [], ?ShipmentCreationErrorCode $error_code = null ): self {
		return new self( $outcome, $shipments, $error_code );
	}

	public function is_success(): bool {
		return $this->outcome->is_success();
	}
}
