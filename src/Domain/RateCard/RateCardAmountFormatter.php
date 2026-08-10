<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RateCard;

/**
 * Fail-closed monetary formatting for rate-card base amounts.
 *
 * Explicit numeric zero is preserved. Non-numeric / missing values never become 0.
 */
final class RateCardAmountFormatter {

	/**
	 * @throws \InvalidArgumentException When the value is missing or non-numeric.
	 */
	public static function format( mixed $value ): string {
		if ( null === $value || '' === $value ) {
			throw new \InvalidArgumentException( 'Rate card base_amount is required and must be numeric.' );
		}

		if ( ! is_numeric( $value ) ) {
			throw new \InvalidArgumentException( 'Rate card base_amount must be numeric.' );
		}

		return number_format( (float) $value, 4, '.', '' );
	}
}
