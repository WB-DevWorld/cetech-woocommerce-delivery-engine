<?php
/**
 * Isolated WordPress-Delete simulation for uninstall.php.
 *
 * Usage: php uninstall-runner.php preserve|delete
 */

declare(strict_types=1);

$mode = $argv[1] ?? 'preserve';
$root = dirname( __DIR__, 3 );

require_once $root . '/tests/bootstrap.php';

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', true );
}

if ( ! defined( 'CETECH_DE_PATH' ) ) {
	define( 'CETECH_DE_PATH', $root . DIRECTORY_SEPARATOR );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/tests/Integration/stubs/' );
}

$administrator = new class() {
	/** @var array<string, bool> */
	public array $capabilities = [ 'view_delivery_engine' => true ];

	public function add_cap( string $capability ): void {
		$this->capabilities[ $capability ] = true;
	}

	public function remove_cap( string $capability ): void {
		unset( $this->capabilities[ $capability ] );
	}

	public function has_cap( string $capability ): bool {
		return ! empty( $this->capabilities[ $capability ] );
	}
};

$GLOBALS['cetech_de_test_roles'] = [ 'administrator' => $administrator, 'shop_manager' => $administrator ];
$GLOBALS['cetech_de_test_wp_roles'] = (object) [
	'roles' => [ 'administrator' => [], 'shop_manager' => [] ],
];
$GLOBALS['cetech_de_test_options'] = [
	'cetech_de_delete_data_on_uninstall' => 'delete' === $mode ? 1 : 0,
	'cetech_de_db_version'               => '4',
	'cetech_de_enable_shipment_records'  => 1,
	'cetech_de_sitewide_defaults'        => [ 'setup_completed' => true ],
	'_cetech_de_unrelated_core_option'   => 'keep-me',
	'woocommerce_unrelated'              => 'keep-me',
];

$wpdb           = new CetechDeliveryEngine\Tests\Unit\Shipment\FakeWpdb();
$GLOBALS['wpdb'] = $wpdb;
$wpdb->register_table( 'wp_delivery_engine_delivery_offers' );
$wpdb->register_table( 'wp_delivery_engine_shipments' );

require $root . '/uninstall.php';

echo wp_json_encode(
	[
		'mode'            => $mode,
		'db_version'      => get_option( 'cetech_de_db_version', null ),
		'shipment_flag'   => get_option( 'cetech_de_enable_shipment_records', null ),
		'sitewide'        => get_option( 'cetech_de_sitewide_defaults', null ),
		'unrelated'       => get_option( 'woocommerce_unrelated', null ),
		'tables'          => $wpdb->table_names(),
		'admin_has_view'  => $administrator->has_cap( 'view_delivery_engine' ),
	]
);
