<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Catalog;

use CetechDeliveryEngine\Application\Shipment\ShipmentCreationIssueQuery;
use CetechDeliveryEngine\Application\Shipment\ShipmentOperationsIssueQuery;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;

/**
 * Unresolved Needs Attention count for admin badges.
 *
 * Uses the same three sources as NeedsAttentionPage: incomplete product
 * delivery setup, paid-order shipment creation failures, and operational
 * shipment issues (delayed / cancel-after-progress / refund review / sync
 * failure). Does not invent a second definition of Needs Attention.
 *
 * Ordinary awaiting-fulfilment, processing, missing tracking, pickup-only,
 * and unpaid/unconfirmed COD orders are not counted unless those canonical
 * queries already treat them as issues.
 */
final class NeedsAttentionCountQuery {

	public function __construct(
		private readonly NeedsAttentionQuery $catalog,
		private readonly ShipmentCreationIssueQuery $creation,
		private readonly ShipmentOperationsIssueQuery $operations,
		private readonly FeatureFlags $flags
	) {
	}

	public function unresolved_count_for_current_user(): int {
		$count = 0;

		if ( $this->can_see_catalog() ) {
			$count += $this->catalog->count();
		}

		if ( $this->can_see_shipment_attention() ) {
			$count += $this->creation->count();
			$count += $this->operations->count();
		}

		return $count;
	}

	public function current_user_can_see_needs_attention(): bool {
		return $this->can_see_catalog() || $this->can_see_shipment_attention();
	}

	private function can_see_catalog(): bool {
		return function_exists( 'current_user_can' )
			&& current_user_can( 'manage_product_delivery_rules' );
	}

	private function can_see_shipment_attention(): bool {
		return $this->flags->is_enabled( 'enable_shipment_records' )
			&& function_exists( 'current_user_can' )
			&& current_user_can( 'manage_shipments' );
	}
}
