<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise\Port;

use CetechDeliveryEngine\Domain\ServicePromise\{BusinessCalendarVersion, PromiseInput, PromiseResult};

/** Future pure calculation seam. No implementation, fact capture, IO or admission authority. */
interface PromiseCalculator {
	/** @param list<BusinessCalendarVersion> $calendars Exact deduplicated input references; missing/changed sources refuse the whole result. */
	public function calculate( PromiseInput $input, array $calendars ): PromiseResult;
}
