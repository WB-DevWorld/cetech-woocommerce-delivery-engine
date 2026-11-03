<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Catalog;

/**
 * What admin screens may show for the catalog part of Needs Attention.
 *
 * $count is null until the first complete scan has finished ("Updating…").
 * A non-fresh reading is the last complete result while a refresh is pending.
 */
final class NeedsAttentionCatalogReading {

	public function __construct(
		public readonly ?int $count,
		public readonly ?int $computed_at,
		public readonly bool $fresh
	) {
	}

	public function is_pending(): bool {
		return null === $this->count;
	}
}
