<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Domain\Enum\ShipmentEventSource;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventType;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentEvent;
use CetechDeliveryEngine\Domain\Shipment\ShipmentRepositoryInterface;

/**
 * Manual staff tracking updates. Does not change shipment status.
 */
final class ShipmentTrackingService {

	public const CARRIER_MAX        = 255;
	public const TRACKING_NUMBER_MAX = 191;
	public const PUBLIC_NOTE_MAX    = 5000;

	public function __construct(
		private readonly ShipmentRepositoryInterface $shipments
	) {
	}

	public function save( int $shipment_id, ShipmentTrackingInput $input, ?int $actor_user_id ): ShipmentTrackingSaveResult {
		if ( $shipment_id <= 0 ) {
			return ShipmentTrackingSaveResult::failure(
				__( 'That shipment could not be found.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		$current = $this->shipments->findById( $shipment_id );

		if ( ! $current instanceof Shipment ) {
			return ShipmentTrackingSaveResult::failure(
				__( 'That shipment could not be found.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		try {
			$carrier = $this->sanitize_short_text( $input->carrier, self::CARRIER_MAX );
			$number  = $this->sanitize_tracking_number( $input->tracking_number );
			$url     = TrackingUrl::normalize( $input->tracking_url );
			$dispatch = ShipmentDispatchDate::from_staff_date( $input->dispatch_date );
			$note    = $this->sanitize_note( $input->public_note );
		} catch ( \InvalidArgumentException $exception ) {
			return ShipmentTrackingSaveResult::failure( $this->error_message( $exception->getMessage() ) );
		}

		$updated = $current->withTrackingDetails( $number, $url, $carrier, $dispatch, $note );

		if ( $this->same_tracking( $current, $updated ) ) {
			return ShipmentTrackingSaveResult::success(
				$current,
				__( 'No tracking changes were needed.', 'cetech-woocommerce-delivery-engine' ),
				true
			);
		}

		$saved = $this->shipments->update( $updated );
		$this->append_history( $current, $saved, $actor_user_id );

		return ShipmentTrackingSaveResult::success(
			$saved,
			__( 'Shipment tracking was saved.', 'cetech-woocommerce-delivery-engine' )
		);
	}

	private function append_history( Shipment $before, Shipment $after, ?int $actor_user_id ): void {
		$tracking_changed = ! $this->same_identity( $before, $after ) || $this->nullable( $before->dispatch_at ) !== $this->nullable( $after->dispatch_at );
		$note_changed     = $this->nullable( $before->public_note ) !== $this->nullable( $after->public_note );

		if ( $tracking_changed ) {
			$had = $before->hasPublicTracking() || '' !== (string) $this->nullable( $before->dispatch_at );
			$has = $after->hasPublicTracking() || '' !== (string) $this->nullable( $after->dispatch_at );
			$type = ( ! $had && $has ) ? ShipmentEventType::TrackingAdded : ShipmentEventType::TrackingUpdated;

			$this->shipments->appendEvent(
				ShipmentEvent::create(
					$after->id,
					$type,
					ShipmentEventSource::Staff,
					null,
					null,
					$this->tracking_event_note( $type, $had, $has ),
					null,
					$actor_user_id
				)
			);
		}

		if ( $note_changed ) {
			$this->shipments->appendEvent(
				ShipmentEvent::create(
					$after->id,
					ShipmentEventType::NoteAdded,
					ShipmentEventSource::Staff,
					null,
					null,
					__( 'Public shipment note updated.', 'cetech-woocommerce-delivery-engine' ),
					null,
					$actor_user_id
				)
			);
		}
	}

	private function tracking_event_note( ShipmentEventType $type, bool $had, bool $has ): string {
		if ( ShipmentEventType::TrackingAdded === $type ) {
			return __( 'Tracking added.', 'cetech-woocommerce-delivery-engine' );
		}

		if ( $had && ! $has ) {
			return __( 'Tracking details were cleared.', 'cetech-woocommerce-delivery-engine' );
		}

		return __( 'Tracking updated.', 'cetech-woocommerce-delivery-engine' );
	}

	private function same_tracking( Shipment $left, Shipment $right ): bool {
		return $this->same_identity( $left, $right )
			&& $this->nullable( $left->dispatch_at ) === $this->nullable( $right->dispatch_at )
			&& $this->nullable( $left->public_note ) === $this->nullable( $right->public_note );
	}

	private function same_identity( Shipment $left, Shipment $right ): bool {
		return $this->nullable( $left->tracking_number ) === $this->nullable( $right->tracking_number )
			&& $this->nullable( $left->tracking_url ) === $this->nullable( $right->tracking_url )
			&& $this->nullable( $left->tracking_carrier_display ) === $this->nullable( $right->tracking_carrier_display );
	}

	private function sanitize_short_text( string $raw, int $max ): ?string {
		$value = $this->sanitize_text( $raw );

		if ( null === $value ) {
			return null;
		}

		if ( strlen( $value ) > $max ) {
			throw new \InvalidArgumentException( 'field_too_long' );
		}

		return $value;
	}

	private function sanitize_tracking_number( string $raw ): ?string {
		$value = $this->sanitize_text( $raw );

		if ( null === $value ) {
			return null;
		}

		if ( strlen( $value ) > self::TRACKING_NUMBER_MAX ) {
			throw new \InvalidArgumentException( 'tracking_number_too_long' );
		}

		return $value;
	}

	private function sanitize_note( string $raw ): ?string {
		$value = function_exists( 'sanitize_textarea_field' )
			? sanitize_textarea_field( $raw )
			: trim( strip_tags( $raw ) );

		if ( '' === $value ) {
			return null;
		}

		if ( strlen( $value ) > self::PUBLIC_NOTE_MAX ) {
			throw new \InvalidArgumentException( 'public_note_too_long' );
		}

		return $value;
	}

	private function sanitize_text( string $raw ): ?string {
		$value = function_exists( 'sanitize_text_field' )
			? sanitize_text_field( $raw )
			: trim( strip_tags( $raw ) );

		return '' === $value ? null : $value;
	}

	private function nullable( ?string $value ): ?string {
		$value = trim( (string) $value );

		return '' === $value ? null : $value;
	}

	private function error_message( string $code ): string {
		return match ( $code ) {
			'tracking_url_unsafe' => __( 'The tracking URL is not allowed. Use a web address that starts with http or https.', 'cetech-woocommerce-delivery-engine' ),
			'tracking_url_invalid', 'tracking_url_too_long' => __( 'Enter a valid tracking web address, or leave the tracking URL blank.', 'cetech-woocommerce-delivery-engine' ),
			'dispatch_date_invalid' => __( 'Enter a valid dispatch date, or leave the dispatch date blank.', 'cetech-woocommerce-delivery-engine' ),
			'tracking_number_too_long' => __( 'The tracking number is too long.', 'cetech-woocommerce-delivery-engine' ),
			'public_note_too_long' => __( 'The public shipment note is too long.', 'cetech-woocommerce-delivery-engine' ),
			default => __( 'Those tracking details could not be saved. Please check the form and try again.', 'cetech-woocommerce-delivery-engine' ),
		};
	}
}
