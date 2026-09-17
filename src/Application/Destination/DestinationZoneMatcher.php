<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Destination;

use CetechDeliveryEngine\Application\Coverage\CoverageGroupMatcher;
use CetechDeliveryEngine\Application\Geography\CanonicalLocationResolver;
use CetechDeliveryEngine\Domain\Coverage\CoverageMatchDiagnostic;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleMatchMode;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Geography\ResolvedDestination;
use CetechDeliveryEngine\Domain\Zone\DestinationRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;

/**
 * Runtime destination zone matcher using configured zones and rules.
 *
 * Returns one primary match for callers that still need a single area, and an
 * ordered list of every matching active area for selected-offer pricing fallback.
 */
final class DestinationZoneMatcher {

	public const SPECIFICITY_FALLBACK = 0;

	public const SPECIFICITY_COUNTRY = 1;

	public const SPECIFICITY_REGION = 2;

	public const SPECIFICITY_CITY = 3;

	public const SPECIFICITY_POSTCODE = 4;

	/** @var list<CoverageMatchDiagnostic> */
	private array $last_diagnostics = [];

	public function __construct(
		private DestinationZoneRepositoryInterface $zone_repository,
		private DestinationRuleRepositoryInterface $rule_repository,
		private ?RegionCodeLabelMatcher $region_matcher = null,
		private ?CoverageGroupMatcher $coverage_matcher = null,
		private ?CanonicalLocationResolver $canonical_resolver = null
	) {
		$this->region_matcher = $region_matcher ?? new RegionCodeLabelMatcher();
	}

	/**
	 * Admin/test diagnostics from the most recent match_all() call. Never customer-facing.
	 *
	 * @return list<CoverageMatchDiagnostic>
	 */
	public function last_diagnostics(): array {
		return $this->last_diagnostics;
	}

	/**
	 * @return array<string, mixed>|null Primary matched zone row or unrestricted fallback zone.
	 */
	public function match(
		string $country_code,
		string $region,
		string $city,
		string $postcode
	): ?array {
		$matches = $this->match_all( $country_code, $region, $city, $postcode );

		return $matches[0] ?? null;
	}

	/**
	 * Every matching active Delivery Area, ordered by configured priority, then
	 * geographic specificity (postcode > city > region > country > fallback),
	 * then a deterministic name/code tie-break. Database creation order is not
	 * a business ranking.
	 *
	 * A Delivery Area marked fallback that still has location rules remains
	 * constrained by those rules. Only a ruleless fallback may catch addresses
	 * that match no other area. If nothing matches, the result is empty (fail closed).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function match_all(
		string $country_code,
		string $region,
		string $city,
		string $postcode,
		array $context = []
	): array {
		$country_code = strtoupper( trim( $country_code ) );
		$region       = strtolower( trim( $region ) );
		$city         = strtolower( trim( $city ) );
		$postcode     = strtoupper( trim( $postcode ) );
		$this->last_diagnostics = [];

		$resolved = $this->resolve_destination( $country_code, $region, $city, $postcode, $context );

		$zones = $this->zone_repository->list(
			[
				'status' => RecordStatus::Active->value,
				'limit'  => 500,
			]
		);

		$candidates            = [];
		$unrestricted_fallback = null;

		foreach ( $zones as $zone ) {
			$zone_id = (int) ( $zone['id'] ?? 0 );

			if ( $zone_id <= 0 ) {
				continue;
			}

			$rules = $this->rule_repository->listByZoneId( $zone_id );

			if ( self::is_unrestricted_fallback( $zone, $rules ) ) {
				$unrestricted_fallback = $zone;
				continue;
			}

			$coverage = $this->match_coverage( $zone_id, $resolved );
			if ( is_array( $coverage ) ) {
				if ( $coverage['matched'] ) {
					$candidates[] = [
						'zone'        => $zone,
						'specificity' => (int) $coverage['specificity'],
					];
				}
				continue;
			}

			if ( [] === $rules ) {
				continue;
			}

			if ( $this->zone_matches_address( $rules, $country_code, $region, $city, $postcode ) ) {
				$candidates[] = [
					'zone'        => $zone,
					'specificity' => self::geographic_specificity_rank( $rules, false ),
				];
			}
		}

		if ( [] !== $candidates ) {
			usort( $candidates, [ $this, 'compare_candidates' ] );

			$ordered = [];

			foreach ( $candidates as $candidate ) {
				$ordered[] = $candidate['zone'];
			}

			return $ordered;
		}

		return null !== $unrestricted_fallback ? [ $unrestricted_fallback ] : [];
	}

	/**
	 * True when the area is marked fallback and has no location rules.
	 * Only this kind of fallback may match addresses that miss every other area.
	 *
	 * @param array<string, mixed>       $zone
	 * @param list<array<string, mixed>> $rules
	 */
	public static function is_unrestricted_fallback( array $zone, array $rules ): bool {
		return ! empty( $zone['is_fallback'] ) && [] === $rules;
	}

	/**
	 * Highest geographic rank represented by the zone's rules.
	 *
	 * A fallback with location rules keeps the rank of those rules. Only a
	 * ruleless fallback is ranked as a true Everywhere else area.
	 *
	 * @param list<array<string, mixed>> $rules
	 */
	public static function geographic_specificity_rank( array $rules, bool $is_fallback = false ): int {
		if ( $is_fallback && [] === $rules ) {
			return self::SPECIFICITY_FALLBACK;
		}

		$rank = self::SPECIFICITY_FALLBACK;

		foreach ( $rules as $rule ) {
			$rank = max(
				$rank,
				match ( (string) ( $rule['rule_type'] ?? '' ) ) {
					DestinationRuleType::Postcode->value => self::SPECIFICITY_POSTCODE,
					DestinationRuleType::City->value => self::SPECIFICITY_CITY,
					DestinationRuleType::Region->value => self::SPECIFICITY_REGION,
					DestinationRuleType::Country->value => self::SPECIFICITY_COUNTRY,
					default => self::SPECIFICITY_FALLBACK,
				}
			);
		}

		return $rank;
	}

	/**
	 * @param array{zone: array<string, mixed>, specificity: int} $left
	 * @param array{zone: array<string, mixed>, specificity: int} $right
	 */
	private function compare_candidates( array $left, array $right ): int {
		$left_zone  = $left['zone'];
		$right_zone = $right['zone'];

		$left_priority  = (int) ( $left_zone['priority'] ?? 100 );
		$right_priority = (int) ( $right_zone['priority'] ?? 100 );

		if ( $left_priority !== $right_priority ) {
			return $left_priority <=> $right_priority;
		}

		$left_specificity  = (int) ( $left['specificity'] ?? self::SPECIFICITY_FALLBACK );
		$right_specificity = (int) ( $right['specificity'] ?? self::SPECIFICITY_FALLBACK );

		if ( $left_specificity !== $right_specificity ) {
			return $right_specificity <=> $left_specificity;
		}

		$name = strnatcasecmp(
			(string) ( $left_zone['internal_name'] ?? $left_zone['public_label'] ?? '' ),
			(string) ( $right_zone['internal_name'] ?? $right_zone['public_label'] ?? '' )
		);

		if ( 0 !== $name ) {
			return $name;
		}

		$code = strnatcasecmp(
			(string) ( $left_zone['internal_code'] ?? '' ),
			(string) ( $right_zone['internal_code'] ?? '' )
		);

		if ( 0 !== $code ) {
			return $code;
		}

		return (int) ( $left_zone['id'] ?? 0 ) <=> (int) ( $right_zone['id'] ?? 0 );
	}

	/**
	 * @param list<array<string, mixed>> $rules
	 */
	private function zone_matches_address(
		array $rules,
		string $country_code,
		string $region,
		string $city,
		string $postcode
	): bool {
		foreach ( $rules as $rule ) {
			if ( ! $this->rule_matches( $rule, $country_code, $region, $city, $postcode ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $rule
	 */
	private function rule_matches(
		array $rule,
		string $country_code,
		string $region,
		string $city,
		string $postcode
	): bool {
		$rule_type  = (string) ( $rule['rule_type'] ?? '' );
		$rule_value = trim( (string) ( $rule['rule_value'] ?? '' ) );
		$match_mode = (string) ( $rule['match_mode'] ?? DestinationRuleMatchMode::Exact->value );

		if ( '' === $rule_value ) {
			return true;
		}

		return match ( $rule_type ) {
			DestinationRuleType::Country->value => '' !== $country_code
				&& strtoupper( $rule_value ) === $country_code,
			DestinationRuleType::Region->value => $this->region_matches( $country_code, $region, $rule_value ),
			DestinationRuleType::City->value => '' !== $city
				&& strtolower( $rule_value ) === $city,
			DestinationRuleType::Postcode->value => '' !== $postcode && $this->postcode_matches( $postcode, strtoupper( $rule_value ), $match_mode ),
			default => false,
		};
	}

	private function region_matches( string $country_code, string $region, string $rule_value ): bool {
		return $this->region_matcher->matches( $country_code, $region, $rule_value );
	}

	private function postcode_matches( string $postcode, string $rule_value, string $match_mode ): bool {
		if ( DestinationRuleMatchMode::Prefix->value === $match_mode ) {
			return str_starts_with( $postcode, $rule_value );
		}

		return $postcode === $rule_value;
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function resolve_destination(
		string $country_code,
		string $region,
		string $city,
		string $postcode,
		array $context
	): ?ResolvedDestination {
		if ( ! $this->canonical_resolver instanceof CanonicalLocationResolver ) {
			return null;
		}

		$key = (string) ( $context['canonical_location_key'] ?? '' );

		return $this->canonical_resolver->resolve(
			$country_code,
			(string) ( $context['state'] ?? $region ),
			(string) ( $context['state_label'] ?? $region ),
			$city,
			$postcode,
			$key
		);
	}

	/**
	 * @return array{matched:bool,specificity:int}|null Null when the zone has no usable coverage groups.
	 */
	private function match_coverage( int $zone_id, ?ResolvedDestination $resolved ): ?array {
		if ( ! $this->coverage_matcher instanceof CoverageGroupMatcher || ! $resolved instanceof ResolvedDestination ) {
			return null;
		}

		$result = $this->coverage_matcher->match_zone( $zone_id, $resolved );
		if ( isset( $result['diagnostic'] ) && $result['diagnostic'] instanceof CoverageMatchDiagnostic ) {
			$this->last_diagnostics[] = $result['diagnostic'];
		}

		if ( 'no_usable_coverage_group' === ( $result['diagnostic']->fallback_reason ?? '' ) ) {
			return null;
		}

		return [
			'matched'     => (bool) $result['matched'],
			'specificity' => (int) $result['specificity'],
		];
	}
}
