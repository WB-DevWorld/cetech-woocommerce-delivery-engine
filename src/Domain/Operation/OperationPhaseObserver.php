<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Operation;

use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;

/** Internal bounded fault-proof hook; never a business mutator or adopter. */
interface OperationPhaseObserver {
	public const PHASES = [ 'reservation_committed', 'effect_mutated', 'audit_staged', 'completion_staged', 'before_effect_commit' ];
	public function observe( string $phase, OperationIdentity $identity ): void;
}
