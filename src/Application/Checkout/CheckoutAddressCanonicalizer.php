<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Checkout;

use CetechDeliveryEngine\Application\Destination\WooCommerceStateCatalogInterface;
use CetechDeliveryEngine\Application\Geography\CanonicalLocationResolver;
use CetechDeliveryEngine\Domain\Enum\CanonicalResolutionContext;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Domain\Geography\GeographyNameNormalizer;
use CetechDeliveryEngine\Domain\Geography\ResolvedDestination;

/**
 * Exact-canonical enrichment of WooCommerce checkout destinations.
 *
 * Never fuzzy. Never weakens MatchingLocation identity comparison. Never
 * mutates customer-visible city/state/street merely to attach a key.
 */
final class CheckoutAddressCanonicalizer {

	public function __construct(
		private CanonicalLocationResolver $resolver,
		private ?WooCommerceStateCatalogInterface $states = null
	) {
	}

	/**
	 * @param array<string, mixed> $address WooCommerce checkout/shipping fields.
	 *
	 * @return array<string, mixed>
	 */
	public function canonicalize( array $address ): array {
		$country  = trim( (string) ( $address['country'] ?? '' ) );
		$state    = trim( (string) ( $address['state'] ?? '' ) );
		$city     = trim( (string) ( $address['city'] ?? '' ) );
		$postcode = trim( (string) ( $address['postcode'] ?? $address['zip'] ?? '' ) );
		$incoming = $this->sanitize_key( (string) ( $address['canonical_location_key'] ?? $address['location_key'] ?? '' ) );

		$out = $address;
		unset( $out['location_key'] );
		$out['canonical_location_key'] = '';

		if ( '' === $country ) {
			return $this->without_key( $out );
		}

		$admin_label = $this->admin_label( $country, $state );
		$pack_usable = $this->resolver->country_has_usable_pack( $country );

		if ( $pack_usable && '' !== $incoming ) {
			$from_key = $this->resolver->resolve(
				$country,
				$state,
				$admin_label,
				$city,
				$postcode,
				$incoming,
				CanonicalResolutionContext::WooCommerceDestination
			);
			if ( $this->may_attach( $from_key, $city, true ) ) {
				$out['canonical_location_key'] = $from_key->location_key();

				return $out;
			}
		}

		if ( ! $pack_usable ) {
			return $this->without_key( $out );
		}

		$from_text = $this->resolver->resolve(
			$country,
			$state,
			$admin_label,
			$city,
			$postcode,
			'',
			CanonicalResolutionContext::WooCommerceDestination
		);
		if ( $this->may_attach( $from_text, $city, false ) ) {
			$out['canonical_location_key'] = $from_text->location_key();

			return $out;
		}

		return $this->without_key( $out );
	}

	/**
	 * @param array<string, mixed> $address
	 *
	 * @return array<string, mixed>
	 */
	private function without_key( array $address ): array {
		$address['canonical_location_key'] = '';

		return $address;
	}

	private function may_attach( ResolvedDestination $resolved, string $city, bool $require_name_match ): bool {
		if ( ! $resolved->hasCanonicalLocation() ) {
			return false;
		}

		$location = $resolved->location;
		if ( ! $location instanceof CanonicalLocation || ! $location->isLocality() ) {
			return false;
		}

		$city = trim( $city );
		if ( '' === $city ) {
			return false;
		}

		if ( ! $require_name_match ) {
			return true;
		}

		return $this->locality_represents_city( $location, $city );
	}

	private function locality_represents_city( CanonicalLocation $location, string $city ): bool {
		$normalized_city = GeographyNameNormalizer::normalize( $city );
		if ( '' === $normalized_city ) {
			return false;
		}

		if ( $location->normalized_name === $normalized_city ) {
			return true;
		}

		if ( GeographyNameNormalizer::normalize( $location->canonical_name ) === $normalized_city ) {
			return true;
		}

		if ( GeographyNameNormalizer::normalize( $location->ascii_name ) === $normalized_city ) {
			return true;
		}

		return false;
	}

	private function admin_label( string $country, string $state ): string {
		$state = trim( $state );
		if ( '' === $state ) {
			return '';
		}

		$states = $this->states instanceof WooCommerceStateCatalogInterface
			? $this->states->states_for_country( $country )
			: [];

		if ( isset( $states[ $state ] ) && '' !== trim( (string) $states[ $state ] ) ) {
			return trim( (string) $states[ $state ] );
		}

		foreach ( $states as $label ) {
			if ( strcasecmp( trim( (string) $label ), $state ) === 0 ) {
				return trim( (string) $label );
			}
		}

		return $state;
	}

	private function sanitize_key( string $raw ): string {
		$trimmed = trim( $raw );

		return preg_replace( '/[^a-zA-Z0-9\-]/', '', $trimmed ) ?? '';
	}
}
