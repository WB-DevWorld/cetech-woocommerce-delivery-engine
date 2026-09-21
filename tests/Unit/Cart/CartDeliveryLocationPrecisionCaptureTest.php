<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Cart;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Presentation\Shared\CustomerStorefrontCopy;
use CetechDeliveryEngine\Tests\Support\PdpPrecisionTestKit;
use CetechDeliveryEngine\Tests\Support\ShopperLocationPrecisionFixture;
use PHPUnit\Framework\TestCase;

final class CartDeliveryLocationPrecisionCaptureTest extends TestCase {

	private string $delivery_key = '';

	private string $pickup_key = '';

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options']     = [];
		$GLOBALS['cetech_de_test_wc_products'] = [];
		$GLOBALS['cetech_de_test_notices']     = [];
		$GLOBALS['cetech_de_test_filters']     = [];
		unset( $GLOBALS['cetech_de_blocks_delivery_option_key'] );
		$_POST = [];

		if ( ! class_exists( 'WooCommerce', false ) ) {
			eval( 'class WooCommerce {}' ); // phpcs:ignore Squiz.PHP.Eval -- test bootstrap only.
		}

		PdpPrecisionTestKit::enable_flags();
		PdpPrecisionTestKit::seed_simple_product();
	}

	protected function tearDown(): void {
		$GLOBALS['cetech_de_test_wc_products'] = [];
		$GLOBALS['cetech_de_test_filters']     = [];
		$GLOBALS['cetech_de_test_notices']     = [];
		unset( $GLOBALS['cetech_de_blocks_delivery_option_key'] );
		$_POST = [];
	}

	public function test_classic_rejects_precision_incomplete_delivery(): void {
		$capture = $this->capture();
		$this->post_classic( $this->delivery_key, $this->greater_accra() );

		$passed = $capture->validate_add_to_cart( true, PdpPrecisionTestKit::PRODUCT_ID, 1 );

		self::assertFalse( $passed );
		self::assertSame(
			CustomerStorefrontCopy::select_city_town_for_exact_fee(),
			$GLOBALS['cetech_de_test_notices'][0]['message'] ?? null
		);
	}

	public function test_classic_accepts_canonical_accra_delivery(): void {
		$capture = $this->capture();
		$this->post_classic( $this->delivery_key, $this->accra() );

		self::assertTrue( $capture->validate_add_to_cart( true, PdpPrecisionTestKit::PRODUCT_ID, 1 ) );
		$item = $capture->add_cart_item_data( [], PdpPrecisionTestKit::PRODUCT_ID, 0 );
		self::assertArrayHasKey( CartDeliverySelectionCapture::CART_SELECTION_KEY, $item );
		self::assertSame( FulfilmentChoice::Delivery->value, $item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ]['fulfilment_choice'] );
	}

	public function test_classic_pickup_is_not_blocked_by_delivery_precision(): void {
		$capture = $this->capture();
		$this->post_classic( $this->pickup_key, $this->greater_accra() );

		self::assertTrue( $capture->validate_add_to_cart( true, PdpPrecisionTestKit::PRODUCT_ID, 1 ) );
		$item = $capture->add_cart_item_data( [], PdpPrecisionTestKit::PRODUCT_ID, 0 );
		self::assertArrayHasKey( CartDeliverySelectionCapture::CART_SELECTION_KEY, $item );
		self::assertSame( FulfilmentChoice::StorePickup->value, $item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ]['fulfilment_choice'] );
	}

	public function test_store_api_rejects_precision_incomplete_delivery(): void {
		$capture = $this->capture();
		$GLOBALS['cetech_de_blocks_delivery_option_key'] = $this->delivery_key;
		add_filter( 'cetech_de_submitted_delivery_option_key', fn () => $this->delivery_key );
		add_filter( 'cetech_de_submitted_matching_location', fn () => $this->greater_accra() );

		self::assertFalse( $capture->validate_add_to_cart( true, PdpPrecisionTestKit::PRODUCT_ID, 1 ) );
		self::assertSame(
			CustomerStorefrontCopy::select_city_town_for_exact_fee(),
			$GLOBALS['cetech_de_test_notices'][0]['message'] ?? null
		);
	}

	public function test_store_api_accepts_canonical_accra_delivery(): void {
		$capture = $this->capture();
		$GLOBALS['cetech_de_blocks_delivery_option_key'] = $this->delivery_key;
		add_filter( 'cetech_de_submitted_delivery_option_key', fn () => $this->delivery_key );
		add_filter( 'cetech_de_submitted_matching_location', fn () => $this->accra() );

		self::assertTrue( $capture->validate_add_to_cart( true, PdpPrecisionTestKit::PRODUCT_ID, 1 ) );
	}

	public function test_add_cart_item_data_does_not_persist_ambiguous_delivery(): void {
		$capture = $this->capture();
		$this->post_classic( $this->delivery_key, $this->greater_accra() );

		$item = $capture->add_cart_item_data( [ 'existing' => 1 ], PdpPrecisionTestKit::PRODUCT_ID, 0 );

		self::assertSame( [ 'existing' => 1 ], $item );
		self::assertArrayNotHasKey( CartDeliverySelectionCapture::CART_SELECTION_KEY, $item );
		self::assertArrayNotHasKey( CartDeliverySelectionCapture::CART_SUMMARY_KEY, $item );
		self::assertArrayNotHasKey( CartDeliverySelectionCapture::CART_HASH_KEY, $item );
	}

	private function capture(): CartDeliverySelectionCapture {
		$fx      = ( new ShopperLocationPrecisionFixture() )->with_usable_gh_pack();
		$fx->add_greater_accra_entire_area();
		$fx->add_accra_selected_descendants();
		$capture = PdpPrecisionTestKit::capture( $fx->precision );
		foreach ( $capture->assess_product_selection( PdpPrecisionTestKit::PRODUCT_ID, 0 )['options'] as $option ) {
			if ( FulfilmentChoice::Delivery->value === $option->fulfilment_choice ) {
				$this->delivery_key = $option->display_key;
			}
			if ( FulfilmentChoice::StorePickup->value === $option->fulfilment_choice ) {
				$this->pickup_key = $option->display_key;
			}
		}
		self::assertNotSame( '', $this->delivery_key );
		self::assertNotSame( '', $this->pickup_key );

		return $capture;
	}

	private function post_classic( string $display_key, MatchingLocation $matching ): void {
		$_POST[ CartDeliverySelectionCapture::POST_FIELD ]               = $display_key;
		$_POST[ CartDeliverySelectionCapture::POST_MATCHING_COUNTRY ]    = $matching->country_identity;
		$_POST[ CartDeliverySelectionCapture::POST_MATCHING_STATE ]      = $matching->state_identity !== '' ? $matching->state_identity : $matching->state;
		$_POST[ CartDeliverySelectionCapture::POST_MATCHING_CITY ]       = $matching->city;
		$_POST[ CartDeliverySelectionCapture::POST_MATCHING_POSTCODE ]   = $matching->postcode;
		$_POST[ CartDeliverySelectionCapture::POST_MATCHING_LOCATION_KEY ] = $matching->canonical_location_key;
	}

	private function greater_accra(): MatchingLocation {
		return MatchingLocation::fromInput(
			[
				'country' => 'GH',
				'state'   => 'AA',
			]
		);
	}

	private function accra(): MatchingLocation {
		$fx = ( new ShopperLocationPrecisionFixture() )->with_usable_gh_pack();

		return $fx->matching_accra();
	}
}
