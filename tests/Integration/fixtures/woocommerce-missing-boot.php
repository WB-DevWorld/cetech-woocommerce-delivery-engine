<?php
/**
 * Boot the plugin in a process where WooCommerce is not stubbed.
 *
 * Usage: CETECH_DE_DISABLE_WC_STUB=1 php woocommerce-missing-boot.php
 */

declare(strict_types=1);

putenv( 'CETECH_DE_DISABLE_WC_STUB=1' );

$root = dirname( __DIR__, 3 );
require_once $root . '/tests/bootstrap.php';

if ( class_exists( 'WooCommerce', false ) ) {
	fwrite( STDERR, "WooCommerce stub was loaded; aborting.\n" );
	exit( 2 );
}

CetechDeliveryEngine\Tests\Integration\LifecycleHarness::reset();
CetechDeliveryEngine\Bootstrap\Activator::activate();
CetechDeliveryEngine\Tests\Integration\LifecycleHarness::reset_plugin_singleton();

$plugin = CetechDeliveryEngine\Bootstrap\Plugin::instance();
$plugin->boot();

$manager    = $plugin->container()->get( CetechDeliveryEngine\Core\AdminNoticeManager::class );
$reflection = new ReflectionClass( $manager );
$property   = $reflection->getProperty( 'notices' );
if ( PHP_VERSION_ID < 80500 ) {
	$property->setAccessible( true );
}

/** @var array<string, mixed> $notices */
$notices = $property->getValue( $manager );

echo wp_json_encode(
	[
		'woocommerce_class' => class_exists( 'WooCommerce', false ),
		'shipping_filter'   => array_key_exists( 'woocommerce_shipping_methods', $GLOBALS['cetech_de_test_filters'] ?? [] ),
		'notice'            => array_key_exists( 'cetech-de-woocommerce-missing', is_array( $notices ) ? $notices : [] ),
		'schema'            => CetechDeliveryEngine\Core\Versioning\SchemaVersion::get(),
	]
);
