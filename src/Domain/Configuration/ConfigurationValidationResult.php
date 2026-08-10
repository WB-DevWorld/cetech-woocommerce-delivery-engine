<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Configuration;

use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;

final class ConfigurationValidationResult {

	/**
	 * @param list<string>                $reason_codes
	 * @param array<string, list<string>> $field_notes
	 */
	public function __construct(
		public readonly EffectiveFieldState $state,
		public readonly array $reason_codes = [],
		public readonly array $field_notes = []
	) {
	}
}
