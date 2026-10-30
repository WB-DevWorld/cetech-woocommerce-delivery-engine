<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Cart;

use CetechDeliveryEngine\Application\Cart\CartDeliveryReselectionService;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Presentation\Frontend\CartDeliveryReselectionRenderer;
use CetechDeliveryEngine\Presentation\Frontend\CartExternalFormBuffer;
use CetechDeliveryEngine\Presentation\Shared\CartDeliveryUiAnchor;
use CetechDeliveryEngine\Tests\Support\PdpPrecisionTestKit;
use CetechDeliveryEngine\Tests\Support\ShopperLocationPrecisionFixture;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CartDeliveryReselectionRendererTest extends TestCase {

	protected function setUp(): void {
		$this->reset_state();
	}

	protected function tearDown(): void {
		$this->reset_state();
	}

	public function test_classic_cart_does_not_inject_block_on_valid_lines(): void {
		$renderer  = $this->renderer();
		$cart_item = [
			'product_id'   => 101,
			'variation_id' => 0,
		];

		ob_start();
		$renderer->render_after_cart_item_name( $cart_item, 'cart-key-1' );
		self::assertSame( '', (string) ob_get_clean() );

		self::assertSame( ' <strong class="product-quantity">&times;&nbsp;2</strong>', $renderer->append_to_item_quantity( ' <strong class="product-quantity">&times;&nbsp;2</strong>', $cart_item, 'cart-key-1' ) );
		self::assertSame( '', $renderer->block_for_line( $cart_item, 'cart-key-1' ) );
		self::assertSame( [], CartExternalFormBuffer::queued_ids() );
	}

	public function test_src_no_longer_asks_customers_to_remove_and_readd(): void {
		$plugin_root = dirname( __DIR__, 3 );
		$files       = [
			$plugin_root . '/src/Application/Cart/CartDeliverySelectionRevalidator.php',
			$plugin_root . '/src/Application/Checkout/CheckoutDeliverySelectionValidator.php',
			$plugin_root . '/src/Integrations/Blocks/BlocksCheckoutValidation.php',
			$plugin_root . '/src/Presentation/Frontend/CartDeliveryReselectionRenderer.php',
		];

		foreach ( $files as $file ) {
			$source = (string) file_get_contents( $file );
			self::assertStringNotContainsString( 'Please remove and re-add the product.', $source );
			self::assertStringNotContainsString( 'Please remove and re-add', $source );
		}
	}

	public function test_reselection_line_output_has_no_nested_form(): void {
		$renderer = $this->renderer();
		$html     = $renderer->render_reselection( 'line-reselect', [ $this->delivery_option() ] );
		$form_id  = CartDeliveryUiAnchor::reselection_form_id_for_cart_item_key( 'line-reselect' );

		self::assertStringNotContainsString( '<form', $html );
		self::assertStringContainsString( 'form="' . $form_id . '"', $html );
		self::assertStringContainsString( 'Update delivery option', $html );

		$shells = CartExternalFormBuffer::drain();
		self::assertStringContainsString( 'id="' . $form_id . '"', $shells );
		self::assertStringContainsString( 'class="cetech-de-cart-reselection__form"', $shells );
		self::assertStringContainsString( 'name="' . CartDeliveryReselectionService::POST_CART_ITEM_KEY . '" value="line-reselect"', $shells );
		self::assertStringContainsString( "add_action( 'woocommerce_after_cart'", (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Presentation/Frontend/CartDeliveryReselectionRenderer.php' ) );
	}

	/**
	 * The real failure mode (M2 acceptance C10/C13 on training): the cart, checkout
	 * review and mini-cart templates print the `woocommerce_cart_item_name` filter
	 * through wp_kses_post(), which drops the radios and the button's form binding.
	 * Anything appended to that filter is unusable, whatever the renderer returns.
	 */
	public function test_block_passed_through_wp_kses_post_loses_its_controls(): void {
		$renderer = $this->renderer();
		$html     = $renderer->render_reselection( 'kses-line', [ $this->delivery_option(), $this->pickup_option() ] );
		$form_id  = CartDeliveryUiAnchor::reselection_form_id_for_cart_item_key( 'kses-line' );

		self::assertSame( 2, substr_count( $html, '<input type="radio"' ) );
		self::assertStringContainsString( '<button type="submit" class="button" form="' . $form_id . '">', $html );

		$as_template_prints_it = self::wp_kses_post_like_wordpress( $html );

		self::assertStringNotContainsString( '<input', $as_template_prints_it );
		self::assertStringNotContainsString( 'form="' . $form_id . '"', $as_template_prints_it );
		self::assertStringContainsString( 'Delivery options for this item have changed.', $as_template_prints_it );
		self::assertStringContainsString( 'QA Local Standard', $as_template_prints_it );
		self::assertStringContainsString( 'Update delivery option', $as_template_prints_it );
	}

	public function test_register_prints_controls_through_hooks_woocommerce_echoes_unfiltered(): void {
		$renderer = $this->renderer( true );
		self::assertTrue( $renderer->is_active() );

		$renderer->register();

		self::assertArrayNotHasKey( 'woocommerce_cart_item_name', $GLOBALS['cetech_de_test_filters'] );
		self::assertArrayNotHasKey( 'woocommerce_cart_item_name', $GLOBALS['cetech_de_test_actions'] );
		self::assertStringNotContainsString(
			"add_filter( 'woocommerce_cart_item_name'",
			(string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Presentation/Frontend/CartDeliveryReselectionRenderer.php' )
		);

		self::assertSame( 'woocommerce_after_cart_item_name', CartDeliveryReselectionRenderer::CART_LINE_ACTION );
		self::assertSame( 'woocommerce_checkout_cart_item_quantity', CartDeliveryReselectionRenderer::CHECKOUT_LINE_FILTER );
		self::assertSame( 'woocommerce_widget_cart_item_quantity', CartDeliveryReselectionRenderer::MINI_CART_LINE_FILTER );

		$cart_actions = $GLOBALS['cetech_de_test_actions']['woocommerce_after_cart_item_name'] ?? [];
		self::assertCount( 1, $cart_actions );
		self::assertSame( [ $renderer, 'render_after_cart_item_name' ], $cart_actions[0]['callback'] );
		self::assertSame( 2, $cart_actions[0]['args'] );

		self::assertSame( [ [ $renderer, 'append_to_item_quantity' ] ], $GLOBALS['cetech_de_test_filters']['woocommerce_checkout_cart_item_quantity'] ?? [] );
		self::assertSame( [ [ $renderer, 'append_to_item_quantity' ] ], $GLOBALS['cetech_de_test_filters']['woocommerce_widget_cart_item_quantity'] ?? [] );

		foreach ( [ 'woocommerce_after_cart', 'woocommerce_after_checkout_form', 'woocommerce_after_mini_cart' ] as $drain_hook ) {
			self::assertSame( [ $renderer, 'render_deferred_forms' ], $GLOBALS['cetech_de_test_actions'][ $drain_hook ][0]['callback'] ?? null, $drain_hook );
		}
	}

	public function test_cart_action_echoes_radios_bound_to_the_deferred_form(): void {
		$renderer  = $this->renderer( true );
		$cart_item = $this->line_needing_reselection();
		$form_id   = CartDeliveryUiAnchor::reselection_form_id_for_cart_item_key( 'line-7' );
		$expected  = $this->available_display_keys();
		self::assertCount( 2, $expected, 'fixture offers Delivery and Store Pickup' );

		ob_start();
		$renderer->render_after_cart_item_name( $cart_item, 'line-7' );
		$printed = (string) ob_get_clean();

		self::assertStringContainsString( 'class="cetech-de-cart-reselection"', $printed );
		self::assertStringContainsString( 'Delivery options for this item have changed. Choose an option to continue.', $printed );
		self::assertSame( 2, preg_match_all( '/<input type="radio" id="([^"]+)" name="' . preg_quote( CartDeliverySelectionCapture::POST_FIELD, '/' ) . '" value="([^"]+)" form="' . preg_quote( $form_id, '/' ) . '" required \/>/', $printed, $radios ) );
		self::assertSame( $expected, $radios[2] );

		foreach ( $radios[1] as $radio_id ) {
			self::assertStringContainsString( 'for="' . $radio_id . '"', $printed, 'every label points at a radio that exists' );
		}

		self::assertStringContainsString( '<button type="submit" class="button" form="' . $form_id . '">Update delivery option</button>', $printed );
		self::assertStringNotContainsString( '<form', $printed );

		ob_start();
		$renderer->render_deferred_forms();
		$deferred = (string) ob_get_clean();

		self::assertStringContainsString( '<form id="' . $form_id . '" class="cetech-de-cart-reselection__form" method="post"', $deferred );
		self::assertStringContainsString( 'name="' . CartDeliveryReselectionService::POST_ACTION . '" value="1"', $deferred );
		self::assertStringContainsString( 'name="' . CartDeliveryReselectionService::POST_CART_ITEM_KEY . '" value="line-7"', $deferred );
		self::assertStringContainsString( 'name="_wpnonce"', $deferred );
	}

	public function test_checkout_and_mini_cart_filters_keep_the_quantity_and_add_the_controls(): void {
		$renderer  = $this->renderer( true );
		$cart_item = $this->line_needing_reselection();
		$form_id   = CartDeliveryUiAnchor::reselection_form_id_for_cart_item_key( 'line-7' );
		$quantity  = ' <strong class="product-quantity">&times;&nbsp;3</strong>';

		$checkout = $renderer->append_to_item_quantity( $quantity, $cart_item, 'line-7' );

		self::assertStringStartsWith( $quantity, $checkout );
		self::assertSame( 2, substr_count( $checkout, '<input type="radio"' ) );
		self::assertSame( 2, substr_count( $checkout, 'form="' . $form_id . '" required' ) );
		self::assertStringContainsString( '<button type="submit" class="button" form="' . $form_id . '">', $checkout );
		self::assertSame( [ $form_id ], CartExternalFormBuffer::queued_ids() );

		CartExternalFormBuffer::reset();

		$mini_cart = $renderer->append_to_item_quantity( '<span class="quantity">3 &times; &#8373;45.00</span>', $cart_item, 'line-7' );

		self::assertStringStartsWith( '<span class="quantity">3 &times; &#8373;45.00</span>', $mini_cart );
		self::assertSame( 2, substr_count( $mini_cart, 'form="' . $form_id . '" required' ) );
		self::assertSame( [ $form_id ], CartExternalFormBuffer::queued_ids() );
	}

	public function test_line_without_available_options_prints_the_unavailable_status_only(): void {
		$renderer  = $this->renderer( true );
		$cart_item = $this->line_needing_reselection( 999 );

		ob_start();
		$renderer->render_after_cart_item_name( $cart_item, 'line-7' );
		$printed = (string) ob_get_clean();

		self::assertStringContainsString( 'role="status"', $printed );
		self::assertStringContainsString( 'Delivery is currently unavailable for this product.', $printed );
		self::assertStringNotContainsString( '<input', $printed );
		self::assertSame( [], CartExternalFormBuffer::queued_ids() );
	}

	private function reset_state(): void {
		CartExternalFormBuffer::reset();
		$GLOBALS['cetech_de_test_options']     = [];
		$GLOBALS['cetech_de_test_filters']     = [];
		$GLOBALS['cetech_de_test_actions']     = [];
		$GLOBALS['cetech_de_test_wc_products'] = [];
	}

	private function renderer( bool $active = false ): CartDeliveryReselectionRenderer {
		if ( ! $active ) {
			$capture = ( new ReflectionClass( CartDeliverySelectionCapture::class ) )->newInstanceWithoutConstructor();

			return new CartDeliveryReselectionRenderer( new FeatureFlags(), new Requirements(), $capture );
		}

		if ( ! class_exists( 'WooCommerce', false ) ) {
			eval( 'class WooCommerce {}' ); // phpcs:ignore Squiz.PHP.Eval -- test bootstrap only.
		}

		PdpPrecisionTestKit::seed_simple_product();
		$fx = ( new ShopperLocationPrecisionFixture() )->with_usable_gh_pack();

		return new CartDeliveryReselectionRenderer( PdpPrecisionTestKit::enable_flags(), new Requirements(), PdpPrecisionTestKit::capture( $fx->precision ) );
	}

	/**
	 * @return list<string>
	 */
	private function available_display_keys(): array {
		$fx      = ( new ShopperLocationPrecisionFixture() )->with_usable_gh_pack();
		$capture = PdpPrecisionTestKit::capture( $fx->precision );
		$keys    = [];

		foreach ( $capture->assess_product_selection( PdpPrecisionTestKit::PRODUCT_ID, 0 )['options'] as $option ) {
			if ( $option->is_available ) {
				$keys[] = $option->display_key;
			}
		}

		return $keys;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function line_needing_reselection( int $product_id = PdpPrecisionTestKit::PRODUCT_ID ): array {
		return [
			'product_id'   => $product_id,
			'variation_id' => 0,
			CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY => true,
		];
	}

	private function delivery_option(): ProductDeliveryOption {
		return new ProductDeliveryOption(
			'in_warehouse:delivery:10',
			'in_warehouse',
			'Availability',
			FulfilmentChoice::Delivery->value,
			'Choice',
			10,
			'QA Local Standard',
			null,
			'2-4 days',
			true,
			null,
			ProductDeliveryOption::CONTRACT_VERSION,
			false,
			null,
			null,
			null,
			null
		);
	}

	private function pickup_option(): ProductDeliveryOption {
		return new ProductDeliveryOption(
			'in_store:pickup:p3',
			'in_store',
			'Availability',
			FulfilmentChoice::StorePickup->value,
			'Choice',
			null,
			'',
			null,
			'Same day',
			true,
			null,
			ProductDeliveryOption::CONTRACT_VERSION,
			false,
			'QA Pickup Point 2',
			null,
			null,
			3
		);
	}

	/**
	 * Applies the two wp_kses_post() rules that matter for this block, as WordPress
	 * core's post allowlist defines them (wp-includes/kses.php, $allowedposttags):
	 * `input` is not an allowed element, so the tag is removed; `button` is allowed
	 * with disabled/name/type/value plus the global attributes, so `form` is removed.
	 * The unit bootstrap's wp_kses_post() stub is an identity function and cannot
	 * show this.
	 */
	private static function wp_kses_post_like_wordpress( string $html ): string {
		$without_inputs = (string) preg_replace( '/<input\b[^>]*>/i', '', $html );

		return (string) preg_replace_callback(
			'/<button\b[^>]*>/i',
			static fn ( array $match ): string => (string) preg_replace( '/\s+(?:form|formaction|formmethod)="[^"]*"/i', '', $match[0] ),
			$without_inputs
		);
	}
}
