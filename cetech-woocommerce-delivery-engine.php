<?php
/**
 * Plugin Name:       CETECH WooCommerce Delivery Engine
 * Plugin URI:        https://cetech.example.com/woocommerce-delivery-engine
 * Description:       Delivery, fulfilment-choice, delivery-pricing, shipment-status, and tracking engine for WooCommerce.
 * Version:           1.0.0-dev.fulfilment.1
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            CETECH
 * Text Domain:       cetech-woocommerce-delivery-engine
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   10.9
 *
 * @package CetechDeliveryEngine
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CETECH_DE_VERSION', '1.0.0-dev.fulfilment.1' );
define( 'CETECH_DE_FILE', __FILE__ );
define( 'CETECH_DE_PATH', plugin_dir_path( __FILE__ ) );
define( 'CETECH_DE_URL', plugin_dir_url( __FILE__ ) );
define( 'CETECH_DE_BASENAME', plugin_basename( __FILE__ ) );

$autoload = CETECH_DE_PATH . 'vendor/autoload.php';

if ( is_readable( $autoload ) ) {
	require_once $autoload;
} else {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__(
					'CETECH WooCommerce Delivery Engine is missing its Composer autoloader. Run composer install before activating, or package the plugin with vendor/ for deployment.',
					'cetech-woocommerce-delivery-engine'
				)
			);
		}
	);

	return;
}

$runtime_contracts = CETECH_DE_PATH . 'src/Bootstrap/RuntimeContracts.php';

if ( ! is_readable( $runtime_contracts ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__(
					'CETECH Delivery Engine is missing required plugin files. Delete the existing plugin folder, then install the complete plugin ZIP.',
					'cetech-woocommerce-delivery-engine'
				)
			);
		}
	);

	return;
}

require_once $runtime_contracts;

if ( ! \CetechDeliveryEngine\Bootstrap\RuntimeContracts::load( CETECH_DE_PATH ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__(
					'CETECH Delivery Engine is missing required plugin files. Delete the existing plugin folder, then install the complete plugin ZIP.',
					'cetech-woocommerce-delivery-engine'
				)
			);
		}
	);

	return;
}

register_activation_hook( __FILE__, [ CetechDeliveryEngine\Bootstrap\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ CetechDeliveryEngine\Bootstrap\Deactivator::class, 'deactivate' ] );

add_action(
	'plugins_loaded',
	static function (): void {
		CetechDeliveryEngine\Bootstrap\Plugin::instance()->boot();
	},
	20
);
