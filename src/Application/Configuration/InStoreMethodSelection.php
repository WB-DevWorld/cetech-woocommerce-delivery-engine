<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration;

use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Pickup\PickupLocationRepositoryInterface;

/**
 * In Store availability (Delivery / Store Pickup) plus the customer default.
 *
 * Availability is not a mutually exclusive lock. fulfilment_choice remains
 * the default/preselected customer choice among enabled methods.
 */
final class InStoreMethodSelection {

	public const METHOD_DELIVERY = 'delivery';

	public const METHOD_STORE_PICKUP = 'store_pickup';

	/**
	 * @param list<int> $delivery_offer_ids
	 */
	public function __construct(
		public readonly bool $delivery_enabled,
		public readonly bool $pickup_enabled,
		public readonly string $default_choice,
		public readonly array $delivery_offer_ids,
		public readonly int $pickup_location_id
	) {
	}

	/**
	 * @param list<string> $available_methods
	 * @param list<int|string> $offer_ids
	 */
	public static function from_posted(
		array $available_methods,
		string $default_choice,
		array $offer_ids,
		int $pickup_location_id,
		?DeliveryOfferRepositoryInterface $offers = null
	): self {
		$methods = [];
		foreach ( $available_methods as $method ) {
			$methods[ sanitize_key( (string) $method ) ] = true;
		}

		$local_ids = self::local_delivery_offer_ids( $offer_ids, $offers );
		$delivery  = isset( $methods[ self::METHOD_DELIVERY ] ) || isset( $methods[ FulfilmentChoice::Delivery->value ] );
		$pickup    = isset( $methods[ self::METHOD_STORE_PICKUP ] ) || isset( $methods[ FulfilmentChoice::StorePickup->value ] );

		if ( ! $delivery && ! $pickup ) {
			$delivery = [] !== $local_ids;
			$pickup   = $pickup_location_id > 0;
		}

		if ( $delivery && [] === $local_ids && [] !== $offer_ids && null === $offers ) {
			$local_ids = self::positive_ids( $offer_ids );
		}

		$choice = FulfilmentChoice::StorePickup->value === sanitize_key( $default_choice )
			? FulfilmentChoice::StorePickup->value
			: FulfilmentChoice::Delivery->value;

		if ( $delivery && ! $pickup ) {
			$choice = FulfilmentChoice::Delivery->value;
		} elseif ( $pickup && ! $delivery ) {
			$choice = FulfilmentChoice::StorePickup->value;
		} elseif ( FulfilmentChoice::StorePickup->value === $choice && ! $pickup ) {
			$choice = FulfilmentChoice::Delivery->value;
		} elseif ( FulfilmentChoice::Delivery->value === $choice && ! $delivery && $pickup ) {
			$choice = FulfilmentChoice::StorePickup->value;
		}

		return new self(
			$delivery,
			$pickup,
			$choice,
			$delivery ? $local_ids : [],
			$pickup ? max( 0, $pickup_location_id ) : 0
		);
	}

	/**
	 * @return list<string>
	 */
	public function validate( ?PickupLocationRepositoryInterface $pickups = null ): array {
		$errors = [];

		if ( ! $this->delivery_enabled && ! $this->pickup_enabled ) {
			$errors[] = __( 'Enable Delivery, Store Pickup, or both.', 'cetech-woocommerce-delivery-engine' );
		}

		if ( $this->delivery_enabled && [] === $this->delivery_offer_ids ) {
			$errors[] = __( 'Select at least one Delivery Option before continuing.', 'cetech-woocommerce-delivery-engine' );
		}

		if ( $this->pickup_enabled ) {
			if ( $this->pickup_location_id <= 0 ) {
				$errors[] = __( 'Store Pickup is enabled. Select a valid active Pickup Location.', 'cetech-woocommerce-delivery-engine' );
			} elseif ( null !== $pickups ) {
				$row = $pickups->findById( $this->pickup_location_id );
				if ( ! is_array( $row ) || RecordStatus::Active->value !== (string) ( $row['status'] ?? '' ) ) {
					$errors[] = __( 'Store Pickup is enabled. Select a valid active Pickup Location.', 'cetech-woocommerce-delivery-engine' );
				}
			}
		}

		if ( FulfilmentChoice::StorePickup->value === $this->default_choice && ! $this->pickup_enabled ) {
			$errors[] = __( 'Default customer choice must be one of the enabled methods.', 'cetech-woocommerce-delivery-engine' );
		}

		if ( FulfilmentChoice::Delivery->value === $this->default_choice && ! $this->delivery_enabled ) {
			$errors[] = __( 'Default customer choice must be one of the enabled methods.', 'cetech-woocommerce-delivery-engine' );
		}

		return array_values( array_unique( $errors ) );
	}

	/**
	 * @return array<string, array{mode: string, value?: mixed, members?: list<int>}>
	 */
	public function field_payloads(): array {
		$fields = [
			ConfigurationFieldKey::FULFILMENT_CHOICE => [
				'mode'  => 'override',
				'value' => $this->default_choice,
			],
			ConfigurationFieldKey::DELIVERY_OFFER_IDS => [
				'mode'    => 'replace',
				'members' => $this->delivery_offer_ids,
			],
		];

		if ( $this->pickup_enabled && $this->pickup_location_id > 0 ) {
			$fields[ ConfigurationFieldKey::PICKUP_LOCATION_ID ] = [
				'mode'  => 'override',
				'value' => $this->pickup_location_id,
			];
		} else {
			$fields[ ConfigurationFieldKey::PICKUP_LOCATION_ID ] = [
				'mode' => 'disable',
			];
		}

		return $fields;
	}

	/**
	 * @param list<int|string> $offer_ids
	 *
	 * @return list<int>
	 */
	public static function local_delivery_offer_ids( array $offer_ids, ?DeliveryOfferRepositoryInterface $offers ): array {
		$ids = self::positive_ids( $offer_ids );
		if ( null === $offers ) {
			return $ids;
		}

		$local = [];
		foreach ( $ids as $id ) {
			$row = $offers->findById( $id );
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( DeliveryRoute::StorePickup->value === (string) ( $row['route'] ?? '' ) ) {
				continue;
			}
			$local[] = $id;
		}

		return array_values( array_unique( $local ) );
	}

	/**
	 * @param list<int|string> $offer_ids
	 *
	 * @return list<int>
	 */
	private static function positive_ids( array $offer_ids ): array {
		$ids = [];
		foreach ( $offer_ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}
}
