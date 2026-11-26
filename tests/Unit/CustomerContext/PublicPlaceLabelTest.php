<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\CustomerContext;

use CetechDeliveryEngine\Domain\CustomerContext\LocationNormalizer;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use CetechDeliveryEngine\Presentation\Shared\CustomerStorefrontCopy;
use PHPUnit\Framework\TestCase;

/**
 * Staff testing on staging (1.0.0-m2.5) found the delivery summary reading
 * "Standard Delivery · GH · …" after a customer chose a region that a Delivery Area covers as a
 * whole: with a price but no town, the place fell back to the two-letter country code. The place
 * is now named in words: the town, else the region, else the country.
 */
final class PublicPlaceLabelTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_wc'] = new class() {
			public object $countries;

			public function __construct() {
				$this->countries = new class() {
					/**
					 * @return array<string, string>
					 */
					public function get_countries(): array {
						return [
							'GH' => 'Ghana',
							'US' => 'United States (US)',
							'CI' => 'C&ocirc;te d&#039;Ivoire',
						];
					}

					/**
					 * @return array<string, string>
					 */
					public function get_states( string $country ): array {
						return 'GH' === $country
							? [
								'AH' => 'Ashanti Region',
								'AA' => 'Greater Accra Region',
							]
							: [];
					}
				};
			}
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cetech_de_test_wc'] );
	}

	public function test_a_town_is_still_the_label_when_one_is_chosen(): void {
		$location = MatchingLocation::fromInput( [ 'country' => 'GH', 'state' => 'AA', 'city' => 'Accra' ] );

		self::assertSame( 'Accra', $location->publicLocalityLabel() );
	}

	public function test_a_region_without_a_town_is_named_not_shown_as_the_country_code(): void {
		$by_code = MatchingLocation::fromInput( [ 'country' => 'GH', 'state' => 'AH' ] );
		$by_name = MatchingLocation::fromInput( [ 'country' => 'GH', 'state' => 'Ashanti Region' ] );

		self::assertSame( 'Ashanti Region', $by_code->publicLocalityLabel() );
		self::assertSame( 'Ashanti Region', $by_name->publicLocalityLabel(), 'a region typed by name resolves to the same label' );
		self::assertNotSame( 'GH', $by_code->publicLocalityLabel() );

		// What the customer reads on the product page, cart and checkout heading.
		self::assertStringContainsString( 'Ashanti Region', CustomerStorefrontCopy::delivery_to( $by_code->publicLocalityLabel() ) );
		self::assertStringNotContainsString( 'GH', CustomerStorefrontCopy::delivery_to( $by_code->publicLocalityLabel() ) );
	}

	public function test_a_country_alone_is_named_in_words(): void {
		self::assertSame( 'Ghana', MatchingLocation::fromInput( [ 'country' => 'GH' ] )->publicLocalityLabel() );
		self::assertSame( 'United States', MatchingLocation::fromInput( [ 'country' => 'US' ] )->publicLocalityLabel(), 'the bracketed code WooCommerce appends is dropped' );
		self::assertSame( "Côte d'Ivoire", MatchingLocation::fromInput( [ 'country' => 'CI' ] )->publicLocalityLabel(), 'stored HTML entities are decoded once; callers escape on output' );
	}

	public function test_a_region_woocommerce_does_not_list_keeps_the_customers_own_text(): void {
		$location = MatchingLocation::fromInput( [ 'country' => 'US', 'state' => 'Somewhere County' ] );

		self::assertSame( 'Somewhere County', $location->publicLocalityLabel() );
		self::assertSame( '', LocationNormalizer::stateLabel( 'GH', '   ' ), 'no region means no region label' );
	}

	public function test_the_country_code_is_only_the_last_resort(): void {
		// A country WooCommerce cannot name.
		self::assertSame( 'ZZ', MatchingLocation::fromInput( [ 'country' => 'ZZ' ] )->publicLocalityLabel() );
		self::assertSame( '', LocationNormalizer::countryLabel( 'ZZ' ) );

		// No WooCommerce at all (the state every other unit test runs in): unchanged behaviour.
		unset( $GLOBALS['cetech_de_test_wc'] );
		self::assertSame( 'GH', MatchingLocation::fromInput( [ 'country' => 'GH' ] )->publicLocalityLabel() );
		self::assertSame( 'AH', MatchingLocation::fromInput( [ 'country' => 'GH', 'state' => 'AH' ] )->publicLocalityLabel(), 'without names to look up, the region as stored still beats the country code' );
	}
}
