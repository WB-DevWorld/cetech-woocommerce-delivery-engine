<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Frontend;

use CetechDeliveryEngine\Application\Runtime\ProductDeliveryRuntimeConfigurationRouter;
use CetechDeliveryEngine\Application\Selector\VariationDeliveryOptionsEndpoint;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use WC_Product;

/**
 * Conditionally enqueues Stage 6 variable-product delivery selector assets.
 *
 * Loaded only on variable product pages when selector + main ECR + variable ECR flags are on.
 * Does not require cart capture, checkout, shipping, or snapshot flags.
 */
final class VariableDeliverySelectorAssets {

	public const HANDLE = 'cetech-de-variable-delivery-selector';

	public function __construct(
		private FeatureFlags $feature_flags,
		private Requirements $requirements
	) {
	}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue(): void {
		if ( ! $this->should_enqueue() ) {
			return;
		}

		$version = defined( 'CETECH_DE_VERSION' ) ? CETECH_DE_VERSION : '1.0.0-rc.1';
		$base    = defined( 'CETECH_DE_URL' ) ? CETECH_DE_URL : '';

		wp_enqueue_style(
			self::HANDLE,
			$base . 'assets/frontend/variable-delivery-selector.css',
			[],
			$version
		);

		wp_enqueue_script(
			self::HANDLE,
			$base . 'assets/frontend/variable-delivery-selector.js',
			[ 'jquery' ],
			$version,
			true
		);

		$product = $this->resolve_product();
		$product_id = $product instanceof WC_Product ? (int) $product->get_id() : 0;

		wp_localize_script(
			self::HANDLE,
			'cetechDeVariableDelivery',
			[
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'action'    => VariationDeliveryOptionsEndpoint::ACTION,
				'nonce'     => wp_create_nonce( VariationDeliveryOptionsEndpoint::ACTION ),
				'productId' => $product_id,
				'i18n'      => [
					'selectOptions' => __( 'Select your product options to see delivery choices.', 'cetech-woocommerce-delivery-engine' ),
					'loading'       => __( 'Loading delivery choices…', 'cetech-woocommerce-delivery-engine' ),
					'unavailable'   => __( 'Delivery options are not available for this variation.', 'cetech-woocommerce-delivery-engine' ),
					'error'         => __( 'Delivery options are temporarily unavailable. Please try again.', 'cetech-woocommerce-delivery-engine' ),
					'choose'        => __( 'Choose a delivery option.', 'cetech-woocommerce-delivery-engine' ),
					'title'         => __( 'Delivery options', 'cetech-woocommerce-delivery-engine' ),
				],
				'postField'           => 'cetech_de_delivery_option_key',
				'postVariationField'  => 'cetech_de_delivery_variation_id',
			]
		);
	}

	private function should_enqueue(): bool {
		if ( ! $this->requirements->is_woocommerce_active() ) {
			return false;
		}

		if ( ! $this->feature_flags->is_enabled( 'enable_product_delivery_selector' ) ) {
			return false;
		}

		if ( ! $this->feature_flags->is_enabled( ProductDeliveryRuntimeConfigurationRouter::CUTOVER_FLAG ) ) {
			return false;
		}

		if ( ! $this->feature_flags->is_enabled( ProductDeliveryRuntimeConfigurationRouter::VARIABLE_CUTOVER_FLAG ) ) {
			return false;
		}

		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return false;
		}

		$product = $this->resolve_product();

		return $product instanceof WC_Product && $product->is_type( 'variable' );
	}

	private function resolve_product(): ?WC_Product {
		global $product;

		if ( $product instanceof WC_Product ) {
			return $product;
		}

		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$post_id = get_the_ID();
		$loaded  = is_int( $post_id ) ? wc_get_product( $post_id ) : false;

		return $loaded instanceof WC_Product ? $loaded : null;
	}
}
