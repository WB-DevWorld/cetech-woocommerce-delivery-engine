<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Cart;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Presentation\Frontend\CartDeliveryReselectionRenderer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CartDeliveryReselectionRendererTest extends TestCase {

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
}
