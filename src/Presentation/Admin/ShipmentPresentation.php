<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Domain\Enum\ShipmentEventType;
use CetechDeliveryEngine\Domain\Enum\ShipmentStatus;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentEvent;

/**
 * Staff-facing shipment labels. Machine codes stay untranslated in queries.
 */
final class ShipmentPresentation {

	public static function staff_reference( Shipment $shipment ): string {
		$number = trim( $shipment->shipment_number );

		if ( '' !== $number ) {
			return $number;
		}

		return sprintf( 'SHP-%06d', max( 0, $shipment->id ) );
	}

	public static function delivery_option_label( Shipment $shipment ): string {
		$label = trim( (string) $shipment->delivery_offer_public_label );

		if ( '' !== $label ) {
			return $label;
		}

		return __( 'Delivery option unavailable', 'cetech-woocommerce-delivery-engine' );
	}

	public static function item_count_label( int $count ): string {
		$count = max( 0, $count );

		return sprintf(
			/* translators: %d: number of shipment items */
			_n( '%d item', '%d items', $count, 'cetech-woocommerce-delivery-engine' ),
			$count
		);
	}

	public static function tracking_state_label( Shipment $shipment ): string {
		return self::has_tracking( $shipment )
			? __( 'Tracking available', 'cetech-woocommerce-delivery-engine' )
			: __( 'Not added', 'cetech-woocommerce-delivery-engine' );
	}

	public static function has_tracking( Shipment $shipment ): bool {
		return '' !== trim( (string) $shipment->tracking_number )
			|| '' !== trim( (string) $shipment->tracking_url )
			|| '' !== trim( (string) $shipment->tracking_carrier_display );
	}

	public static function eta_original_and_current_differ( Shipment $shipment ): bool {
		$original = trim( (string) $shipment->eta_original );
		$current  = trim( (string) $shipment->eta_current );

		return '' !== $original && '' !== $current && $original !== $current;
	}

	public static function list_eta_label( Shipment $shipment ): string {
		$original = trim( (string) $shipment->eta_original );
		$current  = trim( (string) $shipment->eta_current );

		if ( self::eta_original_and_current_differ( $shipment ) ) {
			return $current;
		}

		if ( '' !== $current ) {
			return $current;
		}

		if ( '' !== $original ) {
			return $original;
		}

		return '—';
	}

	public static function customer_delivery_charge( Shipment $shipment ): string {
		$amount = trim( (string) $shipment->customer_paid_shipping_amount );

		if ( '' === $amount ) {
			return '—';
		}

		if ( function_exists( 'wc_price' ) ) {
			$args = [];

			if ( '' !== $shipment->currency_code ) {
				$args['currency'] = $shipment->currency_code;
			}

			return (string) wc_price( $amount, $args );
		}

		if ( '' !== $shipment->currency_code ) {
			return $shipment->currency_code . ' ' . $amount;
		}

		return $amount;
	}

	public static function status_badge_class( ShipmentStatus $status ): string {
		return match ( $status ) {
			ShipmentStatus::AwaitingFulfilment => 'cetech-de-badge cetech-de-badge--awaiting',
			ShipmentStatus::Processing         => 'cetech-de-badge cetech-de-badge--processing',
			ShipmentStatus::Dispatched, ShipmentStatus::InTransit => 'cetech-de-badge cetech-de-badge--transit',
			ShipmentStatus::Delayed            => 'cetech-de-badge cetech-de-badge--attention',
			ShipmentStatus::Delivered          => 'cetech-de-badge cetech-de-badge--ready',
			ShipmentStatus::Cancelled          => 'cetech-de-badge cetech-de-badge--cancelled',
		};
	}

	/**
	 * @return list<array{value: string, label: string}>
	 */
	public static function status_filter_options(): array {
		return [
			[
				'value' => '',
				'label' => __( 'All statuses', 'cetech-woocommerce-delivery-engine' ),
			],
			[
				'value' => ShipmentStatus::AwaitingFulfilment->value,
				'label' => ShipmentStatus::AwaitingFulfilment->label(),
			],
			[
				'value' => ShipmentStatus::Processing->value,
				'label' => ShipmentStatus::Processing->label(),
			],
			[
				'value' => ShipmentStatus::Dispatched->value,
				'label' => ShipmentStatus::Dispatched->label(),
			],
			[
				'value' => ShipmentStatus::InTransit->value,
				'label' => ShipmentStatus::InTransit->label(),
			],
			[
				'value' => ShipmentStatus::Delayed->value,
				'label' => __( 'Delayed / issue', 'cetech-woocommerce-delivery-engine' ),
			],
			[
				'value' => ShipmentStatus::Delivered->value,
				'label' => ShipmentStatus::Delivered->label(),
			],
			[
				'value' => ShipmentStatus::Cancelled->value,
				'label' => ShipmentStatus::Cancelled->label(),
			],
		];
	}

	public static function event_type_label( string $event_type ): string {
		return match ( $event_type ) {
			ShipmentEventType::Created->value => __( 'Awaiting fulfilment', 'cetech-woocommerce-delivery-engine' ),
			ShipmentEventType::StatusChanged->value => __( 'Status updated', 'cetech-woocommerce-delivery-engine' ),
			ShipmentEventType::TrackingUpdated->value => __( 'Tracking updated', 'cetech-woocommerce-delivery-engine' ),
			ShipmentEventType::NoteAdded->value => __( 'Note added', 'cetech-woocommerce-delivery-engine' ),
			default => __( 'Shipment update', 'cetech-woocommerce-delivery-engine' ),
		};
	}

	public static function event_label( ShipmentEvent $event ): string {
		$label = self::event_type_label( $event->event_type->value );

		if ( ShipmentEventType::StatusChanged === $event->event_type && $event->to_status instanceof ShipmentStatus ) {
			return $label . ' — ' . $event->to_status->label();
		}

		return $label;
	}

	public static function can_view_private_notes(): bool {
		return current_user_can( 'view_private_delivery_costs' )
			|| current_user_can( 'view_private_origins' )
			|| current_user_can( 'manage_private_sources' );
	}

	public static function can_view_private_origins(): bool {
		return current_user_can( 'view_private_origins' )
			|| current_user_can( 'manage_private_sources' );
	}

	public static function can_view_private_costs(): bool {
		return current_user_can( 'view_private_delivery_costs' );
	}

	public static function timestamp( string $value ): string {
		$value = trim( $value );

		return '' !== $value ? $value : '—';
	}
}
