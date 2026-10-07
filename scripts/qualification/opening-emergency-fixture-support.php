<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlService;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidator;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionFingerprint;
use CetechDeliveryEngine\Bootstrap\DataLifecycleManifest;
use CetechDeliveryEngine\Bootstrap\Plugin;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\CustomerContext\DeliveryAddress;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlCommand;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionFactory;

/** Private, tracked native fixtures; never part of an installed product tree. */
final class CetechOpeningEmergencyFixture {
	public const CONTROL = 'cetech_de_checkout_control_v1';
	public const GATEWAY_COUNT = 'cetech_opening_c07_gateway_count';
	public const BARRIER = 'cetech_opening_c07_barrier';
	public const OWN_OPTIONS = [ self::CONTROL, self::GATEWAY_COUNT, self::BARRIER ];
	public const WOO_OPTIONS = [ 'woocommerce_currency', 'woocommerce_calc_taxes', 'woocommerce_default_country', 'woocommerce_allowed_countries', 'woocommerce_specific_allowed_countries', 'woocommerce_ship_to_countries', 'woocommerce_checkout_page_id', 'woocommerce_cart_page_id', 'woocommerce_enable_guest_checkout' ];

	/** Physical state and credentials stay in the private fixture file. */
	public array $state;
	public function __construct( array $state = [] ) { $this->state = $state; }
	public static function service(): EmergencyControlService {
		return new EmergencyControlService( new OperationConnectionFactory(), static fn ( int $site, int $actor ): bool => $site === get_current_blog_id() && $actor > 0 && $actor === get_current_user_id() && current_user_can( 'manage_delivery_settings' ) );
	}
	public static function rows( string $suffix ): array {
		global $wpdb;
		if ( ! in_array( $suffix, DataLifecycleManifest::DOMAIN_TABLE_SUFFIXES, true ) ) { throw new RuntimeException( 'C07 fixture table identity is not owned.' ); }
		$rows = $wpdb->get_results( 'SELECT * FROM `' . TableNames::for( $suffix ) . '` ORDER BY id', ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) { throw new RuntimeException( 'C07 physical fixture read failed.' ); }
		return $rows;
	}
	public static function option( string $name ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_id,option_name,option_value,autoload FROM `{$wpdb->options}` WHERE option_name=%s", $name ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'C07 physical control read failed.' ); }
		return is_array( $row ) ? $row : null;
	}
	public static function hash( array $value ): string { return hash( 'sha256', json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) ); }
	public static function invalidate( string $name ): void { wp_cache_delete( $name, 'options' ); wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' ); }
	public function prepare(): void {
		global $wpdb;
		if ( [] !== $this->state ) { throw new RuntimeException( 'Refusing duplicate C07 fixture allocation.' ); }
		$suffix = bin2hex( random_bytes( 6 ) );
		$this->state = [ 'format' => 'cetech-opening-emergency-private-v1', 'site_path' => realpath( ABSPATH ), 'database_name' => DB_NAME, 'site_id' => get_current_blog_id(), 'suffix' => $suffix, 'products' => [], 'posts' => [], 'orders' => [], 'entities' => [], 'namespaces' => [], 'original_options' => [], 'domain_before' => [], 'wc_shipping_methods' => [] ];
		foreach ( array_unique( [ ...DataLifecycleManifest::PRESERVED_OPTIONS, ...DataLifecycleManifest::FEATURE_FLAG_OPTIONS, ...self::WOO_OPTIONS, ...self::OWN_OPTIONS ] ) as $name ) { $this->state['original_options'][ $name ] = self::option( $name ); }
		foreach ( DataLifecycleManifest::DOMAIN_TABLE_SUFFIXES as $name ) { $this->state['domain_before'][ $name ] = self::rows( $name ); }
		$role = 'cetech_c07_' . $suffix; $username = $role . '_user'; $password = wp_generate_password( 40, true, true );
		if ( ! add_role( $role, 'C07 disposable authority', [ 'read' => true, 'view_admin_dashboard' => true, 'view_delivery_engine' => true, 'manage_delivery_settings' => true, 'view_delivery_diagnostics' => true, 'manage_shipments' => true ] ) instanceof WP_Role ) { throw new RuntimeException( 'C07 fixture role allocation failed.' ); }
		$this->state += [ 'role' => $role, 'username' => $username, 'password' => $password ];
		$id = wp_insert_user( [ 'user_login' => $username, 'user_pass' => $password, 'user_email' => $username . '@example.invalid', 'role' => $role ] );
		if ( is_wp_error( $id ) || $id < 1 ) { throw new RuntimeException( 'C07 fixture principal allocation failed.' ); }
		$this->state['user_id'] = (int) $id;
		$this->isolate_prior_global_instructions();
		foreach ( [ 'enable_product_delivery_selector', 'enable_cart_delivery_selection_capture', 'enable_checkout_delivery_selection_validation', 'enable_woocommerce_shipping_rate_calculation', 'enable_order_delivery_snapshot_persistence', 'enable_classic_checkout_adapter' ] as $flag ) { update_option( 'cetech_de_' . $flag, 1, false ); }
		foreach ( [ 'enable_effective_configuration_runtime', 'enable_variable_product_ecr_runtime' ] as $flag ) { update_option( 'cetech_de_' . $flag, 0, false ); }
		update_option( 'woocommerce_currency', 'GHS', false ); update_option( 'woocommerce_calc_taxes', 'no', false ); update_option( 'woocommerce_default_country', 'GH', false ); update_option( 'woocommerce_allowed_countries', 'all', false ); update_option( 'woocommerce_ship_to_countries', '', false ); update_option( 'woocommerce_enable_guest_checkout', 'yes', false );
		update_option( self::GATEWAY_COUNT, 0, false ); delete_option( self::BARRIER );
		$this->state['offer_id'] = $this->insert( 'delivery_offers', [ 'internal_code' => 'c07_delivery_' . $suffix, 'internal_name' => 'C07 synthetic delivery', 'public_label' => 'C07 delivery', 'route' => 'local_delivery', 'service_level' => 'standard', 'status' => 'active' ] );
		$pickup_offer = $this->insert( 'delivery_offers', [ 'internal_code' => 'c07_pickup_' . $suffix, 'internal_name' => 'C07 synthetic pickup', 'public_label' => 'C07 pickup', 'route' => 'store_pickup', 'service_level' => 'standard', 'status' => 'active' ] );
		$this->state['pickup_id'] = $this->insert( 'pickup_locations', [ 'internal_code' => 'c07_pickup_' . $suffix, 'location_name' => 'C07 pickup location', 'public_address' => 'Synthetic pickup address', 'status' => 'active' ] );
		$this->state['zone_id'] = $this->insert( 'destination_zones', [ 'internal_code' => 'c07_zone_' . $suffix, 'internal_name' => 'C07 synthetic GH', 'public_label' => 'C07 synthetic GH', 'status' => 'active', 'priority' => 1 ] );
		$this->insert( 'destination_rules', [ 'zone_id' => $this->state['zone_id'], 'rule_type' => 'country', 'rule_value' => 'GH', 'match_mode' => 'exact', 'priority' => 1 ] );
		$this->state['rate_id'] = $this->insert( 'rate_cards', [ 'internal_code' => 'c07_rate_' . $suffix, 'delivery_offer_id' => $this->state['offer_id'], 'destination_zone_id' => $this->state['zone_id'], 'charge_type' => 'fixed_per_shipment', 'base_amount' => '7.0000', 'base_currency' => 'GHS', 'status' => 'active', 'priority' => 1 ] );
		foreach ( [ 'managed' => '20.00', 'pickup' => '0.00', 'unmanaged' => '10.00' ] as $kind => $price ) {
			$product = new WC_Product_Simple(); $product->set_name( 'C07 synthetic ' . $kind . ' ' . $suffix ); $product->set_status( 'publish' ); $product->set_regular_price( $price ); $product->set_tax_status( 'none' ); $id = $product->save();
			if ( $id < 1 ) { throw new RuntimeException( 'C07 product allocation failed.' ); } $this->state['products'][] = $id; $this->state[ $kind . '_product_id' ] = $id;
			if ( 'unmanaged' !== $kind ) { $this->insert( 'product_delivery_rules', [ 'target_type' => 'product', 'target_id' => $id, 'fulfilment_availability' => 'pickup' === $kind ? 'in_store' : 'in_warehouse', 'fulfilment_choice' => 'pickup' === $kind ? 'store_pickup' : 'delivery', 'delivery_offer_ids' => wp_json_encode( [ 'pickup' === $kind ? $pickup_offer : $this->state['offer_id'] ] ), 'status' => 'active', 'priority' => 1 ] ); }
		}
		foreach ( [ 'classic' => '[woocommerce_checkout]', 'blocks' => '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->', 'cart' => '[woocommerce_cart]' ] as $kind => $content ) {
			$id = wp_insert_post( [ 'post_title' => 'C07 ' . $kind . ' ' . $suffix, 'post_name' => 'c07-' . $kind . '-' . $suffix, 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => $content ] );
			if ( is_wp_error( $id ) || $id < 1 ) { throw new RuntimeException( 'C07 page allocation failed.' ); } $this->state['posts'][] = (int) $id; $this->state[ $kind . '_page_id' ] = (int) $id; $this->state[ $kind . '_url' ] = get_permalink( $id );
		}
		update_option( 'woocommerce_checkout_page_id', $this->state['classic_page_id'], false ); update_option( 'woocommerce_cart_page_id', $this->state['cart_page_id'], false );
		$zone = new WC_Shipping_Zone( 0 );
		foreach ( [ 'delivery_engine_selected_offer', 'flat_rate' ] as $method ) {
			$instance = $zone->add_shipping_method( $method ); if ( ! is_int( $instance ) || $instance < 1 ) { throw new RuntimeException( 'C07 native shipping method allocation failed.' ); }
			$this->state['wc_shipping_methods'][] = $instance; $name = 'woocommerce_' . $method . '_' . $instance . '_settings'; $this->state['original_options'][ $name ] = self::option( $name );
			update_option( $name, [ 'enabled' => 'yes', 'title' => 'C07 synthetic ' . $method, 'cost' => '4.00', 'tax_status' => 'none' ], false );
		}
		foreach ( [ 'managed', 'pickup' ] as $kind ) { $this->item( $kind ); }
	}
	/** The earlier wizard proof leaves authored global defaults in this disposable site. */
	private function isolate_prior_global_instructions(): void {
		global $wpdb;
		$global_ids = [];
		foreach ( $this->state['domain_before']['configuration_scopes'] as $row ) {
			if ( 'global' !== ( $row['scope_type'] ?? null ) ) { continue; }
			$id = CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutFacts::positive_int( $row['id'] ?? null );
			if ( null === $id || count( $global_ids ) >= 200 ) { throw new RuntimeException( 'C07 prior global fixture exceeds its supported bound.' ); }
			$global_ids[] = $id;
		}
		$captured = [];
		foreach ( [ 'configuration_fields', 'configuration_collections' ] as $suffix ) {
			$captured[$suffix] = [];
			foreach ( $this->state['domain_before'][$suffix] as $row ) {
				$scope_id = CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutFacts::positive_int( $row['scope_row_id'] ?? null );
				if ( ! in_array( $scope_id, $global_ids, true ) ) { continue; }
				if ( null === CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutFacts::positive_int( $row['id'] ?? null ) || count( $captured[$suffix] ) >= 200 ) { throw new RuntimeException( 'C07 prior global instructions exceed their supported bound.' ); }
				$captured[$suffix][] = $row;
			}
		}
		$this->state['global_instruction_rows'] = $captured;
		// Only captured instruction IDs are isolated. Scope identities, revisions,
		// audits and the saved all32 baseline remain intact for exact restoration.
		foreach ( $captured as $suffix => $rows ) { foreach ( $rows as $row ) {
			if ( 1 !== $wpdb->delete( TableNames::for( $suffix ), [ 'id' => $row['id'], 'scope_row_id' => $row['scope_row_id'] ], [ '%d', '%d' ] ) ) { throw new RuntimeException( 'C07 prior global instruction isolation failed.' ); }
		} }
		Plugin::instance()->container()->get( CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver::class )->clearMemoization();
	}
	private function restore_prior_global_instructions(): void {
		global $wpdb;
		foreach ( $this->state['global_instruction_rows'] ?? [] as $suffix => $rows ) {
			if ( ! in_array( $suffix, [ 'configuration_fields', 'configuration_collections' ], true ) || ! is_array( $rows ) || count( $rows ) > 200 ) { throw new RuntimeException( 'C07 prior global restoration identity is invalid.' ); }
			$table = TableNames::for( $suffix );
			foreach ( $rows as $row ) {
				$id = CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutFacts::positive_int( $row['id'] ?? null );
				if ( null === $id ) { throw new RuntimeException( 'C07 prior global restoration row is invalid.' ); }
				$present = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id=%d", $id ), ARRAY_A );
				if ( '' !== $wpdb->last_error || ( is_array( $present ) && $present !== $row ) ) { throw new RuntimeException( 'C07 prior global restoration refused a changed row.' ); }
				if ( null === $present && 1 !== $wpdb->insert( $table, $row ) ) { throw new RuntimeException( 'C07 prior global instruction restoration failed.' ); }
			}
		}
		Plugin::instance()->container()->get( CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver::class )->clearMemoization();
	}
	private function insert( string $suffix, array $values ): int {
		global $wpdb;
		if ( false === $wpdb->insert( TableNames::for( $suffix ), $values ) || $wpdb->insert_id < 1 ) { throw new RuntimeException( 'C07 owned entity allocation failed.' ); }
		$id = (int) $wpdb->insert_id; $this->state['entities'][] = [ $suffix, $id ]; return $id;
	}
	public function transition( string $desired, string $reason, ?string $token = null ): object {
		$token ??= 'c07_' . bin2hex( random_bytes( 10 ) ); $identity = EmergencyControlCommand::identity( $this->state['site_id'], get_current_user_id(), $token );
		$this->state['namespaces'][] = $identity->namespace_digest();
		$service = self::service(); $read = $service->read( $this->state['site_id'] );
		if ( ! $read->available || null === $read->state ) { throw new RuntimeException( 'C07 fixture cannot obtain the opened control.' ); }
		return $service->transition( $identity, EmergencyControlCommand::payload( $read->state, $desired, $reason ), RequestContext::create() );
	}
	public function item( string $kind = 'managed' ): array {
		$id = (int) $this->state[ $kind . '_product_id' ]; $product = wc_get_product( $id );
		if ( ! $product instanceof WC_Product ) { throw new RuntimeException( 'C07 native product lookup failed.' ); }
		$item = [ 'key' => 'c07_' . $kind, 'product_id' => $id, 'variation_id' => 0, 'quantity' => 1, 'data' => $product ];
		if ( 'unmanaged' === $kind ) { return $item; }
		// Existing choices are captured while enabled, then reused as a private fixture
		// cart envelope. This is not a paused selector acceptance or an add-to-cart proof.
		if ( isset( $this->state['cart_data'][ $kind ] ) ) { return $item + $this->state['cart_data'][ $kind ]; }
		$key = 'in_warehouse:delivery:' . $this->state['offer_id'];
		if ( 'pickup' === $kind ) { $container = Plugin::instance()->container(); $runtime = $container->get( CetechDeliveryEngine\Application\Runtime\ProductDeliveryConfigurationSourceInterface::class )->resolve( 'product', $id ); $options = $container->get( CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder::class )->buildFromResolution( $runtime->result ); $selected = array_values( array_filter( $options, fn ( object $option ): bool => $option->pickup_location_id === $this->state['pickup_id'] && $option->is_available ) ); if ( 1 !== count( $selected ) ) { throw new RuntimeException( 'C07 owned pickup option was not unique.' ); } $key = $selected[0]->display_key; }
		$validation = Plugin::instance()->container()->get( ProductDeliverySelectionValidator::class )->validate( $id, null, $key );
		if ( ! $validation->valid || ! is_array( $validation->intent ) || ! is_array( $validation->matched_option ) ) { throw new RuntimeException( 'C07 current fixture choice was not accepted.' ); }
		$item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] = $validation->intent; $item[ CartDeliverySelectionCapture::CART_HASH_KEY ] = CartDeliverySelectionFingerprint::fromIntent( $validation->intent ); $item[ CartDeliverySelectionCapture::CART_SUMMARY_KEY ] = CartDeliverySelectionCapture::buildPublicSummary( $validation->matched_option );
		$context = 'pickup' === $kind ? CustomerCartContext::pickup( $this->state['pickup_id'] ) : CustomerCartContext::delivery( $this->state['offer_id'], address: DeliveryAddress::fromInput( [ 'country' => 'GH', 'state' => 'AA', 'city' => 'Accra', 'postcode' => '00001', 'address_1' => 'PRIVATE-C07-SYNTHETIC-ADDRESS', 'first_name' => 'Synthetic', 'last_name' => 'Shopper' ] ) );
		$item = $context->applyToCartItem( $item ); $private = $item; foreach ( [ 'key', 'product_id', 'variation_id', 'quantity', 'data' ] as $name ) { unset( $private[$name] ); } $this->state['cart_data'][ $kind ] = $private; return $item;
	}
	public function order( string $kind = 'managed', bool $legacy = false, bool $missing = false ): WC_Order {
		if ( wc_tax_enabled() ) { throw new RuntimeException( 'C07 owned order fixture requires its explicit no-tax checkout policy.' ); }
		$item = $this->item( $kind ); $order = wc_create_order( [ 'customer_id' => $this->state['user_id'] ] );
		if ( ! $order instanceof WC_Order || $order->get_id() < 1 ) { throw new RuntimeException( 'C07 order allocation failed.' ); }
		$this->state['orders'][] = $order->get_id();
		$order->set_currency( 'GHS' ); $order->set_address( [ 'first_name' => 'Synthetic', 'last_name' => 'Shopper', 'email' => 'c07-shopper@example.invalid', 'phone' => '0200000000', 'country' => 'GH', 'state' => 'AA', 'postcode' => '00001', 'city' => 'Accra', 'address_1' => 'PRIVATE-C07-SYNTHETIC-ADDRESS' ], 'billing' ); $order->set_address( [ 'first_name' => 'Synthetic', 'last_name' => 'Shopper', 'country' => 'GH', 'state' => 'AA', 'postcode' => '00001', 'city' => 'Accra', 'address_1' => 'PRIVATE-C07-SYNTHETIC-ADDRESS' ], 'shipping' );
		$line_id = $order->add_product( $item['data'], 1 ); $line = $order->get_item( $line_id );
		if ( ! $line instanceof WC_Order_Item_Product ) { throw new RuntimeException( 'C07 order item allocation failed.' ); }
		if ( 'unmanaged' !== $kind && ! $missing ) {
			$builder = Plugin::instance()->container()->get( CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotBuilder::class );
			$snapshot = $builder->build_line_snapshot( $item['key'], $item, $order );
			if ( null === $snapshot ) { throw new RuntimeException( 'C07 current source could not build fixture snapshot.' ); }
			$data = $snapshot->toArray();
			if ( $legacy ) { $data['snapshot_version'] = '1'; foreach ( [ 'customer_context_version', 'matching_location', 'delivery_address', 'matching_identity', 'delivery_location_identity', 'pickup_location_id' ] as $field ) { unset( $data[ $field ] ); } $data['delivery_group_id'] = CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity::fromIntent( $item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ); }
			$line->add_meta_data( CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot::META_LINE_SNAPSHOT, wp_json_encode( $data ), true ); $line->add_meta_data( CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION, $data['snapshot_version'], true ); $line->save();
			$group = $data['delivery_group_id'];
			$shipping = new WC_Order_Item_Shipping(); $shipping->set_method_id( 'delivery_engine_selected_offer' ); $shipping->set_method_title( 'C07 selected delivery' ); $shipping->set_total( 'pickup' === $kind ? '0.0000' : '7.0000' ); $shipping->set_taxes( [ 'total' => [] ] ); $shipping->add_meta_data( 'cetech_de_group_id', $group, true ); $order->add_item( $shipping );
			$package = [ 'snapshot_version' => $data['snapshot_version'], 'shipping_method_id' => 'delivery_engine_selected_offer', 'shipping_method_label' => 'C07 selected delivery', 'package_total_delivery_amount' => 'pickup' === $kind ? '0.0000' : '7.0000', 'currency_code' => 'GHS', 'destination_zone_id' => 'pickup' === $kind ? null : $this->state['zone_id'], 'quote_status' => 'success', 'snapshotted_at' => gmdate( 'c' ), 'groups' => [ [ 'group_id' => $group, 'shipping_method_id' => 'delivery_engine_selected_offer', 'shipping_method_label' => 'C07 selected delivery', 'package_total_delivery_amount' => 'pickup' === $kind ? '0.0000' : '7.0000', 'fulfilment_choice' => 'pickup' === $kind ? 'store_pickup' : 'delivery', 'is_pickup' => 'pickup' === $kind, 'display_index' => 1 ] ] ];
			$order->update_meta_data( CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, wp_json_encode( $package ) ); $order->update_meta_data( CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION, $data['snapshot_version'] );
		} elseif ( $missing ) { $shipping = new WC_Order_Item_Shipping(); $shipping->set_method_id( 'delivery_engine_selected_offer' ); $shipping->set_total( '7.0000' ); $shipping->set_taxes( [ 'total' => [] ] ); $order->add_item( $shipping ); }
		$order->set_status( 'pending' ); $order->calculate_totals( false );
		// Native Woo checkout assigns no-tax cart/shipping facts through setters;
		// calculate_totals(false) alone leaves new-object integer tax defaults.
		$order->set_cart_tax( '0' ); $order->set_shipping_tax( '0' ); $order->save(); return $order;
	}
	public static function order_bytes( array $ids ): array {
		global $wpdb;
		if ( [] === $ids ) { return []; } foreach ( $ids as $id ) { if ( ! is_int( $id ) || $id < 1 ) { throw new RuntimeException( 'C07 physical order identity is invalid.' ); } }
		$list = implode( ',', $ids ); $items = $wpdb->get_results( "SELECT * FROM `{$wpdb->prefix}woocommerce_order_items` WHERE order_id IN ({$list}) ORDER BY order_item_id", ARRAY_A );
		$meta = $wpdb->get_results( "SELECT m.* FROM `{$wpdb->prefix}woocommerce_order_itemmeta` m INNER JOIN `{$wpdb->prefix}woocommerce_order_items` i ON i.order_item_id=m.order_item_id WHERE i.order_id IN ({$list}) ORDER BY m.meta_id", ARRAY_A );
		$hpos = 'yes' === (string) get_option( 'woocommerce_custom_orders_table_enabled' );
		$order = $wpdb->get_results( $hpos ? "SELECT * FROM `{$wpdb->prefix}wc_orders` WHERE id IN ({$list}) ORDER BY id" : "SELECT * FROM `{$wpdb->posts}` WHERE ID IN ({$list}) ORDER BY ID", ARRAY_A );
		$order_meta = $wpdb->get_results( $hpos ? "SELECT * FROM `{$wpdb->prefix}wc_orders_meta` WHERE order_id IN ({$list}) ORDER BY id" : "SELECT * FROM `{$wpdb->postmeta}` WHERE post_id IN ({$list}) ORDER BY meta_id", ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $items ) || ! is_array( $meta ) || ! is_array( $order ) || ! is_array( $order_meta ) ) { throw new RuntimeException( 'C07 physical order observation failed.' ); }
		return [ 'orders' => $order, 'order_meta' => $order_meta, 'items' => $items, 'item_meta' => $meta ];
	}
	public function cleanup(): array {
		global $wpdb;
		update_option( 'cetech_de_enable_shipment_records', 0, false );
		foreach ( $this->state['orders'] as $id ) { $order = wc_get_order( $id ); if ( $order instanceof WC_Order ) { $order->delete( true ); } }
		foreach ( array_reverse( $this->state['entities'] ) as [ $suffix, $id ] ) { if ( false === $wpdb->delete( TableNames::for( $suffix ), [ 'id' => $id ] ) ) { throw new RuntimeException( 'C07 tracked entity cleanup failed.' ); } }
		foreach ( [ ...array_reverse( $this->state['products'] ), ...$this->state['posts'] ] as $id ) { wp_delete_post( $id, true ); clean_post_cache( $id ); }
		$zone = new WC_Shipping_Zone( 0 ); foreach ( $this->state['wc_shipping_methods'] as $id ) { $zone->delete_shipping_method( $id ); }
		foreach ( array_unique( $this->state['namespaces'] ) as $digest ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) { throw new RuntimeException( 'C07 completion cleanup identity is invalid.' ); }
			$records = TableNames::for( 'operation_records' ); $changes = TableNames::for( 'operation_changes' ); $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$records}` WHERE site_id=%d AND namespace_hash=%s AND operation=%s", $this->state['site_id'], $digest, EmergencyControlCommand::OPERATION ) );
			foreach ( $ids as $id ) { $wpdb->delete( $changes, [ 'operation_id' => (int) $id, 'site_id' => $this->state['site_id'] ] ); $wpdb->delete( $records, [ 'id' => (int) $id, 'site_id' => $this->state['site_id'] ] ); }
		}
		$this->restore_prior_global_instructions();
		foreach ( $this->state['original_options'] as $name => $row ) {
			if ( null === $row ) { $wpdb->delete( $wpdb->options, [ 'option_name' => $name ] ); }
			else { if ( false === $wpdb->replace( $wpdb->options, $row ) ) { throw new RuntimeException( 'C07 original option restoration failed.' ); } }
			self::invalidate( $name );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php'; if ( isset( $this->state['user_id'] ) ) { wp_delete_user( $this->state['user_id'] ); } if ( isset( $this->state['role'] ) ) { remove_role( $this->state['role'] ); }
		$domain_same = true; foreach ( $this->state['domain_before'] as $name => $rows ) { $domain_same = $domain_same && $rows === self::rows( $name ); }
		$options_same = true; foreach ( $this->state['original_options'] as $name => $row ) { $options_same = $options_same && $row === self::option( $name ); }
		$ids = [ ...$this->state['products'], ...$this->state['posts'] ]; $list = implode( ',', array_map( 'intval', $ids ) ); $posts_gone = [] === $ids || ( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $wpdb->posts . '` WHERE ID IN (' . $list . ')' ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $wpdb->postmeta . '` WHERE post_id IN (' . $list . ')' ) );
		$orders_gone = [] === $this->state['orders'] || [] === array_filter( self::order_bytes( $this->state['orders'] ), static fn ( array $rows ): bool => [] !== $rows );
		return [ 'cleanup_restored' => $domain_same && $options_same && $posts_gone && $orders_gone, 'domain32_restored' => $domain_same, 'original_options_restored' => $options_same, 'history_preserved' => $domain_same, 'control_restored' => $options_same, 'fixture_orders_removed' => $orders_gone, 'products_removed' => $posts_gone, 'posts_removed' => $posts_gone, 'orders_removed' => $orders_gone, 'role_exists' => null !== get_role( $this->state['role'] ), 'user_exists' => false !== get_user_by( 'id', $this->state['user_id'] ) ];
	}
}
