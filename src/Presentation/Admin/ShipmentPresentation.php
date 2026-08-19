<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Shipment\ShipmentStatusService;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventSource;
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
		return $shipment->hasPublicTracking();
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
			ShipmentEventType::Created->value => __( 'Shipment created', 'cetech-woocommerce-delivery-engine' ),
			ShipmentEventType::StatusChanged->value => __( 'Status changed', 'cetech-woocommerce-delivery-engine' ),
			ShipmentEventType::TrackingAdded->value => __( 'Tracking added', 'cetech-woocommerce-delivery-engine' ),
			ShipmentEventType::TrackingUpdated->value => __( 'Tracking updated', 'cetech-woocommerce-delivery-engine' ),
			ShipmentEventType::NoteAdded->value => __( 'Note added', 'cetech-woocommerce-delivery-engine' ),
			ShipmentEventType::EtaUpdated->value => __( 'Estimated delivery updated', 'cetech-woocommerce-delivery-engine' ),
			default => __( 'Shipment update', 'cetech-woocommerce-delivery-engine' ),
		};
	}

	public static function event_label( ShipmentEvent $event ): string {
		if ( $event->event_type->is( ShipmentEventType::Created ) ) {
			$initial = $event->to_status instanceof ShipmentStatus
				? $event->to_status->label()
				: ShipmentStatus::AwaitingFulfilment->label();

			return __( 'Shipment created', 'cetech-woocommerce-delivery-engine' ) . ' — ' . $initial;
		}

		if ( $event->event_type->is( ShipmentEventType::StatusChanged ) && $event->to_status instanceof ShipmentStatus ) {
			$prefix = self::is_correction_event( $event )
				? __( 'Status corrected', 'cetech-woocommerce-delivery-engine' )
				: __( 'Status changed', 'cetech-woocommerce-delivery-engine' );

			return $prefix . ' — ' . $event->to_status->label();
		}

		return self::event_type_label( $event->event_type->value );
	}

	public static function is_correction_event( ShipmentEvent $event ): bool {
		return $event->event_type->is( ShipmentEventType::StatusChanged )
			&& ShipmentStatusService::CORRECTION_MARKER === trim( (string) $event->public_note );
	}

	public static function source_label( ShipmentEventSource $source ): string {
		return match ( $source ) {
			ShipmentEventSource::System => __( 'System', 'cetech-woocommerce-delivery-engine' ),
			ShipmentEventSource::Staff => __( 'Staff', 'cetech-woocommerce-delivery-engine' ),
			ShipmentEventSource::Retry => __( 'Retry', 'cetech-woocommerce-delivery-engine' ),
			ShipmentEventSource::WooCommerce => __( 'WooCommerce order', 'cetech-woocommerce-delivery-engine' ),
		};
	}

	/**
	 * Admin History actor line. Never rewrite stored events; never expose email or login.
	 */
	public static function history_actor_label( ShipmentEvent $event ): string {
		if ( ShipmentEventSource::Staff !== $event->source ) {
			return self::source_label( $event->source );
		}

		$actor_id = $event->actor_user_id;

		if ( null === $actor_id || $actor_id <= 0 ) {
			return self::source_label( ShipmentEventSource::Staff );
		}

		$name = self::staff_person_name( $actor_id );

		if ( null === $name ) {
			return sprintf(
				/* translators: %d: WordPress user ID */
				__( 'Former or unknown staff account (User #%d)', 'cetech-woocommerce-delivery-engine' ),
				$actor_id
			);
		}

		return sprintf(
			/* translators: 1: staff name, 2: WordPress user ID */
			__( '%1$s (Staff · User #%2$d)', 'cetech-woocommerce-delivery-engine' ),
			$name,
			$actor_id
		);
	}

	/**
	 * Escaped History actor markup. Links the human name to wp-admin user-edit when permitted.
	 */
	public static function history_actor_html( ShipmentEvent $event ): string {
		$label = self::history_actor_label( $event );

		if ( ShipmentEventSource::Staff !== $event->source ) {
			return esc_html( $label );
		}

		$actor_id = $event->actor_user_id;

		if ( null === $actor_id || $actor_id <= 0 ) {
			return esc_html( $label );
		}

		$name = self::staff_person_name( $actor_id );

		if ( null === $name || ! self::current_user_can_edit_staff( $actor_id ) ) {
			return esc_html( $label );
		}

		$url = admin_url( 'user-edit.php?user_id=' . $actor_id );
		$suffix = sprintf(
			/* translators: %d: WordPress user ID */
			__( '(Staff · User #%d)', 'cetech-woocommerce-delivery-engine' ),
			$actor_id
		);

		return '<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a> ' . esc_html( $suffix );
	}

	private static function current_user_can_edit_staff( int $user_id ): bool {
		return function_exists( 'current_user_can' ) && current_user_can( 'edit_user', $user_id );
	}

	private static function staff_person_name( int $user_id ): ?string {
		if ( $user_id <= 0 || ! function_exists( 'get_userdata' ) ) {
			return null;
		}

		try {
			$user = get_userdata( $user_id );
		} catch ( \Throwable ) {
			return null;
		}

		if ( ! is_object( $user ) ) {
			return null;
		}

		$first = '';
		$last  = '';

		if ( function_exists( 'get_user_meta' ) ) {
			$first = trim( (string) get_user_meta( $user_id, 'first_name', true ) );
			$last  = trim( (string) get_user_meta( $user_id, 'last_name', true ) );
		}

		if ( '' === $first ) {
			$first = trim( (string) ( $user->first_name ?? '' ) );
		}

		if ( '' === $last ) {
			$last = trim( (string) ( $user->last_name ?? '' ) );
		}

		if ( '' !== $first && '' !== $last ) {
			return $first . ' ' . $last;
		}

		$display = trim( (string) ( $user->display_name ?? '' ) );

		return '' !== $display ? $display : null;
	}

	private static function staff_display_name( int $user_id ): ?string {
		return self::staff_person_name( $user_id );
	}

	public static function operational_reason_label( string $reason ): string {
		return match ( $reason ) {
			'order_cancelled' => __( 'WooCommerce order was cancelled.', 'cetech-woocommerce-delivery-engine' ),
			'shipment_quantities_refunded' => __( 'All quantities on this shipment were refunded.', 'cetech-woocommerce-delivery-engine' ),
			default => $reason,
		};
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
