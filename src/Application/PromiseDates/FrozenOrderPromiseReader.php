<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\PromiseDates;

/**
 * Reads the promised windows frozen on a placed order (authorization decision 6: frozen when
 * the order is placed; drafts are not frozen).
 *
 * Implemented by lane L2a over the order meta `_cetech_de_promise_snapshot`; consumed by
 * customer surfaces (L1), the staff order view and the REST order block (L7). Placed orders
 * always show this frozen value, never one recomputed from current rules.
 */
interface FrozenOrderPromiseReader {

	public const META_KEY = '_cetech_de_promise_snapshot';

	/**
	 * @return array<string, PromiseDisplay> Keyed by delivery group id (the shipping line's
	 *                                       `cetech_de_group_id`); empty when nothing was frozen
	 *                                       or the stored value is unreadable.
	 */
	public function for_order( int $order_id ): array;
}
