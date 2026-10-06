<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\Operation;

use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Operation\OperationPhaseObserver;

/** Pauses once at one declared qualification phase; never changes business facts. */
final class OperationProofObserver implements OperationPhaseObserver {
	private bool $paused = false;
	public function __construct( private string $phase, private string $ready, private string $release ) {
		if ( ! in_array( $phase, self::PHASES, true ) ) { throw new \InvalidArgumentException( 'Invalid operation proof phase.' ); }
	}
	public function observe( string $phase, OperationIdentity $identity ): void {
		if ( ! $this->paused && $phase === $this->phase ) {
			$this->paused = true;
			OperationProofBarrier::pause( $this->ready, $this->release );
		}
	}
}
