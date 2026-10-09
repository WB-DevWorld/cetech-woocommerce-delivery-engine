<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise\Port;

use CetechDeliveryEngine\Domain\ServicePromise\{PromiseCalendarReference, BusinessCalendarVersion};

/** Future unmounted bulk reader; no current-version substitution or holiday inference. */
interface PromiseCalendarVersionLoader {
	/** @param list<PromiseCalendarReference> $references @return list<BusinessCalendarVersion> Exact referenced immutable versions; incomplete/extra/conflicting results must refuse. */
	public function load_versions( array $references ): array;
}
