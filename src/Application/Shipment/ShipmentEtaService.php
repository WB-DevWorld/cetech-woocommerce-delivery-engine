<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Domain\Enum\ShipmentEventSource;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventType;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentEvent;
use CetechDeliveryEngine\Domain\Shipment\ShipmentRepositoryInterface;

/**
 * Updates the operational current ETA only. Original checkout ETA is immutable.
 */
final class ShipmentEtaService {

	public const ETA_MAX    = 255;
	public const REASON_MAX = 1000;

	public function __construct(
		private readonly ShipmentRepositoryInterface $shipments
	) {
	}

	public function update_current( int $shipment_id, string $eta_current, string $reason, ?int $actor_user_id ): ShipmentEtaUpdateResult {
		if ( $shipment_id <= 0 ) {
			return ShipmentEtaUpdateResult::failure(
				__( 'That shipment could not be found.', 'cetech-woocommerce-delivery-engine' ),
				'shipment_not_found'
			);
		}

		$current = $this->shipments->findById( $shipment_id );

		if ( ! $current instanceof Shipment ) {
			return ShipmentEtaUpdateResult::failure(
				__( 'That shipment could not be found.', 'cetech-woocommerce-delivery-engine' ),
				'shipment_not_found'
			);
		}

		try {
			$eta = $this->sanitize_eta( $eta_current );
		} catch ( \InvalidArgumentException ) {
			return ShipmentEtaUpdateResult::failure(
				__( 'The current estimate is too long.', 'cetech-woocommerce-delivery-engine' ),
				'eta_too_long'
			);
		}

		if ( $this->nullable( $current->eta_current ) === $eta ) {
			return ShipmentEtaUpdateResult::success(
				$current,
				__( 'The current estimate was already up to date.', 'cetech-woocommerce-delivery-engine' ),
				true
			);
		}

		$reason_text = $this->sanitize_reason( $reason );

		if ( null === $reason_text ) {
			return ShipmentEtaUpdateResult::failure(
				__( 'Enter a reason for updating the current estimate.', 'cetech-woocommerce-delivery-engine' ),
				'reason_required'
			);
		}

		$original = $current->eta_original;
		$saved    = $this->shipments->update( $current->withCurrentEta( $eta ) );

		if ( $this->nullable( $saved->eta_original ) !== $this->nullable( $original ) ) {
			return ShipmentEtaUpdateResult::failure(
				__( 'The original estimate could not be kept unchanged.', 'cetech-woocommerce-delivery-engine' ),
				'original_eta_protected'
			);
		}

		$this->shipments->appendEvent(
			ShipmentEvent::create(
				$saved->id,
				ShipmentEventType::EtaUpdated,
				ShipmentEventSource::Staff,
				null,
				null,
				null,
				$reason_text,
				$actor_user_id
			)
		);

		return ShipmentEtaUpdateResult::success(
			$saved,
			__( 'The current delivery estimate was updated.', 'cetech-woocommerce-delivery-engine' )
		);
	}

	private function sanitize_eta( string $raw ): ?string {
		$value = function_exists( 'sanitize_text_field' )
			? sanitize_text_field( $raw )
			: trim( strip_tags( $raw ) );

		if ( '' === $value ) {
			return null;
		}

		if ( strlen( $value ) > self::ETA_MAX ) {
			throw new \InvalidArgumentException( 'eta_too_long' );
		}

		return $value;
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

	private function nullable( ?string $value ): ?string {
		$value = trim( (string) $value );

		return '' === $value ? null : $value;
	}
}
