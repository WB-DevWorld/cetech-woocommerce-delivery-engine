<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Selector;

/**
 * The one precedence rule for a Delivery option's customer-visible estimate (M2 "ETA precedence").
 *
 * 1. The effective "Estimated delivery" text configured for the item (variation, then product,
 *    then the site-wide default of its fulfilment type), when it is not empty.
 * 2. Otherwise the Delivery Offer's durations (processing + transit + final mile, in its unit).
 * 3. Otherwise none; a priced delivery option without an estimate stays hidden by the visibility gate.
 *
 * The result is computed once when the product options are built and then copied forward into the
 * cart, checkout, order snapshot and emails, so every surface shows the same text. Store Pickup is
 * not covered here: it shows the Pickup Location's readiness text.
 */
final class DeliveryEstimatePrecedence {

	/**
	 * @param array<string, mixed> $offer Delivery Offer row.
	 */
	public static function for_delivery( ?string $configured_estimate, array $offer ): ?string {
		$configured = self::normalize( $configured_estimate );

		if ( null !== $configured ) {
			return $configured;
		}

		return DeliveryEstimateFormatter::from_offer( $offer );
	}

	/**
	 * Configured text is used verbatim apart from surrounding whitespace; it is never parsed into dates.
	 */
	public static function normalize( ?string $configured_estimate ): ?string {
		if ( null === $configured_estimate ) {
			return null;
		}

		$trimmed = trim( $configured_estimate );

		return '' !== $trimmed ? $trimmed : null;
	}
}
