<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Operation;

use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Typed metadata verifier; never a mutation callback or alternate transaction owner. */
interface OperationReadiness {
	public function assert_ready( OperationSession $session ): void;
}
