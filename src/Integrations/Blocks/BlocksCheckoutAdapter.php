<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\Blocks;

use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Integrations\Registry\IntegrationInterface;

/**
 * WooCommerce Cart & Checkout Blocks adapter.
 *
 * Classic checkout hooks remain registered separately and are not gated here.
 */
final class BlocksCheckoutAdapter implements IntegrationInterface {

	public const KEY = 'blocks';

	public const NAMESPACE = 'cetech-delivery-engine';

	public const SCRIPT_HANDLE = 'cetech-de-blocks-checkout';

	public const STYLE_HANDLE = 'cetech-de-blocks-checkout';

	public function __construct(
		private Requirements $requirements,
		private BlocksStoreApiExtension $store_api,
		private BlocksCheckoutValidation $validation,
		private BlocksAddToCartBridge $add_to_cart,
		private BlocksUsageDetector $usage
	) {
	}

	public function getKey(): string {
		return self::KEY;
	}

	public function is_implemented(): bool {
		return true;
	}

	public function isAvailable(): bool {
		if ( ! $this->requirements->is_woocommerce_active() ) {
			return false;
		}

		return class_exists( '\Automattic\WooCommerce\Blocks\Package' )
			|| class_exists( '\Automattic\WooCommerce\StoreApi\StoreApi' )
			|| function_exists( 'woocommerce_store_api_register_endpoint_data' );
	}

	public function register(): void {
		if ( ! $this->isAvailable() ) {
			return;
		}

		$this->store_api->register();
		$this->validation->register();
		$this->add_to_cart->register();

		add_action( 'woocommerce_blocks_loaded', [ $this, 'register_blocks_integration' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function register_blocks_integration(): void {
		$register = static function ( $registry ): void {
			if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
				return;
			}

			$registry->register( new BlocksScriptIntegration() );
		};

		add_action( 'woocommerce_blocks_cart_block_registration', $register );
		add_action( 'woocommerce_blocks_checkout_block_registration', $register );
	}

	public function enqueue_assets(): void {
		if ( ! $this->should_enqueue() ) {
			return;
		}

		BlocksScriptIntegration::register_assets();
		wp_enqueue_script( self::SCRIPT_HANDLE );
		wp_enqueue_style( self::STYLE_HANDLE );

		$version = defined( 'CETECH_DE_VERSION' ) ? CETECH_DE_VERSION : '0';
		$base    = defined( 'CETECH_DE_URL' ) ? CETECH_DE_URL : '';

		wp_enqueue_style(
			'cetech-de-cart-delivery-reselection',
			$base . 'assets/frontend/cart-delivery-reselection.css',
			[ self::STYLE_HANDLE ],
			$version
		);
	}

	public function usage(): BlocksUsageDetector {
		return $this->usage;
	}

	private function should_enqueue(): bool {
		if ( $this->usage->is_in_use() ) {
			return true;
		}

		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return true;
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}

		if ( function_exists( 'has_block' ) && ( has_block( 'woocommerce/cart' ) || has_block( 'woocommerce/checkout' ) ) ) {
			return true;
		}

		return false;
	}
}
