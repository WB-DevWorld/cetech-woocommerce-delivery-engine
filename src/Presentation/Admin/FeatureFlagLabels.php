<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

/**
 * Operational labels for administrator-visible feature switches.
 */
final class FeatureFlagLabels {

	/**
	 * @return array{label: string, description: string, caution?: string}
	 */
	public static function describe( string $flag ): array {
		return match ( $flag ) {
			'enable_product_delivery_selector' => [
				'label'       => 'Show delivery choices on product pages',
				'description' => 'Lets shoppers pick a delivery option before adding a product to the cart.',
			],
			'enable_cart_delivery_selection_capture' => [
				'label'       => 'Remember the shopper’s delivery choice in the cart',
				'description' => 'Stores the selected delivery option with the cart item so it can be checked later.',
			],
			'enable_checkout_delivery_selection_validation' => [
				'label'       => 'Check delivery choices at checkout',
				'description' => 'Confirms the shopper’s delivery choice is still valid before the order is placed.',
			],
			'enable_woocommerce_shipping_rate_calculation' => [
				'label'       => 'Calculate delivery fees in WooCommerce shipping',
				'description' => 'Adds the selected delivery option’s fee as a WooCommerce shipping rate.',
			],
			'enable_order_delivery_snapshot_persistence' => [
				'label'       => 'Save delivery details on the order',
				'description' => 'Stores a locked copy of the chosen delivery option on the order after checkout.',
			],
			'enable_customer_order_delivery_summary' => [
				'label'       => 'Show delivery details on customer order pages',
				'description' => 'Displays the saved delivery summary on the customer’s order screen.',
			],
			'enable_customer_email_delivery_summary' => [
				'label'       => 'Include delivery details in order emails',
				'description' => 'Adds the saved delivery summary to WooCommerce customer emails.',
			],
			'enable_shipment_records' => [
				'label'       => 'Shipment records (future feature)',
				'description' => 'Reserved for future shipment tracking features. Not required for checkout pricing.',
			],
			'enable_customer_timeline' => [
				'label'       => 'Customer delivery timeline (future feature)',
				'description' => 'Reserved for future customer-facing tracking timelines.',
			],
			'enable_tracking_links' => [
				'label'       => 'Carrier tracking links (future feature)',
				'description' => 'Reserved for future carrier tracking link support.',
			],
			'enable_wpml_adapter' => [
				'label'       => 'WPML language adapter',
				'description' => 'Optional WPML compatibility. Leave off unless CETECH support asks you to enable it.',
			],
			'enable_wcml_adapter' => [
				'label'       => 'WooCommerce Multilingual adapter',
				'description' => 'Optional WCML compatibility. Leave off unless CETECH support asks you to enable it.',
			],
			'enable_woodmart_adapter' => [
				'label'       => 'WoodMart theme adapter',
				'description' => 'Optional WoodMart compatibility. Core delivery does not require this theme.',
			],
			'enable_wcfm_adapter' => [
				'label'       => 'WCFM marketplace adapter',
				'description' => 'Optional WCFM compatibility. Leave off unless CETECH support asks you to enable it.',
			],
			'enable_vitepos_adapter' => [
				'label'       => 'Vitepos adapter',
				'description' => 'Optional Vitepos compatibility. Leave off unless CETECH support asks you to enable it.',
			],
			'enable_bulk_import' => [
				'label'       => 'Bulk import tools',
				'description' => 'Enables bulk import utilities when available.',
			],
			'enable_classic_checkout_adapter' => [
				'label'       => 'Classic checkout support',
				'description' => 'Keeps Delivery Engine compatible with the classic WooCommerce checkout.',
			],
			'enable_blocks_adapter' => [
				'label'       => 'Block checkout adapter',
				'description' => 'Optional WooCommerce block-checkout compatibility. Leave off unless CETECH support asks you to enable it.',
			],
			'enable_category_rules' => [
				'label'       => 'Category-based product rules',
				'description' => 'Allows legacy delivery rules to target product categories.',
			],
			'enable_site_fallback_rule' => [
				'label'       => 'Site-wide fallback product rule',
				'description' => 'Uses a fallback legacy rule when no product-specific rule matches.',
			],
			'enable_effective_configuration_runtime' => [
				'label'       => 'Use the New Delivery Settings System',
				'description' => 'When enabled, eligible products use the new inherited delivery settings instead of the legacy product-rule system.',
				'caution'     => 'Deployment switch. Leave off unless CETECH support has asked you to turn it on for a controlled test.',
			],
			'enable_variable_product_ecr_runtime' => [
				'label'       => 'Use New Delivery Settings for Product Variations',
				'description' => 'Allows individual WooCommerce variations to inherit or override delivery settings. Requires the New Delivery Settings System.',
				'caution'     => 'Deployment switch. Leave off unless CETECH support has asked you to turn it on for a controlled test.',
			],
			'demo_data_on_activation' => [
				'label'       => 'Load demo data on plugin activation',
				'description' => 'For testing environments only. Do not enable on production stores.',
				'caution'     => 'Can create sample zones, offers, and rate cards automatically.',
			],
			default => [
				'label'       => $flag,
				'description' => '',
			],
		};
	}

	public static function label( string $flag ): string {
		$described = self::describe( $flag );

		return $described['label'];
	}
}
