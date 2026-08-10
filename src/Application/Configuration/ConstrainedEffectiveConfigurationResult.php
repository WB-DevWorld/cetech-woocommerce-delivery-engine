<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration;

use CetechDeliveryEngine\Domain\Configuration\EffectiveConfiguration;

final class ConstrainedEffectiveConfigurationResult {

	public function __construct(
		public readonly EffectiveConfiguration $original,
		public readonly EffectiveConfiguration $constrained,
		public readonly bool $applied
	) {
	}
}
