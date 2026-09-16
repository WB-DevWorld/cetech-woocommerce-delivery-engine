<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Selector;

/**
 * Customer-facing duration copy from Delivery Offer min/max fields.
 *
 * Does not invent wording from labels, prices, or rate-card names.
 */
final class DeliveryEstimateFormatter {

	/**
	 * @param array<string, mixed> $offer
	 */
	public static function from_offer( array $offer ): ?string {
		$total_min = 0;
		$total_max = 0;

		foreach ( [ 'default_processing', 'default_transit', 'default_final_mile' ] as $prefix ) {
			$min_key = $prefix . '_min';
			$max_key = $prefix . '_max';
			$min     = isset( $offer[ $min_key ] ) && '' !== $offer[ $min_key ] ? (int) $offer[ $min_key ] : 0;
			$max     = isset( $offer[ $max_key ] ) && '' !== $offer[ $max_key ] ? (int) $offer[ $max_key ] : 0;

			if ( $min > 0 ) {
				$total_min += $min;
			}

			if ( $max > 0 ) {
				$total_max += $max;
			}
		}

		if ( $total_min <= 0 && $total_max <= 0 ) {
			return null;
		}

		$unit_key = (string) ( $offer['duration_unit'] ?? 'business_days' );

		if ( $total_min > 0 && $total_max > 0 && $total_min !== $total_max ) {
			$unit = self::duration_unit_label( $unit_key, max( $total_max, 2 ) );

			return sprintf(
				/* translators: 1: minimum duration, 2: maximum duration, 3: duration unit label */
				__( '%1$d–%2$d %3$s', 'cetech-woocommerce-delivery-engine' ),
				$total_min,
				$total_max,
				$unit
			);
		}

		$value = $total_max > 0 ? $total_max : $total_min;

		return sprintf(
			/* translators: 1: duration value, 2: duration unit label */
			__( '%1$d %2$s', 'cetech-woocommerce-delivery-engine' ),
			$value,
			self::duration_unit_label( $unit_key, $value )
		);
	}

	private static function duration_unit_label( string $unit, int $count ): string {
		return match ( $unit ) {
			'business_days' => _n( 'business day', 'business days', $count, 'cetech-woocommerce-delivery-engine' ),
			'days'          => _n( 'day', 'days', $count, 'cetech-woocommerce-delivery-engine' ),
			default         => _n( 'day', 'days', $count, 'cetech-woocommerce-delivery-engine' ),
		};
	}
}
