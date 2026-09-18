<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\CustomerContext;

use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolverInterface;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteRequest;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use CetechDeliveryEngine\Domain\Enum\CanonicalResolutionContext;
use CetechDeliveryEngine\Domain\ValueObject\CurrencyCode;

/**
 * Probes whether a Delivery Option can be quoted for a matching location.
 *
 * Uses DestinationZoneMatcher geography only (country/state/city/postcode).
 */
final class LocationOfferQuoteProbe {

	public function __construct(
		private PackageDestinationZoneResolverInterface $zone_resolver,
		private RateQuoteEngine $quote_engine
	) {
	}

	/**
	 * @param array<string, string> $destination Matching-level WC destination.
	 */
	public function zone_ids( array $destination ): array {
		return $this->zone_resolver->resolve_zone_ids( $destination );
	}

	public function offer_quotes_for_location( int $offer_id, MatchingLocation $location, string $currency_code = '' ): bool {
		if ( $offer_id <= 0 || ! $location->isPresent() ) {
			return false;
		}

		$destination = $location->toWcPackageDestination();
		$destination['resolution_context'] = CanonicalResolutionContext::ShopperSelector->value;

		return $this->offer_quotes_for_destination( $offer_id, $destination, $currency_code );
	}

	/**
	 * @param array<string, mixed> $destination
	 */
	public function offer_quotes_for_destination( int $offer_id, array $destination, string $currency_code = '' ): bool {
		if ( $offer_id <= 0 ) {
			return false;
		}

		if ( '' === $currency_code ) {
			$currency_code = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'USD';
		}

		$zone_ids = $this->zone_resolver->resolve_zone_ids( $destination );
		if ( [] === $zone_ids ) {
			return false;
		}

		try {
			$currency = new CurrencyCode( strtoupper( $currency_code ) );
		} catch ( \InvalidArgumentException ) {
			return false;
		}

		foreach ( $zone_ids as $zone_id ) {
			$request = new RateQuoteRequest( $offer_id, $zone_id, 1, $currency );
			$result  = $this->quote_engine->quote( $request );
			if ( $result->success && null !== $result->amount ) {
				return true;
			}
		}

		return false;
	}
}
