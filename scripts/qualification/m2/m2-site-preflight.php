<?php
/**
 * M2 acceptance, part B: read-only site preflight.
 *
 * Run with WP-CLI on the site under test:
 *   wp eval-file scripts/qualification/m2/m2-site-preflight.php [receipt.json]
 * or, when the file cannot be placed on the site (read-only access), on stdin:
 *   wp eval-file - - < scripts/qualification/m2/m2-site-preflight.php
 * A receipt path of '-' prints the JSON receipt to stdout after the table instead
 * of writing a file, so a read-only run can still capture it.
 *
 * Checks the preconditions the acceptance cases assume and prints PASS/FAIL per
 * precondition. Reads only. Never prints customer, order or configuration secrets:
 * it reports versions, flag states, counts and the public labels and amounts of
 * the delivery rate cards the cases name (D9).
 *
 * No declare(strict_types=1) here: `wp eval-file` evaluates the file with eval(),
 * where that declaration is a fatal error (only --use-include would allow it).
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'get_option' ) ) {
	fwrite( STDERR, "Run this file through WP-CLI: wp eval-file scripts/qualification/m2/m2-site-preflight.php\n" );
	exit( 1 );
}

$receipt_path = isset( $args[0] ) ? (string) $args[0] : '';
$checks       = array();

$record = static function ( string $id, bool $pass, string $detail ) use ( &$checks ): void {
	$checks[] = array( 'id' => $id, 'result' => $pass ? 'PASS' : 'FAIL', 'detail' => $detail );
};

// 1. Plugin identity and schema.
$plugin_version = defined( 'CETECH_DE_VERSION' ) ? (string) CETECH_DE_VERSION : '';
$record( 'plugin-version', str_starts_with( $plugin_version, '1.0.0-m2.' ), 'installed Delivery Engine version: ' . ( '' !== $plugin_version ? $plugin_version : 'not loaded' ) );
$schema = (string) get_option( 'cetech_de_db_version', '' );
$record( 'schema', '6' === $schema, 'cetech_de_db_version: ' . ( '' !== $schema ? $schema : 'missing' ) );

// 2. Every M2 feature on (D10: Frank switches them on; acceptance requires them on).
$required_flags = array(
	'enable_product_delivery_selector',
	'enable_cart_delivery_selection_capture',
	'enable_checkout_delivery_selection_validation',
	'enable_woocommerce_shipping_rate_calculation',
	'enable_order_delivery_snapshot_persistence',
	'enable_customer_order_delivery_summary',
	'enable_customer_email_delivery_summary',
	'enable_shipment_records',
	'enable_effective_configuration_runtime',
	'enable_variable_product_ecr_runtime',
);
// enable_bulk_import is a stored key nothing reads: Bulk Tools gate on capabilities (see FeatureFlagLabels).
$flags_off = array();
foreach ( $required_flags as $flag ) {
	$raw = get_option( 'cetech_de_' . $flag, null );
	$on  = null !== $raw && in_array( (string) $raw, array( '1', 'yes', 'true', 'on' ), true );
	if ( ! $on ) {
		$flags_off[] = $flag;
	}
}
$record( 'm2-features-on', array() === $flags_off, array() === $flags_off ? 'all ' . count( $required_flags ) . ' M2 flags are on' : 'off: ' . implode( ', ', $flags_off ) );

// 3. Platform freeze: record what is installed (versions only).
global $wpdb;
$wc_version = defined( 'WC_VERSION' ) ? (string) WC_VERSION : 'not active';
$db_version = (string) $wpdb->get_var( 'SELECT VERSION()' );
$record( 'platform', str_contains( strtolower( $db_version ), 'mariadb' ), 'WordPress ' . get_bloginfo( 'version' ) . ', WooCommerce ' . $wc_version . ', PHP ' . PHP_VERSION . ', database ' . $db_version );
$hpos = (string) get_option( 'woocommerce_custom_orders_table_enabled', '' );
$record( 'hpos', 'yes' === $hpos, 'HPOS enabled: ' . ( '' !== $hpos ? $hpos : 'unset' ) );

// 4. Target-stack plugins active (WoodMart theme, WPML, B2BKing).
$theme = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;
$theme_name = $theme ? (string) $theme->get( 'Name' ) : '';
$record( 'theme-woodmart', str_contains( strtolower( $theme_name ), 'woodmart' ), 'active theme: ' . $theme_name );
$active_plugins = (array) get_option( 'active_plugins', array() );
$has = static function ( string $needle ) use ( $active_plugins ): bool {
	foreach ( $active_plugins as $plugin ) {
		if ( str_contains( (string) $plugin, $needle ) ) {
			return true;
		}
	}
	return false;
};
$record( 'wpml-active', $has( 'sitepress-multilingual-cms' ), 'WPML active: ' . ( $has( 'sitepress-multilingual-cms' ) ? 'yes' : 'no' ) );
$record( 'b2bking-active', $has( 'b2bking' ), 'B2BKing active: ' . ( $has( 'b2bking' ) ? 'yes' : 'no' ) );

// 5. Rate cards the cases name (D9): public labels and amounts only.
$table = $wpdb->prefix . 'delivery_engine_rate_cards';
$cards = $wpdb->get_results( "SELECT `status`, `charge_type`, `base_amount`, `base_currency` FROM `{$table}` WHERE `status` = 'active'", ARRAY_A ); // phpcs:ignore WordPress.DB
$cards = is_array( $cards ) ? $cards : array();
$flat  = 0;
foreach ( $cards as $card ) {
	if ( str_starts_with( (string) ( $card['charge_type'] ?? '' ), 'fixed' ) ) {
		++$flat;
	}
}
$record( 'rate-cards-flat', count( $cards ) > 0 && $flat === count( $cards ), count( $cards ) . ' active rate cards, ' . $flat . ' flat (M2 rate cards are flat per delivery only)' );

// 6. D1 note: no product carries an origin, supplier or logistics profile on training.
$fields_table = $wpdb->prefix . 'delivery_engine_configuration_fields';
$tuple_fields = $wpdb->get_var( "SELECT COUNT(*) FROM `{$fields_table}` WHERE `field_key` IN ('origin_id','supplier_id','logistics_profile_id') AND `value_text` IS NOT NULL AND `value_text` <> ''" ); // phpcs:ignore WordPress.DB
if ( null === $tuple_fields ) {
	$record( 'd1-tuples', false, 'could not count origin/supplier/profile fields: ' . ( '' !== (string) $wpdb->last_error ? (string) $wpdb->last_error : 'query returned nothing' ) );
} else {
	$record( 'd1-tuples', true, 'configured origin/supplier/profile fields: ' . (int) $tuple_fields . ' (D1 only changes charges when this is above zero)' );
}

// 7. Pickup locations exist for C2/C10 (count only).
$pickups = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}delivery_engine_pickup_locations` WHERE `status` = 'active'" ); // phpcs:ignore WordPress.DB
$record( 'pickup-locations', $pickups > 0, $pickups . ' active pickup locations' );

// 8. International products are a known limit (D9), not a failure: count them.
$international = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}delivery_engine_configuration_scopes` WHERE `slice_key` = 'international_fulfilment' AND `status` = 'active'" ); // phpcs:ignore WordPress.DB
$record( 'international-known-limit', true, $international . ' active International scope slices (no delivery option until Air/Sea prices exist)' );

// Output.
$failed = 0;
foreach ( $checks as $check ) {
	if ( 'FAIL' === $check['result'] ) {
		++$failed;
	}
	printf( "%-28s %s  %s\n", $check['id'], $check['result'], $check['detail'] );
}
printf( "\n%d checks, %d failed\n", count( $checks ), $failed );

if ( '' !== $receipt_path ) {
	$receipt = array(
		'format'       => 'cetech-m2-site-preflight-v1',
		'generated_at' => gmdate( 'c' ),
		'site'         => (string) wp_parse_url( (string) home_url(), PHP_URL_HOST ),
		'checks'       => $checks,
		'failed'       => $failed,
	);
	$json = json_encode( $receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	if ( '-' === $receipt_path ) {
		echo "receipt:\n" . $json;
	} else {
		file_put_contents( $receipt_path, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		echo "receipt: {$receipt_path}\n";
	}
}

if ( $failed > 0 ) {
	exit( 1 );
}
