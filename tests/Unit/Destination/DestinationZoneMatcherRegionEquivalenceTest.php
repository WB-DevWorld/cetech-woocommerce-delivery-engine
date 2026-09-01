<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Destination;

use CetechDeliveryEngine\Application\Destination\DestinationZoneMatcher;
use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolver;
use CetechDeliveryEngine\Application\Destination\RegionCodeLabelMatcher;
use CetechDeliveryEngine\Application\Destination\WooCommerceStateCatalogInterface;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleMatchMode;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Zone\DestinationRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;
use CetechDeliveryEngine\Presentation\Admin\DestinationZoneTestMatcher;
use PHPUnit\Framework\TestCase;

final class DestinationZoneMatcherRegionEquivalenceTest extends TestCase {

	private const ACCRA_ZONE_ID = 11;
	private const FALLBACK_ZONE_ID = 99;
	private const NG_ZONE_ID = 21;
	private const POSTCODE_ZONE_ID = 31;

	private DestinationZoneMatcher $matcher;
	private DestinationZoneTestMatcher $admin_tester;
	private PackageDestinationZoneResolver $package_resolver;

	protected function setUp(): void {
		$catalog = new class() implements WooCommerceStateCatalogInterface {
			public function states_for_country( string $country_code ): array {
				return match ( strtoupper( trim( $country_code ) ) ) {
					'GH' => [
						'AA' => 'Greater Accra',
						'AH' => 'Ashanti',
					],
					'US' => [
						'AA' => 'Armed Forces Americas',
						'CA' => 'California',
					],
					default => [],
				};
			}
		};

		$zones = new InMemoryDestinationZoneRepository(
			[
				$this->zone( self::ACCRA_ZONE_ID, 'Accra', 10, false ),
				$this->zone( self::NG_ZONE_ID, 'Lagos', 20, false ),
				$this->zone( self::POSTCODE_ZONE_ID, 'Postcode pocket', 30, false ),
				$this->zone( self::FALLBACK_ZONE_ID, 'Fallback', 100, true ),
			]
		);

		$rules = new InMemoryDestinationRuleRepository(
			[
				self::ACCRA_ZONE_ID    => [
					$this->rule( DestinationRuleType::Country, 'GH' ),
					$this->rule( DestinationRuleType::Region, 'Greater Accra' ),
					$this->rule( DestinationRuleType::City, 'Accra' ),
				],
				self::NG_ZONE_ID       => [
					$this->rule( DestinationRuleType::Country, 'NG' ),
					$this->rule( DestinationRuleType::Region, 'Greater Accra' ),
					$this->rule( DestinationRuleType::City, 'Accra' ),
				],
				self::POSTCODE_ZONE_ID => [
					$this->rule( DestinationRuleType::Country, 'GH' ),
					$this->rule( DestinationRuleType::Postcode, '00', DestinationRuleMatchMode::Prefix ),
				],
				self::FALLBACK_ZONE_ID => [],
			]
		);

		$this->matcher          = new DestinationZoneMatcher( $zones, $rules, new RegionCodeLabelMatcher( $catalog ) );
		$this->admin_tester     = new DestinationZoneTestMatcher( $this->matcher );
		$this->package_resolver = new PackageDestinationZoneResolver( $this->matcher );
	}

	public function test_gh_aa_accra_matches_rule_saved_as_greater_accra_label(): void {
		$matched = $this->matcher->match( 'GH', 'AA', 'Accra', '' );

		self::assertNotNull( $matched );
		self::assertSame( self::ACCRA_ZONE_ID, (int) $matched['id'] );
	}

	public function test_gh_aa_accra_matches_rule_saved_as_aa_code(): void {
		$zones = new InMemoryDestinationZoneRepository(
			[
				$this->zone( self::ACCRA_ZONE_ID, 'Accra', 10, false ),
				$this->zone( self::FALLBACK_ZONE_ID, 'Fallback', 100, true ),
			]
		);
		$rules = new InMemoryDestinationRuleRepository(
			[
				self::ACCRA_ZONE_ID    => [
					$this->rule( DestinationRuleType::Country, 'GH' ),
					$this->rule( DestinationRuleType::Region, 'AA' ),
					$this->rule( DestinationRuleType::City, 'Accra' ),
				],
				self::FALLBACK_ZONE_ID => [],
			]
		);
		$matcher = new DestinationZoneMatcher( $zones, $rules, $this->ghana_catalog_matcher() );

		$matched = $matcher->match( 'GH', 'AA', 'Accra', '' );

		self::assertNotNull( $matched );
		self::assertSame( self::ACCRA_ZONE_ID, (int) $matched['id'] );
	}

	public function test_region_matching_is_case_insensitive(): void {
		self::assertSame( self::ACCRA_ZONE_ID, (int) ( $this->matcher->match( 'gh', 'aa', 'accra', '' )['id'] ?? 0 ) );
		self::assertSame( self::ACCRA_ZONE_ID, (int) ( $this->matcher->match( 'GH', 'Greater Accra', 'Accra', '' )['id'] ?? 0 ) );
		self::assertSame( self::ACCRA_ZONE_ID, (int) ( $this->matcher->match( 'GH', 'GREATER ACCRA', 'ACCRA', '' )['id'] ?? 0 ) );
	}

	public function test_region_label_from_another_country_cannot_cross_match(): void {
		$zones = new InMemoryDestinationZoneRepository(
			[
				$this->zone( 40, 'False Accra', 10, false ),
				$this->zone( self::FALLBACK_ZONE_ID, 'Fallback', 100, true ),
			]
		);
		$rules = new InMemoryDestinationRuleRepository(
			[
				40                       => [
					$this->rule( DestinationRuleType::Country, 'GH' ),
					$this->rule( DestinationRuleType::Region, 'Armed Forces Americas' ),
					$this->rule( DestinationRuleType::City, 'Accra' ),
				],
				self::FALLBACK_ZONE_ID => [],
			]
		);
		$matcher = new DestinationZoneMatcher( $zones, $rules, $this->ghana_catalog_matcher() );

		$matched = $matcher->match( 'GH', 'AA', 'Accra', '' );

		self::assertNotNull( $matched );
		self::assertSame( self::FALLBACK_ZONE_ID, (int) $matched['id'] );
	}

	public function test_unknown_state_does_not_false_match_accra_area(): void {
		$matched = $this->matcher->match( 'GH', 'ZZ', 'Accra', '' );

		self::assertNotNull( $matched );
		self::assertSame( self::FALLBACK_ZONE_ID, (int) $matched['id'] );
	}

	public function test_country_rule_is_unchanged(): void {
		$matched = $this->matcher->match( 'NG', 'AA', 'Accra', '' );

		self::assertNotNull( $matched );
		self::assertSame( self::FALLBACK_ZONE_ID, (int) $matched['id'] );
	}

	public function test_city_rule_is_unchanged_and_does_not_use_region_equivalence(): void {
		$matched = $this->matcher->match( 'GH', 'AA', 'Kumasi', '' );

		self::assertNotNull( $matched );
		self::assertSame( self::FALLBACK_ZONE_ID, (int) $matched['id'] );
	}

	public function test_postcode_prefix_rule_is_unchanged(): void {
		$matched = $this->matcher->match( 'GH', '', '', '00233' );

		self::assertNotNull( $matched );
		self::assertSame( self::POSTCODE_ZONE_ID, (int) $matched['id'] );
	}

	public function test_postcode_prefix_does_not_match_other_codes(): void {
		$matched = $this->matcher->match( 'GH', '', '', '99111' );

		self::assertNotNull( $matched );
		self::assertSame( self::FALLBACK_ZONE_ID, (int) $matched['id'] );
	}

	public function test_admin_tester_and_woocommerce_package_resolver_are_equivalent_for_code_and_label(): void {
		$admin_from_label = $this->admin_tester->match( 'GH', 'Greater Accra', 'Accra', '' );
		$admin_from_code  = $this->admin_tester->match( 'GH', 'AA', 'Accra', '' );
		$package_from_aa  = $this->package_resolver->resolve_zone_id(
			[
				'country'  => 'GH',
				'state'    => 'AA',
				'city'     => 'Accra',
				'postcode' => '',
			]
		);
		$package_from_label = $this->package_resolver->resolve_zone_id(
			[
				'country'  => 'GH',
				'state'    => 'Greater Accra',
				'city'     => 'Accra',
				'postcode' => '',
			]
		);

		self::assertSame( self::ACCRA_ZONE_ID, (int) ( $admin_from_label['id'] ?? 0 ) );
		self::assertSame( self::ACCRA_ZONE_ID, (int) ( $admin_from_code['id'] ?? 0 ) );
		self::assertSame( self::ACCRA_ZONE_ID, $package_from_aa );
		self::assertSame( self::ACCRA_ZONE_ID, $package_from_label );
		self::assertSame( $package_from_aa, $package_from_label );
		self::assertSame( (int) $admin_from_label['id'], $package_from_aa );
	}

	private function ghana_catalog_matcher(): RegionCodeLabelMatcher {
		$catalog = new class() implements WooCommerceStateCatalogInterface {
			public function states_for_country( string $country_code ): array {
				return match ( strtoupper( trim( $country_code ) ) ) {
					'GH' => [
						'AA' => 'Greater Accra',
					],
					'US' => [
						'AA' => 'Armed Forces Americas',
						'CA' => 'California',
					],
					default => [],
				};
			}
		};

		return new RegionCodeLabelMatcher( $catalog );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function zone( int $id, string $name, int $priority, bool $fallback ): array {
		return [
			'id'            => $id,
			'internal_name' => $name,
			'public_label'  => $name,
			'status'        => RecordStatus::Active->value,
			'priority'      => $priority,
			'is_fallback'   => $fallback,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function rule( DestinationRuleType $type, string $value, DestinationRuleMatchMode $mode = DestinationRuleMatchMode::Exact ): array {
		return [
			'rule_type'  => $type->value,
			'rule_value' => $value,
			'match_mode' => $mode->value,
		];
	}
}

/**
 * @internal
 */
final class InMemoryDestinationZoneRepository implements DestinationZoneRepositoryInterface {

	/**
	 * @param list<array<string, mixed>> $zones
	 */
	public function __construct(
		private array $zones
	) {
	}

	public function findById( int $id ): ?array {
		foreach ( $this->zones as $zone ) {
			if ( (int) ( $zone['id'] ?? 0 ) === $id ) {
				return $zone;
			}
		}

		return null;
	}

	public function findByCode( string $code ): ?array {
		return null;
	}

	public function save( array $data ): int {
		return 0;
	}

	public function list( array $criteria = [] ): array {
		$status = isset( $criteria['status'] ) ? (string) $criteria['status'] : '';
		$out    = [];

		foreach ( $this->zones as $zone ) {
			if ( '' !== $status && (string) ( $zone['status'] ?? '' ) !== $status ) {
				continue;
			}

			$out[] = $zone;
		}

		return $out;
	}

	public function softDelete( int $id ): bool {
		return false;
	}

	public function hardDelete( int $id ): bool {
		return false;
	}

	public function count_all(): int {
		return count( $this->zones );
	}
}

/**
 * @internal
 */
final class InMemoryDestinationRuleRepository implements DestinationRuleRepositoryInterface {

	/**
	 * @param array<int, list<array<string, mixed>>> $rules_by_zone
	 */
	public function __construct(
		private array $rules_by_zone
	) {
	}

	public function listByZoneId( int $zone_id ): array {
		return $this->rules_by_zone[ $zone_id ] ?? [];
	}

	public function deleteByZoneId( int $zone_id ): bool {
		return false;
	}

	public function replaceForZone( int $zone_id, array $rules ): bool {
		return false;
	}

	public function count_all(): int {
		$count = 0;

		foreach ( $this->rules_by_zone as $rules ) {
			$count += count( $rules );
		}

		return $count;
	}

	public function list( int $limit = 500 ): array {
		$all = [];

		foreach ( $this->rules_by_zone as $rules ) {
			foreach ( $rules as $rule ) {
				$all[] = $rule;
			}
		}

		return array_slice( $all, 0, $limit );
	}
}
