<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\PromiseDates;

/**
 * The default until promise rules are wired: no rule ever applies, so every surface keeps the
 * M2 duration text.
 */
final class NullPromiseDateProvider implements PromiseDateProvider {

	public function promise_for( PromiseDateRequest $request ): ?PromiseDisplay {
		return null;
	}
}
