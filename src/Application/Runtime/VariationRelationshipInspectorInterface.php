<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Runtime;

/**
 * Server-side variation ↔ parent relationship checks for Stage 6 variable ECR.
 *
 * Never trusts client-submitted parent/variation pairing alone.
 */
interface VariationRelationshipInspectorInterface {

	/**
	 * @return array{ok: true, parent_id: int}|array{ok: false, reason: string}
	 */
	public function inspect( int $variation_id ): array;

	public function belongs_to_parent( int $variation_id, int $parent_product_id ): bool;
}
