<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Catalog;

/**
 * Needs Attention product rows plus whether the listing covered the whole catalog.
 *
 * When $complete is true, count($items) is the full catalog count and
 * $scanned is the number of published products.
 */
final class NeedsAttentionCatalogListing {

	/**
	 * @param list<array{id: int, label: string, url: string, reason: string}> $items
	 */
	public function __construct(
		public readonly array $items,
		public readonly bool $complete,
		public readonly int $scanned
	) {
	}
}
