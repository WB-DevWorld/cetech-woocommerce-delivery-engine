<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Configuration;

final class EffectiveConfigurationRequest {

	public function __construct(
		public readonly int $product_id,
		public readonly ?int $variation_id = null,
		public readonly string $slice_key = ConfigurationScope::DEFAULT_SLICE_KEY,
		public readonly ?int $parent_product_id = null
	) {
		if ( $this->product_id <= 0 ) {
			throw new \InvalidArgumentException( 'Effective configuration product_id must be positive.' );
		}

		if ( null !== $this->variation_id && $this->variation_id <= 0 ) {
			throw new \InvalidArgumentException( 'Effective configuration variation_id must be positive when set.' );
		}

		if ( null !== $this->parent_product_id && $this->parent_product_id <= 0 ) {
			throw new \InvalidArgumentException( 'Effective configuration parent_product_id must be positive when set.' );
		}
	}
}
