<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\EmergencyControl;

/** Trusted final adoption boundary. A C07 confirmation alone is tentative here. */
interface EmergencyFinalPlacementGuard {
	/** Acknowledges the private placement receipt before gateway/free processing. */
	public function complete( \WC_Order $order, string $route, int $control_revision, EmergencyCheckoutLocalBinding $binding ): bool;

	/** Same request, order object and route only; never creates or renews a receipt. */
	public function admitted( \WC_Order $order, string $route, int $control_revision, EmergencyCheckoutLocalBinding $binding ): bool;
}
