<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Coverage;

/**
 * Internal/admin diagnostic for a coverage match. Never sent to customers.
 */
final class CoverageMatchDiagnostic {

	/**
	 * @param array<string, mixed> $details
	 */
	public function __construct(
		public readonly bool $matched,
		public readonly int $zone_id,
		public readonly ?int $coverage_group_id,
		public readonly string $inclusion_reason,
		public readonly string $exclusion_reason,
		public readonly int $specificity,
		public readonly string $fallback_reason,
		public readonly array $details = []
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'matched'           => $this->matched,
			'zone_id'           => $this->zone_id,
			'coverage_group_id' => $this->coverage_group_id,
			'inclusion_reason'  => $this->inclusion_reason,
			'exclusion_reason'  => $this->exclusion_reason,
			'specificity'       => $this->specificity,
			'fallback_reason'   => $this->fallback_reason,
			'details'           => $this->details,
		];
	}
}
