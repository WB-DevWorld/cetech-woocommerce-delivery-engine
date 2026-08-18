<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

/**
 * Paginated staff-list payload. Pagination is applied in the repository.
 */
final class ShipmentWorkspaceListResult {

	/**
	 * @param list<ShipmentListRow> $rows
	 */
	public function __construct(
		public readonly array $rows,
		public readonly int $total,
		public readonly int $page,
		public readonly int $per_page
	) {
	}

	public function total_pages(): int {
		if ( $this->per_page < 1 || $this->total < 1 ) {
			return 0;
		}

		return (int) ceil( $this->total / $this->per_page );
	}
}
