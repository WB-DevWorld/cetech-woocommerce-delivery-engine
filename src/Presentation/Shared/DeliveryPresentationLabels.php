<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Shared;

use CetechDeliveryEngine\Application\Pickup\PickupLocationAddressFormatter;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;

/**
 * Shared customer/staff operational labels for delivery presentation.
 *
 * Stage 13F public customer contract (compact):
 * - Delivery option (public label)
 * - Estimated delivery / Ready for pickup
 * - Pickup-only extras when present in the public summary payload
 *
 * Presentation only — does not resolve, quote, or persist delivery data.
 * Does not expose fulfilment availability, generic method terminology,
 * supplier/origin/logistics, rate cards, IDs, or delivery charges when
 * WooCommerce already shows shipping totals.
 */
final class DeliveryPresentationLabels {

	private function __construct() {
	}

	public static function fulfilment(): string {
		return __( 'Fulfilment', 'cetech-woocommerce-delivery-engine' );
	}

	public static function delivery_method(): string {
		return __( 'Delivery method', 'cetech-woocommerce-delivery-engine' );
	}

	public static function method(): string {
		return __( 'Method', 'cetech-woocommerce-delivery-engine' );
	}

	public static function delivery_option(): string {
		return __( 'Delivery option', 'cetech-woocommerce-delivery-engine' );
	}

	public static function estimated_delivery(): string {
		return __( 'Estimated delivery', 'cetech-woocommerce-delivery-engine' );
	}

	public static function ready_for_pickup(): string {
		return __( 'Ready for pickup', 'cetech-woocommerce-delivery-engine' );
	}

	public static function delivery_charge(): string {
		return __( 'Delivery charge', 'cetech-woocommerce-delivery-engine' );
	}

	public static function shipping_method(): string {
		return __( 'Shipping method', 'cetech-woocommerce-delivery-engine' );
	}

	public static function status(): string {
		return __( 'Status', 'cetech-woocommerce-delivery-engine' );
	}

	public static function product(): string {
		return __( 'Product', 'cetech-woocommerce-delivery-engine' );
	}

	public static function pickup_location(): string {
		return __( 'Pickup location', 'cetech-woocommerce-delivery-engine' );
	}

	public static function pickup_address(): string {
		return __( 'Pickup address', 'cetech-woocommerce-delivery-engine' );
	}

	public static function pickup_instructions(): string {
		return __( 'Pickup instructions', 'cetech-woocommerce-delivery-engine' );
	}

	public static function method_label_for_choice( ?string $fulfilment_choice ): string {
		if ( self::is_store_pickup( $fulfilment_choice ) ) {
			return self::method();
		}

		return self::delivery_method();
	}

	public static function estimate_label_for_choice( ?string $fulfilment_choice ): string {
		if ( self::is_store_pickup( $fulfilment_choice ) ) {
			return self::ready_for_pickup();
		}

		return self::estimated_delivery();
	}

	public static function is_store_pickup( ?string $fulfilment_choice ): bool {
		return FulfilmentChoice::StorePickup->value === (string) $fulfilment_choice;
	}

	/**
	 * Compact customer-facing public summary rows (Stage 13F).
	 *
	 * Omits fulfilment availability, generic delivery-method terminology,
	 * public descriptions, and delivery charges. Pickup may add location /
	 * address / instructions when those public fields are present.
	 *
	 * @param array<string, string|null> $summary
	 *
	 * @return list<array{key: string, value: string}>
	 */
	public static function format_public_summary_rows( array $summary, ?string $fulfilment_choice = null ): array {
		$rows = [];

		$choice      = trim( (string) ( $summary['fulfilment_choice_label'] ?? '' ) );
		$offer_label = trim( (string) ( $summary['delivery_offer_public_label'] ?? '' ) );
		$estimate    = trim( (string) ( $summary['estimate_text'] ?? '' ) );

		if ( null === $fulfilment_choice || '' === $fulfilment_choice ) {
			$fulfilment_choice = self::infer_choice_slug_from_label( $choice );
		}

		if ( '' !== $offer_label ) {
			$rows[] = [
				'key'   => self::delivery_option(),
				'value' => $offer_label,
			];
		}

		if ( '' !== $estimate ) {
			$rows[] = [
				'key'   => self::estimate_label_for_choice( $fulfilment_choice ),
				'value' => self::strip_estimated_prefix( $estimate ),
			];
		}

		if ( self::is_store_pickup( $fulfilment_choice ) ) {
			foreach (
				[
					'pickup_location_label'       => self::pickup_location(),
					'pickup_address'              => self::pickup_address(),
					'pickup_instructions'         => self::pickup_instructions(),
					'pickup_public_instructions'  => self::pickup_instructions(),
				] as $field => $label
			) {
				$value = trim( (string) ( $summary[ $field ] ?? '' ) );

				if ( 'pickup_address' === $field ) {
					$value = PickupLocationAddressFormatter::format( $value );
				}

				if ( '' === $value ) {
					continue;
				}

				// Avoid duplicate instruction rows when both keys are populated identically.
				foreach ( $rows as $existing ) {
					if ( $existing['key'] === $label && $existing['value'] === $value ) {
						continue 2;
					}
				}

				$rows[] = [
					'key'   => $label,
					'value' => $value,
				];
			}
		}

		return $rows;
	}

	/**
	 * Product-page estimate line under the public option label.
	 */
	public static function format_product_estimate_line( string $estimate_text, ?string $fulfilment_choice = null ): string {
		$estimate = self::strip_estimated_prefix( $estimate_text );

		if ( '' === $estimate ) {
			return '';
		}

		return self::estimate_label_for_choice( $fulfilment_choice ) . ': ' . $estimate;
	}

	/**
	 * Prefer clean ETA values without a duplicated "Estimated" prefix when the label already says Estimated delivery.
	 */
	public static function strip_estimated_prefix( string $estimate_text ): string {
		$trimmed = trim( $estimate_text );

		if ( preg_match( '/^Estimated\s+/iu', $trimmed ) ) {
			return trim( (string) preg_replace( '/^Estimated\s+/iu', '', $trimmed ) );
		}

		return $trimmed;
	}

	private static function infer_choice_slug_from_label( string $choice_label ): ?string {
		$normalized = strtolower( trim( $choice_label ) );

		if ( '' === $normalized ) {
			return null;
		}

		if ( str_contains( $normalized, 'pickup' ) ) {
			return FulfilmentChoice::StorePickup->value;
		}

		if ( str_contains( $normalized, 'delivery' ) ) {
			return FulfilmentChoice::Delivery->value;
		}

		return null;
	}
}
