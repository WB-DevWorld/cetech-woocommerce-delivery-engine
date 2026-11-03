<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Catalog;

/**
 * Result of counting one batch of published products for Needs Attention.
 */
final class NeedsAttentionCatalogBatch {

	public function __construct(
		public readonly int $flagged,
		public readonly int $scanned,
		public readonly bool $reached_end
	) {
	}
}
