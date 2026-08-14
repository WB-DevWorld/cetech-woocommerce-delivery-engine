<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration;

use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\CarrierVisibility;
use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleMatchMode;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleType;
use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Pickup\PickupLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;
use CetechDeliveryEngine\Presentation\Admin\AdminFormHelper;
use CetechDeliveryEngine\Presentation\Admin\Validation\DeliveryOfferValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\DestinationRuleValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\DestinationZoneValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\PickupLocationValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\RateCardValidator;

/**
 * Lightweight first-setup creation of canonical Delivery Engine records.
 *
 * Creates the same entities used by the normal admin editors. Does not
 * introduce wizard-only copies.
 */
final class ContextualEntityService {

	public function __construct(
		private readonly DeliveryOfferRepositoryInterface $offers,
		private readonly DestinationZoneRepositoryInterface $zones,
		private readonly DestinationRuleRepositoryInterface $rules,
		private readonly RateCardRepositoryInterface $rates,
		private readonly PickupLocationRepositoryInterface $pickups,
		private readonly DeliveryOfferValidator $offer_validator,
		private readonly DestinationZoneValidator $zone_validator,
		private readonly DestinationRuleValidator $rule_validator,
		private readonly RateCardValidator $rate_validator,
		private readonly PickupLocationValidator $pickup_validator
	) {
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @return array{id: int, errors: list<string>}
	 */
	public function create_delivery_option( array $input ): array {
		$name        = trim( (string) ( $input['name'] ?? '' ) );
		$description = trim( (string) ( $input['description'] ?? '' ) );
		$eta         = trim( (string) ( $input['estimated_delivery'] ?? '' ) );
		$route       = sanitize_key( (string) ( $input['route'] ?? DeliveryRoute::LocalDelivery->value ) );
		$active      = ! isset( $input['active'] ) || '0' !== (string) $input['active'];

		if ( '' === $name ) {
			return [ 'id' => 0, 'errors' => [ 'Name is required.' ] ];
		}

		if ( ! $this->is_valid_route( $route ) ) {
			$route = DeliveryRoute::LocalDelivery->value;
		}

		$days = $this->parse_eta_days( $eta );
		$code = $this->unique_code( $name, fn ( string $code ) => null !== $this->offers->findByCode( $code ) );

		$form = [
			'code'                   => $code,
			'public_label'           => $name,
			'description'            => $description,
			'route'                  => $route,
			'service_level'          => $eta,
			'carrier_visibility'     => CarrierVisibility::AssignedByStore->value,
			'carrier_display_name'   => '',
			'processing_min_days'    => $days['min'],
			'processing_max_days'    => $days['max'],
			'transit_min_days'       => null,
			'transit_max_days'       => null,
			'final_mile_min_days'    => null,
			'final_mile_max_days'    => null,
			'display_priority'       => 100,
			'status'                 => $active ? RecordStatus::Active->value : RecordStatus::Inactive->value,
		];

		$errors = array_values( $this->offer_validator->validate( $form ) );
		if ( [] !== $errors ) {
			return [ 'id' => 0, 'errors' => $errors ];
		}

		$id = $this->offers->save(
			[
				'id'                     => 0,
				'internal_code'          => $code,
				'internal_name'          => $name,
				'public_label'           => $name,
				'public_description'     => $description,
				'route'                  => $route,
				'service_level'          => $eta,
				'carrier_visibility'     => CarrierVisibility::AssignedByStore->value,
				'carrier_name'           => null,
				'default_processing_min' => $days['min'],
				'default_processing_max' => $days['max'],
				'default_transit_min'    => null,
				'default_transit_max'    => null,
				'default_final_mile_min' => null,
				'default_final_mile_max' => null,
				'display_priority'       => 100,
				'status'                 => $form['status'],
			]
		);

		if ( $id <= 0 ) {
			return [ 'id' => 0, 'errors' => [ 'Unable to create the delivery option.' ] ];
		}

		return [ 'id' => $id, 'errors' => [] ];
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @return array{id: int, errors: list<string>}
	 */
	public function create_delivery_area( array $input ): array {
		$name    = trim( (string) ( $input['name'] ?? '' ) );
		$country = strtoupper( trim( (string) ( $input['country'] ?? $this->store_country() ) ) );
		$city    = trim( (string) ( $input['city'] ?? '' ) );

		if ( '' === $name ) {
			return [ 'id' => 0, 'errors' => [ 'Area name is required.' ] ];
		}

		if ( '' === $country || ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
			$country = $this->store_country();
		}

		$code = $this->unique_code( $name, fn ( string $code ) => null !== $this->zones->findByCode( $code ) );

		$form = [
			'code'         => $code,
			'name'         => $name,
			'public_label' => $name,
			'priority'     => 100,
			'status'       => RecordStatus::Active->value,
		];

		$errors = array_values( $this->zone_validator->validate( $form ) );
		$rule_rows = [
			[
				'rule_type'  => DestinationRuleType::Country->value,
				'rule_value' => $country,
				'match_mode' => DestinationRuleMatchMode::Exact->value,
				'priority'   => 10,
			],
		];
		if ( '' !== $city ) {
			$rule_rows[] = [
				'rule_type'  => DestinationRuleType::City->value,
				'rule_value' => $city,
				'match_mode' => DestinationRuleMatchMode::Exact->value,
				'priority'   => 20,
			];
		}

		$rule_result = $this->rule_validator->validate_and_normalize( $rule_rows );
		$errors      = array_merge( $errors, array_values( $rule_result['errors'] ) );
		if ( [] !== $errors ) {
			return [ 'id' => 0, 'errors' => $errors ];
		}

		$id = $this->zones->save(
			[
				'id'               => 0,
				'internal_code'    => $code,
				'internal_name'    => $name,
				'public_label'     => $name,
				'is_fallback'      => ! empty( $input['is_fallback'] ),
				'remote_area_flag' => false,
				'priority'         => 100,
				'status'           => RecordStatus::Active->value,
			]
		);

		if ( $id <= 0 ) {
			return [ 'id' => 0, 'errors' => [ 'Unable to create the delivery area.' ] ];
		}

		if ( ! $this->rules->replaceForZone( $id, $rule_result['rules'] ) ) {
			return [ 'id' => $id, 'errors' => [ 'Delivery area saved, but location matching could not be stored.' ] ];
		}

		return [ 'id' => $id, 'errors' => [] ];
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @return array{id: int, errors: list<string>}
	 */
	public function create_delivery_charge( array $input ): array {
		$name     = trim( (string) ( $input['name'] ?? '' ) );
		$style    = sanitize_key( (string) ( $input['charge_style'] ?? 'flat' ) );
		$amount   = trim( (string) ( $input['amount'] ?? '' ) );
		$offer_id = absint( $input['delivery_option_id'] ?? $input['delivery_offer_id'] ?? 0 );
		$zone_id  = absint( $input['delivery_area_id'] ?? $input['destination_zone_id'] ?? 0 );

		if ( '' === $name ) {
			return [ 'id' => 0, 'errors' => [ 'Charge name is required.' ] ];
		}

		if ( $offer_id <= 0 ) {
			return [ 'id' => 0, 'errors' => [ 'Choose a delivery option for this charge.' ] ];
		}

		if ( $zone_id <= 0 ) {
			$areas = $this->zones->list( [ 'limit' => 1 ] );
			if ( isset( $areas[0]['id'] ) ) {
				$zone_id = (int) $areas[0]['id'];
			} else {
				$created = $this->create_delivery_area(
					[
						'name'    => 'Default delivery area',
						'country' => $this->store_country(),
					]
				);
				if ( $created['id'] <= 0 ) {
					return [
						'id'     => 0,
						'errors' => array_merge(
							[ 'Create a delivery area before adding a charge.' ],
							$created['errors']
						),
					];
				}
				$zone_id = $created['id'];
			}
		}

		$charge_type = match ( $style ) {
			'per_item' => RateCardChargeType::FixedPerItem->value,
			default => RateCardChargeType::FixedPerShipment->value,
		};

		if ( '' === $amount || ! is_numeric( $amount ) ) {
			return [ 'id' => 0, 'errors' => [ 'Enter a delivery amount.' ] ];
		}

		$code = $this->unique_code( $name, fn ( string $code ) => null !== $this->rates->findByCode( $code ) );

		$form = [
			'code'                => $code,
			'delivery_offer_id'   => $offer_id,
			'destination_zone_id' => $zone_id,
			'charge_type'         => $charge_type,
			'base_amount'         => $amount,
			'currency_code'       => $this->store_currency(),
			'priority'            => 100,
			'status'              => RecordStatus::Active->value,
		];

		$errors = array_values( $this->rate_validator->validate( $form ) );
		if ( [] !== $errors ) {
			return [ 'id' => 0, 'errors' => $errors ];
		}

		$id = $this->rates->save(
			[
				'id'                   => 0,
				'internal_code'        => $code,
				'delivery_offer_id'    => $offer_id,
				'destination_zone_id'  => $zone_id,
				'logistics_profile_id' => null,
				'supplier_id'          => null,
				'origin_id'            => null,
				'charge_type'          => $charge_type,
				'base_amount'          => $amount,
				'base_currency'        => $this->store_currency(),
				'priority'             => 100,
				'effective_from'       => '',
				'effective_to'         => '',
				'status'               => RecordStatus::Active->value,
			]
		);

		if ( $id <= 0 ) {
			return [ 'id' => 0, 'errors' => [ 'Unable to create the delivery charge.' ] ];
		}

		return [ 'id' => $id, 'errors' => [] ];
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @return array{id: int, errors: list<string>}
	 */
	public function create_pickup_location( array $input ): array {
		$name    = trim( (string) ( $input['name'] ?? '' ) );
		$address = trim( (string) ( $input['address'] ?? '' ) );
		$city    = trim( (string) ( $input['city'] ?? '' ) );
		$ready   = trim( (string) ( $input['ready_time'] ?? '' ) );

		if ( '' === $name ) {
			return [ 'id' => 0, 'errors' => [ 'Location name is required.' ] ];
		}

		$code = $this->unique_code( $name, fn ( string $code ) => null !== $this->pickups->findByCode( $code ) );

		$form = [
			'code'                       => $code,
			'location_name'              => $name,
			'address_line_1'             => $address,
			'city'                       => $city,
			'country_code'               => $this->store_country(),
			'public_pickup_instructions' => $ready,
			'status'                     => RecordStatus::Active->value,
		];

		$errors = array_values( $this->pickup_validator->validate( $form ) );
		if ( [] !== $errors ) {
			return [ 'id' => 0, 'errors' => $errors ];
		}

		$id = $this->pickups->save(
			[
				'id'                         => 0,
				'internal_code'              => $code,
				'location_name'              => $name,
				'public_address'             => $this->pickup_validator->encode_public_address( $form ),
				'public_opening_hours'       => '',
				'public_pickup_instructions' => $ready,
				'contact_phone'              => '',
				'contact_email'              => '',
				'status'                     => RecordStatus::Active->value,
			]
		);

		if ( $id <= 0 ) {
			return [ 'id' => 0, 'errors' => [ 'Unable to create the pickup location.' ] ];
		}

		return [ 'id' => $id, 'errors' => [] ];
	}

	public function store_country(): string {
		if ( function_exists( 'wc_get_base_location' ) ) {
			$location = wc_get_base_location();
			$country  = strtoupper( (string) ( $location['country'] ?? '' ) );
			if ( preg_match( '/^[A-Z]{2}$/', $country ) ) {
				return $country;
			}
		}

		return 'GH';
	}

	public function store_currency(): string {
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$currency = strtoupper( (string) get_woocommerce_currency() );
			if ( '' !== $currency ) {
				return $currency;
			}
		}

		return 'GHS';
	}

	public function store_country_label(): string {
		$country = $this->store_country();
		if ( function_exists( 'WC' ) && isset( WC()->countries ) ) {
			$countries = WC()->countries->get_countries();
			if ( isset( $countries[ $country ] ) ) {
				return (string) $countries[ $country ];
			}
		}

		return $country;
	}

	/**
	 * @param callable(string): bool $exists
	 */
	private function unique_code( string $name, callable $exists ): string {
		$base = AdminFormHelper::sanitize_code( strtolower( $name ) );
		if ( '' === $base ) {
			$base = 'item';
		}
		if ( strlen( $base ) > 40 ) {
			$base = substr( $base, 0, 40 );
		}

		$code  = $base;
		$index = 2;
		while ( $exists( $code ) ) {
			$suffix = '-' . $index;
			$code   = substr( $base, 0, max( 1, 48 - strlen( $suffix ) ) ) . $suffix;
			++$index;
			if ( $index > 99 ) {
				$code = $base . '-' . wp_generate_password( 4, false, false );
				break;
			}
		}

		return $code;
	}

	/**
	 * @return array{min: int|null, max: int|null}
	 */
	private function parse_eta_days( string $eta ): array {
		if ( preg_match( '/(\d+)\s*[–\-]\s*(\d+)/u', $eta, $matches ) ) {
			return [
				'min' => (int) $matches[1],
				'max' => (int) $matches[2],
			];
		}

		if ( preg_match( '/(\d+)/', $eta, $matches ) ) {
			$days = (int) $matches[1];

			return [ 'min' => $days, 'max' => $days ];
		}

		return [ 'min' => null, 'max' => null ];
	}

	private function is_valid_route( string $route ): bool {
		foreach ( DeliveryRoute::cases() as $case ) {
			if ( $case->value === $route ) {
				return true;
			}
		}

		return false;
	}
}
