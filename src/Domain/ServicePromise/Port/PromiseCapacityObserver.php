<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise\Port;

use CetechDeliveryEngine\Domain\ServicePromise\{PromiseInput, PromiseCapacityObservation};

/** Future read-only observation seam; never promises a reservation or permits network under SQL ownership. */
interface PromiseCapacityObserver {
	public function observe( PromiseInput $captured_input ): PromiseCapacityObservation;
}
