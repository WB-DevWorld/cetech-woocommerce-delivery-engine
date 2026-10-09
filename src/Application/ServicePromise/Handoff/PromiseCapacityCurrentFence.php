<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\ServicePromise\Handoff;

use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseCapacityObservation;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseSiteBinding;

/** Trusted native adapters only: exact read-only observation revalidation on the existing owner. */
interface PromiseCapacityCurrentFence {
	public function tables( OperationSession $session, PromiseSiteBinding $binding ): array;
	public function verify( OperationSession $session, PromiseSiteBinding $binding, PromiseCapacityObservation $original ): bool;
}
