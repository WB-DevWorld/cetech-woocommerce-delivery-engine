<?php
/**
 * Uninstall handler for CETECH WooCommerce Delivery Engine.
 *
 * Safety policy:
 * - By default, uninstall does NOT delete plugin data.
 * - Data is removed only when cetech_de_delete_data_on_uninstall is explicitly true (1).
 * - When delete-data is enabled, configuration and shipment tables are dropped.
 * - This file must not fatal when WooCommerce is absent.
 *
 * @package CetechDeliveryEngine
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$delete_data = (bool) (int) get_option( 'cetech_de_delete_data_on_uninstall', 0 );

if ( ! $delete_data ) {
	return;
}

$autoload = __DIR__ . '/vendor/autoload.php';

if ( is_readable( $autoload ) ) {
	require_once $autoload;

	CetechDeliveryEngine\Bootstrap\Uninstaller::uninstall();

	return;
}

// Fallback when vendor/ is missing: remove known options and capabilities without autoload.
// Table drops below must stay in sync with ConfigurationTables::SUFFIXES when new domain tables are added.
$roles = [ 'administrator', 'shop_manager' ];

$capabilities = [
	'view_delivery_engine',
	'manage_delivery_settings',
	'manage_site_wide_defaults',
	'manage_delivery_offers',
	'manage_delivery_rate_cards',
	'manage_delivery_zones',
	'manage_pickup_locations',
	'manage_logistics_profiles',
	'manage_private_sources',
	'manage_product_delivery_rules',
	'manage_shipments',
	'update_shipment_status',
	'view_private_delivery_costs',
	'view_private_origins',
	'manage_delivery_integrations',
	'view_delivery_logs',
	'import_delivery_data',
	'view_delivery_diagnostics',
];

foreach ( $roles as $role_slug ) {
	$role = get_role( $role_slug );

	if ( null === $role ) {
		continue;
	}

	foreach ( $capabilities as $capability ) {
		$role->remove_cap( $capability );
	}
}

$option_prefix = 'cetech_de_';

$feature_flag_options = [
	'enable_product_delivery_selector',
	'enable_cart_delivery_selection_capture',
	'enable_checkout_delivery_selection_validation',
	'enable_woocommerce_shipping_rate_calculation',
	'enable_order_delivery_snapshot_persistence',
	'enable_customer_order_delivery_summary',
	'enable_customer_email_delivery_summary',
	'enable_shipment_records',
	'enable_customer_timeline',
	'enable_tracking_links',
	'enable_wpml_adapter',
	'enable_wcml_adapter',
	'enable_woodmart_adapter',
	'enable_wcfm_adapter',
	'enable_vitepos_adapter',
	'enable_bulk_import',
	'enable_classic_checkout_adapter',
	'enable_blocks_adapter',
	'enable_category_rules',
	'enable_site_fallback_rule',
	'demo_data_on_activation',
];

foreach ( $feature_flag_options as $flag ) {
	delete_option( $option_prefix . $flag );
}

delete_option( 'cetech_de_db_version' );
delete_option( 'cetech_de_last_migration_status' );
delete_option( 'cetech_de_delete_data_on_uninstall' );
delete_option( 'cetech_de_global_configuration_version' );
delete_option( 'cetech_de_v3_config_migration_report' );
delete_option( 'cetech_de_capabilities_version' );
delete_option( 'cetech_de_shipment_creation_failure_order_ids' );
delete_option( 'cetech_de_wpml_string_sync_version' );

global $wpdb;

$table_prefix = $wpdb->prefix . 'delivery_engine_';

// Keep this list aligned with ConfigurationTables::all_suffixes() (see src/Infrastructure/Persistence/ConfigurationTables.php).
$table_suffixes = [
	'delivery_offers',
	'destination_zones',
	'destination_rules',
	'logistics_profiles',
	'suppliers',
	'origins',
	'pickup_locations',
	'rate_cards',
	'rate_card_rules',
	'audit_log',
	'product_delivery_rules',
	'configuration_scopes',
	'configuration_fields',
	'configuration_collections',
	'shipments',
	'shipment_items',
	'shipment_events',
	'bulk_jobs',
	'bulk_job_items',
	'bulk_recipes',
];

foreach ( $table_suffixes as $suffix ) {
	$table = $table_prefix . $suffix;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}
