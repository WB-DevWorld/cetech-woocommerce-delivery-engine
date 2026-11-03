<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Catalog;

/**
 * Site-wide result of one complete Needs Attention catalog scan.
 *
 * Derived cache, not merchant data: it holds no product ids, labels or reasons.
 */
final class NeedsAttentionCatalogSnapshot {

	public function __construct(
		public readonly int $count,
		public readonly int $product_total,
		public readonly int $computed_at,
		public readonly int $generation,
		public readonly bool $complete,
		public readonly string $plugin_version
	) {
	}

	/**
	 * @return array{count: int, product_total: int, computed_at: int, generation: int, complete: bool, plugin_version: string}
	 */
	public function to_array(): array {
		return [
			'count'          => $this->count,
			'product_total'  => $this->product_total,
			'computed_at'    => $this->computed_at,
			'generation'     => $this->generation,
			'complete'       => $this->complete,
			'plugin_version' => $this->plugin_version,
		];
	}

	public static function from_stored( mixed $stored ): ?self {
		if ( ! is_array( $stored ) ) {
			return null;
		}

		foreach ( [ 'count', 'product_total', 'computed_at', 'generation' ] as $key ) {
			if ( ! isset( $stored[ $key ] ) || ! is_numeric( $stored[ $key ] ) ) {
				return null;
			}
		}

		return new self(
			max( 0, (int) $stored['count'] ),
			max( 0, (int) $stored['product_total'] ),
			(int) $stored['computed_at'],
			(int) $stored['generation'],
			(bool) ( $stored['complete'] ?? false ),
			(string) ( $stored['plugin_version'] ?? '' )
		);
	}
}
