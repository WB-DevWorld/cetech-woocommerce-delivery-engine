<?php

declare(strict_types=1);
// Isolated raw native registration boundary. It neither finds rates nor grants placement authority.
require dirname( __DIR__, 3 ) . '/vendor/autoload.php';

use CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeWooSource;

class WP_Hook { public array $callbacks = []; }
class WC_Cart { protected array $cart_contents = []; public function get_cart(): never { throw new LogicException( 'Raw guard invoked getter.' ); } }
class WC_Customer { protected array $data = []; }
class WC_Session_Handler { protected array $_data = []; protected string $_cookie = 'native_original_cookie'; }
class WC_Shipping { protected static ?self $_instance = null; public function __construct() { self::$_instance = $this; } }
function sanitize_title_with_dashes(): never { ++$GLOBALS['native_sanitizer_calls']; throw new LogicException( 'Native sanitizer executed by pure guard.' ); }
function _wp_specialchars(): never { ++$GLOBALS['native_sanitizer_calls']; throw new LogicException( 'Native charset callback executed by pure guard.' ); }
function _canonical_charset(): never { ++$GLOBALS['native_sanitizer_calls']; throw new LogicException( 'Native charset callback executed by pure guard.' ); }

$mode = $argv[1] ?? 'empty'; $hook_name = $argv[2] ?? 'woocommerce_matched_tax_rates'; $calls = 0; $hook = new WP_Hook();
if ( ! in_array( $hook_name, [ 'woocommerce_matched_tax_rates', 'sanitize_text_field', 'woocommerce_format_postcode', 'sanitize_title', 'esc_html', 'pre_option_blog_charset', 'option_blog_charset', 'default_option_blog_charset', 'woocommerce_order_item_get_method_id', 'woocommerce_countries_base_country', 'woocommerce_countries_base_state', 'woocommerce_countries_base_postcode', 'woocommerce_countries_base_city', 'wc_tax_enabled', 'woocommerce_get_base_location', 'pre_wp_load_alloptions', 'alloptions', 'pre_cache_alloptions', 'wp_autoload_values_to_autoload', 'woocommerce_order_get_items', 'woocommerce_order_get_is_vat_exempt', 'woocommerce_order_item_get_cetech_de_group_id', 'woocommerce_order_item_get_product_id', 'woocommerce_order_item_get_variation_id', 'woocommerce_get_product_from_item', 'woocommerce_order_item_product', 'woocommerce_order_get__cetech_de_delivery_quote_snapshot', 'woocommerce_order_get__cetech_de_order_delivery_snapshot_version', 'woocommerce_order_get__cetech_de_delivery_quote_format', 'woocommerce_order_get__cetech_de_quote_native_draft', 'woocommerce_order_get__cetech_de_quote_reference', 'woocommerce_order_get__cetech_de_quote_native_tax_source', 'woocommerce_order_item_get__cetech_de_delivery_snapshot', 'woocommerce_order_item_get__cetech_de_delivery_snapshot_version', 'woocommerce_order_item_get__cetech_de_delivery_quote_format', 'woocommerce_order_item_get__cetech_de_quote_line_key', 'woocommerce_order_item_get__cetech_de_cart_item_key', 'woocommerce_order_item_get_quantity', 'woocommerce_order_type_to_group', 'wc_get_price_decimal_separator', 'pre_option_woocommerce_price_decimal_sep', 'option_woocommerce_price_decimal_sep', 'default_option_woocommerce_price_decimal_sep' ], true ) ) { throw new LogicException( 'Unknown matcher hook.' ); }
$tuple = [ 'function' => static function() use ( &$calls ): never { ++$calls; throw new LogicException( 'Unknown matcher callback executed.' ); }, 'accepted_args' => 6 ];
$hook->callbacks = [ 10 => [ 'native-protocol-entry' => $tuple ] ];
$GLOBALS['wp_filter'] = []; $GLOBALS['native_sanitizer_calls'] = 0; $GLOBALS['blog_id'] = 1; $GLOBALS['current_user'] = (object) [ 'ID' => 17 ]; $_COOKIE = [];
$title = new WP_Hook(); $title->callbacks = [ 10 => [ 'sanitize_title_with_dashes' => [ 'function' => 'sanitize_title_with_dashes', 'accepted_args' => 3 ] ] ];
$charset = new WP_Hook(); $charset_pair = [ '_wp_specialchars' => [ 'function' => '_wp_specialchars', 'accepted_args' => 1 ], '_canonical_charset' => [ 'function' => '_canonical_charset', 'accepted_args' => 1 ] ]; $charset->callbacks = [ 10 => $charset_pair ];
$alter_charset = static function( string $variation ) use ( $charset, $charset_pair, $tuple ): void {
	$charset->callbacks = match ( $variation ) {
		'priority' => [ 11 => $charset_pair ],
		'args' => [ 10 => [ '_wp_specialchars' => $charset_pair['_wp_specialchars'], '_canonical_charset' => [ 'function' => '_canonical_charset', 'accepted_args' => 2 ] ] ],
		'alias' => [ 10 => [ '_wp_specialchars' => $charset_pair['_wp_specialchars'], '\\_canonical_charset' => [ 'function' => '\\_canonical_charset', 'accepted_args' => 1 ] ] ],
		'foreign' => [ 10 => [ '_wp_specialchars' => $charset_pair['_wp_specialchars'], 'foreign' => $tuple ] ],
		'key' => [ 10 => [ '_wp_specialchars' => $charset_pair['_wp_specialchars'], 'foreign-key' => $charset_pair['_canonical_charset'] ] ],
		'duplicate' => [ 10 => [ ...$charset_pair, 'duplicate' => $charset_pair['_canonical_charset'] ] ],
		'duplicate_pair' => [ 10 => [ '_canonical_charset' => $charset_pair['_canonical_charset'], 'duplicate' => $charset_pair['_canonical_charset'] ] ],
		'reorder' => [ 10 => array_reverse( $charset_pair, true ) ],
		'remove' => [],
		default => throw new LogicException( 'Unknown charset variation.' ),
	};
};
$alter_title = static function( string $variation ) use ( $title, $tuple ): void {
	$default = [ 'function' => 'sanitize_title_with_dashes', 'accepted_args' => 3 ];
	$title->callbacks = match ( $variation ) {
		'priority' => [ 11 => [ 'sanitize_title_with_dashes' => $default ] ],
		'args' => [ 10 => [ 'sanitize_title_with_dashes' => [ 'function' => 'sanitize_title_with_dashes', 'accepted_args' => 2 ] ] ],
		'alias' => [ 10 => [ '\\sanitize_title_with_dashes' => [ 'function' => '\\sanitize_title_with_dashes', 'accepted_args' => 3 ] ] ],
		'foreign' => [ 10 => [ 'foreign' => $tuple ] ],
		'key' => [ 10 => [ 'foreign-key' => $default ] ],
		'duplicate' => [ 10 => [ 'sanitize_title_with_dashes' => $default, 'duplicate' => $default ] ],
		'remove' => [],
		default => throw new LogicException( 'Unknown sanitizer variation.' ),
	};
};
if ( str_starts_with( $mode, 'title_' ) ) { $GLOBALS['wp_filter']['sanitize_title'] = $title; if ( 'title_default' !== $mode && ! str_starts_with( $mode, 'title_late_' ) ) { $alter_title( substr( $mode, 6 ) ); } }
if ( str_starts_with( $mode, 'charset_' ) ) { $GLOBALS['wp_filter']['option_blog_charset'] = $charset; if ( 'charset_default' !== $mode && ! str_starts_with( $mode, 'charset_late_' ) ) { $alter_charset( substr( $mode, 8 ) ); } }
$shipping = new WC_Shipping(); $wc = (object) [ 'cart' => new WC_Cart(), 'customer' => new WC_Customer(), 'session' => new WC_Session_Handler(), 'countries' => new stdClass() ]; $GLOBALS['woocommerce'] = $wc;
$source = new QuoteNativeWooSource();
foreach ( [ 'wc' => $wc, 'site' => 1, 'user' => 17, 'objects' => [ $wc->cart, $wc->customer, $wc->session, $shipping ] ] as $name => $value ) { ( new ReflectionProperty( $source, $name ) )->setValue( $source, $value ); }
$raw = new ReflectionMethod( $source, 'raw_binding' ); ( new ReflectionProperty( $source, 'binding' ) )->setValue( $source, $raw->invoke( $source ) );
$before = $source->unchanged();
if ( str_starts_with( $mode, 'title_late_' ) ) { $alter_title( substr( $mode, 11 ) ); }
elseif ( str_starts_with( $mode, 'charset_late_' ) ) { $alter_charset( substr( $mode, 13 ) ); }
elseif ( ! str_starts_with( $mode, 'title_' ) && ! str_starts_with( $mode, 'charset_' ) && 'empty' !== $mode ) { $GLOBALS['wp_filter'][$hook_name] = $hook; }
$expected_hook = $hook->callbacks; $expected_title = $title->callbacks; $expected_charset = $charset->callbacks;
$unchanged = $source->unchanged(); $supported = QuoteNativeWooSource::supports_current_hooks();
$raw_untouched = $hook->callbacks === $expected_hook && $title->callbacks === $expected_title && $charset->callbacks === $expected_charset;
echo json_encode( [ 'before' => $before, 'current' => $unchanged, 'supported' => $supported, 'callback_calls' => $calls + $GLOBALS['native_sanitizer_calls'], 'registration_unchanged' => $raw_untouched ], JSON_THROW_ON_ERROR );
