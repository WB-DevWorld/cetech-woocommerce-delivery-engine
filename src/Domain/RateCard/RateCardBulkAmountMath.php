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

	/**
	 * Translate an administrator amount operation into the internal signed percent/fixed pair.
	 * Missing or non-numeric values never become zero. Explicit numeric zero remains valid.
	 *
	 * @return array{op: string, value: string}
	 */
	public static function normalize_operation( string $operation, mixed $value ): array {
		if ( null === $value || '' === $value ) {
			throw new \InvalidArgumentException( 'Amount value is required and must be numeric.' );
		}
		if ( ! is_numeric( $value ) ) {
			throw new \InvalidArgumentException( 'Amount value must be numeric.' );
		}

		$operation = strtolower( trim( $operation ) );
		$magnitude = (float) $value;

		if ( in_array( $operation, [ 'increase_percent', 'decrease_percent', 'increase_fixed', 'decrease_fixed' ], true ) && $magnitude < 0 ) {
			throw new \InvalidArgumentException( 'Enter a positive amount. The selected operation already chooses increase or decrease.' );
		}

		return match ( $operation ) {
			'increase_percent' => [
				'op'    => 'percent',
				'value' => (string) abs( $magnitude ),
			],
			'decrease_percent' => [
				'op'    => 'percent',
				'value' => (string) ( 0 - abs( $magnitude ) ),
			],
			'increase_fixed' => [
				'op'    => 'fixed',
				'value' => (string) abs( $magnitude ),
			],
			'decrease_fixed' => [
				'op'    => 'fixed',
				'value' => (string) ( 0 - abs( $magnitude ) ),
			],
			'percent', 'fixed' => [
				'op'    => $operation,
				'value' => (string) $value,
			],
			default => throw new \InvalidArgumentException( 'Unknown Delivery Charge amount operation.' ),
		};
	}
}
