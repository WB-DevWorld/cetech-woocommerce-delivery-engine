<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Destination;

use CetechDeliveryEngine\Application\Destination\RegionCodeLabelMatcher;
use CetechDeliveryEngine\Application\Destination\WooCommerceStateCatalogInterface;
use PHPUnit\Framework\TestCase;

final class RegionCodeLabelMatcherTest extends TestCase {

	private RegionCodeLabelMatcher $matcher;

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

		$this->matcher = new RegionCodeLabelMatcher( $catalog );
	}

	public function test_code_matches_saved_label_for_same_country(): void {
		self::assertTrue( $this->matcher->matches( 'GH', 'AA', 'Greater Accra' ) );
	}

	public function test_code_matches_saved_code_for_same_country(): void {
		self::assertTrue( $this->matcher->matches( 'GH', 'AA', 'AA' ) );
	}

	public function test_label_matches_saved_code_for_same_country(): void {
		self::assertTrue( $this->matcher->matches( 'GH', 'Greater Accra', 'AA' ) );
	}

	public function test_matching_is_case_insensitive(): void {
		self::assertTrue( $this->matcher->matches( 'gh', 'aa', 'greater accra' ) );
		self::assertTrue( $this->matcher->matches( 'GH', 'Greater Accra', 'aa' ) );
		self::assertTrue( $this->matcher->matches( 'GH', 'GREATER ACCRA', 'Greater Accra' ) );
	}

	public function test_label_from_another_country_does_not_cross_match(): void {
		self::assertFalse( $this->matcher->matches( 'GH', 'AA', 'Armed Forces Americas' ) );
		self::assertFalse( $this->matcher->matches( 'GH', 'AA', 'California' ) );
		self::assertFalse( $this->matcher->matches( 'US', 'AA', 'Greater Accra' ) );
		self::assertFalse( $this->matcher->matches( 'US', 'CA', 'Greater Accra' ) );
	}

	public function test_unknown_code_or_label_does_not_false_match(): void {
		self::assertFalse( $this->matcher->matches( 'GH', 'ZZ', 'Greater Accra' ) );
		self::assertFalse( $this->matcher->matches( 'GH', 'Unknown Region', 'AA' ) );
		self::assertFalse( $this->matcher->matches( 'GH', 'AA', 'Not A Ghana State' ) );
	}

	public function test_unknown_identical_strings_still_match_literally(): void {
		self::assertTrue( $this->matcher->matches( 'GH', 'Custom Region', 'custom region' ) );
	}

	public function test_empty_address_or_rule_does_not_match(): void {
		self::assertFalse( $this->matcher->matches( 'GH', '', 'Greater Accra' ) );
		self::assertFalse( $this->matcher->matches( 'GH', 'AA', '' ) );
	}

	public function test_without_catalog_only_literal_case_insensitive_match_remains(): void {
		$literal = new RegionCodeLabelMatcher();

		self::assertTrue( $literal->matches( 'GH', 'Greater Accra', 'greater accra' ) );
		self::assertFalse( $literal->matches( 'GH', 'AA', 'Greater Accra' ) );
	}
}
