<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Destination;

use CetechDeliveryEngine\Application\Destination\OverlappingDeliveryAreaCoverage;
use CetechDeliveryEngine\Application\Destination\RegionCodeLabelMatcher;
use CetechDeliveryEngine\Application\Destination\WooCommerceStateCatalogInterface;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleType;
use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationRuleRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationZoneRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryQuoteRateCardRepository;
use PHPUnit\Framework\TestCase;

final class OverlappingDeliveryAreaCoverageTest extends TestCase {

	public function test_nested_city_region_without_duplicate_air_rate_is_not_a_warning(): void {
		$coverage = $this->coverage(
			[
				$this->card( 1, 11, 30, '50.00' ),
				$this->card( 2, 101, 3, '150.00' ),
			]
		);

		self::assertTrue( $coverage->has_nested_overlaps() );
		self::assertSame( [], $coverage->warnings() );
		self::assertSame( [], $coverage->uncovered_zone_ids() );
	}

	public function test_equal_specificity_equal_priority_overlap_is_a_warning(): void {
		$zones = new InMemoryDestinationZoneRepository();
		$zones->save( $this->zone( 10, 'Accra Central', 100 ) );
		$zones->save( $this->zone( 11, 'Accra East', 100 ) );
		$rules = new InMemoryDestinationRuleRepository();
		$rules->replaceForZone(
			10,
			[
				$this->rule( DestinationRuleType::Country, 'GH' ),
				$this->rule( DestinationRuleType::City, 'Accra' ),
			]
		);
		$rules->replaceForZone(
			11,
			[
				$this->rule( DestinationRuleType::Country, 'GH' ),
				$this->rule( DestinationRuleType::City, 'Accra' ),
			]
		);
		$coverage = new OverlappingDeliveryAreaCoverage(
			$zones,
			$rules,
			new InMemoryQuoteRateCardRepository( [] ),
			$this->ghana_catalog()
		);
		$warnings = $coverage->warnings();

		self::assertNotSame( [], $warnings );
		self::assertSame( 'overlapping_equal_specificity_areas', $warnings[0]['code'] );
	}

	public function test_invalid_specific_rate_warns_when_broader_rate_is_valid(): void {
		$coverage = $this->coverage(
			[
				$this->card( 1, 101, 30, 'banana' ),
				$this->card( 2, 101, 3, '150.00' ),
			]
		);
		$warnings = $coverage->warnings();

		self::assertNotSame( [], $warnings );
		self::assertSame( 'overlapping_invalid_specific_rate', $warnings[0]['code'] );
	}

	/**
	 * @param list<array<string, mixed>> $cards
	 */
	private function coverage( array $cards ): OverlappingDeliveryAreaCoverage {
		$zones = new InMemoryDestinationZoneRepository();
		$zones->save( $this->zone( 30, 'Accra', 100 ) );
		$zones->save( $this->zone( 3, 'Greater Accra', 100 ) );
		$rules = new InMemoryDestinationRuleRepository();
		$rules->replaceForZone(
			30,
			[
				$this->rule( DestinationRuleType::Country, 'GH' ),
				$this->rule( DestinationRuleType::City, 'Accra' ),
			]
		);
		$rules->replaceForZone(
			3,
			[
				$this->rule( DestinationRuleType::Country, 'GH' ),
				$this->rule( DestinationRuleType::Region, 'Greater Accra' ),
			]
		);

		return new OverlappingDeliveryAreaCoverage(
			$zones,
			$rules,
			new InMemoryQuoteRateCardRepository( $cards ),
			$this->ghana_catalog()
		);
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

	/**
	 * @return array<string, mixed>
	 */
	private function card( int $id, int $offer_id, int $zone_id, string $amount ): array {
		return [
			'id'                   => $id,
			'delivery_offer_id'    => $offer_id,
			'destination_zone_id'  => $zone_id,
			'charge_type'          => RateCardChargeType::FixedPerShipment->value,
			'base_amount'          => $amount,
			'base_currency'        => 'GHS',
			'priority'             => 100,
			'status'               => RecordStatus::Active->value,
		];
	}
}
