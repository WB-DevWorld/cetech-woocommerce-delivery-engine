<?php

declare(strict_types=1);
// Native-shaped callback registrations only. No Woo getter, filter or callback may run.
require dirname( __DIR__, 3 ) . '/vendor/autoload.php';

use CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeWooSource;

class WP_Hook { public array $callbacks = []; }
eval( 'namespace Automattic\\WooCommerce\\Blocks\\Shipping; class ShippingController { public static int $calls=0; public function filter_shipping_packages():never { ++self::$calls; throw new \\LogicException("Callback executed."); } public function remove_shipping_if_no_address():never { ++self::$calls; throw new \\LogicException("Callback executed."); } public function register_local_pickup_method():never { ++self::$calls; throw new \\LogicException("Callback executed."); } public function filter_taxable_address():never { ++self::$calls; throw new \\LogicException("Callback executed."); } public function filter_order_tax_location():never { ++self::$calls; throw new \\LogicException("Callback executed."); } }' );
function WC(): never { ++$GLOBALS['getter_calls']; throw new LogicException( 'Woo getter executed.' ); }

$mode = $argv[1] ?? 'default'; $GLOBALS['getter_calls'] = 0; $GLOBALS['wp_filter'] = [];
$controller = new Automattic\WooCommerce\Blocks\Shipping\ShippingController();
$held = WeakReference::create( $controller );
$tuple = static fn( object $object, string $method, int $args ): array => [ 'function' => [ $object, $method ], 'accepted_args' => $args ];
$key = static fn( object $object, string $method ): string => (string) spl_object_id( $object ) . $method;
$register = static function( object $object ) use ( $tuple, $key ): void {
	foreach ( [ 'woocommerce_shipping_packages' => [ [ 10, 'filter_shipping_packages', 1 ], [ 11, 'remove_shipping_if_no_address', 1 ] ], 'woocommerce_local_pickup_methods' => [ [ 10, 'register_local_pickup_method', 1 ] ], 'woocommerce_customer_taxable_address' => [ [ 10, 'filter_taxable_address', 1 ] ], 'woocommerce_order_get_tax_location' => [ [ 10, 'filter_order_tax_location', 2 ] ] ] as $hook => $entries ) {
		$entry = new WP_Hook(); foreach ( $entries as [ $priority, $method, $args ] ) { $entry->callbacks[$priority][$key( $object, $method )] = $tuple( $object, $method, $args ); } $GLOBALS['wp_filter'][$hook] = $entry;
	}
};
$register( $controller );
$alter = static function( string $variation ) use ( &$controller, $tuple, $key, $register ): void {
	$hook = $GLOBALS['wp_filter']['woocommerce_order_get_tax_location']; $original = $tuple( $controller, 'filter_order_tax_location', 2 ); $native_key = $key( $controller, 'filter_order_tax_location' );
	if ( 'replacement_all' === $variation ) { $controller = new Automattic\WooCommerce\Blocks\Shipping\ShippingController(); $register( $controller ); return; }
	if ( in_array( $variation, [ 'foreign_instance', 'subclass' ], true ) ) { $other = 'subclass' === $variation ? new class extends Automattic\WooCommerce\Blocks\Shipping\ShippingController {} : new Automattic\WooCommerce\Blocks\Shipping\ShippingController(); $hook->callbacks = [ 10 => [ $key( $other, 'filter_order_tax_location' ) => $tuple( $other, 'filter_order_tax_location', 2 ) ] ]; return; }
	$hook->callbacks = match ( $variation ) {
		'priority' => [ 11 => [ $native_key => $original ] ],
		'arity' => [ 10 => [ $native_key => $tuple( $controller, 'filter_order_tax_location', 1 ) ] ],
		'bool_arity' => [ 10 => [ $native_key => [ 'function' => [ $controller, 'filter_order_tax_location' ], 'accepted_args' => true ] ] ],
		'method' => [ 10 => [ $key( $controller, 'filter_taxable_address' ) => $tuple( $controller, 'filter_taxable_address', 2 ) ] ],
		'static' => [ 10 => [ 'static' => [ 'function' => [ Automattic\WooCommerce\Blocks\Shipping\ShippingController::class, 'filter_order_tax_location' ], 'accepted_args' => 2 ] ] ],
		'key' => [ 10 => [ 'foreign-key' => $original ] ],
		'duplicate' => [ 10 => [ $native_key => $original, 'duplicate' => $original ] ],
		'foreign' => [ 10 => [ 'foreign' => [ 'function' => static function(): never { ++$GLOBALS['getter_calls']; throw new LogicException( 'Foreign callback executed.' ); }, 'accepted_args' => 2 ] ] ],
		'remove' => [],
		default => throw new LogicException( 'Unknown alteration.' ),
	};
};
$late = str_starts_with( $mode, 'late_' );
$settings_mode = str_starts_with( $mode, 'settings_' ) || str_starts_with( $mode, 'scope_' );
$scope = QuoteNativeWooSource::OPTIONS; $settings = 'woocommerce_delivery_engine_selected_offer_11_settings';
$install_option = static function( string $hook ): void { $entry = new WP_Hook(); $entry->callbacks = [ 10 => [ 'foreign' => [ 'function' => static function(): never { ++$GLOBALS['getter_calls']; throw new LogicException( 'Option filter executed.' ); }, 'accepted_args' => 1 ] ] ]; $GLOBALS['wp_filter'][$hook] = $entry; };
if ( $settings_mode ) { $scope = [ $settings ]; }
if ( 'scope_full' === $mode ) { $scope = QuoteNativeWooSource::OPTIONS; for ( $i = 0; count( $scope ) < 64; ++$i ) { $scope[] = 'woocommerce_delivery_engine_selected_offer_' . $i . '_settings'; } }
if ( str_starts_with( $mode, 'scope_invalid_' ) ) {
	$scope = match ( substr( $mode, 14 ) ) {
		'bool' => [ true ], 'foreign' => [ 'foreign_option' ], 'whitespace' => [ $settings . ' ' ], 'negative' => [ 'woocommerce_delivery_engine_selected_offer_-1_settings' ], 'duplicate' => [ $settings, $settings ], 'associative' => [ 'name' => $settings ], 'oversize' => array_fill( 0, 65, $settings ), default => throw new LogicException( 'Unknown scope variation.' ),
	};
}
if ( str_starts_with( $mode, 'settings_before_' ) ) { $install_option( substr( $mode, 16 ) . $settings ); }
if ( ! $late && ! $settings_mode && 'default' !== $mode ) { $alter( $mode ); }
$supported_before = QuoteNativeWooSource::supports_current_hooks(); $source = null;
try { if ( method_exists( QuoteNativeWooSource::class, 'capture_hook_fence' ) ) { $source = QuoteNativeWooSource::capture_hook_fence( $scope ); } } catch ( Throwable ) {}
$before = null === $source ? false : $source->hooks_unchanged();
if ( str_starts_with( $mode, 'settings_after_' ) ) { $install_option( substr( $mode, 15 ) . $settings ); }
elseif ( 'settings_scope_alias' === $mode ) { $scope[0] = 'woocommerce_delivery_engine_selected_offer_12_settings'; $install_option( 'option_' . $settings ); }
elseif ( 'late_option' === $mode ) { $install_option( 'option_woocommerce_currency' ); }
elseif ( $late ) { $alter( substr( $mode, 5 ) ); }
$expected = []; foreach ( $GLOBALS['wp_filter'] as $name => $hook ) { $expected[$name] = $hook->callbacks; }
$supported = QuoteNativeWooSource::supports_current_hooks(); $current = null === $source ? false : $source->hooks_unchanged();
$unchanged = true; foreach ( $GLOBALS['wp_filter'] as $name => $hook ) { $unchanged = $unchanged && $expected[$name] === $hook->callbacks; }
echo json_encode( [ 'supported_before' => $supported_before, 'captured' => null !== $source, 'before' => $before, 'supported' => $supported, 'current' => $current, 'callbacks' => Automattic\WooCommerce\Blocks\Shipping\ShippingController::$calls, 'getters' => $GLOBALS['getter_calls'], 'registry_unchanged' => $unchanged, 'original_retained' => null !== $held->get() ], JSON_THROW_ON_ERROR );
