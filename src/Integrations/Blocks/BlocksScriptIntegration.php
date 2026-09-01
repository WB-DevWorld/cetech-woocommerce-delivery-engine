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
					],
				]
			);
		}
	}
}
