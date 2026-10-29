<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Shipment;

/**
 * A guarded shipment write found the row changed since the editor loaded it (COR-032).
 *
 * Nothing was written: the row and its event stay as they were.
 */
final class ShipmentConcurrentEditException extends \RuntimeException {

	public function __construct( int $shipment_id ) {
		parent::__construct( sprintf( 'Shipment %d changed since it was loaded; the edit was not applied.', $shipment_id ) );
	}
}
