<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\Operation;

use CetechDeliveryEngine\Domain\Contracts\CanonicalIntent;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Operation\OperationCommand;

/** Original validated disposable-counter command; no production operation. */
final readonly class OperationProofCommand implements OperationCommand {
	private CanonicalIntent $canonical;
	public function __construct( OperationIdentity $identity, public int $row_id, public int $expected_revision, public int $value ) {
		$this->canonical = CanonicalIntent::from_command( $identity, [ 'row_id' => $row_id ], [ 'revision' => $expected_revision ], [ 'value' => $value ] );
	}
	public function intent(): CanonicalIntent { return $this->canonical; }
}
