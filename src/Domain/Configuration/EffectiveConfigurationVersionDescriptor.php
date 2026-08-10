<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Configuration;

final class EffectiveConfigurationVersionDescriptor {

	public function __construct(
		public readonly int $product_id,
		public readonly ?int $variation_id,
		public readonly string $slice_key,
		public readonly int $global_version,
		public readonly int $product_version,
		public readonly int $variation_version,
		public readonly string $fingerprint
	) {
	}
}
