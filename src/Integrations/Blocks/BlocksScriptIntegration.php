<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\Blocks;

use CetechDeliveryEngine\Presentation\Shared\CustomerStorefrontCopy;

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
						'change' => CustomerStorefrontCopy::change(),
						'addressNeeded' => CustomerStorefrontCopy::address_needed(),
						'addDeliveryAddress' => CustomerStorefrontCopy::add_delivery_address(),
						'editDeliveryDetails' => CustomerStorefrontCopy::edit_delivery_details(),
						'editPickupDetails' => CustomerStorefrontCopy::edit_pickup_details(),
						'changeDestination' => CustomerStorefrontCopy::change_destination(),
						'destination' => CustomerStorefrontCopy::destination(),
						'deliveryMethod' => CustomerStorefrontCopy::delivery_method(),
						'deliveryAddress' => CustomerStorefrontCopy::delivery_address(),
						'recipientOptional' => CustomerStorefrontCopy::recipient_details_optional(),
						'applyToQuantity' => CustomerStorefrontCopy::apply_to_quantity(),
						'saveDeliveryDetails' => CustomerStorefrontCopy::save_delivery_details(),
						'cancel' => CustomerStorefrontCopy::cancel(),
						'addressLine2Optional' => CustomerStorefrontCopy::address_line_2_optional(),
						'changeDelivery' => CustomerStorefrontCopy::edit_delivery_details(),
						'changePickup' => CustomerStorefrontCopy::edit_pickup_details(),
						'deliveringTo' => __( 'Delivering to', 'cetech-woocommerce-delivery-engine' ),
						'storePickup' => CustomerStorefrontCopy::store_pickup(),
						'yourDeliveries' => CustomerStorefrontCopy::your_deliveries(),
						'updateDetails' => CustomerStorefrontCopy::save_delivery_details(),
						'useForAll' => CustomerStorefrontCopy::use_for_all_delivery_items(),
						'applyAll' => CustomerStorefrontCopy::apply_to_quantity(),
						'split' => __( 'Move some quantity', 'cetech-woocommerce-delivery-engine' ),
						'applyCheckoutAddress' => CustomerStorefrontCopy::use_my_checkout_address(),
						'cartUrl' => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
						'country' => __( 'Country', 'cetech-woocommerce-delivery-engine' ),
						'state' => __( 'State / Region', 'cetech-woocommerce-delivery-engine' ),
						'city' => __( 'City', 'cetech-woocommerce-delivery-engine' ),
						'postcode' => __( 'Postcode', 'cetech-woocommerce-delivery-engine' ),
						'address1' => CustomerStorefrontCopy::address_line_1(),
						'address2' => CustomerStorefrontCopy::address_line_2_optional(),
						'firstName' => __( 'First name', 'cetech-woocommerce-delivery-engine' ),
						'lastName' => __( 'Last name', 'cetech-woocommerce-delivery-engine' ),
						'phone' => __( 'Phone', 'cetech-woocommerce-delivery-engine' ),
						'company' => __( 'Company', 'cetech-woocommerce-delivery-engine' ),
						'applyAllN' => __( 'Apply to all %d items', 'cetech-woocommerce-delivery-engine' ),
						'applyToAllN' => __( 'Apply to all %d items', 'cetech-woocommerce-delivery-engine' ),
						'splitQty' => __( 'Quantity to move', 'cetech-woocommerce-delivery-engine' ),
						'optionLegend' => CustomerStorefrontCopy::delivery_method(),
					],
				]
			);
		}
	}
}
