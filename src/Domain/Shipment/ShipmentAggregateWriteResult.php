<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Shipment;

/**
 * Result of an atomic shipment aggregate write.
 */
final class ShipmentAggregateWriteResult {

	public const CREATED = 'created';

	public const ALREADY_COMPLETE = 'already_complete';

	public const REPAIRED = 'repaired';

	public function __construct(
		public readonly Shipment $shipment,
		public readonly string $status
	) {
	}

	public function was_created(): bool {
		return self::CREATED === $this->status;
	}

	public function was_repaired(): bool {
		return self::REPAIRED === $this->status;
	}
}
