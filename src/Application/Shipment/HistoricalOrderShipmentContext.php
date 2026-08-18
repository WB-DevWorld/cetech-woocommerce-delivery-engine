<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Application\Order\OrderDeliveryLineSnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliveryPackageSnapshot;

/**
 * Historical order facts the planner may consume. No current configuration.
 */
final class HistoricalOrderShipmentContext {

	/**
	 * @param list<HistoricalShipmentLineContext> $lines
	 * @param list<HistoricalShippingLineContext> $shipping_lines
	 */
	public function __construct(
		public readonly int $order_id,
		public readonly string $order_number,
		public readonly ?OrderDeliveryPackageSnapshot $package,
		public readonly bool $package_meta_present,
		public readonly bool $package_unreadable,
		public readonly array $lines,
		public readonly array $shipping_lines
	) {
	}

	public function has_delivery_engine_snapshot(): bool {
		if ( $this->package_meta_present || null !== $this->package ) {
			return true;
		}

		foreach ( $this->lines as $line ) {
			if ( $line->has_snapshot ) {
				return true;
			}
		}

		return false;
	}
}
