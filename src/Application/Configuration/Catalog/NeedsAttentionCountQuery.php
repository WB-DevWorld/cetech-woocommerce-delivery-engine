<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Catalog;

use CetechDeliveryEngine\Application\Bulk\BulkStaleJobQuery;
use CetechDeliveryEngine\Application\Shipment\CodAwaitingShipmentQuery;
use CetechDeliveryEngine\Application\Shipment\ShipmentCreationIssueQuery;
use CetechDeliveryEngine\Application\Shipment\ShipmentOperationsIssueQuery;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;

/**
 * Unresolved Needs Attention count for admin badges.
 *
 * Uses the same sources as NeedsAttentionPage: incomplete product delivery
 * setup, paid-order shipment creation failures, operational shipment issues,
 * Cash on Delivery orders awaiting staff shipment creation, and genuinely
 * stalled Bulk Tools jobs. Does not invent a second definition of Needs Attention.
 *
 * Ordinary awaiting-fulfilment, processing, missing tracking, and pickup-only
 * orders are not counted unless those canonical queries already treat them as
 * issues. Legitimate unpaid COD delivery orders are counted as action required.
 */
final class NeedsAttentionCountQuery {

	public function __construct(
		private readonly NeedsAttentionQuery $catalog,
		private readonly ShipmentCreationIssueQuery $creation,
		private readonly ShipmentOperationsIssueQuery $operations,
		private readonly FeatureFlags $flags,
		private readonly ?CodAwaitingShipmentQuery $cod_awaiting = null,
		private readonly ?BulkStaleJobQuery $bulk_stale = null
	) {
	}

	public function unresolved_count_for_current_user(): int {
		$count = 0;

		if ( $this->current_user_can_see_catalog_attention() ) {
			$count += $this->catalog->count();
			$count += $this->bulk_stale instanceof BulkStaleJobQuery ? $this->bulk_stale->count() : 0;
		}

		if ( $this->current_user_can_see_shipment_attention() ) {
			$count += $this->creation->count();
			$count += $this->operations->count();
			$count += $this->cod_awaiting instanceof CodAwaitingShipmentQuery
				? $this->cod_awaiting->count()
				: 0;
		}

		return $count;
	}

	public function current_user_can_see_needs_attention(): bool {
		return $this->current_user_can_see_catalog_attention()
			|| $this->current_user_can_see_shipment_attention();
	}

	public function current_user_can_see_catalog_attention(): bool {
		return function_exists( 'current_user_can' )
			&& current_user_can( 'manage_product_delivery_rules' );
	}

	public function current_user_can_see_shipment_attention(): bool {
		return $this->flags->is_enabled( 'enable_shipment_records' )
			&& function_exists( 'current_user_can' )
			&& current_user_can( 'manage_shipments' );
	}
}
