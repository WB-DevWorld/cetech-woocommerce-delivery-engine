<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Configuration;

final class EffectiveConfigurationSet {

	/**
	 * @param array<string, EffectiveConfiguration> $configurations
	 * @param list<string>                         $ordered_slice_keys
	 */
	public function __construct(
		public readonly int $product_id,
		public readonly ?int $variation_id,
		public readonly array $configurations,
		public readonly array $ordered_slice_keys
	) {
		foreach ( $this->configurations as $slice_key => $configuration ) {
			if ( ! $configuration instanceof EffectiveConfiguration || $configuration->slice_key !== $slice_key ) {
				throw new InvalidConfigurationException( 'Effective configuration set keys must match slice keys.' );
			}
		}
	}

	public function for_slice( string $slice_key ): ?EffectiveConfiguration {
		return $this->configurations[ $slice_key ] ?? null;
	}
}
