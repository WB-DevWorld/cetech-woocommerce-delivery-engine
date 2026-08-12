<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Shared;

use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;

/**
 * Shared customer/staff operational labels for delivery presentation.
 *
 * Presentation only — does not resolve, quote, or persist delivery data.
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
	 * Build labeled public summary rows for cart/checkout/customer surfaces.
	 *
	 * @param array<string, string|null> $summary
	 *
	 * @return list<array{key: string, value: string}>
	 */
	public static function format_public_summary_rows( array $summary, ?string $fulfilment_choice = null ): array {
		$rows = [];

		$availability = trim( (string) ( $summary['fulfilment_availability_label'] ?? '' ) );
		$choice       = trim( (string) ( $summary['fulfilment_choice_label'] ?? '' ) );
		$offer_label  = trim( (string) ( $summary['delivery_offer_public_label'] ?? '' ) );
		$estimate     = trim( (string) ( $summary['estimate_text'] ?? '' ) );

		if ( null === $fulfilment_choice || '' === $fulfilment_choice ) {
			$fulfilment_choice = self::infer_choice_slug_from_label( $choice );
		}

		if ( '' !== $availability ) {
			$rows[] = [
				'key'   => self::fulfilment(),
				'value' => $availability,
			];
		}

		if ( '' !== $choice ) {
			$rows[] = [
				'key'   => self::method_label_for_choice( $fulfilment_choice ),
				'value' => $choice,
			];
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

		return $rows;
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
