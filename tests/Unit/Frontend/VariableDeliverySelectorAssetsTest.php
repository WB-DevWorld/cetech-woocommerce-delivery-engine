<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Frontend;

use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Presentation\Frontend\VariableDeliverySelectorAssets;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WC_Product;

/**
 * Stage 6B-3R: variable selector assets must enqueue for product-page testing before cart capture.
 */
final class VariableDeliverySelectorAssetsTest extends TestCase {

	private FeatureFlags $feature_flags;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['cetech_de_test_options']          = [];
		$GLOBALS['cetech_de_test_wc_products']      = [];
		$GLOBALS['cetech_de_test_is_product']       = false;
		$GLOBALS['cetech_de_test_the_id']           = 0;
		$GLOBALS['cetech_de_test_global_product']   = null;
		$GLOBALS['cetech_de_test_enqueued_scripts'] = [];
		$GLOBALS['cetech_de_test_enqueued_styles']  = [];

		$this->feature_flags = new FeatureFlags();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['cetech_de_test_is_product'],
			$GLOBALS['cetech_de_test_the_id'],
			$GLOBALS['cetech_de_test_global_product'],
			$GLOBALS['cetech_de_test_enqueued_scripts'],
			$GLOBALS['cetech_de_test_enqueued_styles']
		);

		parent::tearDown();
	}

	public function test_eligible_variable_product_enqueues_assets_when_downstream_flags_off(): void {
		$this->enable_variable_product_page_flags();
		$this->set_variable_product_context( 39717 );

		$assets = new VariableDeliverySelectorAssets( $this->feature_flags, new Requirements() );
		$assets->enqueue();

		self::assertContains( VariableDeliverySelectorAssets::HANDLE, $GLOBALS['cetech_de_test_enqueued_scripts'] );
		self::assertContains( VariableDeliverySelectorAssets::HANDLE, $GLOBALS['cetech_de_test_enqueued_styles'] );
		self::assertContains(
			\CetechDeliveryEngine\Presentation\Frontend\ProductDeliverySelectorRenderer::STYLE_HANDLE,
			$GLOBALS['cetech_de_test_enqueued_styles']
		);
	}

	public function test_should_enqueue_true_for_eligible_variable_product_without_cart_capture(): void {
		$this->enable_variable_product_page_flags();
		$this->set_variable_product_context( 39717 );

		self::assertTrue( $this->should_enqueue( new VariableDeliverySelectorAssets( $this->feature_flags, new Requirements() ) ) );
	}

	public function test_variable_ecr_off_does_not_enqueue(): void {
		$this->enable_variable_product_page_flags();
		$this->feature_flags->set( 'enable_variable_product_ecr_runtime', false );
		$this->set_variable_product_context( 39717 );

		self::assertFalse( $this->should_enqueue( new VariableDeliverySelectorAssets( $this->feature_flags, new Requirements() ) ) );
	}

	public function test_main_ecr_off_does_not_enqueue(): void {
		$this->enable_variable_product_page_flags();
		$this->feature_flags->set( 'enable_effective_configuration_runtime', false );
		$this->set_variable_product_context( 39717 );

		self::assertFalse( $this->should_enqueue( new VariableDeliverySelectorAssets( $this->feature_flags, new Requirements() ) ) );
	}

	public function test_selector_off_does_not_enqueue(): void {
		$this->enable_variable_product_page_flags();
		$this->feature_flags->set( 'enable_product_delivery_selector', false );
		$this->set_variable_product_context( 39717 );

		self::assertFalse( $this->should_enqueue( new VariableDeliverySelectorAssets( $this->feature_flags, new Requirements() ) ) );
	}

	public function test_simple_product_does_not_enqueue(): void {
		$this->enable_variable_product_page_flags();
		$this->set_product_context( 39705, 'simple' );

		self::assertFalse( $this->should_enqueue( new VariableDeliverySelectorAssets( $this->feature_flags, new Requirements() ) ) );
	}

	public function test_cart_checkout_shipping_snapshot_flags_off_still_enqueue(): void {
		$this->enable_variable_product_page_flags();
		$this->feature_flags->set( 'enable_cart_delivery_selection_capture', false );
		$this->feature_flags->set( 'enable_checkout_delivery_selection_validation', false );
		$this->feature_flags->set( 'enable_woocommerce_shipping_rate_calculation', false );
		$this->feature_flags->set( 'enable_order_delivery_snapshot_persistence', false );
		$this->set_variable_product_context( 39717 );

		$assets = new VariableDeliverySelectorAssets( $this->feature_flags, new Requirements() );
		$assets->enqueue();

		self::assertTrue( $this->should_enqueue( $assets ) );
		self::assertContains( VariableDeliverySelectorAssets::HANDLE, $GLOBALS['cetech_de_test_enqueued_scripts'] );
	}

	private function enable_variable_product_page_flags(): void {
		$this->feature_flags->set( 'enable_product_delivery_selector', true );
		$this->feature_flags->set( 'enable_effective_configuration_runtime', true );
		$this->feature_flags->set( 'enable_variable_product_ecr_runtime', true );
	}

	private function set_variable_product_context( int $product_id ): void {
		$this->set_product_context( $product_id, 'variable' );
	}

	private function set_product_context( int $product_id, string $type ): void {
		$GLOBALS['cetech_de_test_is_product']     = true;
		$GLOBALS['cetech_de_test_the_id']         = $product_id;
		$GLOBALS['cetech_de_test_global_product'] = new WC_Product(
			[
				'id'   => $product_id,
				'type' => $type,
			]
		);
		$GLOBALS['cetech_de_test_wc_products'][ $product_id ] = $GLOBALS['cetech_de_test_global_product'];
	}

	private function should_enqueue( VariableDeliverySelectorAssets $assets ): bool {
		$method = new ReflectionMethod( VariableDeliverySelectorAssets::class, 'should_enqueue' );
		$method->setAccessible( true );

		return (bool) $method->invoke( $assets );
	}
}
