<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Operation;

use CetechDeliveryEngine\Domain\Contracts\CanonicalIntent;

/** A profile-specific validated original command, not an arbitrary stored body. */
interface OperationCommand {
	public function intent(): CanonicalIntent;
}
