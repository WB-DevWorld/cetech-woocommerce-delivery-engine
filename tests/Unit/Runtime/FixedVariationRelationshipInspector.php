<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Runtime;

use CetechDeliveryEngine\Application\Runtime\VariationRelationshipInspectorInterface;

/**
 * Test double: fixed variation-to-parent mapping for unit tests.
 */
final class FixedVariationRelationshipInspector implements VariationRelationshipInspectorInterface {

	/** @param array<int,int> $variation_to_parent */
	public function __construct( private array $variation_to_parent = [] ) {
	}

	/**
	 * @return array{ok: true, parent_id: int}|array{ok: false, reason: string}
	 */
	public function inspect( int $variation_id ): array {
		if ( $variation_id <= 0 ) {
			return [ 'ok' => false, 'reason' => 'invalid_variation_id' ];
		}

		if ( ! array_key_exists( $variation_id, $this->variation_to_parent ) ) {
			return [ 'ok' => false, 'reason' => 'variation_not_found' ];
		}

		return [ 'ok' => true, 'parent_id' => $this->variation_to_parent[ $variation_id ] ];
	}

	public function belongs_to_parent( int $variation_id, int $parent_product_id ): bool {
		if ( $parent_product_id <= 0 ) {
			return false;
		}

		$inspection = $this->inspect( $variation_id );

		return ! empty( $inspection['ok'] )
			&& (int) ( $inspection['parent_id'] ?? 0 ) === $parent_product_id;
	}
}
