<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise\Port;

use CetechDeliveryEngine\Domain\ServicePromise\{PromisePolicyReference, ServicePromisePolicy};

/** Future unmounted bulk reader. Exact references only; absence is never a default policy. */
interface PromisePolicyVersionLoader {
	/** @param list<PromisePolicyReference> $references @return list<ServicePromisePolicy> Exact referenced immutable versions; incomplete/extra/conflicting results must refuse. */
	public function load_versions( array $references ): array;
}
