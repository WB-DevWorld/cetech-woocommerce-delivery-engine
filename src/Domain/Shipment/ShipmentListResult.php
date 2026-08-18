<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Shipment;

/**
 * Paginated shipment query result.
 */
final class ShipmentListResult {

	/**
	 * @param list<Shipment> $items
	 */
	public function __construct(
		public readonly array $items,
		public readonly int $total,
		public readonly int $page,
		public readonly int $per_page
	) {
	}
}
