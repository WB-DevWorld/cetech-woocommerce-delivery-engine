<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Enum;

/**
 * Machine codes for paid-order shipment creation outcomes.
 */
enum ShipmentCreationOutcome: string {

	case Created                     = 'created';
	case AlreadyExistsComplete       = 'already_exists_complete';
	case CompletedExistingIncomplete = 'completed_existing_incomplete';
	case ZeroShipmentsPickupOnly     = 'zero_shipments_pickup_only';
	case NotDeliveryEngineOrder      = 'not_delivery_engine_order';
	case FeatureDisabled             = 'feature_disabled';
	case NotPaid                     = 'not_paid';
	case Ineligible                  = 'ineligible';
	case InvalidSnapshot             = 'invalid_snapshot';
	case CreationFailed              = 'creation_failed';

	public function is_success(): bool {
		return in_array(
			$this,
			[
				self::Created,
				self::AlreadyExistsComplete,
				self::CompletedExistingIncomplete,
				self::ZeroShipmentsPickupOnly,
			],
			true
		);
	}

	public function is_needs_attention(): bool {
		return self::InvalidSnapshot === $this || self::CreationFailed === $this;
	}
}
