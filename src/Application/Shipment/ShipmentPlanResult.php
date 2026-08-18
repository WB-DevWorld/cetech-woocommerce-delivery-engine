<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Domain\Enum\ShipmentCreationErrorCode;

/**
 * Planner output. Persistence is the service's job.
 */
final class ShipmentPlanResult {

	/**
	 * @param list<ShipmentPlan> $plans
	 */
	public function __construct(
		public readonly bool $ok,
		public readonly array $plans,
		public readonly int $pickup_groups_skipped,
		public readonly ?ShipmentCreationErrorCode $error_code = null
	) {
	}

	public static function success( array $plans, int $pickup_groups_skipped ): self {
		return new self( true, $plans, $pickup_groups_skipped, null );
	}

	public static function failure( ShipmentCreationErrorCode $error_code ): self {
		return new self( false, [], 0, $error_code );
	}

	public function is_pickup_only(): bool {
		return $this->ok && [] === $this->plans && $this->pickup_groups_skipped > 0;
	}
}
