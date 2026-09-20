<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Destination;

use CetechDeliveryEngine\Application\Destination\DestinationZoneMatcher;
use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolver;
use CetechDeliveryEngine\Application\Destination\RegionCodeLabelMatcher;
use CetechDeliveryEngine\Application\Destination\WooCommerceStateCatalogInterface;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Presentation\Admin\DestinationZoneTestMatcher;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationRuleRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationZoneRepository;
use PHPUnit\Framework\TestCase;

final class DestinationZoneMatchedAreaOrderingTest extends TestCase {

	private const ACCRA_ID = 30;

	private const GREATER_ACCRA_ID = 3;

	private DestinationZoneMatcher $matcher;

	protected function setUp(): void {
		$this->matcher = $this->matcher_with_overlap( self::ACCRA_ID, self::GREATER_ACCRA_ID );
	}

	public function test_same_priority_city_is_ordered_before_region_regardless_of_database_id(): void {
		$matched = $this->matcher->match_all( 'GH', 'AA', 'Accra', '' );

		self::assertCount( 2, $matched );
		self::assertSame( self::ACCRA_ID, (int) $matched[0]['id'] );
		self::assertSame( self::GREATER_ACCRA_ID, (int) $matched[1]['id'] );
		self::assertSame( self::ACCRA_ID, (int) ( $this->matcher->match( 'GH', 'AA', 'Accra', '' )['id'] ?? 0 ) );
	}

	public function test_city_still_wins_when_it_has_the_lower_database_id(): void {
		$matcher = $this->matcher_with_overlap( 1, 3 );
		$matched = $matcher->match_all( 'GH', 'Greater Accra', 'Accra', '' );

		self::assertSame( 1, (int) $matched[0]['id'] );
		self::assertSame( 3, (int) $matched[1]['id'] );
	}

	public function test_configured_priority_still_outranks_geographic_specificity(): void {
		$matcher = $this->matcher_with_overlap( self::ACCRA_ID, self::GREATER_ACCRA_ID, 20, 10 );
		$matched = $matcher->match_all( 'GH', 'AA', 'Accra', '' );

		self::assertSame( self::GREATER_ACCRA_ID, (int) $matched[0]['id'] );
		self::assertSame( self::ACCRA_ID, (int) $matched[1]['id'] );
	}

	public function test_same_level_overlap_explicit_priority_wins(): void {
		$zones = new InMemoryDestinationZoneRepository();
		$zones->save( $this->zone( 80, 'Accra West', 40 ) );
		$zones->save( $this->zone( 81, 'Accra East', 10 ) );
		$rules = new InMemoryDestinationRuleRepository();
		$rules->replaceForZone(
			80,
			[
				$this->rule( DestinationRuleType::Country, 'GH' ),
				$this->rule( DestinationRuleType::City, 'Accra' ),
			]
		);
		$rules->replaceForZone(
			81,
			[
				$this->rule( DestinationRuleType::Country, 'GH' ),
				$this->rule( DestinationRuleType::City, 'Accra' ),
			]
		);
		$matcher = new DestinationZoneMatcher( $zones, $rules, $this->ghana_catalog() );
		$matched = $matcher->match_all( 'GH', 'AA', 'Accra', '' );

		self::assertSame( 81, (int) $matched[0]['id'] );
		self::assertSame( 80, (int) $matched[1]['id'] );
	}

	public function test_admin_tester_and_package_resolver_expose_the_same_ordered_ids(): void {
		$admin    = new DestinationZoneTestMatcher( $this->matcher );
		$resolver = new PackageDestinationZoneResolver( $this->matcher );
		$all      = $admin->match_all( 'GH', 'AA', 'Accra', '' );
		$ids      = $resolver->resolve_zone_ids(
			[
				'country'  => 'GH',
				'state'    => 'AA',
				'city'     => 'Accra',
				'postcode' => '',
			]
		);

		self::assertSame( [ self::ACCRA_ID, self::GREATER_ACCRA_ID ], $ids );
		self::assertSame( self::ACCRA_ID, $resolver->resolve_zone_id(
			[
				'country'  => 'GH',
				'state'    => 'AA',
				'city'     => 'Accra',
				'postcode' => '',
			]
		) );
		self::assertSame( self::ACCRA_ID, (int) ( $all[0]['id'] ?? 0 ) );
	}

	private function matcher_with_overlap( int $accra_id, int $greater_id, int $accra_priority = 100, int $greater_priority = 100 ): DestinationZoneMatcher {
		$zones = new InMemoryDestinationZoneRepository();
		$zones->save( $this->zone( $accra_id, 'Accra', $accra_priority ) );
		$zones->save( $this->zone( $greater_id, 'Greater Accra', $greater_priority ) );

		$rules = new InMemoryDestinationRuleRepository();
		$rules->replaceForZone(
			$accra_id,
			[
				$this->rule( DestinationRuleType::Country, 'GH' ),
				$this->rule( DestinationRuleType::City, 'Accra' ),
			]
		);
		$rules->replaceForZone(
			$greater_id,
			[
				$this->rule( DestinationRuleType::Country, 'GH' ),
				$this->rule( DestinationRuleType::Region, 'Greater Accra' ),
			]
		);

		return new DestinationZoneMatcher( $zones, $rules, $this->ghana_catalog() );
	}

	private function ghana_catalog(): RegionCodeLabelMatcher {
		$catalog = new class() implements WooCommerceStateCatalogInterface {
			public function states_for_country( string $country_code ): array {
				return 'GH' === strtoupper( trim( $country_code ) )
					? [ 'AA' => 'Greater Accra' ]
					: [];
			}
		};

		return new RegionCodeLabelMatcher( $catalog );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function zone( int $id, string $name, int $priority ): array {
		return [
			'id'            => $id,
			'internal_name' => $name,
			'public_label'  => $name,
			'internal_code' => strtolower( str_replace( ' ', '-', $name ) ),
			'status'        => RecordStatus::Active->value,
			'priority'      => $priority,
			'is_fallback'   => false,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function rule( DestinationRuleType $type, string $value ): array {
		return [
			'rule_type'  => $type->value,
			'rule_value' => $value,
			'match_mode' => 'exact',
		];
	}
}
