<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration;

use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationReasonCode;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\EffectiveCollectionField;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfiguration;
use CetechDeliveryEngine\Domain\Configuration\EffectiveScalarField;
use CetechDeliveryEngine\Domain\Configuration\FieldProvenance;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Pickup\PickupLocationRepositoryInterface;

/**
 * Configuration-level hard fulfilment invariants.
 *
 * Applies after EffectiveConfigurationResolver inheritance and before offer/rate eligibility.
 * Does not calculate prices, inspect destinations, write cart/order data, or mutate persisted scopes.
 */
final class HardFulfilmentConstraintService implements FulfilmentConstraintServiceInterface {

	public function __construct(
		private readonly ?DeliveryOfferRepositoryInterface $delivery_offers = null,
		private readonly ?PickupLocationRepositoryInterface $pickup_locations = null
	) {
	}

	public function apply( EffectiveConfiguration $configuration ): EffectiveConfiguration {
		$availability = $this->resolve_availability( $configuration );

		if ( null === $availability ) {
			return $configuration;
		}

		$policy = $this->policy_for( $availability );

		if ( null === $policy ) {
			return $configuration;
		}

		$scalars     = $configuration->scalars;
		$collections = $configuration->collections;
		$reasons     = $configuration->reason_codes;
		$changed     = false;

		$choice_field = $scalars[ ConfigurationFieldKey::FULFILMENT_CHOICE ] ?? null;

		if ( $choice_field instanceof EffectiveScalarField && EffectiveFieldState::Valid === $choice_field->state ) {
			$choice = (string) $choice_field->value;

			if ( ! $policy['pickup_allowed'] && FulfilmentChoice::StorePickup->value === $choice ) {
				$scalars[ ConfigurationFieldKey::FULFILMENT_CHOICE ] = new EffectiveScalarField(
					ConfigurationFieldKey::FULFILMENT_CHOICE,
					EffectiveFieldState::Invalid,
					$choice,
					new FieldProvenance( null, 'hard_constraint' ),
					[ ConfigurationReasonCode::CONSTRAINT_CHOICE_PROHIBITED ]
				);
				$reasons[] = ConfigurationReasonCode::CONSTRAINT_CHOICE_PROHIBITED;
				$changed   = true;
			}
		}

		$pickup_field = $scalars[ ConfigurationFieldKey::PICKUP_LOCATION_ID ] ?? null;
		if ( $pickup_field instanceof EffectiveScalarField && EffectiveFieldState::Valid === $pickup_field->state ) {
			$constrained = $this->constrain_pickup_location( $pickup_field, $policy['pickup_allowed'] );
			if ( $constrained !== $pickup_field ) {
				$scalars[ ConfigurationFieldKey::PICKUP_LOCATION_ID ] = $constrained;
				$reasons                                             = array_merge( $reasons, $constrained->reason_codes );
				$changed                                             = true;
			}
		}

		$offers_field = $collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ] ?? null;

		if ( $offers_field instanceof EffectiveCollectionField && EffectiveFieldState::Valid === $offers_field->state ) {
			$filtered = $this->filter_offer_ids( $offers_field->members, $policy['allowed_routes'] );

			if ( $filtered !== $offers_field->members ) {
				$collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ] = new EffectiveCollectionField(
					ConfigurationFieldKey::DELIVERY_OFFER_IDS,
					EffectiveFieldState::Valid,
					$filtered,
					new FieldProvenance( null, 'hard_constraint', null, $offers_field->provenance->contributing_mutations ),
					array_values(
						array_unique(
							array_merge(
								$offers_field->reason_codes,
								[ ConfigurationReasonCode::CONSTRAINT_ROUTE_FILTERED ]
							)
						)
					),
					$offers_field->mutation_steps
				);
				$reasons[] = ConfigurationReasonCode::CONSTRAINT_ROUTE_FILTERED;
				$changed   = true;
			}
		}

		if ( ! $changed ) {
			return $configuration;
		}

		$state = $configuration->state;

		foreach ( $scalars as $field ) {
			if ( ! $field instanceof EffectiveScalarField || EffectiveFieldState::Invalid !== $field->state ) {
				continue;
			}

			// Invalid Store Pickup location must not silently offer Pickup, but it
			// must not fail-close an otherwise valid Delivery configuration.
			if (
				ConfigurationFieldKey::PICKUP_LOCATION_ID === $field->field_key
				&& $policy['pickup_allowed']
				&& $this->has_usable_delivery_offers( $collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ] ?? null )
			) {
				continue;
			}

			$state = EffectiveFieldState::Invalid;
			break;
		}

		return new EffectiveConfiguration(
			$configuration->product_id,
			$configuration->variation_id,
			$configuration->slice_key,
			$scalars,
			$collections,
			$state,
			array_values( array_unique( $reasons ) ),
			$configuration->version
		);
	}

	/**
	 * @return array{pickup_allowed: bool, allowed_routes: list<string>}|null
	 */
	private function policy_for( string $availability ): ?array {
		return match ( $availability ) {
			FulfilmentAvailability::InternationalFulfilment->value => [
				'pickup_allowed' => false,
				'allowed_routes' => [
					DeliveryRoute::Air->value,
					DeliveryRoute::Sea->value,
				],
			],
			FulfilmentAvailability::InStore->value => [
				'pickup_allowed' => true,
				'allowed_routes' => [
					DeliveryRoute::LocalDelivery->value,
					DeliveryRoute::StorePickup->value,
				],
			],
			FulfilmentAvailability::InWarehouse->value => [
				'pickup_allowed' => false,
				'allowed_routes' => [
					DeliveryRoute::LocalDelivery->value,
				],
			],
			default => null,
		};
	}

	private function resolve_availability( EffectiveConfiguration $configuration ): ?string {
		$slice = $configuration->slice_key;

		if ( ConfigurationScope::DEFAULT_SLICE_KEY !== $slice && $this->is_availability( $slice ) ) {
			return $slice;
		}

		$field = $configuration->scalar( ConfigurationFieldKey::FULFILMENT_AVAILABILITY );

		if ( null === $field || EffectiveFieldState::Valid !== $field->state ) {
			return null;
		}

		$value = (string) $field->value;

		return $this->is_availability( $value ) ? $value : null;
	}

	private function constrain_pickup_location( EffectiveScalarField $field, bool $pickup_allowed ): EffectiveScalarField {
		$value = (int) $field->value;

		if ( ! $pickup_allowed ) {
			return new EffectiveScalarField(
				ConfigurationFieldKey::PICKUP_LOCATION_ID,
				EffectiveFieldState::Invalid,
				$value > 0 ? $value : $field->value,
				new FieldProvenance( null, 'hard_constraint' ),
				[ ConfigurationReasonCode::CONSTRAINT_CHOICE_PROHIBITED ]
			);
		}

		if ( $value <= 0 ) {
			return new EffectiveScalarField(
				ConfigurationFieldKey::PICKUP_LOCATION_ID,
				EffectiveFieldState::Invalid,
				$field->value,
				new FieldProvenance( null, 'hard_constraint' ),
				[ ConfigurationReasonCode::CONSTRAINT_PICKUP_LOCATION_INVALID ]
			);
		}

		if ( null === $this->pickup_locations ) {
			return $field;
		}

		$row = $this->pickup_locations->findById( $value );
		if ( ! is_array( $row ) || RecordStatus::Active->value !== (string) ( $row['status'] ?? '' ) ) {
			return new EffectiveScalarField(
				ConfigurationFieldKey::PICKUP_LOCATION_ID,
				EffectiveFieldState::Invalid,
				$value,
				new FieldProvenance( null, 'hard_constraint' ),
				[ ConfigurationReasonCode::CONSTRAINT_PICKUP_LOCATION_INVALID ]
			);
		}

		return $field;
	}

	private function is_availability( string $value ): bool {
		foreach ( FulfilmentAvailability::cases() as $case ) {
			if ( $case->value === $value ) {
				return true;
			}
		}

		return false;
	}

	private function has_usable_delivery_offers( mixed $offers_field ): bool {
		if ( ! $offers_field instanceof EffectiveCollectionField || EffectiveFieldState::Valid !== $offers_field->state ) {
			return false;
		}

		foreach ( $offers_field->members as $offer_id ) {
			if ( $offer_id <= 0 ) {
				continue;
			}
			if ( null === $this->delivery_offers ) {
				return true;
			}
			$row = $this->delivery_offers->findById( $offer_id );
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( DeliveryRoute::LocalDelivery->value === (string) ( $row['route'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<int>    $offer_ids
	 * @param list<string> $allowed_routes
	 *
	 * @return list<int>
	 */
	private function filter_offer_ids( array $offer_ids, array $allowed_routes ): array {
		if ( null === $this->delivery_offers || [] === $offer_ids ) {
			return $offer_ids;
		}

		$allowed = array_fill_keys( $allowed_routes, true );
		$kept    = [];

		foreach ( $offer_ids as $offer_id ) {
			$row = $this->delivery_offers->findById( $offer_id );

			if ( null === $row ) {
				// Unknown offer IDs are left for downstream active-offer checks.
				$kept[] = $offer_id;
				continue;
			}

			$route = (string) ( $row['route'] ?? '' );

			if ( isset( $allowed[ $route ] ) ) {
				$kept[] = $offer_id;
			}
		}

		return array_values( $kept );
	}
}
