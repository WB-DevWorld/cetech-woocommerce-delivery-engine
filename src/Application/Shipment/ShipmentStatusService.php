<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Domain\Enum\ShipmentEventType;
use CetechDeliveryEngine\Domain\Enum\ShipmentStatus;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentEvent;
use CetechDeliveryEngine\Domain\Shipment\ShipmentRepositoryInterface;

/**
 * Authoritative shipment status mutations. Presentation and WooCommerce hooks
 * must not apply status changes themselves.
 */
final class ShipmentStatusService {

	public const REASON_MAX = 1000;

	public const REASON_ORDER_CANCELLED            = 'order_cancelled';
	public const REASON_SHIPMENT_QUANTITIES_REFUNDED = 'shipment_quantities_refunded';

	public const CORRECTION_MARKER = 'correction';

	public function __construct(
		private readonly ShipmentRepositoryInterface $shipments,
		private readonly ShipmentOperationsIssueStore $issues
	) {
	}

	public function change( int $shipment_id, ShipmentStatus $target, ShipmentStatusChangeRequest $request ): ShipmentStatusChangeResult {
		if ( $shipment_id <= 0 ) {
			return ShipmentStatusChangeResult::failure(
				__( 'That shipment could not be found.', 'cetech-woocommerce-delivery-engine' ),
				'shipment_not_found'
			);
		}

		$current = $this->shipments->findById( $shipment_id );

		if ( ! $current instanceof Shipment ) {
			return ShipmentStatusChangeResult::failure(
				__( 'That shipment could not be found.', 'cetech-woocommerce-delivery-engine' ),
				'shipment_not_found'
			);
		}

		if ( $current->status === $target ) {
			return ShipmentStatusChangeResult::success(
				$current,
				__( 'The shipment already has that status.', 'cetech-woocommerce-delivery-engine' ),
				true
			);
		}

		$correction = ShipmentStatusChangeMode::Correction === $request->mode;

		if ( ShipmentStatusChangeMode::Automatic === $request->mode ) {
			if ( ShipmentStatus::Cancelled !== $target || ! ShipmentStatusTransitionPolicy::allows_automatic_cancel( $current->status ) ) {
				return ShipmentStatusChangeResult::failure(
					__( 'That shipment status cannot be changed automatically.', 'cetech-woocommerce-delivery-engine' ),
					'transition_not_allowed'
				);
			}
		} elseif ( ! $correction && ! ShipmentStatusTransitionPolicy::allows_normal( $current->status, $target ) ) {
			return ShipmentStatusChangeResult::failure(
				__( 'That shipment status change is not allowed.', 'cetech-woocommerce-delivery-engine' ),
				'transition_not_allowed'
			);
		}

		$reason = $this->sanitize_reason( $request->reason );
		$needs_reason = ShipmentStatusTransitionPolicy::requires_reason( $current->status, $target, $correction )
			|| ShipmentStatusChangeMode::Automatic === $request->mode;

		if ( $needs_reason && null === $reason ) {
			return ShipmentStatusChangeResult::failure(
				__( 'Enter a reason for this shipment status change.', 'cetech-woocommerce-delivery-engine' ),
				'reason_required'
			);
		}

		$saved = $this->shipments->update( $current->withStatus( $target ) );
		$this->shipments->appendEvent(
			ShipmentEvent::create(
				$saved->id,
				ShipmentEventType::StatusChanged,
				$request->source,
				$current->status,
				$saved->status,
				$correction ? self::CORRECTION_MARKER : null,
				$reason,
				$request->actor_user_id
			)
		);

		if ( $this->clears_operational_issues( $saved->status ) ) {
			$this->issues->clear_shipment( $saved->id );
		}

		return ShipmentStatusChangeResult::success(
			$saved,
			__( 'Shipment status was updated.', 'cetech-woocommerce-delivery-engine' )
		);
	}

	public function target_from_request( string $raw ): ?ShipmentStatus {
		return ShipmentStatus::tryFromMachineCode( sanitize_key( $raw ) );
	}

	private function clears_operational_issues( ShipmentStatus $status ): bool {
		return ShipmentStatus::Cancelled === $status || ShipmentStatus::Delivered === $status;
	}

	private function sanitize_reason( string $raw ): ?string {
		$value = function_exists( 'sanitize_textarea_field' )
			? sanitize_textarea_field( $raw )
			: trim( strip_tags( $raw ) );

		if ( '' === $value ) {
			return null;
		}

		if ( strlen( $value ) > self::REASON_MAX ) {
			return substr( $value, 0, self::REASON_MAX );
		}

		return $value;
	}
}
