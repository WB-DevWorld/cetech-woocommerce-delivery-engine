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
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CartDeliveryReselectionRendererTest extends TestCase {

	protected function setUp(): void {
		CartExternalFormBuffer::reset();
	}

	protected function tearDown(): void {
		CartExternalFormBuffer::reset();
	}

	public function test_classic_cart_does_not_inject_form_on_valid_lines(): void {
		$capture  = ( new ReflectionClass( CartDeliverySelectionCapture::class ) )->newInstanceWithoutConstructor();
		$renderer = new CartDeliveryReselectionRenderer(
			new FeatureFlags(),
			new Requirements(),
			$capture
		);

		self::assertSame(
			'Cable',
			$renderer->append_reselection_form(
				'Cable',
				[
					'product_id'   => 101,
					'variation_id' => 0,
				],
				'cart-key-1'
			)
		);
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
		$capture  = ( new ReflectionClass( CartDeliverySelectionCapture::class ) )->newInstanceWithoutConstructor();
		$renderer = new CartDeliveryReselectionRenderer( new FeatureFlags(), new Requirements(), $capture );
		$option   = new ProductDeliveryOption(
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

		$html    = $renderer->render_reselection( 'line-reselect', [ $option ] );
		$form_id = CartDeliveryUiAnchor::reselection_form_id_for_cart_item_key( 'line-reselect' );

		self::assertStringNotContainsString( '<form', $html );
		self::assertStringContainsString( 'form="' . $form_id . '"', $html );
		self::assertStringContainsString( 'Update delivery option', $html );

		$shells = CartExternalFormBuffer::drain();
		self::assertStringContainsString( 'id="' . $form_id . '"', $shells );
		self::assertStringContainsString( 'class="cetech-de-cart-reselection__form"', $shells );
		self::assertStringContainsString( 'name="' . CartDeliveryReselectionService::POST_CART_ITEM_KEY . '" value="line-reselect"', $shells );
		self::assertStringContainsString( "add_action( 'woocommerce_after_cart'", (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Presentation/Frontend/CartDeliveryReselectionRenderer.php' ) );
	}
}
