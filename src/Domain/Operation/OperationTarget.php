<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Operation;

/** Facts obtained from the profile's locking/current target read. */
final readonly class OperationTarget {
	public array $facts;
	public function __construct( OperationSchema $schema, array $facts ) {
		$this->facts = $schema->validate( $facts );
	}
}
