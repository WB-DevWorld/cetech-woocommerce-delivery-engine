<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Destination;

use CetechDeliveryEngine\Domain\Enum\DestinationRuleMatchMode;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;

/**
 * Detects overlapping Delivery Areas that need an administrator warning.
 *
 * Nested city/region overlap with missing more-specific rates is expected:
 * pricing inherits from the broader matching area. That is not a warning.
 *
 * Warnings are reserved for:
 * - equal-specificity, equal-priority overlaps (ambiguous ranking);
 * - a more-specific area whose selected-offer rate is malformed while a
 *   broader overlapping area has a valid rate (fail-closed, will not inherit).
 */
final class OverlappingDeliveryAreaCoverage {

	public function __construct(
		private DestinationZoneRepositoryInterface $zone_repository,
		private DestinationRuleRepositoryInterface $rule_repository,
		private RateCardRepositoryInterface $rate_card_repository,
		private ?RegionCodeLabelMatcher $region_matcher = null,
		private ?\CetechDeliveryEngine\Domain\Coverage\CoverageGroupRepositoryInterface $coverage_groups = null
	) {
		$this->region_matcher = $region_matcher ?? new RegionCodeLabelMatcher();
	}

	/**
	 * @return list<array{
	 *     code: string,
	 *     title: string,
	 *     message: string,
	 *     zone_id: int,
	 *     details: string
	 * }>
	 */
	public function warnings(): array {
		$active = $this->active_zones_with_rules();
		$warnings = [];
		$canonical_overlap_noted = [];

		foreach ( $this->pairs( $active ) as $pair ) {
			$left  = $pair[0];
			$right = $pair[1];

			$left_canonical_only  = ! empty( $left['canonical'] ) && [] === $left['rules'];
			$right_canonical_only = ! empty( $right['canonical'] ) && [] === $right['rules'];
			if ( $left_canonical_only || $right_canonical_only ) {
				$canonical_zone = $left_canonical_only ? $left['zone'] : $right['zone'];
				$other_zone     = $left_canonical_only ? $right['zone'] : $left['zone'];
				$cid            = (int) ( $canonical_zone['id'] ?? 0 );
				if ( $cid > 0 && ! isset( $canonical_overlap_noted[ $cid ] ) ) {
					$canonical_overlap_noted[ $cid ] = true;
					$warnings[] = $this->canonical_overlap_warning( $canonical_zone, $other_zone );
				}
				continue;
			}

			if ( ! $this->rules_are_compatible( $left['rules'], $right['rules'] ) ) {
				continue;
			}

			$left_rank  = DestinationZoneMatcher::geographic_specificity_rank( $left['rules'], ! empty( $left['zone']['is_fallback'] ) );
			$right_rank = DestinationZoneMatcher::geographic_specificity_rank( $right['rules'], ! empty( $right['zone']['is_fallback'] ) );
			$left_priority  = (int) ( $left['zone']['priority'] ?? 100 );
			$right_priority = (int) ( $right['zone']['priority'] ?? 100 );

			if ( $left_rank === $right_rank && $left_priority === $right_priority && $left_rank > DestinationZoneMatcher::SPECIFICITY_FALLBACK ) {
				$warnings[] = $this->ambiguous_warning( $left['zone'], $right['zone'] );
			}

			$malformed = $this->malformed_specific_rate_warning( $left, $right, $left_rank, $right_rank );

			if ( null !== $malformed ) {
				$warnings[] = $malformed;
			}
		}

		return $warnings;
	}

	public function has_nested_overlaps(): bool {
		$active = $this->active_zones_with_rules();

		foreach ( $this->pairs( $active ) as $pair ) {
			$left_canonical_only  = ! empty( $pair[0]['canonical'] ) && [] === $pair[0]['rules'];
			$right_canonical_only = ! empty( $pair[1]['canonical'] ) && [] === $pair[1]['rules'];
			if ( $left_canonical_only || $right_canonical_only ) {
				return true;
			}
			if ( ! $this->rules_are_compatible( $pair[0]['rules'], $pair[1]['rules'] ) ) {
				continue;
			}

			$left_rank  = DestinationZoneMatcher::geographic_specificity_rank( $pair[0]['rules'], ! empty( $pair[0]['zone']['is_fallback'] ) );
			$right_rank = DestinationZoneMatcher::geographic_specificity_rank( $pair[1]['rules'], ! empty( $pair[1]['zone']['is_fallback'] ) );

			if ( $left_rank !== $right_rank && min( $left_rank, $right_rank ) > DestinationZoneMatcher::SPECIFICITY_FALLBACK ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Active areas that have no charges of their own and no broader overlapping
	 * area that can supply a charge. Nested missing rates that inherit are omitted.
	 *
	 * @return list<int>
	 */
	public function uncovered_zone_ids(): array {
		$active     = $this->active_zones_with_rules();
		$cards      = $this->rate_card_repository->list( [ 'limit' => 2000 ] );
		$card_zones = [];

		foreach ( $cards as $card ) {
			if ( RecordStatus::Active->value !== (string) ( $card['status'] ?? '' ) ) {
				continue;
			}

			$zone_id = (int) ( $card['destination_zone_id'] ?? 0 );

			if ( $zone_id > 0 ) {
				$card_zones[ $zone_id ] = true;
			}
		}

		$uncovered = [];

		foreach ( $active as $item ) {
			$zone_id = (int) ( $item['zone']['id'] ?? 0 );

			if ( $zone_id <= 0 || isset( $card_zones[ $zone_id ] ) ) {
				continue;
			}

			if ( ! empty( $item['canonical'] ) && [] === $item['rules'] ) {
				$uncovered[] = $zone_id;
				continue;
			}

			$rank = DestinationZoneMatcher::geographic_specificity_rank( $item['rules'], ! empty( $item['zone']['is_fallback'] ) );
			$inherits = false;

			foreach ( $active as $other ) {
				$other_id = (int) ( $other['zone']['id'] ?? 0 );

				if ( $other_id === $zone_id || ! isset( $card_zones[ $other_id ] ) ) {
					continue;
				}

				if ( ! $this->rules_are_compatible( $item['rules'], $other['rules'] ) ) {
					continue;
				}

				$other_rank = DestinationZoneMatcher::geographic_specificity_rank( $other['rules'], ! empty( $other['zone']['is_fallback'] ) );

				if ( $other_rank < $rank ) {
					$inherits = true;
					break;
				}
			}

			if ( ! $inherits ) {
				$uncovered[] = $zone_id;
			}
		}

		return $uncovered;
	}

	/**
	 * @return list<array{zone: array<string, mixed>, rules: list<array<string, mixed>>}>
	 */
	private function active_zones_with_rules(): array {
		$out = [];

		foreach ( $this->zone_repository->list( [ 'status' => RecordStatus::Active->value, 'limit' => 500 ] ) as $zone ) {
			$zone_id = (int) ( $zone['id'] ?? 0 );

			if ( $zone_id <= 0 ) {
				continue;
			}

			$rules = $this->rule_repository->listByZoneId( $zone_id );
			$has_canonical = $this->zone_has_canonical_coverage( $zone_id );

			if ( ( [] === $rules || DestinationZoneMatcher::is_unrestricted_fallback( $zone, $rules ) ) && ! $has_canonical ) {
				continue;
			}

			$out[] = [
				'zone'       => $zone,
				'rules'      => $rules,
				'canonical'  => $has_canonical,
			];
		}

		return $out;
	}

	private function zone_has_canonical_coverage( int $zone_id ): bool {
		if ( ! $this->coverage_groups instanceof \CetechDeliveryEngine\Domain\Coverage\CoverageGroupRepositoryInterface ) {
			return false;
		}
		foreach ( $this->coverage_groups->list_by_zone( $zone_id ) as $group ) {
			if ( $group->isUsable() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<array{zone: array<string, mixed>, rules: list<array<string, mixed>>}> $items
	 *
	 * @return list<array{0: array{zone: array<string, mixed>, rules: list<array<string, mixed>>}, 1: array{zone: array<string, mixed>, rules: list<array<string, mixed>>}}>
	 */
	private function pairs( array $items ): array {
		$pairs = [];
		$count = count( $items );

		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$pairs[] = [ $items[ $i ], $items[ $j ] ];
			}
		}

		return $pairs;
	}

	/**
	 * @param list<array<string, mixed>> $left
	 * @param list<array<string, mixed>> $right
	 */
	private function rules_are_compatible( array $left, array $right ): bool {
		$left_map  = $this->constraint_map( $left );
		$right_map = $this->constraint_map( $right );

		if ( ! $this->same_or_one_empty( $left_map['country'] ?? '', $right_map['country'] ?? '', true ) ) {
			return false;
		}

		$country = '' !== ( $left_map['country'] ?? '' ) ? (string) $left_map['country'] : (string) ( $right_map['country'] ?? '' );

		if ( ! $this->regions_compatible( $country, (string) ( $left_map['region'] ?? '' ), (string) ( $right_map['region'] ?? '' ) ) ) {
			return false;
		}

		if ( ! $this->same_or_one_empty( $left_map['city'] ?? '', $right_map['city'] ?? '', false ) ) {
			return false;
		}

		return $this->postcodes_compatible(
			(string) ( $left_map['postcode'] ?? '' ),
			(string) ( $left_map['postcode_mode'] ?? DestinationRuleMatchMode::Exact->value ),
			(string) ( $right_map['postcode'] ?? '' ),
			(string) ( $right_map['postcode_mode'] ?? DestinationRuleMatchMode::Exact->value )
		);
	}

	/**
	 * @param list<array<string, mixed>> $rules
	 *
	 * @return array<string, string>
	 */
	private function constraint_map( array $rules ): array {
		$map = [
			'country'       => '',
			'region'        => '',
			'city'          => '',
			'postcode'      => '',
			'postcode_mode' => DestinationRuleMatchMode::Exact->value,
		];

		foreach ( $rules as $rule ) {
			$type  = (string) ( $rule['rule_type'] ?? '' );
			$value = trim( (string) ( $rule['rule_value'] ?? '' ) );

			if ( '' === $value ) {
				continue;
			}

			if ( DestinationRuleType::Country->value === $type ) {
				$map['country'] = strtoupper( $value );
			} elseif ( DestinationRuleType::Region->value === $type ) {
				$map['region'] = strtolower( $value );
			} elseif ( DestinationRuleType::City->value === $type ) {
				$map['city'] = strtolower( $value );
			} elseif ( DestinationRuleType::Postcode->value === $type ) {
				$map['postcode']      = strtoupper( $value );
				$map['postcode_mode'] = (string) ( $rule['match_mode'] ?? DestinationRuleMatchMode::Exact->value );
			}
		}

		return $map;
	}

	private function same_or_one_empty( string $left, string $right, bool $uppercase ): bool {
		$left  = $uppercase ? strtoupper( trim( $left ) ) : strtolower( trim( $left ) );
		$right = $uppercase ? strtoupper( trim( $right ) ) : strtolower( trim( $right ) );

		if ( '' === $left || '' === $right ) {
			return true;
		}

		return $left === $right;
	}

	private function regions_compatible( string $country, string $left, string $right ): bool {
		$left  = strtolower( trim( $left ) );
		$right = strtolower( trim( $right ) );

		if ( '' === $left || '' === $right || $left === $right ) {
			return true;
		}

		return $this->region_matcher->matches( $country, $left, $right )
			|| $this->region_matcher->matches( $country, $right, $left );
	}

	private function postcodes_compatible( string $left, string $left_mode, string $right, string $right_mode ): bool {
		$left  = strtoupper( trim( $left ) );
		$right = strtoupper( trim( $right ) );

		if ( '' === $left || '' === $right ) {
			return true;
		}

		$left_prefix  = DestinationRuleMatchMode::Prefix->value === $left_mode;
		$right_prefix = DestinationRuleMatchMode::Prefix->value === $right_mode;

		if ( $left_prefix && $right_prefix ) {
			return str_starts_with( $left, $right ) || str_starts_with( $right, $left );
		}

		if ( $left_prefix ) {
			return str_starts_with( $right, $left );
		}

		if ( $right_prefix ) {
			return str_starts_with( $left, $right );
		}

		return $left === $right;
	}

	/**
	 * @param array<string, mixed> $left
	 * @param array<string, mixed> $right
	 *
	 * @return array{code: string, title: string, message: string, zone_id: int, details: string}
	 */
	/**
	 * @param array<string, mixed> $canonical
	 * @param array<string, mixed> $other
	 *
	 * @return array{code: string, title: string, message: string, zone_id: int, details: string}
	 */
	private function canonical_overlap_warning( array $canonical, array $other ): array {
		$canonical_label = $this->zone_label( $canonical );
		$other_label     = $this->zone_label( $other );

		return [
			'code'    => 'canonical_coverage_overlap_unproven',
			'title'   => __( 'Canonical coverage overlap cannot be proven from legacy rules', 'cetech-woocommerce-delivery-engine' ),
			'message' => sprintf(
				/* translators: 1: canonical area name, 2: other area name */
				__( 'Delivery Area "%1$s" uses canonical coverage. Overlap with other Delivery Areas (including "%2$s") cannot be proven from legacy destination rules.', 'cetech-woocommerce-delivery-engine' ),
				$canonical_label,
				$other_label
			),
			'zone_id' => (int) ( $canonical['id'] ?? 0 ),
			'details' => $canonical_label . ' / ' . $other_label,
		];
	}

	private function ambiguous_warning( array $left, array $right ): array {
		$left_label  = $this->zone_label( $left );
		$right_label = $this->zone_label( $right );

		return [
			'code'    => 'overlapping_equal_specificity_areas',
			'title'   => __( 'Overlapping delivery areas need a clearer ranking', 'cetech-woocommerce-delivery-engine' ),
			'message' => sprintf(
				/* translators: 1: first area name, 2: second area name */
				__( '%1$s and %2$s can match the same address at the same level and priority. Set different priorities or make one area more specific so pricing is not ambiguous.', 'cetech-woocommerce-delivery-engine' ),
				$left_label,
				$right_label
			),
			'zone_id' => (int) ( $left['id'] ?? 0 ),
			'details' => $left_label . ' / ' . $right_label,
		];
	}

	/**
	 * @param array{zone: array<string, mixed>, rules: list<array<string, mixed>>} $left
	 * @param array{zone: array<string, mixed>, rules: list<array<string, mixed>>} $right
	 *
	 * @return array{code: string, title: string, message: string, zone_id: int, details: string}|null
	 */
	private function malformed_specific_rate_warning( array $left, array $right, int $left_rank, int $right_rank ): ?array {
		if ( $left_rank === $right_rank ) {
			return null;
		}

		$specific = $left_rank > $right_rank ? $left : $right;
		$broader  = $left_rank > $right_rank ? $right : $left;
		$specific_id = (int) ( $specific['zone']['id'] ?? 0 );
		$broader_id  = (int) ( $broader['zone']['id'] ?? 0 );

		$specific_cards = $this->cards_by_offer( $specific_id );
		$broader_cards  = $this->cards_by_offer( $broader_id );

		foreach ( $specific_cards as $offer_id => $cards ) {
			if ( ! isset( $broader_cards[ $offer_id ] ) ) {
				continue;
			}

			if ( ! $this->has_valid_card( $cards ) && $this->has_valid_card( $broader_cards[ $offer_id ] ) ) {
				$specific_label = $this->zone_label( $specific['zone'] );
				$broader_label  = $this->zone_label( $broader['zone'] );

				return [
					'code'    => 'overlapping_invalid_specific_rate',
					'title'   => __( 'A more-specific delivery area has an invalid charge', 'cetech-woocommerce-delivery-engine' ),
					'message' => sprintf(
						/* translators: 1: more-specific area name, 2: broader area name */
						__( '%1$s has a charge that cannot be used, so pricing will not fall back to %2$s for that delivery option. Fix or remove the invalid charge.', 'cetech-woocommerce-delivery-engine' ),
						$specific_label,
						$broader_label
					),
					'zone_id' => $specific_id,
					'details' => $specific_label . ' / ' . $broader_label,
				];
			}
		}

		return null;
	}

	/**
	 * @return array<int, list<array<string, mixed>>>
	 */
	private function cards_by_offer( int $zone_id ): array {
		$by_offer = [];

		foreach ( $this->rate_card_repository->list( [ 'limit' => 2000 ] ) as $card ) {
			if ( (int) ( $card['destination_zone_id'] ?? 0 ) !== $zone_id ) {
				continue;
			}

			if ( RecordStatus::Active->value !== (string) ( $card['status'] ?? '' ) ) {
				continue;
			}

			$offer_id = (int) ( $card['delivery_offer_id'] ?? 0 );

			if ( $offer_id <= 0 ) {
				continue;
			}

			$by_offer[ $offer_id ][] = $card;
		}

		return $by_offer;
	}

	/**
	 * @param list<array<string, mixed>> $cards
	 */
	private function has_valid_card( array $cards ): bool {
		foreach ( $cards as $card ) {
			if ( ! array_key_exists( 'base_amount', $card ) || null === $card['base_amount'] || '' === $card['base_amount'] ) {
				continue;
			}

			$amount = (string) $card['base_amount'];

			if ( ! is_numeric( $amount ) || (float) $amount < 0 ) {
				continue;
			}

			return true;
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $zone
	 */
	private function zone_label( array $zone ): string {
		$label = trim( (string) ( $zone['public_label'] ?? $zone['internal_name'] ?? '' ) );

		return '' !== $label ? $label : (string) ( $zone['id'] ?? '' );
	}
}
