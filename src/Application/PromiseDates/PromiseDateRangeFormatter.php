<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\PromiseDates;

/**
 * The one customer wording for a promised window, used on every surface: product page, cart,
 * checkout (Classic and Blocks), thank-you page, My Account, both email formats and the staff
 * order view.
 *
 * Default (authorization decision 3): "Delivery between Tue 2 Dec and Thu 4 Dec", or
 * "Delivery on Tue 2 Dec" for a one-day window. The year is added when the window is not in the
 * current year or crosses a year end. Day and month names follow the site language through
 * wp_date() when WordPress is loaded.
 */
final class PromiseDateRangeFormatter {

	private const DAY_FORMAT      = 'D j M';
	private const DAY_YEAR_FORMAT = 'D j M Y';

	/** @var \Closure(): \DateTimeImmutable */
	private \Closure $clock;

	/**
	 * @param (callable(): \DateTimeImmutable)|null $clock Only for tests; defaults to the current time.
	 */
	public function __construct( ?callable $clock = null ) {
		$this->clock = null === $clock
			? static fn (): \DateTimeImmutable => new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) )
			: \Closure::fromCallable( $clock );
	}

	public function format( PromiseDisplay $promise ): string {
		$zone      = new \DateTimeZone( $promise->timezone );
		$from      = $promise->from();
		$to        = $promise->to();
		$this_year = ( ( $this->clock )() )->setTimezone( $zone )->format( 'Y' );
		$with_year = $from->format( 'Y' ) !== $this_year || $to->format( 'Y' ) !== $this_year;
		$pattern   = $with_year ? self::DAY_YEAR_FORMAT : self::DAY_FORMAT;

		if ( $promise->is_single_day() ) {
			/* translators: %s: a delivery date such as "Tue 2 Dec". */
			return sprintf( __( 'Delivery on %s', 'cetech-woocommerce-delivery-engine' ), self::label( $from, $pattern, $zone ) );
		}

		return sprintf(
			/* translators: 1: first possible delivery date, 2: last possible delivery date, such as "Tue 2 Dec" and "Thu 4 Dec". */
			__( 'Delivery between %1$s and %2$s', 'cetech-woocommerce-delivery-engine' ),
			self::label( $from, $pattern, $zone ),
			self::label( $to, $pattern, $zone )
		);
	}

	private static function label( \DateTimeImmutable $day, string $pattern, \DateTimeZone $zone ): string {
		// Noon keeps the calendar day stable for any zone offset when a timestamp is formatted.
		$noon = $day->setTime( 12, 0 );
		if ( function_exists( 'wp_date' ) ) {
			$label = wp_date( $pattern, $noon->getTimestamp(), $zone );
			if ( is_string( $label ) && '' !== $label ) {
				return $label;
			}
		}

		return $noon->format( $pattern );
	}
}
