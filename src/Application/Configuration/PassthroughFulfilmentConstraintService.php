<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration;

use CetechDeliveryEngine\Domain\Configuration\EffectiveConfiguration;

final class PassthroughFulfilmentConstraintService implements FulfilmentConstraintServiceInterface {

	public function apply( EffectiveConfiguration $configuration ): EffectiveConfiguration {
		return $configuration;
	}
}
