<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Operation;

/** Internal fixed-message failure; raw database diagnostics are never retained. */
final class OperationStorageException extends \RuntimeException {

	public function __construct() {
		parent::__construct( 'The operation store could not complete its database statement.' );
	}
}
