<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\CustomerContext;

/**
 * Customer-owned matching location (Delivery Area / rate destination).
 *
 * Identity fields: country, state, city, postcode. No street or recipient.
 */
final class MatchingLocation {

	private function __construct(
		public readonly string $country,
		public readonly string $state,
		public readonly string $city,
		public readonly string $postcode,
		public readonly string $country_identity,
		public readonly string $state_identity,
		public readonly string $city_identity,
		public readonly string $postcode_identity,
		public readonly string $canonical_location_key = ''
	) {
	}

	/**
	 * @param array<string, mixed> $raw
	 */
	public static function fromInput( array $raw ): self {
		$country_raw = (string) ( $raw['country'] ?? '' );
		$state_raw   = (string) ( $raw['state'] ?? '' );
		$city_raw    = (string) ( $raw['city'] ?? '' );
		$postcode    = (string) ( $raw['postcode'] ?? $raw['zip'] ?? '' );
		$canonical   = trim( (string) ( $raw['canonical_location_key'] ?? $raw['location_key'] ?? '' ) );
		$canonical   = preg_replace( '/[^a-zA-Z0-9\-]/', '', $canonical ) ?? '';

		$country = LocationNormalizer::countryDisplay( $country_raw );
		$state   = LocationNormalizer::stateDisplay( $country, $state_raw );

		return new self(
			$country,
			$state,
			LocationNormalizer::cityDisplay( $city_raw ),
			LocationNormalizer::postcode( $postcode ),
			LocationNormalizer::country( $country_raw ),
			LocationNormalizer::state( $country, $state_raw ),
			LocationNormalizer::cityIdentity( $city_raw ),
			LocationNormalizer::postcode( $postcode ),
			$canonical
		);
	}

	public function isPresent(): bool {
		return '' !== $this->country_identity;
	}

	public function identity(): string {
		return LocationIdentity::matching(
			$this->country_identity,
			$this->state_identity,
			$this->city_identity,
			$this->postcode_identity,
			$this->canonical_location_key
		);
	}

	/**
	 * Matching-level geography for DestinationZoneMatcher (no street).
	 *
	 * @return array<string, string>
	 */
	public function toDestinationArray(): array {
		return [
			'country'                 => $this->country_identity,
			'state'                   => $this->state_identity,
			'city'                    => $this->city,
			'postcode'                => $this->postcode,
			'canonical_location_key'  => $this->canonical_location_key,
		];
	}

	/**
	 * WooCommerce package destination. Street is optional and never used for area matching.
	 *
	 * @return array<string, string>
	 */
	public function toWcPackageDestination( string $address = '', string $address_2 = '' ): array {
		return [
			'country'                => $this->country_identity,
			'state'                  => $this->state_identity,
			'city'                   => $this->city,
			'postcode'               => $this->postcode,
			'address'                => $address,
			'address_2'              => $address_2,
			'canonical_location_key' => $this->canonical_location_key,
		];
	}

	/**
	 * The most specific place the customer chose, in words: the town, else the region's name, else
	 * the country's name. A region-wide Delivery Area prices a destination that has no town, and
	 * every customer surface (product page, cart, checkout, shipping label, order, emails) prints
	 * this text, so it must never be a bare code such as "GH" while a name is available. The
	 * country code is only the last resort, when WooCommerce cannot name the country.
	 */
	public function publicLocalityLabel(): string {
		$city = trim( $this->city );
		if ( '' !== $city ) {
			return $city;
		}

		$region = LocationNormalizer::stateLabel( $this->country, $this->state );
		if ( '' !== $region ) {
			return $region;
		}

		$country = LocationNormalizer::countryLabel( $this->country );

		return '' !== $country ? $country : $this->country;
	}

	/**
	 * @return array<string, string>
	 */
	public function toArray(): array {
		return [
			'country'                 => $this->country,
			'state'                   => $this->state,
			'city'                    => $this->city,
			'postcode'                => $this->postcode,
			'country_identity'        => $this->country_identity,
			'state_identity'          => $this->state_identity,
			'city_identity'           => $this->city_identity,
			'postcode_identity'       => $this->postcode_identity,
			'canonical_location_key'  => $this->canonical_location_key,
		];
	}

	/**
	 * @param array<string, mixed> $raw
	 */
	public static function fromArray( array $raw ): self {
		return self::fromInput( $raw );
	}
}
