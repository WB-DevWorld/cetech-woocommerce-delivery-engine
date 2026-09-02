<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\CustomerContext;

use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;

/**
 * Customer-owned cart-line context. Never includes administrator-derived
 * configuration (labels, ETA, fingerprints, hashes, rule_id).
 */
final class CustomerCartContext {

	public const CONTRACT_VERSION = 1;

	public const CART_KEY = 'cetech_de_customer_context';

	private function __construct(
		public readonly int $contract_version,
		public readonly string $fulfilment_choice,
		public readonly ?int $delivery_offer_id,
		public readonly ?int $pickup_location_id,
		public readonly ?MatchingLocation $matching_location,
		public readonly ?DeliveryAddress $delivery_address,
		public readonly ?string $matching_identity,
		public readonly ?string $delivery_location_identity
	) {
	}

	public static function delivery(
		?int $delivery_offer_id,
		?MatchingLocation $matching = null,
		?DeliveryAddress $address = null
	): self {
		$matching = $address?->matching ?? $matching;
		$complete = $address?->isComplete() ? $address : null;

		return new self(
			self::CONTRACT_VERSION,
			FulfilmentChoice::Delivery->value,
			( $delivery_offer_id ?? 0 ) > 0 ? $delivery_offer_id : null,
			null,
			$matching,
			$complete,
			$matching?->isPresent() ? $matching->identity() : null,
			$complete instanceof DeliveryAddress ? $complete->identity() : null
		);
	}

	public static function pickup( ?int $pickup_location_id ): self {
		$id = ( $pickup_location_id ?? 0 ) > 0 ? $pickup_location_id : null;

		return new self(
			self::CONTRACT_VERSION,
			FulfilmentChoice::StorePickup->value,
			null,
			$id,
			null,
			null,
			null,
			null
		);
	}

	public function isPickup(): bool {
		return FulfilmentChoice::StorePickup->value === $this->fulfilment_choice;
	}

	public function isDelivery(): bool {
		return FulfilmentChoice::Delivery->value === $this->fulfilment_choice;
	}

	public function hasCompleteDeliveryAddress(): bool {
		return $this->isDelivery()
			&& $this->delivery_address instanceof DeliveryAddress
			&& $this->delivery_address->isComplete()
			&& is_string( $this->delivery_location_identity )
			&& '' !== $this->delivery_location_identity;
	}

	public function hasMatchingLocation(): bool {
		return $this->matching_location instanceof MatchingLocation
			&& $this->matching_location->isPresent()
			&& is_string( $this->matching_identity )
			&& '' !== $this->matching_identity;
	}

	public function isPickupComplete(): bool {
		return $this->isPickup() && null !== $this->pickup_location_id && $this->pickup_location_id > 0;
	}

	/**
	 * Cart-line location identity: hashes only. Never raw address JSON.
	 */
	public function cartLocationIdentitySegment(): string {
		if ( $this->isPickup() ) {
			return $this->isPickupComplete() ? 'p' . (string) $this->pickup_location_id : 'pickup_incomplete';
		}

		if ( $this->hasCompleteDeliveryAddress() && is_string( $this->delivery_location_identity ) ) {
			return 'd' . $this->delivery_location_identity;
		}

		if ( $this->hasMatchingLocation() && is_string( $this->matching_identity ) ) {
			return 'm' . $this->matching_identity;
		}

		return '';
	}

	public function withRecipient( RecipientContact $recipient ): self {
		if ( ! $this->delivery_address instanceof DeliveryAddress ) {
			return $this;
		}

		$raw                 = $this->delivery_address->toArray();
		$raw['first_name']   = $recipient->first_name;
		$raw['last_name']    = $recipient->last_name;
		$raw['company']      = $recipient->company;
		$raw['phone']        = $recipient->phone;

		return self::delivery( $this->delivery_offer_id, $this->matching_location, DeliveryAddress::fromInput( $raw ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'contract_version'            => $this->contract_version,
			'fulfilment_choice'           => $this->fulfilment_choice,
			'delivery_offer_id'           => $this->delivery_offer_id,
			'pickup_location_id'          => $this->pickup_location_id,
			'matching_location'           => $this->matching_location?->toArray(),
			'delivery_address'             => $this->delivery_address?->toArray(),
			'matching_identity'           => $this->matching_identity,
			'delivery_location_identity'   => $this->delivery_location_identity,
		];
	}

	/**
	 * @param array<string, mixed> $raw
	 */
	public static function fromArray( array $raw ): ?self {
		$choice = sanitize_key( (string) ( $raw['fulfilment_choice'] ?? '' ) );

		if ( FulfilmentChoice::StorePickup->value === $choice ) {
			$id = isset( $raw['pickup_location_id'] ) ? (int) $raw['pickup_location_id'] : 0;

			return self::pickup( $id > 0 ? $id : null );
		}

		if ( FulfilmentChoice::Delivery->value !== $choice ) {
			return null;
		}

		$offer = isset( $raw['delivery_offer_id'] ) ? (int) $raw['delivery_offer_id'] : 0;
		$address_raw = $raw['delivery_address'] ?? null;
		$match_raw   = $raw['matching_location'] ?? null;

		$address  = is_array( $address_raw ) ? DeliveryAddress::fromArray( $address_raw ) : null;
		$matching = is_array( $match_raw ) ? MatchingLocation::fromArray( $match_raw ) : $address?->matching;

		return self::delivery( $offer > 0 ? $offer : null, $matching, $address );
	}

	/**
	 * @param array<string, mixed> $cart_item
	 */
	public static function fromCartItem( array $cart_item ): ?self {
		$raw = $cart_item[ self::CART_KEY ] ?? null;

		if ( is_array( $raw ) ) {
			return self::fromArray( $raw );
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $cart_item
	 *
	 * @return array<string, mixed>
	 */
	public function applyToCartItem( array $cart_item ): array {
		$cart_item[ self::CART_KEY ] = $this->toArray();

		return $cart_item;
	}

	public function equals( self $other ): bool {
		return $this->toArray() === $other->toArray();
	}
}
