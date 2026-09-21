<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation\Frontend;

use CetechDeliveryEngine\Application\CustomerContext\CustomerBrowsingLocationStore;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Presentation\Frontend\ProductDeliverySelectorRenderer;
use CetechDeliveryEngine\Presentation\Shared\CustomerStorefrontCopy;
use CetechDeliveryEngine\Tests\Support\PdpPrecisionTestKit;
use CetechDeliveryEngine\Tests\Support\ShopperLocationPrecisionFixture;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ProductDeliverySelectorPrecisionRendererTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options'] = [];
		$GLOBALS['cetech_de_test_notices'] = [];

		if ( ! class_exists( 'WooCommerce', false ) ) {
			eval( 'class WooCommerce {}' ); // phpcs:ignore Squiz.PHP.Eval -- test bootstrap only.
		}

		if ( ! function_exists( 'get_woocommerce_currency' ) ) {
			eval( 'function get_woocommerce_currency(): string { return "GHS"; }' ); // phpcs:ignore Squiz.PHP.Eval -- test bootstrap only.
		}
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cetech_de_test_wc'] );
		$_POST = [];
	}

	public function test_initial_html_suppresses_broad_delivery_price_and_shows_city(): void {
		$fx = ( new ShopperLocationPrecisionFixture() )->with_usable_gh_pack();
		$fx->add_greater_accra_entire_area();
		$fx->add_accra_selected_descendants();
		$this->remember( $fx->matching_greater_accra() );

		$html = $this->render( $fx, [ $this->priced_delivery(), $this->pickup() ] );

		self::assertStringContainsString( 'data-cetech-de-reveal="locality"', $html );
		self::assertDoesNotMatchRegularExpression( '/data-cetech-de-reveal="locality"[^>]*\bhidden\b/', $html );
		self::assertStringContainsString( CustomerStorefrontCopy::select_city_town_for_exact_fee(), $html );
		self::assertStringNotContainsString( 'GH₵30.00', $html );
		self::assertStringNotContainsString( 'in_store:delivery:10" checked', $html );
		self::assertStringNotContainsString( 'value="in_store:delivery:10"', $html );
		self::assertStringContainsString( 'Accra showroom', $html );
		self::assertStringContainsString( 'role="status"', $html );
		self::assertStringContainsString( 'aria-live="polite"', $html );
	}

	public function test_canonical_accra_renders_authoritative_delivery_price(): void {
		$fx = ( new ShopperLocationPrecisionFixture() )->with_usable_gh_pack();
		$fx->add_greater_accra_entire_area();
		$fx->add_accra_selected_descendants();
		$this->remember( $fx->matching_accra() );

		$html = $this->render( $fx, [ $this->priced_delivery(), $this->pickup() ] );

		self::assertStringContainsString( 'GH₵30.00', $html );
		self::assertStringContainsString( 'value="in_store:delivery:10"', $html );
		self::assertStringNotContainsString( CustomerStorefrontCopy::select_city_town_for_exact_fee(), $html );
	}

	/**
	 * @param list<ProductDeliveryOption> $options
	 */
	private function render( ShopperLocationPrecisionFixture $fx, array $options ): string {
		$precision = $fx->precision;
		$renderer  = new ProductDeliverySelectorRenderer(
			new FeatureFlags(),
			new Requirements(),
			PdpPrecisionTestKit::source(),
			PdpPrecisionTestKit::builder(),
			new CustomerBrowsingLocationStore(),
			PdpPrecisionTestKit::always_quote_ghana( $precision ),
			$precision
		);
		$method = new ReflectionMethod( ProductDeliverySelectorRenderer::class, 'render_interactive_options' );
		if ( \PHP_VERSION_ID < 80500 ) {
			$method->setAccessible( true );
		}
		ob_start();
		$method->invoke( $renderer, $options, PdpPrecisionTestKit::PRODUCT_ID );

		return (string) ob_get_clean();
	}

	private function remember( \CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation $matching ): void {
		$session = new class() {
			/** @var array<string, mixed> */
			public array $data = [];

			public function get( string $key ) {
				return $this->data[ $key ] ?? null;
			}

			public function set( string $key, $value ): void {
				$this->data[ $key ] = $value;
			}
		};
		$GLOBALS['cetech_de_test_wc'] = (object) [ 'session' => $session ];
		( new CustomerBrowsingLocationStore() )->save( $matching );
	}

	private function priced_delivery(): ProductDeliveryOption {
		return new ProductDeliveryOption(
			'in_store:delivery:10',
			'in_store',
			'In store',
			FulfilmentChoice::Delivery->value,
			'Delivery',
			PdpPrecisionTestKit::DELIVERY_OFFER_ID,
			'Standard Delivery',
			null,
			'2–4 business days',
			true,
			null,
			ProductDeliveryOption::CONTRACT_VERSION,
			true,
			null,
			null,
			null,
			null,
			'30.0000',
			'GHS',
			'GH₵30.00',
			'per_shipment'
		);
	}

	private function pickup(): ProductDeliveryOption {
		return new ProductDeliveryOption(
			'in_store:store_pickup:pickup',
			'in_store',
			'In store',
			FulfilmentChoice::StorePickup->value,
			'Store pickup',
			null,
			'Accra showroom',
			null,
			null,
			true,
			null,
			ProductDeliveryOption::CONTRACT_VERSION,
			false,
			'Accra showroom',
			null,
			null,
			PdpPrecisionTestKit::PICKUP_ID
		);
	}
}
