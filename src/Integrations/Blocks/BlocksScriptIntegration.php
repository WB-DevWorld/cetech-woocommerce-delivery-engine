<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\Blocks;

/**
 * WooCommerce Blocks IntegrationInterface-compatible script registration.
 *
 * Implements the Blocks Integration methods by convention so it can be passed
 * to IntegrationRegistry::register() when the WooCommerce interface exists.
 */
final class BlocksScriptIntegration {

	public function get_name(): string {
		return BlocksCheckoutAdapter::NAMESPACE;
	}

	public function initialize(): void {
		self::register_assets();
	}

	/**
	 * @return list<string>
	 */
	public function get_script_handles(): array {
		return [ BlocksCheckoutAdapter::SCRIPT_HANDLE ];
	}

	/**
	 * @return list<string>
	 */
	public function get_editor_script_handles(): array {
		return [];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_script_data(): array {
		return [
			'namespace' => BlocksCheckoutAdapter::NAMESPACE,
		];
	}

	public static function register_assets(): void {
		if ( ! function_exists( 'wp_register_script' ) ) {
			return;
		}

		$version = defined( 'CETECH_DE_VERSION' ) ? CETECH_DE_VERSION : '0';
		$base    = defined( 'CETECH_DE_URL' ) ? CETECH_DE_URL : '';

		wp_register_style(
			BlocksCheckoutAdapter::STYLE_HANDLE,
			$base . 'assets/frontend/blocks-checkout.css',
			[],
			$version
		);

		wp_register_script(
			BlocksCheckoutAdapter::SCRIPT_HANDLE,
			$base . 'assets/frontend/blocks-checkout.js',
			[],
			$version,
			true
		);

		if ( function_exists( 'wp_localize_script' ) ) {
			wp_localize_script(
				BlocksCheckoutAdapter::SCRIPT_HANDLE,
				'cetechDeBlocks',
				[
					'namespace' => BlocksCheckoutAdapter::NAMESPACE,
					'i18n'      => [
						'update' => __( 'Update delivery option', 'cetech-woocommerce-delivery-engine' ),
						'choose' => __( 'Choose a delivery option', 'cetech-woocommerce-delivery-engine' ),
						'change' => __( 'Change', 'cetech-woocommerce-delivery-engine' ),
						'changeDelivery' => __( 'Change', 'cetech-woocommerce-delivery-engine' ),
						'changePickup' => __( 'Change', 'cetech-woocommerce-delivery-engine' ),
						'deliveringTo' => __( 'Delivering to', 'cetech-woocommerce-delivery-engine' ),
						'storePickup' => __( 'Store Pickup', 'cetech-woocommerce-delivery-engine' ),
						'yourDeliveries' => __( 'Your deliveries', 'cetech-woocommerce-delivery-engine' ),
						'addDeliveryAddress' => __( 'Add delivery address', 'cetech-woocommerce-delivery-engine' ),
						'updateDetails' => __( 'Update', 'cetech-woocommerce-delivery-engine' ),
						'useForAll' => __( 'Use this address for all delivery items', 'cetech-woocommerce-delivery-engine' ),
						'applyAll' => __( 'Apply to all items', 'cetech-woocommerce-delivery-engine' ),
						'split' => __( 'Move some quantity', 'cetech-woocommerce-delivery-engine' ),
						'applyCheckoutAddress' => __( 'Use my checkout address', 'cetech-woocommerce-delivery-engine' ),
						'cartUrl' => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
						'country' => __( 'Country', 'cetech-woocommerce-delivery-engine' ),
						'state' => __( 'State / Region', 'cetech-woocommerce-delivery-engine' ),
						'city' => __( 'City', 'cetech-woocommerce-delivery-engine' ),
						'postcode' => __( 'Postcode', 'cetech-woocommerce-delivery-engine' ),
						'address1' => __( 'Address line 1', 'cetech-woocommerce-delivery-engine' ),
						'address2' => __( 'Address line 2', 'cetech-woocommerce-delivery-engine' ),
						'firstName' => __( 'First name', 'cetech-woocommerce-delivery-engine' ),
						'lastName' => __( 'Last name', 'cetech-woocommerce-delivery-engine' ),
						'phone' => __( 'Phone', 'cetech-woocommerce-delivery-engine' ),
						'applyAllN' => __( 'Apply to all items', 'cetech-woocommerce-delivery-engine' ),
						'splitQty' => __( 'Quantity to move', 'cetech-woocommerce-delivery-engine' ),
						'optionLegend' => __( 'Delivery', 'cetech-woocommerce-delivery-engine' ),
					],
				]
			);
		}
	}
}
