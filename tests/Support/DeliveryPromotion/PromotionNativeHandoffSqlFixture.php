<?php

declare(strict_types=1);

namespace {
	if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( string $scheme = 'auth' ): string { return $GLOBALS['cetech_de_n17_native_identity_key'] ?? 'private-promotion-native-handoff-fixture-key'; } }
}

namespace CetechDeliveryEngine\Tests\Support\DeliveryPromotion {

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionNativeCapture, FixedPromotionNativeRequests};
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteProvider, LegacyQuoteSourcePlan, QuoteNativeCaptureSource, QuoteNativeState};
use CetechDeliveryEngine\Application\Order\QuoteNativeOrderFacts;
use CetechDeliveryEngine\Domain\DeliveryPromotion\DeliveryPromotionPlan;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteContext, QuoteHeader, QuoteId, QuoteOwner, QuoteStoredRow, QuoteTerms};
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\LegacyQuoteSourceSnapshotReader;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{LegacyQuoteProviderFixtures as L, QuoteFixtures as Q};

/** Native-shaped InnoDB rows; Woo getters and fixture saves are synthetic, never Woo CRUD certification. */
final class PromotionNativeHandoffSqlFixture {

	/** Actual legacy source receipts and compiled current-key economics over declared synthetic source/native observations. */
	public static function sources( OperationSession $session, array $pure ): array {
		$capture = $pure['quote']->context()->fixed_promotion_capture(); $owner = $pure['owner']; $context = $capture->original_context()->private_facts(); $terms = $capture->original_terms()->private_facts();
		$legacy = L::plan(); $plan = LegacyQuoteSourcePlan::create( $owner, QuoteContext::from_array( $context ), $legacy->member_proofs(), $legacy->rate_ranges(), $legacy->fences() );
		$original = ( new LegacyQuoteSourceSnapshotReader() )->capture( $session, $owner, QuoteContext::from_array( $context ), $plan, Q::time() );
		foreach ( $context['groups'] as &$group ) { $group['policy_digest'] = $original->policy_digest(); $group['candidate_digest'] = $original->candidate_digest( 20, 50, 'GHS' ); $group['candidate_count'] = $original->candidate_count( 20, 50, 'GHS' ); } unset( $group );
		foreach ( $terms['groups'] as &$group ) { $group['policy_digest'] = $original->policy_digest(); } unset( $group ); $context = QuoteContext::from_array( $context ); $terms = QuoteTerms::from_array( $terms ); $original = $original->bind_context( $context );
		$rule = F::rule(); $component = $pure['component']; $promotion = DeliveryPromotionPlan::from_evidence( $terms, $component, $rule, 'n17_synthetic_policy', 1, [ 'format_version' => 1, 'provider' => [ 'code' => 'n17_synthetic_policy', 'version' => 1 ], 'terms_digest' => $terms->digest(), 'component_key' => $component, 'rule_digest' => $rule->digest(), 'eligibility' => [ 'state' => 'eligible', 'evidence_digest' => Q::digest( 'N17 synthetic eligibility' ) ], 'budget' => [ 'state' => 'available', 'evidence_digest' => Q::digest( 'N17 planning only, actual ledger checked in SQL' ) ] ] );
		$header = QuoteHeader::issue( QuoteId::generate(), $owner, $context, $terms, Q::time(), [ 'issue' => Q::digest( 'N17 original issue' ), 'accept' => Q::digest( 'N17 original accept' ), 'invalidate' => Q::digest( 'N17 original invalidation' ) ], 'legacy_fixed_base_v1' );
		$requests = FixedPromotionNativeRequests::from_captured( $header, $context, $terms, [ [ 'component_key' => $component, 'plan' => $promotion ] ], [ [ 'component_key' => $component, 'legacy_group_id' => $pure['group'] ] ] );
		$facts = F::facts(); $facts['key_epoch'] = $owner->key_epoch(); $facts['destination_digest'] = $context->private_facts()['destination']['digest']; $facts['exempt'] = true; $facts['tax_enabled'] = false; $facts['cart_shipping_tax'] = '0.00';
		$facts['packages'][0] = array_replace( $facts['packages'][0], [ 'group_id' => $pure['group'], 'tax_status' => 'none', 'taxes' => [], 'fresh_taxes' => [], 'rounded_tax' => '0.00', 'display_total' => '9.50' ] ); $facts['source_facts']['selectors']['table_prefix'] = $session->table_prefix();
		$source = new readonly class( $owner, $facts ) implements QuoteNativeCaptureSource {
			public function __construct( private QuoteOwner $owner, public array $data ) {}
			public function current_owner(): QuoteOwner { return $this->owner; }
			public function capture(): QuoteNativeState { return QuoteNativeState::from_array( $this->data ); }
			public function unchanged(): bool { return true; }
		};
		$fresh = ( new FixedPromotionNativeCapture( $source ) )->capture( $owner, $requests ); $provider = new PromotionQuoteIssueCountingProvider( FixedPromotionQuoteProvider::from_native_result( $fresh, [ [ 'component_key' => $component, 'rule' => $rule ] ] ) ); $slots = [ [ 'component_key' => $component, 'account_key' => Q::digest( 'N17 aligned owned pool' ) ] ];
		return compact( 'original', 'fresh', 'provider', 'source', 'slots', 'owner' );
	}

	public static function install( \mysqli $db, string $prefix ): void {
		DB::validate_prefix( $prefix ); $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
		$schemas = [
			'woocommerce_order_items' => '(order_item_id bigint unsigned NOT NULL PRIMARY KEY,order_item_name text NOT NULL,order_item_type varchar(200) NOT NULL,order_id bigint unsigned NOT NULL,KEY order_id(order_id))',
			'woocommerce_order_itemmeta' => '(meta_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,order_item_id bigint unsigned NOT NULL,meta_key varchar(255) NOT NULL,meta_value longtext NULL,KEY item_key(order_item_id,meta_key(191)))',
			'wc_orders' => '(id bigint unsigned NOT NULL PRIMARY KEY,status varchar(20) NOT NULL,type varchar(20) NOT NULL,parent_order_id bigint unsigned NOT NULL DEFAULT 0,currency varchar(10) NOT NULL,total_amount decimal(26,8) NOT NULL,tax_amount decimal(26,8) NOT NULL,customer_id bigint unsigned NOT NULL DEFAULT 0)',
			'wc_order_operational_data' => '(id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,order_id bigint unsigned NOT NULL UNIQUE,order_key varchar(100) NOT NULL,shipping_total_amount decimal(26,8) NOT NULL,shipping_tax_amount decimal(26,8) NOT NULL,date_paid_gmt datetime NULL)',
			'wc_order_addresses' => '(id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,order_id bigint unsigned NOT NULL,address_type varchar(20) NOT NULL,country varchar(2) NOT NULL,state varchar(100) NOT NULL,city varchar(100) NOT NULL,postcode varchar(20) NOT NULL,address_1 varchar(100) NOT NULL,address_2 varchar(100) NOT NULL,KEY order_type(order_id,address_type))',
			'wc_orders_meta' => '(id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,order_id bigint unsigned NOT NULL,meta_key varchar(255) NOT NULL,meta_value longtext NULL,KEY order_key(order_id,meta_key(191)))',
			'posts' => '(ID bigint unsigned NOT NULL PRIMARY KEY,post_type varchar(20) NOT NULL,post_status varchar(20) NOT NULL,post_parent bigint unsigned NOT NULL DEFAULT 0)',
			'postmeta' => '(meta_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,post_id bigint unsigned NOT NULL,meta_key varchar(255) NOT NULL,meta_value longtext NULL,KEY post_key(post_id,meta_key(191)))',
		];
		foreach ( $schemas as $suffix => $columns ) { DB::execute( $db, "CREATE TABLE `{$prefix}{$suffix}` {$columns}{$tail}" ); }
	}

	public static function mode( \mysqli $db, string $prefix, bool $hpos ): void {
		DB::validate_prefix( $prefix );
		$value = $hpos ? 'yes' : 'no'; $statement = $db->prepare( "INSERT INTO `{$prefix}options` (option_name,option_value) VALUES ('woocommerce_custom_orders_table_enabled',?) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)" );
		if ( false === $statement || ! $statement->bind_param( 's', $value ) || ! $statement->execute() ) { throw new \RuntimeException( 'Owned native mode fixture failed.' ); } $statement->close();
	}

	/** Persist native getter facts into real local tables without invoking Woo or a second tax engine. */
	public static function put( \mysqli $db, string $prefix, \WC_Order $order, bool $hpos, array $order_meta = [], array $line_meta = [] ): void {
		DB::validate_prefix( $prefix ); $facts = QuoteNativeOrderFacts::native( $order ); $id = $order->get_id();
		// Refusal cases may deliberately delete a native item and leave its metadata orphaned.
		$owned_ids = []; foreach ( [ 'lines', 'shipping', 'tax' ] as $kind ) { foreach ( $facts[$kind] as $item ) { $owned_ids[] = (int) $item['id']; } }
		if ( [] !== $owned_ids ) { DB::execute( $db, "DELETE FROM `{$prefix}woocommerce_order_itemmeta` WHERE order_item_id IN (" . implode( ',', $owned_ids ) . ')' ); }
		foreach ( [ 'woocommerce_order_itemmeta', 'woocommerce_order_items', 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data', 'wc_orders', 'postmeta', 'posts' ] as $suffix ) {
			$column = match ( $suffix ) { 'posts' => 'ID', 'postmeta' => 'post_id', 'wc_orders' => 'id', default => 'order_id' };
			if ( 'woocommerce_order_itemmeta' === $suffix ) { DB::execute( $db, "DELETE m FROM `{$prefix}{$suffix}` m INNER JOIN `{$prefix}woocommerce_order_items` i ON i.order_item_id=m.order_item_id WHERE i.order_id={$id}" ); }
			else { DB::execute( $db, "DELETE FROM `{$prefix}{$suffix}` WHERE {$column}={$id}" ); }
		}
		$status = 'wc-' . $order->get_status();
		if ( $hpos ) {
			self::insert( $db, $prefix . 'wc_orders', [ 'id' => $id, 'status' => $status, 'type' => 'shop_order', 'parent_order_id' => 0, 'currency' => $facts['currency'], 'total_amount' => $facts['total'], 'tax_amount' => $facts['cart_tax'], 'customer_id' => $facts['customer_id'] ] );
			self::insert( $db, $prefix . 'wc_order_operational_data', [ 'order_id' => $id, 'order_key' => $facts['order_key'], 'shipping_total_amount' => $facts['shipping_total'], 'shipping_tax_amount' => $facts['shipping_tax'], 'date_paid_gmt' => null ] );
			self::insert( $db, $prefix . 'wc_order_addresses', [ 'order_id' => $id, 'address_type' => 'shipping', ...$facts['address'] ] );
			self::meta( $db, $prefix . 'wc_orders_meta', 'order_id', $id, $order_meta );
		} else {
			self::insert( $db, $prefix . 'posts', [ 'ID' => $id, 'post_type' => 'shop_order', 'post_status' => $status, 'post_parent' => 0 ] );
			$meta = [ '_order_key' => $facts['order_key'], '_order_currency' => $facts['currency'], '_order_total' => $facts['total'], '_order_tax' => $facts['cart_tax'], '_order_shipping' => $facts['shipping_total'], '_order_shipping_tax' => $facts['shipping_tax'], '_customer_user' => $facts['customer_id'] ];
			foreach ( $facts['address'] as $key => $value ) { $meta['_shipping_' . $key] = $value; }
			self::meta( $db, $prefix . 'postmeta', 'post_id', $id, [ ...$meta, ...$order_meta ] );
		}
		foreach ( $facts['lines'] as $line ) {
			self::insert( $db, $prefix . 'woocommerce_order_items', [ 'order_item_id' => $line['id'], 'order_item_type' => 'line_item', 'order_item_name' => 'Owned N17 line', 'order_id' => $id ] );
			$meta = [ '_product_id' => $line['product_id'], '_variation_id' => $line['variation_id'], '_qty' => $line['quantity'], '_line_total' => $line['total'], '_line_tax' => $line['tax'], '_line_subtotal' => $line['subtotal'], '_line_subtotal_tax' => $line['subtotal_tax'], '_line_tax_data' => $line['taxes'] ];
			self::meta( $db, $prefix . 'woocommerce_order_itemmeta', 'order_item_id', $line['id'], [ ...$meta, ...( $line_meta[$line['id']] ?? [] ) ] );
		}
		foreach ( $facts['shipping'] as $shipping ) {
			self::insert( $db, $prefix . 'woocommerce_order_items', [ 'order_item_id' => $shipping['id'], 'order_item_type' => 'shipping', 'order_item_name' => $shipping['label'], 'order_id' => $id ] );
			self::meta( $db, $prefix . 'woocommerce_order_itemmeta', 'order_item_id', $shipping['id'], [ 'method_id' => $shipping['method_id'], 'instance_id' => $shipping['instance_id'], 'cetech_de_group_id' => $shipping['group_id'], 'cost' => $shipping['total'], 'total_tax' => $shipping['tax'], 'taxes' => $shipping['taxes'] ] );
		}
		foreach ( $facts['tax'] as $tax ) {
			self::insert( $db, $prefix . 'woocommerce_order_items', [ 'order_item_id' => $tax['id'], 'order_item_type' => 'tax', 'order_item_name' => $tax['code'], 'order_id' => $id ] );
			self::meta( $db, $prefix . 'woocommerce_order_itemmeta', 'order_item_id', $tax['id'], [ 'rate_id' => $tax['rate_id'], 'label' => $tax['label'], 'compound' => $tax['compound'] ? '1' : '', 'tax_amount' => $tax['tax'], 'shipping_tax_amount' => $tax['shipping_tax'], 'rate_percent' => $tax['rate_percent'] ] );
		}
	}

	public static function insert( \mysqli $db, string $table, array $row ): void {
		if ( 1 !== preg_match( '/\\Agc6_[a-f0-9]{12}_[a-z_]+\\z/D', $table ) ) { throw new \InvalidArgumentException( 'Owned native table refused.' ); }
		$columns = implode( ',', array_map( static fn( string $column ): string => '`' . $column . '`', array_keys( $row ) ) ); $quoted = [];
		foreach ( $row as $value ) { $quoted[] = null === $value ? 'NULL' : "'" . $db->real_escape_string( (string) $value ) . "'"; }
		DB::execute( $db, "INSERT INTO `{$table}` ({$columns}) VALUES (" . implode( ',', $quoted ) . ')' );
	}

	public static function meta( \mysqli $db, string $table, string $column, int $id, array $values ): void {
		foreach ( $values as $key => $value ) { self::insert( $db, $table, [ $column => $id, 'meta_key' => $key, 'meta_value' => is_array( $value ) ? serialize( $value ) : (string) $value ] ); }
	}

	public static function census( \mysqli $db, string $prefix ): array {
		DB::validate_prefix( $prefix ); $out = [];
		foreach ( [ 'woocommerce_order_items', 'woocommerce_order_itemmeta', 'wc_orders', 'wc_order_operational_data', 'wc_order_addresses', 'wc_orders_meta', 'posts', 'postmeta', 'delivery_engine_promotion_budget_accounts', 'delivery_engine_promotion_budget_reservations', 'delivery_engine_delivery_quotes', 'delivery_engine_delivery_quote_bindings', 'delivery_engine_operation_records', 'delivery_engine_operation_changes', 'options' ] as $suffix ) {
			$result = $db->query( "SELECT * FROM `{$prefix}{$suffix}` ORDER BY 1" ); if ( ! $result instanceof \mysqli_result ) { throw new \RuntimeException( 'Owned native census failed.' ); } $out[$suffix] = $result->fetch_all( MYSQLI_ASSOC );
		}
		return $out;
	}

	/** Deterministic synthetic native getters from the exact compiled terms, never a second price/tax calculation. */
	public static function order( QuoteStoredRow $quote, array $mapping, array $groups, array $address, int $customer = 0, int $order_id = 100 ): PromotionNativeHandoffSqlOrder {
		$products = []; foreach ( $mapping['groups'] as $group ) { foreach ( $group['lines'] as $line ) { $products[$line['item_id']] = new PromotionNativeHandoffSqlProduct( $order_id, $line ); } }
		$shipping = []; $taxes = []; $shipping_units = 0; $tax_units = 0; $index = 0;
		foreach ( $quote->terms()->private_facts()['groups'] as $term ) {
			$component = $term['component_key']; if ( ! isset( $groups[$component] ) ) { throw new \InvalidArgumentException( 'Owned group fixture absent.' ); }
			$map = []; foreach ( $term['native_tax_receipt']['rates'] as $rate ) { $map[$rate['rate_id']] = $rate['amount']['amount']; $taxes[$rate['rate_id']] = ( $taxes[$rate['rate_id']] ?? 0 ) + self::units( $rate['amount']['amount'] ); }
			$shipping_units += self::units( $term['final']['amount'] ); $tax_units += self::units( $term['tax']['amount'] );
			$id = $order_id * 10 + 500 + ++$index;
			$shipping[$id] = new PromotionNativeHandoffSqlShipping( $id, $order_id, $groups[$component], $term['customer_label'], $term['final']['amount'], $term['tax']['amount'], $map );
		}
		$tax_items = []; foreach ( $taxes as $rate => $units ) { $id = $order_id * 10 + 700 + count( $tax_items ); $tax_items[$id] = new PromotionNativeHandoffSqlTax( $id, $order_id, (int) $rate, self::amount( $units ) ); }
		return new PromotionNativeHandoffSqlOrder( $order_id, $customer, $products, $shipping, $tax_items, $address, self::amount( $shipping_units ), self::amount( $tax_units ), self::amount( count( $products ) * 25000000 + $shipping_units + $tax_units ) );
	}
	private static function units( string $amount ): int { [ $whole, $fraction ] = array_pad( explode( '.', $amount, 2 ), 2, '' ); return (int) $whole * 1000000 + (int) str_pad( $fraction, 6, '0' ); }
	private static function amount( int $units ): string { return intdiv( $units, 1000000 ) . '.' . str_pad( (string) ( $units % 1000000 ), 6, '0', STR_PAD_LEFT ); }
}

/** Native identity fields are declared so the production authorization fence reads real raw properties. */
final class PromotionNativeHandoffSqlOrder extends \WC_Order {
	protected int $id;
	protected array $data;
	protected array $changes = [];
	public bool $poison = false;
	public function __construct( int $id, int $customer, private array $products, private array $shipping, private array $tax, private array $address, private string $shipping_total, private string $shipping_tax, private string $total ) {
		parent::__construct(); $this->id = $id; $this->data = [ 'customer_id' => $customer, 'order_key' => 'wc_order_n17_' . $id ];
	}
	private function read(): void { if ( $this->poison ) { throw new \LogicException( 'Native getters must not execute under SQL proof.' ); } }
	public function get_id(): int { $this->read(); return $this->id; }
	public function get_customer_id( string $context = 'view' ): int { $this->read(); return $this->data['customer_id']; }
	public function get_order_key( string $context = 'view' ): string { $this->read(); return $this->data['order_key']; }
	public function get_currency( string $context = 'view' ): string { $this->read(); return 'GHS'; }
	public function get_type(): string { $this->read(); return 'shop_order'; }
	public function get_parent_id( string $context = 'view' ): int { $this->read(); return 0; }
	public function get_status(): string { $this->read(); return 'pending'; }
	public function is_paid(): bool { $this->read(); return false; }
	public function get_date_paid( $context = 'view' ): mixed { $this->read(); return null; }
	public function get_total( string $context = 'view' ): string { $this->read(); return $this->total; }
	public function get_total_tax( string $context = 'view' ): string { $this->read(); return $this->shipping_tax; }
	public function get_cart_tax( string $context = 'view' ): string { $this->read(); return '0'; }
	public function get_shipping_total( string $context = 'view' ): string { $this->read(); return $this->shipping_total; }
	public function get_shipping_tax( string $context = 'view' ): string { $this->read(); return $this->shipping_tax; }
	public function get_items( string $type = '' ): array { $this->read(); return match ( $type ) { '', 'line_item' => $this->products, 'shipping' => $this->shipping, 'tax' => $this->tax, default => [] }; }
	public function get_shipping_country( string $context = 'view' ): string { $this->read(); return $this->address['country']; }
	public function get_shipping_state( string $context = 'view' ): string { $this->read(); return $this->address['state']; }
	public function get_shipping_city( string $context = 'view' ): string { $this->read(); return $this->address['city']; }
	public function get_shipping_postcode( string $context = 'view' ): string { $this->read(); return $this->address['postcode']; }
	public function get_shipping_address_1( string $context = 'view' ): string { $this->read(); return $this->address['address'] ?? $this->address['address_1']; }
	public function get_shipping_address_2( string $context = 'view' ): string { $this->read(); return $this->address['address_2']; }
}

final class PromotionNativeHandoffSqlProduct extends \WC_Order_Item_Product {
	public function __construct( private int $order, private array $line ) { parent::__construct( [ 'id' => $line['item_id'], 'product_id' => $line['product_id'], 'variation_id' => $line['variation_id'] ?? 0, 'quantity' => (int) $line['quantity'], 'meta' => [ QuoteNativeOrderFacts::META_LINE_KEY => $line['line_key'] ] ] ); }
	public function get_order_id( string $context = 'view' ): int { return $this->order; }
	public function get_total( string $context = 'view' ): string { return '25'; }
	public function get_total_tax( string $context = 'view' ): string { return '0'; }
	public function get_subtotal( string $context = 'view' ): string { return '25'; }
	public function get_subtotal_tax( string $context = 'view' ): string { return '0'; }
	public function get_taxes( string $context = 'view' ): array { return [ 'total' => [], 'subtotal' => [] ]; }
}

final class PromotionNativeHandoffSqlShipping extends \WC_Order_Item_Shipping {
	public function __construct( private int $id, private int $order, private string $group, private string $label, private string $amount, private string $tax, private array $rates ) { parent::__construct(); }
	public function get_id(): int { return $this->id; }
	public function get_order_id( string $context = 'view' ): int { return $this->order; }
	public function get_name( string $context = 'view' ): string { return $this->label; }
	public function get_method_id( string $context = 'view' ): string { return 'delivery_engine_selected_offer'; }
	public function get_instance_id( string $context = 'view' ): int { return 1; }
	public function get_total( string $context = 'view' ): string { return $this->amount; }
	public function get_total_tax( string $context = 'view' ): string { return $this->tax; }
	public function get_taxes( string $context = 'view' ): array { return [ 'total' => $this->rates ]; }
	public function get_meta( string $key, bool $single = true ): mixed { return 'cetech_de_group_id' === $key ? $this->group : ''; }
}

// The stock minimal harness does not define tax items. This private class is aliased only on construction.
final class PromotionNativeHandoffSqlTax {
	public function __construct( private int $id, private int $order, private int $rate, private string $amount ) { if ( ! class_exists( '\WC_Order_Item_Tax' ) ) { class_alias( self::class, '\WC_Order_Item_Tax' ); } }
	public function get_id(): int { return $this->id; }
	public function get_order_id( string $context = 'view' ): int { return $this->order; }
	public function get_rate_id( string $context = 'view' ): int { return $this->rate; }
	public function get_name( string $context = 'view' ): string { return 'N17-TAX-' . $this->rate; }
	public function get_label( string $context = 'view' ): string { return 'Owned synthetic rate'; }
	public function get_compound( string $context = 'view' ): bool { return false; }
	public function get_rate_percent( string $context = 'view' ): string { return '20'; }
	public function get_tax_total( string $context = 'view' ): string { return '0'; }
	public function get_shipping_tax_total( string $context = 'view' ): string { return $this->amount; }
}
}
