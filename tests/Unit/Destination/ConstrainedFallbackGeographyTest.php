<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Destination;

use CetechDeliveryEngine\Application\Destination\DestinationZoneMatcher;
use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolver;
use CetechDeliveryEngine\Application\Destination\RegionCodeLabelMatcher;
use CetechDeliveryEngine\Application\Destination\WooCommerceStateCatalogInterface;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationRuleRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationZoneRepository;
use PHPUnit\Framework\TestCase;

final class ConstrainedFallbackGeographyTest extends TestCase {

	private const ACCRA_ID = 30;

	private const GREATER_ACCRA_ID = 3;

	private const GLOBAL_FALLBACK_ID = 99;

	public function test_constrained_greater_accra_fallback_does_not_match_usa(): void {
		$matcher = $this->matcher( true, false );

		self::assertSame( [], $matcher->match_all( 'US', 'NY', 'New York', '10001' ) );
		self::assertNull( $matcher->match( 'US', 'NY', 'New York', '10001' ) );
		self::assertSame(
			[],
			( new PackageDestinationZoneResolver( $matcher ) )->resolve_zone_ids(
				[
					'country'  => 'US',
					'state'    => 'NY',
					'city'     => 'New York',
					'postcode' => '10001',
				]
			)
		);
	}

	public function test_ruleless_fallback_catches_unmatched_usa_address(): void {
		$matcher = $this->matcher( false, true );
		$matched = $matcher->match_all( 'US', 'NY', 'New York', '10001' );

		self::assertCount( 1, $matched );
		self::assertSame( self::GLOBAL_FALLBACK_ID, (int) $matched[0]['id'] );
	}

	public function test_no_fallback_means_unresolved_for_unmatched_address(): void {
		$matcher = $this->matcher( false, false );

		self::assertSame( [], $matcher->match_all( 'US', 'NY', 'New York', '10001' ) );
		self::assertNull( $matcher->match( 'US', 'NY', 'New York', '10001' ) );
	}

	public function test_constrained_greater_accra_fallback_still_matches_accra_with_city_first(): void {
		$matcher = $this->matcher( true, false );
		$matched = $matcher->match_all( 'GH', 'AA', 'Accra', '' );

		self::assertCount( 2, $matched );
		self::assertSame( self::ACCRA_ID, (int) $matched[0]['id'] );
		self::assertSame( self::GREATER_ACCRA_ID, (int) $matched[1]['id'] );
	}

	public function test_constrained_fallback_keeps_region_specificity(): void {
		$rules = [
			$this->rule( DestinationRuleType::Country, 'GH' ),
			$this->rule( DestinationRuleType::Region, 'Greater Accra' ),
		];

		self::assertSame(
			DestinationZoneMatcher::SPECIFICITY_REGION,
			DestinationZoneMatcher::geographic_specificity_rank( $rules, true )
		);
		self::assertTrue(
			DestinationZoneMatcher::is_unrestricted_fallback(
				[ 'is_fallback' => true ],
				[]
			)
		);
		self::assertFalse(
			DestinationZoneMatcher::is_unrestricted_fallback(
				[ 'is_fallback' => true ],
				$rules
			)
		);
	}

	private function matcher( bool $greater_is_fallback, bool $with_global_fallback ): DestinationZoneMatcher {
		$zones = new InMemoryDestinationZoneRepository();
		$zones->save( $this->zone( self::ACCRA_ID, 'Accra', false ) );
		$zones->save( $this->zone( self::GREATER_ACCRA_ID, 'Greater Accra', $greater_is_fallback ) );

		$rules = new InMemoryDestinationRuleRepository();
		$rules->replaceForZone(
			self::ACCRA_ID,
			[
				$this->rule( DestinationRuleType::Country, 'GH' ),
				$this->rule( DestinationRuleType::City, 'Accra' ),
			]
		);
		$rules->replaceForZone(
			self::GREATER_ACCRA_ID,
			[
				$this->rule( DestinationRuleType::Country, 'GH' ),
				$this->rule( DestinationRuleType::Region, 'Greater Accra' ),
			]
		);

		if ( $with_global_fallback ) {
			$zones->save( $this->zone( self::GLOBAL_FALLBACK_ID, 'Everywhere else', true ) );
			$rules->replaceForZone( self::GLOBAL_FALLBACK_ID, [] );
		}

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
	private function zone( int $id, string $name, bool $fallback ): array {
		return [
			'id'            => $id,
			'internal_name' => $name,
			'public_label'  => $name,
			'status'        => RecordStatus::Active->value,
			'priority'      => 100,
			'is_fallback'   => $fallback,
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
