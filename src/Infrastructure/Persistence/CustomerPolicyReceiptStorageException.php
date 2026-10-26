<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Infrastructure\Persistence;

/** Closed outcome; no connection settings, statement or server text escapes. */
final class CustomerPolicyReceiptStorageException extends \RuntimeException {
	public function __construct( public readonly string $outcome = 'outcome_unknown' ) {
		parent::__construct( 'Customer policy original storage is unavailable.' );
	}
}
