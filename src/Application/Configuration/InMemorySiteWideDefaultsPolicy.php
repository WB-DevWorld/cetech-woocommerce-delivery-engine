<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration;

/**
 * Test double for site-wide default policy.
 */
final class InMemorySiteWideDefaultsPolicy implements SiteWideDefaultsPolicyInterface {

	/**
	 * @param list<string> $active_profile_keys
	 */
	public function __construct(
		private ?string $primary_profile_key = null,
		private bool $setup_complete = false,
		private array $active_profile_keys = []
	) {
		if ( [] === $this->active_profile_keys && null !== $this->primary_profile_key ) {
			$this->active_profile_keys = [ $this->primary_profile_key ];
		}
	}

	public function primary_profile_key(): ?string {
		return $this->primary_profile_key;
	}

	public function is_setup_complete(): bool {
		return $this->setup_complete;
	}

	/**
	 * @return list<string>
	 */
	public function active_profile_keys(): array {
		return $this->active_profile_keys;
	}

	public function is_active_profile( string $key ): bool {
		return in_array( $key, $this->active_profile_keys, true );
	}

	public function with_primary( ?string $key ): self {
		$clone = clone $this;
		$clone->primary_profile_key = $key;

		return $clone;
	}
}
