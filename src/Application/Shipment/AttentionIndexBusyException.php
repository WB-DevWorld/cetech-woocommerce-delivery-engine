<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

/**
 * A Needs Attention index could not be locked for a mutation (COR-033).
 *
 * The index was not written; the caller decides whether to retry or report.
 */
final class AttentionIndexBusyException extends \RuntimeException {

	public function __construct( string $option ) {
		parent::__construct( sprintf( 'The %s index is busy; the update was not applied.', $option ) );
	}
}
