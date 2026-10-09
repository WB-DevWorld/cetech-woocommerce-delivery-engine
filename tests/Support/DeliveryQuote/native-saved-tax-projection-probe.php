<?php
declare(strict_types=1);

require dirname( __DIR__, 3 ) . '/vendor/autoload.php';
require __DIR__ . '/QuoteStorageFixtures.php';
require dirname( __DIR__, 2 ) . '/Unit/Order/DeliveryQuoteSnapshotFixtures.php';

use CetechDeliveryEngine\Application\DeliveryQuote\{NativeCartQuotePreparation,QuoteNativeContextIdentity,QuoteNativeReceiptGuard,QuoteNativeTaxSource,QuoteNativeWooSource,QuoteSavedOrderAuthorization,QuoteSavedOrderNativeEvidence,QuoteSavedOrderNativeTaxGuard,QuotePlacementSavedEvidenceGuard};
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding,QuoteContext,QuoteHeader,QuoteJson,QuoteStoredRow,QuoteTerms};
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult,OperationConnectionFactory,OperationSession};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteFixtures,QuoteStorageFixtures};
use CetechDeliveryEngine\Tests\Unit\Order\DeliveryQuoteSnapshotFixtures;

/** Isolated native-shaped IO boundary; no cart, tax source writes or placement claim. */
final class SavedTaxCalls {
	public static string $mode; public static array $physical = [], $options = [], $rates = []; public static int $finders = 0, $native = 0, $callbacks = 0, $physical_reads = 0, $native_tax_callbacks = 0, $factory_calls = 0, $line_census_reads = 0; public static bool $owned = false;
	public static function native(): void { ++self::$native; if ( self::$owned ) { throw new LogicException( 'Native call under owned SQL.' ); } }
	public static function hook(): void { $hook = new WP_Hook(); $hook->callbacks = [ 10 => [ [ 'function' => static function(): void { ++self::$callbacks; }, 'accepted_args' => 1 ] ] ]; $GLOBALS['wp_filter']['woocommerce_matched_tax_rates'] = $hook; }
	public static function native_hooks( string $kind = 'native' ): void {
		$class = 'subclass' === $kind ? SavedTaxControllerSubclass::class : 'Automattic\\WooCommerce\\Blocks\\Shipping\\ShippingController'; $controller = new $class();
		foreach ( [ [ 'woocommerce_shipping_packages', 'filter_shipping_packages', 10, 1 ], [ 'woocommerce_shipping_packages', 'remove_shipping_if_no_address', 11, 1 ], [ 'woocommerce_local_pickup_methods', 'register_local_pickup_method', 10, 1 ], [ 'woocommerce_customer_taxable_address', 'filter_taxable_address', 10, 1 ], [ 'woocommerce_order_get_tax_location', 'filter_order_tax_location', 10, 2 ] ] as [$name,$method,$priority,$args] ) {
			$object = 'foreign' === $kind && 'woocommerce_order_get_tax_location' === $name ? new $class() : $controller;
			if ( 'woocommerce_order_get_tax_location' === $name ) { if ( 'priority' === $kind ) { $priority = 11; } if ( 'args' === $kind ) { $args = 1; } }
			$hook = $GLOBALS['wp_filter'][$name] ?? new WP_Hook(); $key = 'registration' === $kind && 'woocommerce_order_get_tax_location' === $name ? 'foreign-registration' : (string)spl_object_id($object).$method;
			$hook->callbacks[$priority][$key] = [ 'function' => [$object,$method], 'accepted_args' => $args ]; $GLOBALS['wp_filter'][$name] = $hook;
		}
		if ( 'unknown' === $kind || 'method_filter' === $kind ) { self::unknown_hook('method_filter' === $kind ? 'woocommerce_order_item_get_method_id' : 'woocommerce_order_get_tax_location'); }
	}
	public static function unknown_hook(string $name):void { $hook = $GLOBALS['wp_filter'][$name] ?? new WP_Hook(); $hook->callbacks[10]['unknown'] = [ 'function' => static function():void { ++SavedTaxCalls::$callbacks; }, 'accepted_args'=>2 ]; $GLOBALS['wp_filter'][$name]=$hook; }
	public static function option_hook():void { $hook=new WP_Hook(); $hook->callbacks[10]['unknown']=['function'=>static function(mixed $value):mixed { ++SavedTaxCalls::$callbacks; return $value; },'accepted_args'=>1]; $GLOBALS['wp_filter']['option_woocommerce_delivery_engine_selected_offer_1_settings']=$hook; }
	public static function replace_native_hooks():void { foreach ( ['woocommerce_shipping_packages','woocommerce_local_pickup_methods','woocommerce_customer_taxable_address','woocommerce_order_get_tax_location'] as $hook ) { unset($GLOBALS['wp_filter'][$hook]); } self::native_hooks(); }
	public static function rows( string $sql ): array { foreach ( [ 'options' => 'option_rows', 'woocommerce_tax_rate_locations' => 'tax_location_rows', 'woocommerce_tax_rates' => 'tax_rows', 'wc_tax_rate_classes' => 'tax_class_rows', 'woocommerce_shipping_zone_methods' => 'method_rows', 'woocommerce_sessions' => 'session_row', 'usermeta' => 'customer_rows' ] as $suffix => $kind ) { if ( str_contains( $sql, '`wp_' . $suffix . '`' ) ) { return self::$physical[$kind]; } } throw new LogicException( 'Unknown native source query.' ); }
}
class WP_Hook { public array $callbacks = []; }
eval('namespace Automattic\\WooCommerce\\Blocks\\Shipping; class ShippingController { public function filter_shipping_packages($value) { return $value; } public function remove_shipping_if_no_address($value) { return $value; } public function register_local_pickup_method($value) { return $value; } public function filter_taxable_address($value) { return $value; } public function filter_order_tax_location($value,$order) { ++\\SavedTaxCalls::$native_tax_callbacks; return $value; } }');
class SavedTaxControllerSubclass extends \Automattic\WooCommerce\Blocks\Shipping\ShippingController {}
class WC_Shipping_Method {}
class WC_Cache_Helper { public static function invalidate_cache_group( string $group ): void { SavedTaxCalls::native(); } }
class WC_Tax {
	public static function init(): void { SavedTaxCalls::native(); }
	public static function find_shipping_rates( array $location ): array { SavedTaxCalls::native(); ++SavedTaxCalls::$finders; if ( 'hook_during' === SavedTaxCalls::$mode ) { SavedTaxCalls::hook(); } if ( 'native_hook_during_replace' === SavedTaxCalls::$mode ) { SavedTaxCalls::replace_native_hooks(); } if ( 'dynamic_option_during' === SavedTaxCalls::$mode ) { SavedTaxCalls::option_hook(); } if ( 'source_during' === SavedTaxCalls::$mode ) { SavedTaxCalls::$physical['tax_rows'][0]['tax_rate_name'] = 'Changed without changing money'; } return SavedTaxCalls::$rates; }
	public static function calc_shipping_tax( float $amount, array $rates ): array { SavedTaxCalls::native(); $out = []; foreach ( $rates as $id => $rate ) { $out[$id] = (string) ( $amount * $rate['rate'] / 100 ); } return $out; }
}
class WC_Order_Item_Shipping {
	public function __construct( public array $taxes ) {}
	public function get_method_id( string $context ): string { SavedTaxCalls::native(); return 'delivery_engine_selected_offer'; }
	public function get_instance_id( string $context ): int { SavedTaxCalls::native(); return 'instance' === SavedTaxCalls::$mode ? 2 : 1; }
	public function get_meta( string $key, bool $single ): string { SavedTaxCalls::native(); return 'in_warehouse|delivery|20|native'; }
	public function get_total( string $context ): string { SavedTaxCalls::native(); return 'money' === SavedTaxCalls::$mode ? '0.000001' : ( 'paid' === SavedTaxCalls::$mode ? '10.00' : '0.00' ); }
	public function get_taxes( string $context ): array { SavedTaxCalls::native(); return [ 'total' => $this->taxes ]; }
	public function get_total_tax( string $context ): string { SavedTaxCalls::native(); return 'paid' === SavedTaxCalls::$mode ? '1.50' : '0.00'; }
}
class WC_Order_Item_Product {
	public function __construct( private int $id=901, private int $order_id=701 ) {}
	public function get_id():int { SavedTaxCalls::native(); return $this->id; }
	public function get_order_id(string $context):int { SavedTaxCalls::native(); return $this->order_id; }
	public function get_meta( string $key, bool $single ): mixed { SavedTaxCalls::native(); if ( OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION === $key ) { return '1'; } if ( OrderDeliverySnapshot::META_LINE_SNAPSHOT !== $key ) { return ''; } $line = DeliveryQuoteSnapshotFixtures::line(); $line['delivery_group_id'] = 'in_warehouse|delivery|20|native'; return json_encode( $line, JSON_THROW_ON_ERROR ); }
	public function meta_exists( string $key ): bool { SavedTaxCalls::native(); return false; }
}
class SavedTaxProductSubclass extends WC_Order_Item_Product {}
class WC_Order {
	protected int $id = 701; protected array $data = [ 'customer_id' => 0, 'order_key' => 'PRIVATE-EXACT-ORDER-KEY' ]; protected array $changes = [];
	private array $line_items;
	public function __construct( public WC_Order_Item_Shipping $shipping ) { $product='cached_subclass'===SavedTaxCalls::$mode?new SavedTaxProductSubclass():new WC_Order_Item_Product('cached_id'===SavedTaxCalls::$mode?902:901,'cached_parent'===SavedTaxCalls::$mode?702:701); $this->line_items=['cached_key'===SavedTaxCalls::$mode?'0901':901=>'cached_class'===SavedTaxCalls::$mode?new stdClass():$product]; if ('cached_missing'===SavedTaxCalls::$mode) { $this->line_items=[]; } if ('cached_extra'===SavedTaxCalls::$mode) { $this->line_items[902]=new WC_Order_Item_Product(902); } if ('cached_foreign'===SavedTaxCalls::$mode) { $this->line_items=[902=>new WC_Order_Item_Product(902)]; } }
	public function get_id():int { SavedTaxCalls::native(); return $this->id; }
	public function get_currency( string $context ): string { SavedTaxCalls::native(); return 'GHS'; }
	public function get_customer_id( string $context ): int { SavedTaxCalls::native(); return 'customer' === SavedTaxCalls::$mode ? 1 : 0; }
	public function get_items( string $type ): array { SavedTaxCalls::native(); if ('line_item'===$type) { ++SavedTaxCalls::$line_census_reads; return $this->line_items; } return 'shipping' === $type ? [ $this->shipping ] : ( 'fee' === $type && 'fee' === SavedTaxCalls::$mode ? [ new stdClass() ] : [] ); }
	public function get_items_tax_classes(): array { SavedTaxCalls::native(); return [ '' ]; }
	public function get_taxable_location(): array { SavedTaxCalls::native(); $location=[ 'country' => 'location' === SavedTaxCalls::$mode ? 'US' : 'GH', 'state' => 'AA', 'postcode' => '00001', 'city' => 'Accra' ]; foreach ( ($GLOBALS['wp_filter']['woocommerce_order_get_tax_location']->callbacks??[]) as $items ) { foreach($items as $item) { $location=($item['function'])($location,$this); } } return $location; }
	public function get_meta( string $key, bool $single ): string { SavedTaxCalls::native(); return 'exempt' === SavedTaxCalls::$mode ? 'yes' : ''; }
	public function get_item( int $id ): never { ++SavedTaxCalls::$factory_calls; throw new LogicException('A fresh order item factory must never run.'); }
}
class SavedTaxDatabase {
	public mysqli $dbh; public string $prefix = 'wp_', $usermeta = 'wp_usermeta';
	public function __construct() { $this->dbh = new mysqli(); }
	public function prepare( string $sql, mixed ...$args ): string { return "'" . str_replace( "'", "''", (string) $args[0] ) . "'"; }
	public function get_results( string $sql, mixed $format ): array { ++SavedTaxCalls::$physical_reads; return SavedTaxCalls::rows( $sql ); }
}
function get_current_blog_id(): int { SavedTaxCalls::native(); return 1; }
function get_option( string $name, mixed $default = null ): mixed { SavedTaxCalls::native(); if ( 'cached_option' === SavedTaxCalls::$mode && 'woocommerce_currency' === $name ) { return 'USD'; } $value=SavedTaxCalls::$options[$name]??$default; foreach (($GLOBALS['wp_filter']['option_'.$name]->callbacks??[]) as $items) { foreach($items as $item) { $value=($item['function'])($value); } } return $value; }
function wc_get_price_decimals(): int { SavedTaxCalls::native(); return 'precision' === SavedTaxCalls::$mode ? 3 : 2; }
function wc_tax_enabled(): bool { SavedTaxCalls::native(); return true; }
function wp_salt( string $scheme ): string { SavedTaxCalls::native(); return 'isolated-saved-native-tax-key'; }
function has_filter( string $hook ): bool { SavedTaxCalls::native(); return false; }
function is_serialized( mixed $value, bool $strict = true ): bool { return is_string( $value ) && str_starts_with( $value, 'a:' ); }
function wc_format_decimal( mixed $amount, mixed $precision ): string { SavedTaxCalls::native(); return is_string( $amount ) ? $amount : rtrim( rtrim( sprintf( '%.6F', $amount ), '0' ), '.' ); }
function wp_cache_delete( string $key, string $group ): void { SavedTaxCalls::native(); }
define( 'ARRAY_A', 'ARRAY_A' );

final class SavedTaxSession implements OperationSession {
	public int $reads = 0; public bool $retired = false;
	public function site_id(): int { return 1; } public function table_prefix(): string { return 'wp_'; } public function charset_collate(): string { return 'utf8mb4'; } public function begin(): bool { return true; } public function commit(): OperationCommitResult { throw new LogicException( 'No commit.' ); } public function rollback(): bool { return true; } public function retire(): bool { return $this->retired = true; } public function is_retired(): bool { return $this->retired; } public function in_transaction(): bool { return true; } public function validate_tables( array $tables ): bool { return true; } public function query( string $sql ): int|false { throw new LogicException( 'No writes.' ); } public function get_row( string $sql ): array|null|false { throw new LogicException( 'No arbitrary reads.' ); } public function errno(): int { return 0; } public function insert_id(): int { return 0; }
	public function prepare( string $sql, mixed ...$args ): string { $value = $args[0]; if ( is_array( $value ) ) { $value = $value[0]; } return "'" . str_replace( "'", "''", (string) $value ) . "'"; }
	public function get_results( string $sql ): array|false { ++$this->reads; if ( ! str_ends_with( $sql, ' FOR UPDATE' ) ) { throw new LogicException( 'Unfenced source.' ); } return SavedTaxCalls::rows( $sql ); }
}

SavedTaxCalls::$mode = $argv[1] ?? 'prewarm'; $GLOBALS['wp_filter'] = []; $GLOBALS['blog_id'] = 1; $GLOBALS['current_user'] = (object) [ 'ID' => 0 ]; $GLOBALS['wpdb'] = new SavedTaxDatabase();
$location = QuoteNativeContextIdentity::from_private_key( 'isolated-saved-native-tax-key' )->tax_location_digest( 1, [ 'GH', 'AA', '00001', 'Accra' ] );
SavedTaxCalls::$options = [ 'woocommerce_currency' => 'GHS', 'woocommerce_shipping_tax_class' => 'inherit', 'woocommerce_delivery_engine_selected_offer_1_settings' => [ 'tax_status' => 'taxable' ] ];
$option_rows = []; foreach ( SavedTaxCalls::$options as $name => $value ) { $option_rows[] = [ 'option_id' => (string) (count($option_rows)+1), 'option_name' => $name, 'option_value' => is_array($value) ? serialize($value) : $value, 'autoload' => 'off' ]; } usort( $option_rows, static fn( array $a, array $b ): int => strcmp( $a['option_name'], $b['option_name'] ) );
$tax_rows = []; foreach ( [ 1 => '10.0000', 2 => '5.0000', 9 => '7.0000' ] as $id => $rate ) { $tax_rows[] = [ 'tax_rate_id' => (string)$id, 'tax_rate_country' => 9 === $id ? 'US' : 'GH', 'tax_rate_state' => '', 'tax_rate' => $rate, 'tax_rate_name' => 'Native tax '.$id, 'tax_rate_priority' => (string)$id, 'tax_rate_compound' => '0', 'tax_rate_shipping' => '1', 'tax_rate_order' => (string)$id, 'tax_rate_class' => '' ]; }
$names = [ ...\CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeWooSource::OPTIONS, 'woocommerce_delivery_engine_selected_offer_1_settings' ]; sort( $names, SORT_STRING );
SavedTaxCalls::$physical = [ 'option_rows' => $option_rows, 'tax_rows' => $tax_rows, 'tax_class_rows' => [], 'tax_location_rows' => [], 'method_rows' => [ [ 'zone_id'=>'1','instance_id'=>'1','method_id'=>'delivery_engine_selected_offer','method_order'=>'0','is_enabled'=>'1' ] ], 'session_row' => [ [ 'session_id'=>'1','session_key'=>'original-native-session','session_value'=>'PRIVATE-CURRENT-NATIVE-SESSION','session_expiry'=>'2000000000' ] ], 'customer_rows'=>[], 'selectors'=>[ 'option_names'=>$names,'tax_class'=>'','method_instance_ids'=>[1],'session_key'=>'original-native-session','customer_id'=>0,'site_id'=>1,'table_prefix'=>'wp_' ] ];
SavedTaxCalls::$rates = [ 1 => [ 'rate'=>10.0,'label'=>'Native tax 1','shipping'=>'yes','compound'=>'no' ], 2 => [ 'rate'=>5.0,'label'=>'Native tax 2','shipping'=>'yes','compound'=>'no' ] ];
$material = SavedTaxCalls::$physical; unset( $material['session_row'] );
$original = QuoteNativeTaxSource::from_private_facts( [ 'format_version'=>1,'digest_version'=>2,'currency'=>'GHS','precision'=>2,'exempt'=>false,'tax_class'=>'','location_digest'=>$location,'rounding'=>'per_line','tax_enabled'=>true,'source'=>$material ] );
$component = NativeCartQuotePreparation::component_key( 'in_warehouse|delivery|20|native' ); $context_facts = QuoteFixtures::context()->private_facts(); $context_facts['tax']['context_digest'] = $original->digest(); $context_facts['lines'][0]['component_key'] = $component; $context_facts['groups'][0]['component_key'] = $component; $context = QuoteContext::from_array( $context_facts );
$terms_facts = QuoteFixtures::terms()->private_facts(); $term = &$terms_facts['groups'][0]; $term['component_key'] = $component; foreach ( [ 'list','final','tax','total' ] as $field ) { $term[$field]['amount'] = '0.00'; } $term['native_tax_receipt'] = array_replace( $term['native_tax_receipt'], [ 'context_digest'=>$original->digest(),'exempt'=>false,'tax_status'=>'taxable','location_digest'=>$location ] ); foreach ( [ 'native_total','display_total' ] as $field ) { $term['native_money_receipt'][$field]['amount'] = '0.00'; }
if ( 'paid' === SavedTaxCalls::$mode ) { foreach ( [ 'list','final' ] as $field ) { $term[$field]['amount'] = '10.00'; } $term['tax']['amount'] = '1.50'; $term['total']['amount'] = '11.50'; $term['native_tax_receipt']['rounded_tax']['amount'] = '1.50'; $term['native_tax_receipt']['rates'] = [ [ 'rate_id'=>1,'amount'=>[ 'amount'=>'1.00','currency'=>'GHS','precision'=>2 ] ], [ 'rate_id'=>2,'amount'=>[ 'amount'=>'0.50','currency'=>'GHS','precision'=>2 ] ] ]; foreach ( [ 'native_total','display_total' ] as $field ) { $term['native_money_receipt'][$field]['amount'] = '11.50'; } }
unset( $term ); $terms = QuoteTerms::from_array( $terms_facts ); $base = QuoteStorageFixtures::quote( state:'accepted' ); $header = QuoteHeader::issue( $base->header()->id(),$base->header()->owner(),$context,$terms,QuoteFixtures::time(),$base->header()->namespace_hashes(),'fixture_v1',1,QuoteFixtures::reference($base->header()->id()) ); $quote = QuoteStoredRow::from_row( array_replace( $base->row(), [ 'material_digest'=>$header->material_digest(),'body_digest'=>$header->body_digest(),'header_json'=>$header->to_private_json(),'private_body_json'=>QuoteJson::encode( [ 'context'=>$context->private_facts(),'terms'=>$terms->private_facts() ] ) ] ) );
$map = 'empty' === SavedTaxCalls::$mode ? [] : ( 'paid' === SavedTaxCalls::$mode ? [1=>'1.00',2=>'0.50'] : [1=>'0',2=>'0.000000'] ); if ( 'foreign' === SavedTaxCalls::$mode ) { $map = [1=>'0',9=>'0']; } if ( 'subset' === SavedTaxCalls::$mode ) { $map = [1=>'0']; } if ( 'tiny' === SavedTaxCalls::$mode ) { $map[1] = '0.000001'; }
$order = new WC_Order( new WC_Order_Item_Shipping($map) ); $binding = QuoteStorageFixtures::binding($quote,order:701); $factory = new class implements OperationConnectionFactory { public function open(): OperationSession { throw new LogicException('No owned unit during native prewarm.'); } }; $evidence = new QuoteSavedOrderNativeEvidence($factory);
if ( 'hook_before' === SavedTaxCalls::$mode ) { SavedTaxCalls::hook(); } if ( 'source_before' === SavedTaxCalls::$mode ) { SavedTaxCalls::$physical['tax_rows'][0]['tax_rate_name'] = 'Changed with identical amounts'; }
if ('dynamic_option_before'===SavedTaxCalls::$mode) { SavedTaxCalls::option_hook(); }
if ( str_contains(SavedTaxCalls::$mode,'native_hook') ) { $kind=preg_replace('/\Anative_hook_?/','',SavedTaxCalls::$mode); SavedTaxCalls::native_hooks(in_array($kind,['unknown','priority','args','foreign','registration','subclass','method_filter'],true)?$kind:'native'); }
$before_map = $order->shipping->taxes; $ok = false; $guard_ok = null; $guard_reads = 0; $owned_native = 0; $error_class = null; $error_line = null;
try {
	if ( 'prewarm' === SavedTaxCalls::$mode ) { $projection = QuoteSavedOrderNativeEvidence::prewarm_tax($order,$quote,$original); $ok = $projection->native_rates() === SavedTaxCalls::$rates && QuoteJson::encode($projection->physical()) === QuoteJson::encode(SavedTaxCalls::$physical); }
	else { $hooks=QuoteNativeWooSource::capture_hook_fence($original->private_facts()['source']['selectors']['option_names']); $physical = (new ReflectionMethod($evidence,'native_tax'))->invoke($evidence,$order,$quote,$binding,$context,$original,$hooks); $ok = QuoteJson::encode($physical) === QuoteJson::encode(SavedTaxCalls::$physical);
		if ( str_starts_with(SavedTaxCalls::$mode,'guard') ) { $authorization = QuoteSavedOrderAuthorization::capture($order,static fn():bool=>true); $saved = new class implements QuotePlacementSavedEvidenceGuard { public function tables(OperationSession $s):array { return ['wp_owned_order']; } public function verify(OperationSession $s,QuoteBinding $b):bool { if ('guard_native_hook_replaced_after_sql'===SavedTaxCalls::$mode) { SavedTaxCalls::replace_native_hooks(); } if ('guard_dynamic_option_after_sql'===SavedTaxCalls::$mode) { SavedTaxCalls::option_hook(); } return 'guard_saved_changed' !== SavedTaxCalls::$mode; } }; $guard = new QuoteSavedOrderNativeTaxGuard($quote->header()->owner(),$context,$physical,$authorization,$saved,$binding,$hooks); if ('guard_source_changed'===SavedTaxCalls::$mode) { SavedTaxCalls::$physical['tax_rows'][0]['tax_rate_name']='Changed with same money'; } if ('guard_hook_changed'===SavedTaxCalls::$mode) { SavedTaxCalls::hook(); } if ('guard_session_changed'===SavedTaxCalls::$mode) { SavedTaxCalls::$physical['session_row'][0]['session_value']='Changed native session'; } if ('guard_native_hook_replaced'===SavedTaxCalls::$mode) { SavedTaxCalls::replace_native_hooks(); } if ('guard_native_hook_method_filter'===SavedTaxCalls::$mode) { SavedTaxCalls::unknown_hook('woocommerce_order_item_get_method_id'); } if ('guard_dynamic_option'===SavedTaxCalls::$mode) { SavedTaxCalls::option_hook(); } $session = new SavedTaxSession(); $n = SavedTaxCalls::$native; SavedTaxCalls::$owned = true; try { $guard_ok=$guard->verify($session,$quote->header()->owner(),$context); } finally { SavedTaxCalls::$owned=false; $owned_native=SavedTaxCalls::$native-$n; } $guard_reads=$session->reads; }
	}
} catch ( Throwable $error ) { $error_class = get_class($error); $error_line = $error->getLine(); }
echo json_encode( [ 'accepted'=>$ok,'finders'=>SavedTaxCalls::$finders,'actual_map_unchanged'=>$before_map===$order->shipping->taxes,'callbacks'=>SavedTaxCalls::$callbacks,'native_tax_callbacks'=>SavedTaxCalls::$native_tax_callbacks,'factory_calls'=>SavedTaxCalls::$factory_calls,'line_census_reads'=>SavedTaxCalls::$line_census_reads,'physical_reads'=>SavedTaxCalls::$physical_reads,'guard_accepted'=>$guard_ok,'guard_reads'=>$guard_reads,'owned_native_calls'=>$owned_native,'fresh_hooks_supported'=>QuoteNativeWooSource::supports_current_hooks(),'error_class'=>$error_class,'error_line'=>$error_line ], JSON_THROW_ON_ERROR ),"\n";
