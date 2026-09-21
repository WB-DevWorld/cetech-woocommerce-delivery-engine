<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\CustomerContext;

use CetechDeliveryEngine\Application\CustomerContext\MatchingLocationOptionsEndpoint;
use CetechDeliveryEngine\Application\CustomerContext\CustomerBrowsingLocationStore;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\CustomerContext\ShopperLocationPrecision;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Presentation\Shared\CustomerStorefrontCopy;
use CetechDeliveryEngine\Tests\Support\PdpPrecisionTestKit;
use CetechDeliveryEngine\Tests\Support\ShopperLocationPrecisionFixture;
use PHPUnit\Framework\TestCase;

final class MatchingLocationOptionsPrecisionTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options']     = [];
		$GLOBALS['cetech_de_test_wc_products'] = [];
		$GLOBALS['cetech_de_test_notices']     = [];

		if ( ! class_exists( 'WooCommerce', false ) ) {
			eval( 'class WooCommerce {}' ); // phpcs:ignore Squiz.PHP.Eval -- test bootstrap only.
		}

		if ( ! function_exists( 'get_woocommerce_currency' ) ) {
			eval( 'function get_woocommerce_currency(): string { return "GHS"; }' ); // phpcs:ignore Squiz.PHP.Eval -- test bootstrap only.
		}

		PdpPrecisionTestKit::enable_flags();
		PdpPrecisionTestKit::seed_simple_product();
	}

	protected function tearDown(): void {
		$GLOBALS['cetech_de_test_wc_products'] = [];
		$GLOBALS['cetech_de_test_filters']     = [];
		$_POST                                 = [];
	}

	public function test_greater_accra_broad_selection_needs_locality_precision(): void {
		$fx       = $this->accra_fixture();
		$endpoint = $this->endpoint( $fx );
		$payload  = $endpoint->build_payload( PdpPrecisionTestKit::PRODUCT_ID, 0, $fx->matching_greater_accra() );

		self::assertSame( 'need_precision', $payload['status'] );
		self::assertFalse( $payload['precision']['sufficient'] );
		self::assertSame( ShopperLocationPrecision::LEVEL_LOCALITY, $payload['precision']['required_level'] );
		self::assertSame( CustomerStorefrontCopy::select_city_town_for_exact_fee(), $payload['message'] );
		self::assertTrue( $payload['requires_location'] );
		self::assertArrayNotHasKey( 'default_key', $payload );
		self::assertTrue( $payload['has_pickup'] );
		self::assertSame( [], $this->delivery_options( $payload ) );
		self::assertNotEmpty( $this->pickup_options( $payload ) );
		self::assertStringNotContainsString( '30.00', (string) wp_json_encode( $payload ) );
		self::assertStringNotContainsString( 'GH₵30', (string) wp_json_encode( $payload ) );
	}

	public function test_canonical_accra_returns_ok_delivery_quote(): void {
		$fx       = $this->accra_fixture();
		$endpoint = $this->endpoint( $fx );
		$payload  = $endpoint->build_payload( PdpPrecisionTestKit::PRODUCT_ID, 0, $fx->matching_accra() );

		self::assertSame( 'ok', $payload['status'] );
		self::assertTrue( $payload['precision']['sufficient'] );
		self::assertNotEmpty( $this->delivery_options( $payload ) );
		self::assertNotSame( '', (string) ( $payload['default_key'] ?? '' ) );
	}

	public function test_non_accra_greater_accra_locality_allows_broader_quote(): void {
		$fx       = $this->accra_fixture();
		$endpoint = $this->endpoint( $fx );
		$payload  = $endpoint->build_payload( PdpPrecisionTestKit::PRODUCT_ID, 0, $fx->matching_tema() );

		self::assertSame( 'ok', $payload['status'] );
		self::assertTrue( $payload['precision']['sufficient'] );
		self::assertNotEmpty( $this->delivery_options( $payload ) );
	}

	private function accra_fixture(): ShopperLocationPrecisionFixture {
		$fx = ( new ShopperLocationPrecisionFixture() )->with_usable_gh_pack();
		$fx->add_greater_accra_entire_area();
		$fx->add_accra_selected_descendants();

		return $fx;
	}

	private function endpoint( ShopperLocationPrecisionFixture $fx ): MatchingLocationOptionsEndpoint {
		$precision = $fx->precision;

		return new MatchingLocationOptionsEndpoint(
			new FeatureFlags(),
			new Requirements(),
			PdpPrecisionTestKit::capture( $precision ),
			PdpPrecisionTestKit::always_quote_ghana( $precision ),
			new CustomerBrowsingLocationStore(),
			$fx->resolver,
			$precision
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 *
	 * @return list<array<string, mixed>>
	 */
	private function delivery_options( array $payload ): array {
		return array_values(
			array_filter(
				$payload['options'],
				static fn ( array $row ): bool => FulfilmentChoice::Delivery->value === (string) ( $row['fulfilment_choice'] ?? '' )
			)
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 *
	 * @return list<array<string, mixed>>
	 */
	private function pickup_options( array $payload ): array {
		return array_values(
			array_filter(
				$payload['options'],
				static fn ( array $row ): bool => FulfilmentChoice::StorePickup->value === (string) ( $row['fulfilment_choice'] ?? '' )
			)
		);
	}
}
