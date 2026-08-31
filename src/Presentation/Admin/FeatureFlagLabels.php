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
				'label'       => 'Enable shipment records',
				'description' => 'Turns on shipment creation and the staff Shipments workspace for eligible paid Delivery Engine orders.',
			],
			'enable_customer_timeline' => [
				'label'       => 'Customer delivery timeline (future feature)',
				'description' => 'Reserved for a future customer-facing tracking timeline. Not part of this release.',
			],
			'enable_tracking_links' => [
				'label'       => 'Enable customer tracking links',
				'description' => 'Allows customers to use Track shipment when a shipment has a valid tracking URL. This does not contact carriers or update tracking automatically.',
			],
			'enable_wpml_adapter' => [
				'label'       => 'WPML language adapter',
				'description' => 'Optional WPML compatibility. No adapter is implemented in this release.',
			],
			'enable_wcml_adapter' => [
				'label'       => 'WooCommerce Multilingual adapter',
				'description' => 'Optional WCML compatibility. No adapter is implemented in this release.',
			],
			'enable_woodmart_adapter' => [
				'label'       => 'WoodMart theme adapter',
				'description' => 'No dedicated WoodMart adapter. Core delivery uses generic WooCommerce hooks.',
			],
			'enable_wcfm_adapter' => [
				'label'       => 'WCFM marketplace adapter',
				'description' => 'Optional WCFM compatibility. No adapter is implemented in this release.',
			],
			'enable_vitepos_adapter' => [
				'label'       => 'Vitepos adapter',
				'description' => 'Optional Vitepos compatibility. No adapter is implemented in this release.',
			],
			'enable_bulk_import' => [
				'label'       => 'Bulk import tools',
				'description' => 'Bulk Tools are available from the Delivery Engine menu when the user has the required capability. This stored key is unused.',
			],
			'enable_classic_checkout_adapter' => [
				'label'       => 'Classic checkout support',
				'description' => 'Classic WooCommerce checkout remains automatically available.',
			],
			'enable_blocks_adapter' => [
				'label'       => 'WooCommerce Cart and Checkout Blocks',
				'description' => 'Blocks support is automatic when WooCommerce Cart or Checkout Blocks are present. This stored key is not an operator switch.',
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
				'label'       => 'Use Site-wide Defaults at checkout',
				'description' => 'When active, eligible products use Site-wide Defaults and Product Exceptions for live checkout. Activated with the Delivery Engine; not an ordinary Settings switch.',
			],
			'enable_variable_product_ecr_runtime' => [
				'label'       => 'Use Site-wide Defaults for product variations',
				'description' => 'Variation inheritance and overrides follow Site-wide Defaults when that runtime is active.',
			],
			'demo_data_on_activation' => [
				'label'       => 'Demo data on activation',
				'description' => 'Not used. This release does not seed demo Delivery Areas, Delivery Options, or Delivery Charges.',
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
