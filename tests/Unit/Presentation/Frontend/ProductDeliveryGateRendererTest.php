<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation\Frontend;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
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

/**
 * M2 D2: the product page gates on Delivery vs Store Pickup, pre-selects only a lone valid
 * choice, and collapses a chosen card to one compact line with a single "Change" control.
 */
final class ProductDeliveryGateRendererTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options'] = [];
		$GLOBALS['cetech_de_test_notices'] = [];

		if ( ! class_exists( 'WooCommerce', false ) ) {
			eval( 'class WooCommerce {}' ); // phpcs:ignore Squiz.PHP.Eval -- test bootstrap only.
		}

		if ( ! function_exists( 'get_woocommerce_currency' ) ) {
			eval( 'function get_woocommerce_currency(): string { return "GHS"; }' ); // phpcs:ignore Squiz.PHP.Eval -- test bootstrap only.
		}

		PdpPrecisionTestKit::enable_quote_runtime();
		PdpPrecisionTestKit::seed_simple_product();
		$_POST = [];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cetech_de_test_wc'] );
		$_POST = [];
	}

	public function test_both_choices_valid_offers_both_and_pre_selects_nothing(): void {
		$html = $this->render( [ $this->priced_delivery(), $this->pickup() ] );

		self::assertStringContainsString( 'data-cetech-de-active-choice=""', $html );
		self::assertStringNotContainsString( 'data-cetech-de-collapsed', $html );
		self::assertMatchesRegularExpression( '/id="cetech-de-fulfilment-ui-delivery"(?![^>]*(checked|disabled))/', $html );
		self::assertMatchesRegularExpression( '/id="cetech-de-fulfilment-ui-store_pickup"(?![^>]*(checked|disabled))/', $html );
		self::assertStringNotContainsString( CustomerStorefrontCopy::pickup_not_available(), $html );
		self::assertStringNotContainsString( CustomerStorefrontCopy::delivery_not_available(), $html );
		self::assertMatchesRegularExpression( '/data-cetech-de-choice-panel="delivery"[^>]*\bhidden\b/', $html );
		self::assertMatchesRegularExpression( '/data-cetech-de-choice-panel="store_pickup"[^>]*\bhidden\b/', $html );
		self::assertDoesNotMatchRegularExpression( '/name="cetech_de_delivery_option_key"[^>]*checked/', $html );
		self::assertMatchesRegularExpression( '/data-cetech-de-selection-line="1"[^>]*\bhidden\b/', $html );
		self::assertSame( 1, substr_count( $html, 'data-cetech-de-change="1"' ), 'exactly one Change control' );
	}

	public function test_delivery_only_pre_selects_delivery_and_explains_missing_pickup(): void {
		$html = $this->render( [ $this->priced_delivery() ] );

		self::assertStringContainsString( 'data-cetech-de-active-choice="delivery"', $html );
		self::assertMatchesRegularExpression( '/id="cetech-de-fulfilment-ui-delivery"[^>]*checked="checked"/', $html );
		self::assertMatchesRegularExpression( '/id="cetech-de-fulfilment-ui-store_pickup"[^>]*disabled="disabled"/', $html );
		self::assertStringContainsString( '>' . CustomerStorefrontCopy::pickup_not_available() . '<', $html );
		self::assertDoesNotMatchRegularExpression( '/data-cetech-de-choice-panel="delivery"[^>]*\bhidden\b/', $html );
		self::assertStringContainsString( 'data-cetech-de-locality="Accra"', $html );
		// The lone card is pre-selected and the chooser renders collapsed to the compact line.
		self::assertMatchesRegularExpression( '/value="in_store:delivery:10"[^>]*checked="checked"/', $html );
		self::assertStringContainsString( 'data-cetech-de-collapsed="1"', $html );
		self::assertMatchesRegularExpression(
			'/data-cetech-de-selection-text="1">Standard Delivery · Accra · 2–4 business days · [^<]*50\.00</',
			$html,
			'one compact line: option · destination · estimate · fee'
		);
		self::assertStringContainsString( 'data-cetech-de-change="1">' . CustomerStorefrontCopy::change() . '<', $html );
		self::assertStringContainsString( 'data-cetech-de-price="', $html );
	}

	public function test_pickup_only_pre_selects_pickup_and_explains_missing_delivery(): void {
		$html = $this->render( [ $this->pickup() ] );

		self::assertStringContainsString( 'data-cetech-de-active-choice="store_pickup"', $html );
		self::assertMatchesRegularExpression( '/id="cetech-de-fulfilment-ui-store_pickup"[^>]*checked="checked"/', $html );
		self::assertMatchesRegularExpression( '/id="cetech-de-fulfilment-ui-delivery"[^>]*disabled="disabled"/', $html );
		self::assertStringContainsString( '>' . CustomerStorefrontCopy::delivery_not_available() . '<', $html );
		self::assertMatchesRegularExpression( '/value="in_store:store_pickup:pickup"[^>]*checked="checked"/', $html );
		self::assertStringContainsString( 'data-cetech-de-collapsed="1"', $html );
		self::assertStringContainsString( 'data-cetech-de-selection-text="1">Store Pickup — Accra showroom · Free<', $html );
	}

	public function test_posted_card_repopulates_its_choice_and_the_compact_line(): void {
		$_POST[ CartDeliverySelectionCapture::POST_FIELD ] = 'in_store:store_pickup:pickup';

		$html = $this->render( [ $this->priced_delivery(), $this->pickup() ] );

		self::assertStringContainsString( 'data-cetech-de-active-choice="store_pickup"', $html );
		self::assertMatchesRegularExpression( '/id="cetech-de-fulfilment-ui-store_pickup"[^>]*checked="checked"/', $html );
		self::assertDoesNotMatchRegularExpression( '/id="cetech-de-fulfilment-ui-delivery"[^>]*checked/', $html );
		self::assertMatchesRegularExpression( '/value="in_store:store_pickup:pickup"[^>]*checked="checked"/', $html );
		self::assertMatchesRegularExpression( '/data-cetech-de-choice-panel="delivery"[^>]*\bhidden\b/', $html );
		self::assertStringContainsString( 'data-cetech-de-selection-text="1">Store Pickup — Accra showroom · Free<', $html );
	}

	/**
	 * @param list<ProductDeliveryOption> $options
	 */
	private function render( array $options ): string {
		$fx = ( new ShopperLocationPrecisionFixture() )->with_usable_gh_pack();
		$fx->add_greater_accra_entire_area();
		$fx->add_accra_selected_descendants();
		$this->remember( $fx->matching_accra() );

		$renderer = new ProductDeliverySelectorRenderer(
			new FeatureFlags(),
			new Requirements(),
			PdpPrecisionTestKit::source(),
			PdpPrecisionTestKit::builder(),
			new CustomerBrowsingLocationStore(),
			PdpPrecisionTestKit::location_aware_quote( $fx ),
			$fx->precision
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
			true
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
