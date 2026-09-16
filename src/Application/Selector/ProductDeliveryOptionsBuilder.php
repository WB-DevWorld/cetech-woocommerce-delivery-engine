<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Selector;

use CetechDeliveryEngine\Application\Pickup\PickupLocationAddressFormatter;
use CetechDeliveryEngine\Application\ProductRule\ProductRuleResolutionResult;
use CetechDeliveryEngine\Application\ProductRule\ResolvedProductDeliveryRule;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Pickup\PickupLocationRepositoryInterface;

/**
 * Builds customer-safe delivery options from resolver output and active delivery offers.
 *
 * Display-only; does not persist selections or calculate prices.
 * Fulfilment choice on the resolved rule is the default preselection, not a path lock.
 */
final class ProductDeliveryOptionsBuilder {

	public function __construct(
		private DeliveryOfferRepositoryInterface $delivery_offer_repository,
		private ?PickupLocationRepositoryInterface $pickup_locations = null
	) {
	}

	/**
	 * @return list<ProductDeliveryOption>
	 */
	public function buildFromResolution( ProductRuleResolutionResult $result ): array {
		$options          = [];
		$preferred_choice = FulfilmentChoice::Delivery->value;

		foreach ( $result->chosen_rules as $availability => $rule ) {
			if ( ! $rule instanceof ResolvedProductDeliveryRule ) {
				continue;
			}

			$availability_slug  = (string) $availability;
			$availability_label = $this->availability_label( $availability_slug );

			if ( null === $availability_label ) {
				continue;
			}

			$choice_slug  = (string) $rule->fulfilment_choice;
			$choice_label = $this->choice_label( $choice_slug );

			if ( null === $choice_label ) {
				continue;
			}

			$split            = $this->split_offer_ids( $rule->delivery_offer_ids );
			$delivery_options = $this->delivery_offer_options(
				$availability_slug,
				$availability_label,
				$split['delivery']
			);
			$pickup_location  = $this->resolve_pickup_location( $rule );
			$emit_pickup      = $this->should_emit_pickup(
				$availability_slug,
				$pickup_location,
				$split['pickup']
			);

			if ( [] !== $delivery_options ) {
				foreach ( $delivery_options as $option ) {
					$options[] = $option;
				}
			} elseif ( ! $emit_pickup && FulfilmentChoice::Delivery->value === $choice_slug ) {
				$options[] = $this->unavailable_delivery_option(
					$availability_slug,
					$availability_label
				);
			}

			if ( $emit_pickup ) {
				$locations = $this->eligible_pickup_locations( $rule, $split['pickup'] );
				$count     = count( $locations );
				foreach ( $locations as $location ) {
					$suffix = 1 === $count
						? 'pickup'
						: 'p' . (string) (int) ( $location['id'] ?? 0 );
					$options[] = $this->store_pickup_option( $availability_slug, $availability_label, $location, $suffix );
				}
			}

			if (
				FulfilmentAvailability::InStore->value === $availability_slug
				&& FulfilmentChoice::StorePickup->value === $choice_slug
				&& $emit_pickup
			) {
				$preferred_choice = FulfilmentChoice::StorePickup->value;
			}
		}

		return $this->mark_default( $options, $preferred_choice );
	}

	/**
	 * @param list<ProductDeliveryOption> $options
	 *
	 * @return list<ProductDeliveryOption>
	 */
	public function mark_default( array $options, string $preferred_choice ): array {
		$key = self::defaultDisplayKey( $options, $preferred_choice );

		if ( '' === $key ) {
			return array_values( $options );
		}

		$marked = [];

		foreach ( $options as $option ) {
			$marked[] = $option->withDefault( $option->display_key === $key );
		}

		return $marked;
	}

	/**
	 * @param list<ProductDeliveryOption> $options
	 */
	public static function defaultDisplayKey( array $options, ?string $preferred_choice = null ): string {
		$available = array_values(
			array_filter(
				$options,
				static fn ( ProductDeliveryOption $option ): bool => $option->is_available
			)
		);

		if ( [] === $available ) {
			return '';
		}

		if ( 1 === count( $available ) ) {
			return $available[0]->display_key;
		}

		$preferred = $preferred_choice;
		$has_delivery = false;
		$has_pickup   = false;

		foreach ( $available as $option ) {
			if ( FulfilmentChoice::StorePickup->value === $option->fulfilment_choice ) {
				$has_pickup = true;
			} else {
				$has_delivery = true;
			}
		}

		if ( null === $preferred || '' === $preferred ) {
			$preferred = $has_delivery
				? FulfilmentChoice::Delivery->value
				: FulfilmentChoice::StorePickup->value;
		}

		if ( FulfilmentChoice::StorePickup->value === $preferred && ! $has_pickup ) {
			$preferred = FulfilmentChoice::Delivery->value;
		}

		if ( FulfilmentChoice::Delivery->value === $preferred && ! $has_delivery ) {
			$preferred = FulfilmentChoice::StorePickup->value;
		}

		$of_choice = array_values(
			array_filter(
				$available,
				static fn ( ProductDeliveryOption $option ): bool => $option->fulfilment_choice === $preferred
			)
		);

		if ( 1 === count( $of_choice ) ) {
			return $of_choice[0]->display_key;
		}

		if ( FulfilmentChoice::StorePickup->value === $preferred && [] !== $of_choice ) {
			return $of_choice[0]->display_key;
		}

		return '';
	}

	/**
	 * @param list<ProductDeliveryOption> $options
	 *
	 * @return array{delivery: list<ProductDeliveryOption>, store_pickup: list<ProductDeliveryOption>}
	 */
	public static function groupByChoice( array $options ): array {
		$groups = [
			FulfilmentChoice::Delivery->value    => [],
			FulfilmentChoice::StorePickup->value => [],
		];

		foreach ( $options as $option ) {
			if ( FulfilmentChoice::StorePickup->value === $option->fulfilment_choice ) {
				$groups[ FulfilmentChoice::StorePickup->value ][] = $option;
			} else {
				$groups[ FulfilmentChoice::Delivery->value ][] = $option;
			}
		}

		return $groups;
	}

	/**
	 * @param list<int> $offer_ids
	 *
	 * @return array{delivery: list<int>, pickup: list<int>}
	 */
	private function split_offer_ids( array $offer_ids ): array {
		$delivery = [];
		$pickup   = [];

		foreach ( $offer_ids as $offer_id ) {
			$row = $this->delivery_offer_repository->findById( (int) $offer_id );

			if ( null === $row ) {
				continue;
			}

			if ( RecordStatus::Active->value !== (string) ( $row['status'] ?? '' ) ) {
				continue;
			}

			$route = (string) ( $row['route'] ?? '' );

			if ( DeliveryRoute::StorePickup->value === $route ) {
				$pickup[] = (int) $offer_id;
				continue;
			}

			$delivery[] = (int) $offer_id;
		}

		return [
			'delivery' => $delivery,
			'pickup'   => $pickup,
		];
	}

	/**
	 * @param array<string, mixed>|null $pickup_location
	 * @param list<int>                 $pickup_offer_ids
	 */
	private function should_emit_pickup( string $availability, ?array $pickup_location, array $pickup_offer_ids ): bool {
		if ( FulfilmentAvailability::InStore->value !== $availability ) {
			return false;
		}

		if ( null !== $pickup_location ) {
			return true;
		}

		return [] !== $pickup_offer_ids;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function resolve_pickup_location( ResolvedProductDeliveryRule $rule ): ?array {
		$id = $rule->pickup_location_id;
		if ( null === $id || $id <= 0 || null === $this->pickup_locations ) {
			return null;
		}

		$row = $this->pickup_locations->findById( $id );
		if ( ! is_array( $row ) ) {
			return null;
		}

		if ( RecordStatus::Active->value !== (string) ( $row['status'] ?? '' ) ) {
			return null;
		}

		return $row;
	}

	/**
	 * @param array<string, mixed> $location
	 */
	/**
	 * @param array<string, mixed> $location
	 */
	private function store_pickup_option(
		string $availability_slug,
		string $availability_label,
		array $location,
		string $suffix = 'pickup'
	): ProductDeliveryOption {
		$choice_slug  = FulfilmentChoice::StorePickup->value;
		$choice_label = $this->choice_label( $choice_slug ) ?? __( 'Store pickup', 'cetech-woocommerce-delivery-engine' );
		$label        = __( 'Store pickup', 'cetech-woocommerce-delivery-engine' );
		$estimate     = null;
		$instructions = null;
		$address      = null;
		$location_label = null;

		if ( is_array( $location ) ) {
			$name = trim( (string) ( $location['location_name'] ?? $location['name'] ?? '' ) );

			if ( '' !== $name ) {
				$location_label = $name;
			}

			$address_text = PickupLocationAddressFormatter::format(
				(string) ( $location['public_address'] ?? '' )
			);

			if ( '' !== $address_text ) {
				$address = $address_text;
			}

			$instruction_text = trim( (string) ( $location['public_pickup_instructions'] ?? '' ) );

			if ( '' !== $instruction_text ) {
				$instructions = $instruction_text;
			}

			$readiness = trim( (string) ( $location['readiness_estimate'] ?? '' ) );

			if ( '' !== $readiness ) {
				$estimate = $readiness;
			}
		}

		if ( is_string( $location_label ) && '' !== $location_label ) {
			$label = $location_label;
		}

		$location_id = (int) ( $location['id'] ?? 0 );

		return new ProductDeliveryOption(
			$this->display_key( $availability_slug, $choice_slug, $suffix ),
			$availability_slug,
			$availability_label,
			$choice_slug,
			$choice_label,
			null,
			$label,
			$instructions,
			$estimate,
			true,
			null,
			ProductDeliveryOption::CONTRACT_VERSION,
			false,
			$location_label,
			$address,
			$instructions,
			$location_id > 0 ? $location_id : null
		);
	}

	/**
	 * @param list<int> $pickup_offer_ids
	 *
	 * @return list<array<string, mixed>>
	 */
	private function eligible_pickup_locations( ResolvedProductDeliveryRule $rule, array $pickup_offer_ids ): array {
		$specific = $this->resolve_pickup_location( $rule );
		if ( is_array( $specific ) ) {
			return [ $specific ];
		}

		if ( null === $this->pickup_locations ) {
			return [];
		}

		$rows   = $this->pickup_locations->list( [ 'status' => RecordStatus::Active->value, 'limit' => 50 ] );
		$active = [];

		foreach ( $rows as $row ) {
			if ( RecordStatus::Active->value !== (string) ( $row['status'] ?? '' ) ) {
				continue;
			}

			$id = (int) ( $row['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}

			$active[] = $row;
		}

		if ( [] !== $active ) {
			return $active;
		}

		if ( [] !== $pickup_offer_ids ) {
			$fallback = $this->default_pickup_location();

			return is_array( $fallback ) ? [ $fallback ] : [];
		}

		return [];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function default_pickup_location(): ?array {
		if ( null === $this->pickup_locations ) {
			return null;
		}

		$rows = $this->pickup_locations->list( [ 'status' => RecordStatus::Active->value, 'limit' => 20 ] );

		foreach ( $rows as $row ) {
			if ( RecordStatus::Active->value !== (string) ( $row['status'] ?? '' ) ) {
				continue;
			}

			return $row;
		}

		return null;
	}

	/**
	 * @param list<int> $offer_ids
	 *
	 * @return list<ProductDeliveryOption>
	 */
	private function delivery_offer_options(
		string $availability_slug,
		string $availability_label,
		array $offer_ids
	): array {
		$options      = [];
		$choice_slug  = FulfilmentChoice::Delivery->value;
		$choice_label = $this->choice_label( $choice_slug ) ?? __( 'Delivery', 'cetech-woocommerce-delivery-engine' );

		foreach ( $offer_ids as $offer_id ) {
			$row = $this->delivery_offer_repository->findById( (int) $offer_id );

			if ( null === $row ) {
				continue;
			}

			if ( RecordStatus::Active->value !== (string) ( $row['status'] ?? '' ) ) {
				continue;
			}

			if ( DeliveryRoute::StorePickup->value === (string) ( $row['route'] ?? '' ) ) {
				continue;
			}

			$public_label = trim( (string) ( $row['public_label'] ?? '' ) );

			if ( '' === $public_label ) {
				$public_label = __( 'Delivery option', 'cetech-woocommerce-delivery-engine' );
			}

			$public_description = trim( (string) ( $row['public_description'] ?? '' ) );

			$options[] = new ProductDeliveryOption(
				$this->display_key( $availability_slug, $choice_slug, (string) $offer_id ),
				$availability_slug,
				$availability_label,
				$choice_slug,
				$choice_label,
				(int) $offer_id,
				$public_label,
				'' !== $public_description ? $public_description : null,
				$this->format_estimate_text( $row ),
				true,
				null
			);
		}

		return $options;
	}

	private function unavailable_delivery_option(
		string $availability_slug,
		string $availability_label
	): ProductDeliveryOption {
		$choice_slug  = FulfilmentChoice::Delivery->value;
		$choice_label = $this->choice_label( $choice_slug ) ?? __( 'Delivery', 'cetech-woocommerce-delivery-engine' );

		return new ProductDeliveryOption(
			$this->display_key( $availability_slug, $choice_slug, 'unavailable' ),
			$availability_slug,
			$availability_label,
			$choice_slug,
			$choice_label,
			null,
			__( 'Delivery unavailable', 'cetech-woocommerce-delivery-engine' ),
			__(
				'Delivery options for this product are not currently available.',
				'cetech-woocommerce-delivery-engine'
			),
			null,
			false,
			__(
				'No active delivery offers are configured for this product.',
				'cetech-woocommerce-delivery-engine'
			)
		);
	}

	private function display_key( string $availability, string $choice, string $suffix ): string {
		return self::formatDisplayKey( $availability, $choice, $suffix );
	}

	/**
	 * Builds a deterministic customer-safe display key: {availability}:{choice}:{suffix}.
	 *
	 * Suffix is an offer ID, "pickup", or "unavailable". No private data or prices.
	 */
	public static function formatDisplayKey( string $availability, string $choice, string $suffix ): string {
		return self::normalizeDisplayKey(
			sanitize_key( $availability ) . ':' . sanitize_key( $choice ) . ':' . sanitize_key( $suffix )
		);
	}

	/**
	 * Normalizes a display key for comparison (three colon-separated sanitized segments).
	 */
	public static function normalizeDisplayKey( string $display_key ): string {
		$parts = explode( ':', trim( $display_key ), 3 );

		if ( 3 !== count( $parts ) ) {
			return '';
		}

		$normalized = [];

		foreach ( $parts as $part ) {
			$segment = sanitize_key( $part );

			if ( '' === $segment ) {
				return '';
			}

			$normalized[] = $segment;
		}

		return implode( ':', $normalized );
	}

	private function availability_label( string $availability ): ?string {
		foreach ( FulfilmentAvailability::cases() as $case ) {
			if ( $case->value === $availability ) {
				return match ( $case ) {
					FulfilmentAvailability::InternationalFulfilment => __( 'International fulfilment', 'cetech-woocommerce-delivery-engine' ),
					FulfilmentAvailability::InStore               => __( 'In store', 'cetech-woocommerce-delivery-engine' ),
					FulfilmentAvailability::InWarehouse             => __( 'In warehouse', 'cetech-woocommerce-delivery-engine' ),
				};
			}
		}

		return null;
	}

	private function choice_label( string $choice ): ?string {
		foreach ( FulfilmentChoice::cases() as $case ) {
			if ( $case->value === $choice ) {
				return match ( $case ) {
					FulfilmentChoice::Delivery    => __( 'Delivery', 'cetech-woocommerce-delivery-engine' ),
					FulfilmentChoice::StorePickup => __( 'Store pickup', 'cetech-woocommerce-delivery-engine' ),
				};
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $offer
	 */
	private function format_estimate_text( array $offer ): ?string {
		$total_min = 0;
		$total_max = 0;

		foreach ( [ 'default_processing', 'default_transit', 'default_final_mile' ] as $prefix ) {
			$min_key = $prefix . '_min';
			$max_key = $prefix . '_max';
			$min     = isset( $offer[ $min_key ] ) && '' !== $offer[ $min_key ] ? (int) $offer[ $min_key ] : 0;
			$max     = isset( $offer[ $max_key ] ) && '' !== $offer[ $max_key ] ? (int) $offer[ $max_key ] : 0;

			if ( $min > 0 ) {
				$total_min += $min;
			}

			if ( $max > 0 ) {
				$total_max += $max;
			}
		}

		if ( $total_min <= 0 && $total_max <= 0 ) {
			return null;
		}

		$unit = $this->duration_unit_label( (string) ( $offer['duration_unit'] ?? 'business_days' ) );

		if ( $total_min > 0 && $total_max > 0 && $total_min !== $total_max ) {
			return sprintf(
				/* translators: 1: minimum duration, 2: maximum duration, 3: duration unit label */
				__( '%1$d–%2$d %3$s', 'cetech-woocommerce-delivery-engine' ),
				$total_min,
				$total_max,
				$unit
			);
		}

		$value = $total_max > 0 ? $total_max : $total_min;

		return sprintf(
			/* translators: 1: duration value, 2: duration unit label */
			__( '%1$d %2$s', 'cetech-woocommerce-delivery-engine' ),
			$value,
			$unit
		);
	}

	private function duration_unit_label( string $unit ): string {
		return match ( $unit ) {
			'business_days' => __( 'business days', 'cetech-woocommerce-delivery-engine' ),
			'days'          => __( 'days', 'cetech-woocommerce-delivery-engine' ),
			default         => __( 'days', 'cetech-woocommerce-delivery-engine' ),
		};
	}
}
