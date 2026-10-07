<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\EmergencyControl;

/** A successful absence is distinct from a failed configuration read. */
interface EmergencyConfigurationOwnershipProbeInterface {
	public function ownership( int $product_id, ?int $variation_id ): EmergencyOwnership;
}
