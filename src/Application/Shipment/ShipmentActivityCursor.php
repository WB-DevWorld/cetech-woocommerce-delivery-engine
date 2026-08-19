<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Shipment\ShipmentRepositoryInterface;

/**
 * Per-user Shipments workspace activity cursor.
 *
 * Semantic: COUNT DISTINCT shipment_id that have at least one persisted event
 * with id greater than this user's last-reviewed event ID. Multiple events on
 * the same shipment count as one. Opening the Shipments workspace (list or
 * detail) stores the current max event ID for that user only.
 *
 * Missing cursor means the user has never reviewed the workspace, so every
 * shipment with events is unreviewed until they open Shipments. That is an
 * unreviewed backlog, not a permanent lifetime counter.
 *
 * Does not rewrite shipment events. Does not scan WooCommerce orders.
 */
final class ShipmentActivityCursor {

	public const USER_META_KEY = '_cetech_de_shipments_reviewed_event_id';

	public function __construct(
		private readonly FeatureFlags $flags,
		private readonly ShipmentRepositoryInterface $shipments
	) {
	}

	public function unreviewed_shipment_count_for_current_user(): int {
		if ( ! $this->current_user_can_review() ) {
			return 0;
		}

		$user_id = $this->current_user_id();

		if ( $user_id <= 0 ) {
			return 0;
		}

		$cursor = $this->reviewed_event_id( $user_id );

		return $this->shipments->countDistinctShipmentsWithEventsAfter( $cursor );
	}

	public function mark_reviewed_for_current_user(): void {
		if ( ! $this->current_user_can_review() ) {
			return;
		}

		$user_id = $this->current_user_id();

		if ( $user_id <= 0 || ! function_exists( 'update_user_meta' ) ) {
			return;
		}

		update_user_meta( $user_id, self::USER_META_KEY, $this->shipments->maxEventId() );
	}

	private function reviewed_event_id( int $user_id ): int {
		if ( ! function_exists( 'get_user_meta' ) ) {
			return 0;
		}

		return max( 0, (int) get_user_meta( $user_id, self::USER_META_KEY, true ) );
	}

	private function current_user_can_review(): bool {
		return $this->flags->is_enabled( 'enable_shipment_records' )
			&& function_exists( 'current_user_can' )
			&& current_user_can( 'manage_shipments' );
	}

	private function current_user_id(): int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}
}
