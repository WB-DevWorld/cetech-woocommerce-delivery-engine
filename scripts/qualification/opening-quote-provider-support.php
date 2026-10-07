<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionFingerprint;
use CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteSourcePlan;
use CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteNativeSourcePreparer;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeOwnerResolver;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeWooSource;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryConfigurationSourceInterface;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidator;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Bootstrap\DataLifecycleManifest;
use CetechDeliveryEngine\Bootstrap\Plugin;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\CustomerContext\DeliveryAddress;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Infrastructure\WooCommerce\Shipping\SelectedOfferShippingMethod;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionFactory;

/** Real Woo objects and physical owned rows; no Q01 synthetic context builder. */
final class CetechNativeQuoteProviderFixture {

	public CetechNativeQuoteProviderObservedFactory $factory;
	public QuoteOwner $owner;
	public int $offer;
	public int $zone;
	public int $rate;
	public int $shipping_instance;
	public int $alternate_supplier;
	public int $alternate_profile;
	public int $alternate_origin;
	public string $suffix;
	public string $tax_class;
	public array $products = [];
	public array $rules = [];
	public array $tax_rates = [];
	public array $extra_products = [];
	public ?int $variation_id = null;
	public ?int $variation_parent = null;
	public ?int $alternate_parent = null;
	private array $entities = [];
	private array $original_options = [];
	private array $domain_before = [];
	private array $native_before = [];
	private array $globals_before = [];
	private array $hooks_before = [];
	private array $wc_before = [];
	private array $term_before = [];
	private array $owned_namespaces = [];
	private array $owned_quotes = [];
	private ?WC_Session_Handler $session = null;
	private ?string $session_id = null;
	private array $cart_items = [];
	public array $last_native = [];
	private bool $installed = false;
	private bool $tax_class_created = false;
	private string $cleanup_stage = 'not_started';

	public function __construct( private wpdb $db ) {
		$this->factory = new CetechNativeQuoteProviderObservedFactory( new OperationConnectionFactory() );
		$this->suffix = bin2hex( random_bytes( 6 ) ); $this->tax_class = 'q04_' . $this->suffix;
	}

	public static function rows( string $table, string $order = 'id' ): array {
		global $wpdb;
		if ( ! preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table ) || ! preg_match( '/\A[a-zA-Z0-9_]+\z/D', $order ) ) { throw new RuntimeException( 'Native Q04 table identity is invalid.' ); }
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY `{$order}`", ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Native Q04 physical observation failed.' ); }
		return $rows;
	}
	private function option( string $name ): ?array { $row = $this->db->get_row( $this->db->prepare( "SELECT option_id,option_name,option_value,autoload FROM `{$this->db->options}` WHERE option_name=%s", $name ), ARRAY_A ); if ( '' !== $this->db->last_error ) { throw new RuntimeException( 'Native Q04 option observation failed.' ); } return $row; }
	public function set_option( string $name, mixed $value ): void { if ( ! array_key_exists( $name, $this->original_options ) ) { $this->original_options[$name] = $this->option( $name ); } update_option( $name, $value, false ); }
	public function restore_option( string $name ): void { if ( ! array_key_exists( $name, $this->original_options ) ) { throw new RuntimeException( 'Native Q04 option was not tracked.' ); } $row = $this->original_options[$name]; if ( null === $row ) { if ( false === $this->db->delete( $this->db->options, [ 'option_name' => $name ] ) ) { throw new RuntimeException( 'Native Q04 option restoration failed.' ); } } elseif ( false === $this->db->replace( $this->db->options, $row ) ) { throw new RuntimeException( 'Native Q04 option restoration failed.' ); } wp_cache_delete( $name, 'options' ); wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' ); }
	private function insert( string $suffix, array $row ): int { if ( false === $this->db->insert( TableNames::for( $suffix ), $row ) || $this->db->insert_id < 1 ) { throw new RuntimeException( 'Native Q04 entity allocation failed.' ); } $id = (int) $this->db->insert_id; $this->entities[] = [ $suffix, $id ]; return $id; }
	public function physical_update( string $suffix, int $id, array $values ): void { if ( false === $this->db->update( TableNames::for( $suffix ), $values, [ 'id' => $id ] ) ) { throw new RuntimeException( 'Native Q04 owned source update failed.' ); } }
	public function new_rate( array $values ): int { return $this->insert( 'rate_cards', [ 'internal_code' => 'q04_rate_' . bin2hex( random_bytes( 6 ) ), 'delivery_offer_id' => $this->offer, 'destination_zone_id' => $this->zone, 'base_currency' => 'GHS', 'base_amount' => '9.0000', 'charge_type' => 'fixed_per_shipment', 'status' => 'active', 'priority' => 1, ...$values ] ); }
	public function remove_entity( string $suffix, int $id ): void { if ( false === $this->db->delete( TableNames::for( $suffix ), [ 'id' => $id ] ) ) { throw new RuntimeException( 'Native Q04 tracked source removal failed.' ); } }
	public function row( string $suffix, int $id ): array { $row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM `' . TableNames::for( $suffix ) . '` WHERE id=%d', $id ), ARRAY_A ); if ( ! is_array( $row ) || '' !== $this->db->last_error ) { throw new RuntimeException( 'Native Q04 owned source is unavailable.' ); } return $row; }
	public function history(): array { $out = []; foreach ( [ 'operation_records', 'operation_changes', 'delivery_quotes', 'delivery_quote_bindings', 'delivery_quote_budget_windows' ] as $suffix ) { $out[$suffix] = self::rows( TableNames::for( $suffix ) ); } return $out; }
	public function track( $result ): void { if ( null !== $result->command ) { $this->owned_namespaces[] = $result->command->identity->namespace_digest(); } if ( null !== $result->quote ) { $row = $result->quote->row(); $this->owned_quotes[] = $row['quote_uuid']; foreach ( [ 'issue_namespace_hash', 'accept_namespace_hash', 'invalidate_namespace_hash' ] as $key ) { $this->owned_namespaces[] = $row[$key]; } } }
	public function track_command( CetechDeliveryEngine\Application\DeliveryQuote\QuoteIssueCommand $command ): void { $this->owned_namespaces[] = $command->identity()->namespace_digest(); }
	private static function shipping( ?WC_Shipping $replacement = null ): WC_Shipping { $property = new ReflectionProperty( WC_Shipping::class, '_instance' ); $current = WC()->shipping(); if ( null !== $replacement ) { $property->setValue( null, $replacement ); } return $current; }
	public function cleanup_stage(): string { return $this->cleanup_stage; }

	public function install(): void {
		if ( $this->installed ) { throw new RuntimeException( 'Native Q04 fixture is already allocated.' ); } $this->installed = true;
		foreach ( DataLifecycleManifest::DOMAIN_TABLE_SUFFIXES as $suffix ) { $this->domain_before[$suffix] = self::rows( TableNames::for( $suffix ) ); }
		foreach ( [ 'woocommerce_tax_rates' => 'tax_rate_id', 'woocommerce_tax_rate_locations' => 'location_id', 'woocommerce_shipping_zone_methods' => 'instance_id', 'woocommerce_sessions' => 'session_id', 'wc_tax_rate_classes' => 'tax_rate_class_id' ] as $suffix => $key ) { $this->native_before[$suffix] = self::rows( $this->db->prefix . $suffix, $key ); }
		$this->term_before = self::rows( $this->db->term_taxonomy, 'term_taxonomy_id' );
		foreach ( [ 'current_user', 'user_ID' ] as $key ) { $this->globals_before[$key] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[$key] ?? null ]; }
		foreach ( [ 'session', 'cart', 'customer' ] as $key ) { $this->wc_before[$key] = WC()->$key ?? null; } $this->wc_before['shipping'] = self::shipping();
		foreach ( $GLOBALS['wp_filter'] as $hook => $callbacks ) { $this->hooks_before[$hook] = is_object( $callbacks ) ? clone $callbacks : $callbacks; }
		// WP_ADMIN qualification does not load Woo's frontend cart helpers. Use
		// the actual installed Woo files before constructing the native shopper.
		$woo_root = defined( 'WC_ABSPATH' ) ? realpath( WC_ABSPATH ) : false; $customer_file = ( new ReflectionClass( WC_Customer::class ) )->getFileName();
		if ( false === $woo_root || ! is_string( $customer_file ) || realpath( $customer_file ) !== $woo_root . '/includes/class-wc-customer.php' ) { throw new RuntimeException( 'Native Q04 installed Woo helper identity is unavailable.' ); }
		require_once $woo_root . '/includes/wc-cart-functions.php';
		require_once $woo_root . '/includes/wc-notice-functions.php';
		if ( ! function_exists( 'wc_get_chosen_shipping_method_ids' ) || ! function_exists( 'wc_get_chosen_shipping_method_for_package' ) || ! function_exists( 'wc_add_notice' ) ) { throw new RuntimeException( 'Native Q04 installed Woo cart helpers are unavailable.' ); }
		foreach ( [ '_transient_shipping-transient-version', '_transient_timeout_shipping-transient-version' ] as $name ) { $this->original_options[$name] = $this->option( $name ); }
		foreach ( [ 'enable_product_delivery_selector', 'enable_cart_delivery_selection_capture', 'enable_checkout_delivery_selection_validation', 'enable_woocommerce_shipping_rate_calculation' ] as $flag ) { $this->set_option( 'cetech_de_' . $flag, 1 ); }
		foreach ( [ 'enable_effective_configuration_runtime', 'enable_variable_product_ecr_runtime', 'enable_category_rules', 'enable_site_fallback_rule' ] as $flag ) { $this->set_option( 'cetech_de_' . $flag, 0 ); }
		foreach ( LegacyQuoteSourcePlan::OPTIONS as $name ) { if ( ! array_key_exists( $name, $this->original_options ) ) { $this->original_options[$name] = $this->option( $name ); } }
		foreach ( [ 'woocommerce_currency' => 'GHS', 'woocommerce_calc_taxes' => 'yes', 'woocommerce_prices_include_tax' => 'no', 'woocommerce_tax_round_at_subtotal' => 'no', 'woocommerce_shipping_tax_class' => $this->tax_class, 'woocommerce_default_country' => 'GH:AA', 'woocommerce_default_customer_address' => 'base', 'woocommerce_tax_based_on' => 'shipping', 'woocommerce_price_num_decimals' => '2', 'woocommerce_shipping_debug_mode' => 'yes' ] as $name => $value ) { $this->set_option( $name, $value ); }
		$this->set_option( 'woocommerce_tax_classes', (string) get_option( 'woocommerce_tax_classes', '' ) . "\n" . $this->tax_class );
		$created_class = WC_Tax::create_tax_class( 'Q04 native tax ' . $this->suffix, $this->tax_class ); if ( is_wp_error( $created_class ) ) { throw new RuntimeException( 'Native Q04 tax class allocation failed.' ); } $this->tax_class_created = true;
		$this->offer = $this->insert( 'delivery_offers', [ 'internal_code' => 'q04_offer_' . $this->suffix, 'internal_name' => 'Q04 native fixture delivery', 'public_label' => 'Q04 native delivery', 'route' => 'local_delivery', 'service_level' => 'standard', 'status' => 'active' ] );
		$this->zone = $this->insert( 'destination_zones', [ 'internal_code' => 'q04_zone_' . $this->suffix, 'internal_name' => 'Q04 native GH', 'public_label' => 'Q04 GH', 'priority' => 1, 'status' => 'active' ] );
		$this->insert( 'destination_rules', [ 'zone_id' => $this->zone, 'rule_type' => 'country', 'rule_value' => 'GH', 'match_mode' => 'exact', 'priority' => 1 ] );
		// Earlier authority fixtures deliberately retain city areas. An exact owned
		// postcode makes this fixture's real matched primary zone unambiguous.
		$this->insert( 'destination_rules', [ 'zone_id' => $this->zone, 'rule_type' => 'postcode', 'rule_value' => '00001', 'match_mode' => 'exact', 'priority' => 1 ] );
		$this->rate = $this->insert( 'rate_cards', [ 'internal_code' => 'q04_rate_' . $this->suffix, 'delivery_offer_id' => $this->offer, 'destination_zone_id' => $this->zone, 'base_currency' => 'GHS', 'base_amount' => '7.0000', 'charge_type' => 'fixed_per_shipment', 'status' => 'active', 'priority' => 1 ] );
		$this->alternate_supplier = $this->insert( 'suppliers', [ 'internal_code' => 'q04_supplier_' . $this->suffix, 'internal_name' => 'Q04 private fixture supplier', 'status' => 'active' ] );
		$this->alternate_profile = $this->insert( 'logistics_profiles', [ 'internal_code' => 'q04_profile_' . $this->suffix, 'internal_name' => 'Q04 private fixture profile', 'status' => 'active' ] );
		$this->alternate_origin = $this->insert( 'origins', [ 'supplier_id' => $this->alternate_supplier, 'internal_code' => 'q04_origin_' . $this->suffix, 'internal_name' => 'Q04 private fixture origin', 'status' => 'active' ] );
		for ( $i = 0; $i < 2; ++$i ) { $product = new WC_Product_Simple(); $product->set_name( 'Q04 native item ' . $this->suffix . ':' . $i ); $product->set_status( 'publish' ); $product->set_regular_price( '20.00' ); $product->set_tax_status( 'taxable' ); $product->set_tax_class( $this->tax_class ); $product->set_manage_stock( true ); $product->set_stock_quantity( 10 ); $product->set_backorders( 'no' ); $id = $product->save(); if ( $id < 1 ) { throw new RuntimeException( 'Native Q04 product allocation failed.' ); } $this->products[] = $id; $this->rules[] = $this->insert( 'product_delivery_rules', [ 'target_type' => 'product', 'target_id' => $id, 'fulfilment_availability' => 'in_warehouse', 'fulfilment_choice' => 'delivery', 'delivery_offer_ids' => wp_json_encode( [ $this->offer ] ), 'priority' => 1, 'status' => 'active' ] ); }
		$zone = new WC_Shipping_Zone( 0 ); $this->shipping_instance = (int) $zone->add_shipping_method( SelectedOfferShippingMethod::METHOD_ID ); if ( $this->shipping_instance < 1 ) { throw new RuntimeException( 'Native Q04 shipping method allocation failed.' ); }
		$this->set_option( 'woocommerce_' . SelectedOfferShippingMethod::METHOD_ID . '_' . $this->shipping_instance . '_settings', [ 'enabled' => 'yes', 'title' => 'Q04 native delivery', 'tax_status' => 'taxable' ] );
		foreach ( [ 1, 2 ] as $priority ) { $id = WC_Tax::_insert_tax_rate( [ 'tax_rate_country' => 'GH', 'tax_rate_state' => 'AA', 'tax_rate' => '5.0000', 'tax_rate_name' => 'Q04 native tax ' . $priority, 'tax_rate_priority' => $priority, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => $priority, 'tax_rate_class' => $this->tax_class ] ); if ( $id < 1 ) { throw new RuntimeException( 'Native Q04 tax rate allocation failed.' ); } $this->tax_rates[] = (int) $id; }
		WC_Tax::init();
		wp_set_current_user( 0 ); WC()->session = new WC_Session_Handler(); WC()->session->init(); WC()->session->set_customer_session_cookie( true ); $this->session = WC()->session; $this->session_id = $this->session->get_customer_id();
		WC()->customer = new WC_Customer( 0, true ); WC()->customer->set_billing_country( 'GH' ); WC()->customer->set_billing_state( 'AA' ); WC()->customer->set_billing_postcode( '00001' ); WC()->customer->set_shipping_country( 'GH' ); WC()->customer->set_shipping_state( 'AA' ); WC()->customer->set_shipping_city( 'Accra' ); WC()->customer->set_shipping_postcode( '00001' ); WC()->customer->set_shipping_address_1( 'PRIVATE-Q04-NATIVE-FIXTURE-ADDRESS' ); WC()->customer->set_shipping_first_name( 'Synthetic' ); WC()->customer->set_shipping_last_name( 'Shopper' ); WC()->customer->set_is_vat_exempt( false );
		WC()->cart = new WC_Cart(); self::shipping( new WC_Shipping() );
		$this->owner = ( new QuoteNativeOwnerResolver() )->current();
		$this->cart();
	}

	/** Native cart calculation + actual chosen native rate; this is not add-to-cart adoption. */
	public function cart( bool $reverse = false ): void {
		$this->cart_items = [];
		foreach ( $this->products as $i => $id ) {
			$product = wc_get_product( $id ); $key = 'q04_item_' . $i . '_' . $this->suffix;
			$validation = Plugin::instance()->container()->get( ProductDeliverySelectionValidator::class )->validate( $id, null, 'in_warehouse:delivery:' . $this->offer );
			if ( ! $product instanceof WC_Product || ! $validation->valid || ! is_array( $validation->intent ) || ! is_array( $validation->matched_option ) ) { throw new RuntimeException( 'Native Q04 current member selection is unavailable.' ); }
			$item = [ 'key' => $key, 'product_id' => $id, 'variation_id' => 0, 'quantity' => $i + 1, 'data' => $product, CartDeliverySelectionCapture::CART_SELECTION_KEY => $validation->intent, CartDeliverySelectionCapture::CART_HASH_KEY => CartDeliverySelectionFingerprint::fromIntent( $validation->intent ), CartDeliverySelectionCapture::CART_SUMMARY_KEY => CartDeliverySelectionCapture::buildPublicSummary( $validation->matched_option ) ];
			$context = CustomerCartContext::delivery( $this->offer, address: DeliveryAddress::fromInput( [ 'country' => 'GH', 'state' => 'AA', 'city' => 'Accra', 'postcode' => '00001', 'address_1' => 'PRIVATE-Q04-NATIVE-FIXTURE-ADDRESS', 'first_name' => 'Synthetic', 'last_name' => 'Shopper' ] ) );
			$this->cart_items[$key] = $context->applyToCartItem( $item );
		}
		if ( $reverse ) { $this->cart_items = array_reverse( $this->cart_items, true ); }
		WC()->cart->cart_contents = $this->cart_items; WC()->cart->set_applied_coupons( [] ); $this->recalculate();
	}
	public function recalculate(): void {
		WC_Tax::init(); WC_Cache_Helper::get_transient_version( 'shipping', true ); self::shipping( new WC_Shipping() );
		WC()->session->set( 'chosen_shipping_methods', [] ); WC()->cart->calculate_totals(); $chosen = [];
		foreach ( WC()->shipping()->get_packages() as $i => $package ) { $found = []; foreach ( $package['rates'] ?? [] as $key => $rate ) { if ( $rate instanceof WC_Shipping_Rate && SelectedOfferShippingMethod::METHOD_ID === $rate->get_method_id() && $this->shipping_instance === $rate->get_instance_id() ) { $found[] = $key; } } if ( 1 !== count( $found ) ) { throw new RuntimeException( 'Native Q04 owned selected rate is not unique.' ); } $chosen[$i] = $found[0]; }
		if ( [] === $chosen ) { throw new RuntimeException( 'Native Q04 selected shipping package is unavailable.' ); } WC()->session->set( 'chosen_shipping_methods', $chosen ); WC()->cart->calculate_totals(); WC()->cart->set_session(); WC()->session->save_data();
	}
	public function variation_cart(): void {
		if ( null === $this->variation_id ) {
			foreach ( [ 'current', 'alternate' ] as $kind ) { $parent = new WC_Product_Variable(); $parent->set_name( 'Q04 native variation parent ' . $kind . ':' . $this->suffix ); $parent->set_status( 'publish' ); $parent->set_tax_status( 'taxable' ); $parent->set_tax_class( $this->tax_class ); $id = $parent->save(); if ( $id < 1 ) { throw new RuntimeException( 'Native Q04 variation parent allocation failed.' ); } $this->extra_products[] = $id; if ( 'current' === $kind ) { $this->variation_parent = $id; } else { $this->alternate_parent = $id; } }
			$variation = new WC_Product_Variation(); $variation->set_parent_id( $this->variation_parent ); $variation->set_status( 'publish' ); $variation->set_regular_price( '20.00' ); $variation->set_tax_status( 'taxable' ); $variation->set_tax_class( $this->tax_class ); $variation->set_manage_stock( true ); $variation->set_stock_quantity( 10 ); $variation->set_backorders( 'no' ); $this->variation_id = $variation->save(); if ( $this->variation_id < 1 ) { throw new RuntimeException( 'Native Q04 variation allocation failed.' ); } $this->extra_products[] = $this->variation_id; WC_Product_Variable::sync( $this->variation_parent );
			$this->insert( 'product_delivery_rules', [ 'target_type' => 'variation', 'target_id' => $this->variation_id, 'fulfilment_availability' => 'in_warehouse', 'fulfilment_choice' => 'delivery', 'delivery_offer_ids' => wp_json_encode( [ $this->offer ] ), 'priority' => 1, 'status' => 'active' ] );
		}
		$product = wc_get_product( $this->variation_id ); $key = 'q04_variation_' . $this->suffix; $validation = Plugin::instance()->container()->get( ProductDeliverySelectionValidator::class )->validate( $this->variation_parent, $this->variation_id, 'in_warehouse:delivery:' . $this->offer );
		if ( ! $product instanceof WC_Product_Variation || ! $validation->valid || ! is_array( $validation->intent ) || ! is_array( $validation->matched_option ) ) { throw new RuntimeException( 'Native Q04 variation selection is unavailable.' ); }
		$item = [ 'key' => $key, 'product_id' => $this->variation_parent, 'variation_id' => $this->variation_id, 'quantity' => 1, 'data' => $product, CartDeliverySelectionCapture::CART_SELECTION_KEY => $validation->intent, CartDeliverySelectionCapture::CART_HASH_KEY => CartDeliverySelectionFingerprint::fromIntent( $validation->intent ), CartDeliverySelectionCapture::CART_SUMMARY_KEY => CartDeliverySelectionCapture::buildPublicSummary( $validation->matched_option ) ]; $context = CustomerCartContext::delivery( $this->offer, address: DeliveryAddress::fromInput( [ 'country' => 'GH', 'state' => 'AA', 'city' => 'Accra', 'postcode' => '00001', 'address_1' => 'PRIVATE-Q04-NATIVE-FIXTURE-ADDRESS', 'first_name' => 'Synthetic', 'last_name' => 'Shopper' ] ) ); WC()->cart->cart_contents = [ $key => $context->applyToCartItem( $item ) ]; $this->recalculate();
	}

	/** Each line's source/inventory and retained tuple come from current native resolution. */
	public function capture_input(): array {
		$state = ( new QuoteNativeWooSource() )->capture()->facts(); $this->last_native = $state; $lines = []; $members = []; $groups = []; $legacy = []; $product_ids = [];
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$id = (int) $item['product_id']; $variation = (int) ( $item['variation_id'] ?? 0 ); $product = wc_get_product( $variation > 0 ? $variation : $id ); $runtime = Plugin::instance()->container()->get( ProductDeliveryConfigurationSourceInterface::class )->resolve( $variation > 0 ? 'variation' : 'product', $variation > 0 ? $variation : $id ); $rule = $runtime->result->chosen_rules['in_warehouse'] ?? null;
			if ( ! $runtime->result->success || 'legacy' !== $runtime->source || ! is_object( $rule ) || ! method_exists( $rule, 'toArray' ) || ! in_array( $this->offer, $rule->delivery_offer_ids, true ) || ! $product instanceof WC_Product ) { throw new RuntimeException( 'Native Q04 current retained source is unavailable.' ); }
			$group_id = DeliveryGroupIdentity::fromCartItem( $item ); if ( null === $group_id ) { throw new RuntimeException( 'Native Q04 retained group identity is unavailable.' ); } $component = hash( 'sha256', 'native-q04-component-v1:' . $group_id ); $dimensions = $runtime->quote_dimensions_for( 'in_warehouse' ); $dimension = static fn ( ?int $value ): array => [ 'state' => null === $value || $value < 1 ? 'absent' : 'known', 'id' => null === $value || $value < 1 ? null : $value ];
			$lines[] = [ 'line_key' => $key, 'product_id' => $id, 'variation_id' => $variation > 0 ? $variation : null, 'parent_id' => $variation > 0 ? $id : null, 'quantity' => (string) $item['quantity'], 'component_key' => $component, 'source' => LegacyQuoteNativeSourcePreparer::source_facts( $runtime, $rule ), 'inventory' => LegacyQuoteNativeSourcePreparer::inventory_facts( $product, (int) $item['quantity'] ) ];
			$proof = [ 'line_key' => $key, 'offer_id' => $this->offer, 'service_id' => $this->offer, 'choice' => 'delivery', 'origin' => $dimension( $dimensions['origin_id'] ), 'supplier' => $dimension( $dimensions['supplier_id'] ), 'profile' => $dimension( $dimensions['logistics_profile_id'] ), 'destination_zone_id' => $this->zone, 'endpoint_digest' => $state['destination_digest'] ]; $members[] = $proof;
			if ( ! isset( $groups[$component] ) ) { $group = $proof; unset( $group['line_key'] ); $groups[$component] = [ 'component_key' => $component, 'line_keys' => [], ...$group, 'policy_digest' => hash( 'sha256', 'native-q04-unfilled-policy' ), 'candidate_digest' => hash( 'sha256', 'native-q04-unfilled-candidates' ), 'candidate_count' => 0 ]; } $groups[$component]['line_keys'][] = $key; $legacy[] = [ 'type' => 'product', 'id' => $id ]; $product_ids[] = $id;
		}
		$selection = []; foreach ( WC()->cart->get_cart() as $key => $item ) { $selection[$key] = $item[CartDeliverySelectionCapture::CART_HASH_KEY]; } ksort( $selection, SORT_STRING );
		$context = QuoteContext::from_array( [ 'format_version' => 1, 'kind' => 'checkout', 'selection_digest' => hash( 'sha256', QuoteJson::encode( $selection ) ), 'destination' => [ 'kind' => 'full', 'digest' => $state['destination_digest'], 'key_epoch' => $this->owner->key_epoch() ], 'currency' => [ 'base' => get_option( 'woocommerce_currency' ), 'presentment' => $state['currency'], 'charged' => $state['currency'], 'precision' => $state['display_precision'] ], 'tax' => [ 'context_digest' => hash( 'sha256', 'native-q04-unfilled-tax' ), 'native_money_digest' => hash( 'sha256', 'native-q04-unfilled-money' ) ], 'lines' => $lines, 'groups' => array_values( $groups ) ] );
		$fences = [ [ 'source' => 'product', 'ids' => $product_ids ], [ 'source' => 'product_meta', 'ids' => $product_ids ], [ 'source' => 'term_relationships', 'ids' => $product_ids ], [ 'source' => 'offers', 'ids' => [ $this->offer ] ], [ 'source' => 'options', 'names' => LegacyQuoteSourcePlan::OPTIONS ], [ 'source' => 'legacy_rules', 'targets' => $legacy ], [ 'source' => 'zones' ], [ 'source' => 'zone_rules' ] ];
		$legacy_groups = []; foreach ( WC()->cart->get_cart() as $item ) { $group_id = DeliveryGroupIdentity::fromCartItem( $item ); $legacy_groups[hash( 'sha256', 'native-q04-component-v1:' . $group_id )] = $group_id; }
		// The production preparer refreshes every member and constructs its own
		// complete plan. This fixture does not authorize an explicit plan bypass.
		return [ $context, $legacy_groups ];
	}

	public function method_tax_status( string $status ): void { $this->set_option( 'woocommerce_' . SelectedOfferShippingMethod::METHOD_ID . '_' . $this->shipping_instance . '_settings', [ 'enabled' => 'yes', 'title' => 'Q04 native delivery', 'tax_status' => $status ] ); }
	public function warm_sources(): void { foreach ( $this->products as $id ) { wc_get_product( $id ); Plugin::instance()->container()->get( ProductDeliveryConfigurationSourceInterface::class )->resolve( 'product', $id ); } get_option( 'cetech_de_enable_effective_configuration_runtime' ); get_option( 'woocommerce_currency' ); }

	public function cleanup(): array {
		$this->cleanup_stage = 'owned_connections';
		$ok = $this->factory->close_all();
		$GLOBALS['wp_filter'] = $this->hooks_before;
		$this->cleanup_stage = 'owned_session';
		if ( null !== $this->session ) { $this->session->destroy_session(); remove_action( 'shutdown', [ $this->session, 'save_data' ], 20 ); remove_action( 'woocommerce_set_cart_cookies', [ $this->session, 'set_customer_session_cookie' ], 10 ); }
		$this->cleanup_stage = 'quote_operation_history';
		foreach ( [ 'delivery_quote_bindings', 'delivery_quotes' ] as $suffix ) { foreach ( array_unique( $this->owned_quotes ) as $uuid ) { $field = 'delivery_quotes' === $suffix ? 'quote_uuid' : 'quote_uuid'; if ( false === $this->db->delete( TableNames::for( $suffix ), [ 'site_id' => get_current_blog_id(), $field => $uuid ] ) ) { $ok = false; } } }
		foreach ( array_unique( $this->owned_namespaces ) as $namespace ) { $records = TableNames::for( 'operation_records' ); $ids = $this->db->get_col( $this->db->prepare( "SELECT id FROM `{$records}` WHERE site_id=%d AND namespace_hash=%s AND operation IN ('delivery_quote.issue','delivery_quote.accept','delivery_quote.invalidate')", get_current_blog_id(), $namespace ) ); foreach ( $ids as $id ) { $ok = false !== $this->db->delete( TableNames::for( 'operation_changes' ), [ 'site_id' => get_current_blog_id(), 'operation_id' => (int) $id ] ) && $ok; $ok = false !== $this->db->delete( $records, [ 'site_id' => get_current_blog_id(), 'id' => (int) $id ] ) && $ok; } }
		// Only rows absent from the pre-fixture snapshot and exact existing counter IDs
		// belonging to the tracked admission/session/site keys are restored.
		$budget_table = TableNames::for( 'delivery_quote_budget_windows' ); $before = $this->domain_before['delivery_quote_budget_windows'] ?? []; $before_ids = array_column( $before, 'id' );
		foreach ( self::rows( $budget_table ) as $row ) { $owned = isset( $this->owner ) && (int) $row['site_id'] === get_current_blog_id() && ( $row['slot_key'] === CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBudgetSlot::site_slot_key( get_current_blog_id() ) || $row['slot_key'] === CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBudgetSlot::session_slot_key( $this->owner ) || in_array( $row['admission_namespace_hash'], $this->owned_namespaces, true ) ); if ( $owned && ! in_array( $row['id'], $before_ids, true ) ) { $ok = false !== $this->db->delete( $budget_table, [ 'id' => $row['id'], 'site_id' => get_current_blog_id() ] ) && $ok; } }
		foreach ( $before as $row ) { if ( $row['slot_key'] === CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBudgetSlot::site_slot_key( get_current_blog_id() ) ) { $ok = false !== $this->db->replace( $budget_table, $row ) && $ok; } }
		$this->cleanup_stage = 'owned_sources';
		foreach ( array_reverse( $this->entities ) as [ $suffix, $id ] ) { $ok = false !== $this->db->delete( TableNames::for( $suffix ), [ 'id' => $id ] ) && $ok; }
		foreach ( array_reverse( [ ...$this->products, ...$this->extra_products ] ) as $id ) { wp_delete_post( $id, true ); clean_post_cache( $id ); }
		if ( isset( $this->shipping_instance ) ) { ( new WC_Shipping_Zone( 0 ) )->delete_shipping_method( $this->shipping_instance ); }
		foreach ( $this->tax_rates as $id ) { WC_Tax::_delete_tax_rate( $id ); }
		if ( $this->tax_class_created ) { WC_Tax::delete_tax_class( $this->tax_class ); }
		$this->cleanup_stage = 'raw_options';
		foreach ( $this->original_options as $name => $row ) { if ( null === $row ) { $ok = false !== $this->db->delete( $this->db->options, [ 'option_name' => $name ] ) && $ok; } else { $ok = false !== $this->db->replace( $this->db->options, $row ) && $ok; } wp_cache_delete( $name, 'options' ); } wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' ); WC_Tax::init();
		$this->cleanup_stage = 'native_objects';
		foreach ( $this->wc_before as $key => $value ) { if ( 'shipping' === $key ) { self::shipping( $value ); } else { WC()->$key = $value; } } foreach ( $this->globals_before as $key => [ $exists, $value ] ) { if ( $exists ) { $GLOBALS[$key] = $value; } else { unset( $GLOBALS[$key] ); } }
		$this->cleanup_stage = 'restoration_verification';
		$domains = true; foreach ( $this->domain_before as $suffix => $rows ) { $domains = $domains && $rows === self::rows( TableNames::for( $suffix ) ); }
		$options = true; foreach ( $this->original_options as $name => $row ) { $options = $options && $row === $this->option( $name ); }
		$native = true; foreach ( [ 'woocommerce_tax_rates' => 'tax_rate_id', 'woocommerce_tax_rate_locations' => 'location_id', 'woocommerce_shipping_zone_methods' => 'instance_id', 'woocommerce_sessions' => 'session_id', 'wc_tax_rate_classes' => 'tax_rate_class_id' ] as $suffix => $key ) { $native = $native && $this->native_before[$suffix] === self::rows( $this->db->prefix . $suffix, $key ); } $taxonomy = $this->term_before === self::rows( $this->db->term_taxonomy, 'term_taxonomy_id' );
		$products = true; foreach ( [ ...$this->products, ...$this->extra_products ] as $id ) { $products = $products && 0 === (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM `{$this->db->posts}` WHERE ID=%d", $id ) ) && 0 === (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM `{$this->db->postmeta}` WHERE post_id=%d", $id ) ) && 0 === (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM `{$this->db->prefix}wc_product_meta_lookup` WHERE product_id=%d", $id ) ); }
		$objects = WC()->cart === $this->wc_before['cart'] && WC()->customer === $this->wc_before['customer'] && WC()->session === $this->wc_before['session'] && WC()->shipping() === $this->wc_before['shipping'];
		$this->cleanup_stage = 'completed';
		return [ 'cleanup_restored' => $ok && $domains && $options && $native && $products && $taxonomy && $objects, 'domain35_and_quote_operation_history_restored' => $domains, 'raw_options_restored' => $options, 'native_tax_method_session_rows_restored' => $native, 'native_taxonomy_restored' => $taxonomy, 'owned_products_removed' => $products, 'native_wc_objects_restored' => $objects, 'all_owned_connections_retired' => $this->factory->all_retired() ];
	}
}

/** Observation-only transport; actual owner and acknowledgements are unchanged. */
final class CetechNativeQuoteProviderObservedFactory implements CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory {
	public int $source_reads = 0;
	public int $native_reads = 0;
	public array $sessions = [];
	public function __construct( private CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory $native ) {}
	public function open(): CetechDeliveryEngine\Domain\Operation\OperationSession { $session = new CetechNativeQuoteProviderObservedSession( $this->native->open(), $this ); $this->sessions[] = $session; return $session; }
	public function observe( string $sql ): string { if ( preg_match( '/\ASELECT\b/i', ltrim( $sql ) ) ) { if ( preg_match( '/(?:FROM|JOIN)\s+`?[a-zA-Z0-9_]*(?:delivery_engine_(?:rate_cards|delivery_offers|product_delivery_rules|destination_zones|destination_rules|configuration_scopes|configuration_fields|configuration_collections|origins|suppliers|logistics_profiles|destination_coverage_groups|destination_coverage_members|destination_coverage_postcodes)|posts|postmeta|term_relationships|term_taxonomy)`?\b/i', $sql ) ) { ++$this->source_reads; } elseif ( preg_match( '/(?:FROM|JOIN)\s+`?[a-zA-Z0-9_]*(?:woocommerce_tax_rates|woocommerce_tax_rate_locations|woocommerce_shipping_zone_methods|woocommerce_sessions|usermeta)`?\b/i', $sql ) || ( str_contains( $sql, 'option_name IN (' ) && str_contains( $sql, 'woocommerce_' ) ) ) { ++$this->native_reads; } } return $sql; }
	public function all_retired(): bool { foreach ( $this->sessions as $session ) { if ( ! $session->is_retired() ) { return false; } } return true; }
	public function close_all(): bool { $ok = true; foreach ( $this->sessions as $session ) { if ( ! $session->is_retired() ) { $ok = $session->retire() && $ok; } } return $ok && $this->all_retired(); }
}
final class CetechNativeQuoteProviderObservedSession implements CetechDeliveryEngine\Domain\Operation\OperationSession {
	public function __construct( private CetechDeliveryEngine\Domain\Operation\OperationSession $native, private CetechNativeQuoteProviderObservedFactory $observer ) {}
	public function site_id(): int { return $this->native->site_id(); }
	public function table_prefix(): string { return $this->native->table_prefix(); }
	public function charset_collate(): string { return $this->native->charset_collate(); }
	public function begin(): bool { return $this->native->begin(); }
	public function commit(): CetechDeliveryEngine\Domain\Operation\OperationCommitResult { return $this->native->commit(); }
	public function rollback(): bool { return $this->native->rollback(); }
	public function retire(): bool { return $this->native->retire(); }
	public function is_retired(): bool { return $this->native->is_retired(); }
	public function in_transaction(): bool { return $this->native->in_transaction(); }
	public function validate_tables( array $names ): bool { return $this->native->validate_tables( $names ); }
	public function query( string $sql ): int|false { return $this->native->query( $this->observer->observe( $sql ) ); }
	public function get_row( string $sql ): array|null|false { return $this->native->get_row( $this->observer->observe( $sql ) ); }
	public function get_results( string $sql ): array|false { return $this->native->get_results( $this->observer->observe( $sql ) ); }
	public function prepare( string $sql, mixed ...$args ): string { return $this->native->prepare( $sql, ...$args ); }
	public function errno(): int { return $this->native->errno(); }
	public function insert_id(): int { return $this->native->insert_id(); }
}

/** One explicitly labelled fixture change after genuine native receipt capture. */
final class CetechNativeQuoteProviderEpochSource implements CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeCaptureSource {
	private CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeWooSource $native;
	public int $captures = 0;
	public function __construct( private CetechNativeQuoteProviderFixture $fixture, private ?string $new_control_bytes = null ) { $this->native = new CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeWooSource(); }
	public function current_owner(): QuoteOwner { return $this->native->current_owner(); }
	public function capture(): CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeState { $result = $this->native->capture(); ++$this->captures; if ( null !== $this->new_control_bytes ) { $this->fixture->set_option( CetechDeliveryEngine\Infrastructure\Persistence\EmergencyControlStore::OPTION_NAME, $this->new_control_bytes ); } return $result; }
	public function unchanged(): bool { return $this->native->unchanged(); }
}
