<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Domain\Enum\ShipmentEventSource;

final class ShipmentStatusChangeRequest {

	public function __construct(
		public readonly ShipmentStatusChangeMode $mode,
		public readonly ShipmentEventSource $source,
		public readonly string $reason,
		public readonly ?int $actor_user_id
	) {
	}

	public static function staff_normal( string $reason, ?int $actor_user_id ): self {
		return new self( ShipmentStatusChangeMode::Normal, ShipmentEventSource::Staff, $reason, $actor_user_id );
	}

	public static function staff_correction( string $reason, ?int $actor_user_id ): self {
		return new self( ShipmentStatusChangeMode::Correction, ShipmentEventSource::Staff, $reason, $actor_user_id );
	}

	public static function automatic( ShipmentEventSource $source, string $reason ): self {
		return new self( ShipmentStatusChangeMode::Automatic, $source, $reason, null );
	}
}
