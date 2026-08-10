<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Configuration;

use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;

final class EffectiveScalarField {

	/**
	 * @param list<string> $reason_codes
	 */
	public function __construct(
		public readonly string $field_key,
		public readonly EffectiveFieldState $state,
		public readonly mixed $value,
		public readonly FieldProvenance $provenance,
		public readonly array $reason_codes = []
	) {
	}
}
