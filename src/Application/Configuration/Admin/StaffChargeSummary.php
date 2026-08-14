<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Admin;

use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;

/**
 * Staff-facing delivery-charge summaries. Never expose internal codes/slugs.
 */
final class StaffChargeSummary {

	/**
	 * @param array<string, mixed>      $rate
	 * @param array<string, mixed>|null $offer
	 * @param array<string, mixed>|null $zone
	 */
	public static function heading( array $rate, ?array $offer = null, ?array $zone = null ): string {
		$option = self::option_label( $offer );
		$amount = self::amount_label( $rate );
		$area   = self::area_label( $zone );

		$parts = [];
		if ( '' !== $area ) {
			$parts[] = $area;
		}
		if ( '' !== $option ) {
			$parts[] = $option;
		}
		if ( '' !== $amount ) {
			$parts[] = $amount;
		}

		return [] === $parts ? self::method_label( (string) ( $rate['charge_type'] ?? '' ) ) : implode( ' — ', $parts );
	}

	/**
	 * @param array<string, mixed> $rate
	 */
	public static function method_label( string $charge_type ): string {
		return match ( $charge_type ) {
			RateCardChargeType::FixedPerItem->value, 'fixed_per_item' => 'Amount per item',
			default => 'Flat amount per delivery',
		};
	}

	/**
	 * @param array<string, mixed> $rate
	 */
	public static function amount_label( array $rate ): string {
		$amount   = trim( (string) ( $rate['base_amount'] ?? '' ) );
		$currency = strtoupper( trim( (string) ( $rate['base_currency'] ?? $rate['currency_code'] ?? '' ) ) );
		if ( '' === $amount ) {
			return '';
		}

		$formatted = AdminMoneyFormatter::display( $amount, $currency );

		return '—' === $formatted ? '' : $formatted;
	}

	/**
	 * @param array<string, mixed>      $rate
	 * @param array<string, mixed>|null $offer
	 */
	public static function line( array $rate, ?array $offer = null ): string {
		$heading = self::heading( $rate, $offer );
		$method  = self::method_label( (string) ( $rate['charge_type'] ?? '' ) );

		return $heading . ' · ' . $method;
	}

	public static function pickup_none(): string {
		return 'Store Pickup — No delivery charge';
	}

	/**
	 * @param array<string, mixed>|null $offer
	 */
	public static function option_label( ?array $offer ): string {
		if ( null === $offer ) {
			return '';
		}

		$label = trim( (string) ( $offer['public_label'] ?? $offer['internal_name'] ?? '' ) );
		if ( '' !== $label ) {
			return $label;
		}

		return match ( (string) ( $offer['route'] ?? '' ) ) {
			DeliveryRoute::StorePickup->value => 'Store Pickup',
			DeliveryRoute::Air->value => 'Air Shipping',
			DeliveryRoute::Sea->value => 'Sea Shipping',
			DeliveryRoute::LocalDelivery->value => 'Standard Delivery',
			default => '',
		};
	}

	/**
	 * @param array<string, mixed>|null $zone
	 */
	public static function area_label( ?array $zone ): string {
		if ( null === $zone ) {
			return '';
		}

		return trim( (string) ( $zone['public_label'] ?? $zone['internal_name'] ?? '' ) );
	}
}
