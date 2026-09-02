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
		public readonly string $postcode_identity
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
			LocationNormalizer::postcode( $postcode )
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
			$this->postcode_identity
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function toDestinationArray(): array {
		return [
			'country'  => $this->country_identity,
			'state'    => $this->state_identity,
			'city'     => $this->city,
			'postcode' => $this->postcode,
		];
	}

	/**
	 * @return array<string, string>
	 */
	public function toArray(): array {
		return [
			'country'           => $this->country,
			'state'             => $this->state,
			'city'              => $this->city,
			'postcode'          => $this->postcode,
			'country_identity'  => $this->country_identity,
			'state_identity'    => $this->state_identity,
			'city_identity'     => $this->city_identity,
			'postcode_identity' => $this->postcode_identity,
		];
	}

	/**
	 * @param array<string, mixed> $raw
	 */
	public static function fromArray( array $raw ): self {
		return self::fromInput( $raw );
	}
}
