<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\CustomerContext;

/**
 * Complete physical delivery address plus separately stored recipient/contact.
 *
 * Delivery-location identity uses country, state, city, postcode, address_1,
 * address_2 only. Recipient fields never enter that identity.
 */
final class DeliveryAddress {

	private function __construct(
		public readonly MatchingLocation $matching,
		public readonly string $address_1,
		public readonly string $address_2,
		public readonly string $address_1_identity,
		public readonly string $address_2_identity,
		public readonly RecipientContact $recipient
	) {
	}

	/**
	 * @param array<string, mixed> $raw
	 */
	public static function fromInput( array $raw ): self {
		$matching   = MatchingLocation::fromInput( $raw );
		$address_1  = (string) ( $raw['address_1'] ?? $raw['address'] ?? '' );
		$address_2  = (string) ( $raw['address_2'] ?? '' );
		$recipient  = RecipientContact::fromInput( $raw );

		return new self(
			$matching,
			LocationNormalizer::addressDisplay( $address_1 ),
			LocationNormalizer::addressDisplay( $address_2 ),
			LocationNormalizer::addressIdentity( $address_1 ),
			LocationNormalizer::addressIdentity( $address_2 ),
			$recipient
		);
	}

	public function isComplete(): bool {
		return $this->matching->isPresent() && '' !== $this->address_1_identity;
	}

	public function identity(): string {
		return LocationIdentity::deliveryLocation(
			$this->matching->country_identity,
			$this->matching->state_identity,
			$this->matching->city_identity,
			$this->matching->postcode_identity,
			$this->address_1_identity,
			$this->address_2_identity
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function toWcPackageDestination(): array {
		return $this->matching->toWcPackageDestination( $this->address_1, $this->address_2 );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array_merge(
			$this->matching->toArray(),
			[
				'address_1'          => $this->address_1,
				'address_2'          => $this->address_2,
				'address_1_identity' => $this->address_1_identity,
				'address_2_identity' => $this->address_2_identity,
				'recipient'          => $this->recipient->toArray(),
			]
		);
	}

	/**
	 * @param array<string, mixed> $raw
	 */
	public static function fromArray( array $raw ): self {
		$nested = is_array( $raw['recipient'] ?? null ) ? $raw['recipient'] : [];

		return self::fromInput( array_merge( $raw, $nested ) );
	}
}
