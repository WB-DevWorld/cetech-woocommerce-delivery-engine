<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Cart;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartLineDeliveryPriceResolver;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\CustomerContext\DeliveryAddress;
use CetechDeliveryEngine\Tests\Support\PdpPrecisionTestKit;
use CetechDeliveryEngine\Tests\Support\ShopperLocationPrecisionFixture;
use CetechDeliveryEngine\Tests\Unit\CustomerContext\PerItemContextFixtures;
use PHPUnit\Framework\TestCase;

/**
 * M2 D2: the cart and checkout compact line shows the fee the product page quoted for that line.
 */
final class CartLineDeliveryPriceResolverTest extends TestCase {

	private ShopperLocationPrecisionFixture $fx;

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options'] = [];

		if ( ! class_exists( 'WooCommerce', false ) ) {
			eval( 'class WooCommerce {}' ); // phpcs:ignore Squiz.PHP.Eval -- test bootstrap only.
		}

		if ( ! function_exists( 'get_woocommerce_currency' ) ) {
			// Same store currency the earliest suite definer (Checkout) uses; later suites expect it.
			eval( 'function get_woocommerce_currency(): string { return "USD"; }' ); // phpcs:ignore Squiz.PHP.Eval -- test bootstrap only.
		}

		PdpPrecisionTestKit::enable_quote_runtime();
		PdpPrecisionTestKit::seed_simple_product();
		$this->fx = ( new ShopperLocationPrecisionFixture() )->with_usable_gh_pack();
		$this->fx->add_greater_accra_entire_area();
		$this->fx->add_accra_selected_descendants();
	}

	public function test_delivery_line_reuses_the_product_page_quote_for_its_destination(): void {
		$resolver = $this->resolver();
		$item     = PerItemContextFixtures::cartItem(
			$this->intent( 'in_store:delivery:10', 'delivery', PdpPrecisionTestKit::DELIVERY_OFFER_ID ),
			CustomerCartContext::delivery(
				PdpPrecisionTestKit::DELIVERY_OFFER_ID,
				$this->fx->matching_accra(),
				DeliveryAddress::fromInput(
					array_merge(
						$this->fx->matching_accra()->toArray(),
						[ 'address_1' => '12 Boundary Rd', 'first_name' => 'Ama', 'last_name' => 'Mensah', 'phone' => '0244000000' ]
					)
				)
			),
			2
		);

		$price = $resolver->price_text_for( $item );

		self::assertStringContainsString( '50.00', $price, 'the Accra quote in the PDP fixture is 50.00, the same fee the product page showed' );
		self::assertSame( [ 'a' => $price ], $resolver->price_texts_for_cart( [ 'a' => $item, 'b' => [ 'product_id' => 101 ] ] ) );
	}

	public function test_pickup_line_is_free_and_a_line_needing_reselection_has_no_fee(): void {
		$resolver = $this->resolver();
		$pickup   = [
			'product_id'   => PdpPrecisionTestKit::PRODUCT_ID,
			'variation_id' => 0,
			'quantity'     => 1,
			CartDeliverySelectionCapture::CART_SELECTION_KEY => $this->intent( 'in_store:store_pickup:pickup', 'store_pickup', null ),
		];

		self::assertSame( 'Free', $resolver->price_text_for( $pickup ) );

		$stale = $pickup;
		$stale[ CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY ] = true;
		self::assertSame( '', $resolver->price_text_for( $stale ) );
		self::assertSame( '', $resolver->price_text_for( [ 'product_id' => 101 ] ), 'a native line has no quoted fee' );
	}

	private function resolver(): CartLineDeliveryPriceResolver {
		return new CartLineDeliveryPriceResolver(
			PdpPrecisionTestKit::capture( $this->fx->precision ),
			PdpPrecisionTestKit::location_aware_quote( $this->fx )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function intent( string $display_key, string $choice, ?int $offer_id ): array {
		return [
			'contract_version'        => '1',
			'product_id'              => PdpPrecisionTestKit::PRODUCT_ID,
			'variation_id'            => 0,
			'target_type'             => 'product',
			'target_id'               => PdpPrecisionTestKit::PRODUCT_ID,
			'display_key'             => $display_key,
			'fulfilment_availability' => 'in_store',
			'fulfilment_choice'       => $choice,
			'delivery_offer_id'       => $offer_id,
			'rule_id'                 => null,
			'issued_at'               => '2026-10-29T00:00:00+00:00',
		];
	}
}
