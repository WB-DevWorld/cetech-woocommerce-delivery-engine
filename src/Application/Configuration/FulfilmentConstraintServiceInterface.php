<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration;

use CetechDeliveryEngine\Domain\Configuration\EffectiveConfiguration;

interface FulfilmentConstraintServiceInterface {

	public function apply( EffectiveConfiguration $configuration ): EffectiveConfiguration;
}
