<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RateCard;

/**
 * Percentage/fixed bulk amount math. Malformed values never become zero.
 */
final class RateCardBulkAmountMath {

	public static function increase_percent( mixed $amount, mixed $percent ): string {
		$base = RateCardAmountFormatter::format( $amount );
		if ( ! is_numeric( $percent ) ) {
			throw new \InvalidArgumentException( 'Percentage must be numeric.' );
		}
		$factor = 1 + ( (float) $percent / 100 );
		$result = (float) $base * $factor;
		if ( ! is_finite( $result ) ) {
			throw new \InvalidArgumentException( 'Percentage update produced a non-finite amount.' );
		}

		return RateCardAmountFormatter::format( $result );
	}

	public static function increase_fixed( mixed $amount, mixed $delta ): string {
		$base = RateCardAmountFormatter::format( $amount );
		if ( ! is_numeric( $delta ) ) {
			throw new \InvalidArgumentException( 'Amount delta must be numeric.' );
		}
		$result = (float) $base + (float) $delta;
		if ( $result < 0 ) {
			throw new \InvalidArgumentException( 'Amount update would be negative.' );
		}

		return RateCardAmountFormatter::format( $result );
	}
}
