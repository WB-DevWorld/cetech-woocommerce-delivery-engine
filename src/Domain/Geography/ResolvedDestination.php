<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Geography;

/**
 * Resolved destination used by coverage matching.
 *
 * Built only through CanonicalLocationResolver — never trusted from the browser.
 */
final class ResolvedDestination {

	/**
	 * @param list<CanonicalLocation> $ancestry Root-first including self.
	 */
	public function __construct(
		public readonly ?CanonicalLocation $location,
		public readonly string $country_code,
		public readonly string $admin_code,
		public readonly string $admin_label,
		public readonly string $locality_label,
		public readonly string $postcode,
		public readonly bool $canonical,
		public readonly string $resolution_source,
		public readonly array $ancestry = []
	) {
	}

	public function location_id(): int {
		return $this->location?->id ?? 0;
	}

	public function location_key(): string {
		return $this->location?->location_key ?? '';
	}

	public function hasCanonicalLocation(): bool {
		return $this->canonical && $this->location instanceof CanonicalLocation;
	}
}
