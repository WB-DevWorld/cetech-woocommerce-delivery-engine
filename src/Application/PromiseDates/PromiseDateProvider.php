<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\PromiseDates;

/**
 * Turns the staff promise rules into a promised window for one request.
 *
 * Implemented by lane L2 (promise rules); consumed by lane L1 (customer surfaces).
 * Returns null when no published rule applies, when a fact the rule needs is unknown, or when the
 * rules cannot be read. The caller then shows the M2 duration text. A provider never invents a
 * date (DE-PROMISE-009) and never throws to a customer surface.
 */
interface PromiseDateProvider {

	public function promise_for( PromiseDateRequest $request ): ?PromiseDisplay;
}
