<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote {
	/** Source failure seam after the genuine environment's second native calculation. */
	final class NativeCartQuotePreparation {
		public function __construct( \CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory $factory ) {}
		public function prepare_promotion( QuoteCartDraft $draft, \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime $at, callable $unchanged ): FixedPromotionNativeCartPreparedCapture {
			NativeCartQuoteShipping::prepare_promotion_after_admission();
			if ( 'swapped' === \Calls::$mode ) { $GLOBALS['woocommerce']->session = \Calls::$foreign; }
			if ( 'key_changed' === \Calls::$mode ) { \Calls::$original->change_key(); }
			throw new \RuntimeException( 'Synthetic post-totals source fence refusal.' );
		}
	}
}
namespace {
	require dirname( __DIR__, 3 ) . '/vendor/autoload.php';
	use CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuoteEnvironment;
	use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
	use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory, OperationSession};
	define( 'AUTH_KEY', str_repeat( 'a', 32 ) ); define( 'AUTH_SALT', str_repeat( 'b', 32 ) ); define( 'LOGGED_IN_COOKIE', 'native_logged_in' );
	final class Calls { public static int $totals = 0, $layouts = 0, $writes = 0, $reads = 0, $retired = 0; public static string $mode; public static WC_Session_Handler $original, $foreign; }
	class WC_Cart {
		public array $cart_contents = []; protected array $totals = [], $shipping_methods = []; protected bool $has_calculated_shipping = false;
		public function get_shipping_packages(): array { ++Calls::$layouts; return [ [ 'contents' => $this->cart_contents ] ]; }
		public function calculate_totals(): void {
			++Calls::$totals; $amount = 1 === Calls::$totals ? '12.50' : '9.50'; $this->totals = [ 'shipping_total' => $amount, 'shipping_tax' => '0' ]; $this->shipping_methods = [ 'discounted-rate' ]; $this->has_calculated_shipping = true;
			$GLOBALS['woocommerce']->session->packet( 'shipping_for_package_0', [ 'amount' => $amount ] ); $GLOBALS['woocommerce']->session->packet( 'cart_totals', $this->totals ); $GLOBALS['woocommerce']->session->packet( 'chosen_shipping_methods', [ 'discounted-rate' ] );
			WC_Shipping::current()->packages = [ [ 'rates' => [ 'discounted-rate' => $amount ] ] ];
		}
		public function reset(): bool { return [] === $this->totals && [] === $this->shipping_methods && ! $this->has_calculated_shipping; }
	}
	class WC_Session_Handler {
		protected string $_customer_id = '9', $_cookie = 'native_session'; protected bool $_has_cookie = true; protected array $_data = [ 'unrelated' => 'preserve' ]; public array $persisted = [];
		public function packet( string $key, mixed $value ): void { $this->_data[$key] = $value; }
		public function __unset( string $key ): void { unset( $this->_data[$key] ); }
		public function save_data(): void { ++Calls::$writes; $this->persisted = $this->_data; }
		public function change_key(): void { $this->_customer_id = '10'; }
	}
	class WC_Shipping { protected static ?self $_instance = null; public array $packages = []; public static function current(): self { return self::$_instance ??= new self(); } }
	class WC_Customer { protected array $data = [ 'shipping' => [ 'country' => 'GH', 'state' => 'AA', 'city' => 'Accra', 'postcode' => '00001', 'address_1' => 'PRIVATE ADDRESS', 'address_2' => '' ] ], $changes = []; }
	class WP_Object_Cache { private array $cache = [ 'options' => [ 'alloptions' => [ 'woocommerce_currency' => 'GHS', 'woocommerce_price_num_decimals' => '2' ] ] ]; private string $blog_prefix = ''; protected array $global_groups = []; private bool $multisite = false; }
	class wpdb { public string $prefix = 'wp_'; }
	Calls::$mode = $argv[1] ?? 'late'; Calls::$original = new WC_Session_Handler(); Calls::$foreign = new WC_Session_Handler(); Calls::$foreign->packet( 'shipping_for_package_0', [ 'amount' => 'FOREIGN' ] ); Calls::$foreign->save_data(); Calls::$writes = 0;
	$cart = new WC_Cart(); $selection = [ 'contract_version' => '1', 'product_id' => 10, 'variation_id' => null, 'target_type' => 'product', 'target_id' => 10, 'display_key' => 'in_warehouse:delivery:20', 'fulfilment_availability' => 'in_warehouse', 'fulfilment_choice' => 'delivery', 'delivery_offer_id' => 20, 'rule_id' => 1 ];
	$cart->cart_contents['line_one'] = [ 'product_id' => 10, 'variation_id' => 0, 'quantity' => 2, 'cetech_de_delivery_selection' => $selection, 'cetech_de_delivery_selection_hash' => hash( 'sha256', 'selection' ), 'cetech_de_customer_context' => [ 'contract_version' => 1, 'fulfilment_choice' => 'delivery', 'delivery_offer_id' => 20 ] ];
	WC_Shipping::current(); $GLOBALS['woocommerce'] = (object) [ 'cart' => $cart, 'session' => Calls::$original, 'customer' => new WC_Customer() ]; $GLOBALS['current_user'] = (object) [ 'ID' => 9 ]; $GLOBALS['blog_id'] = 1; $GLOBALS['wp_filter'] = []; $GLOBALS['wp_object_cache'] = new WP_Object_Cache(); $_COOKIE[LOGGED_IN_COOKIE] = 'name|2000000000|first-auth-session|signature';
	$GLOBALS['wpdb'] = new wpdb();
	if ( 'cold' === Calls::$mode ) { ( new \ReflectionProperty( WC_Shipping::class, '_instance' ) )->setValue( null, null ); }
	$factory = new class implements OperationConnectionFactory { public function open(): OperationSession { return new class implements OperationSession {
		private bool $active = false, $retired = false; public function site_id(): int { return 1; } public function table_prefix(): string { return 'wp_'; } public function charset_collate(): string { return 'utf8mb4_unicode_ci'; }
		public function begin(): bool { $this->active = true; return true; } public function rollback(): bool { $this->active = false; return true; } public function retire(): bool { $this->retired = true; ++Calls::$retired; return true; } public function is_retired(): bool { return $this->retired; } public function in_transaction(): bool { return $this->active; }
		public function validate_tables( array $tables ): bool { return [ 'wp_woocommerce_sessions' ] === $tables; } public function prepare( string $sql, mixed ...$args ): string { return $sql; }
		public function get_results( string $sql ): array|false { if ( ! $this->active || $this->retired ) { throw new \LogicException( 'Unowned cleanup read.' ); } ++Calls::$reads; return [ [ 'session_key' => '9', 'session_value' => in_array( Calls::$mode, [ 'stale_read', 'fresh_stale_read' ], true ) ? serialize( [ 'shipping_for_package_0' => [ 'amount' => '9.50' ] ] ) : serialize( Calls::$original->persisted ) ] ]; }
		public function commit(): \CetechDeliveryEngine\Domain\Operation\OperationCommitResult { throw new \LogicException( 'Cleanup must not commit.' ); } public function query( string $sql ): int|false { throw new \LogicException( 'Cleanup must not write SQL.' ); } public function get_row( string $sql ): array|null|false { throw new \LogicException( 'Unexpected read.' ); } public function errno(): int { return 0; } public function insert_id(): int { return 0; }
	}; } };
	$environment = new NativeCartQuoteEnvironment( $factory, null, null, static fn(): string => 'fixed_promotion_native_v1' ); $draft = $environment->draft(); $refused = false;
	$abort_confirmed = null;
	try { if ( in_array( Calls::$mode, [ 'fresh', 'fresh_stale_read' ], true ) ) { \CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuoteShipping::prepare_promotion_after_admission(); \CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuoteShipping::prepare_promotion_after_admission(); $environment->abort_promotion(); $abort_confirmed = true; $refused = true; } else { $environment->prepare_promotion_at( $draft, QuoteTime::now() ); } } catch ( \Throwable $error ) { $refused = true; $abort_confirmed = str_contains( $error->getMessage(), 'unconfirmed' ) ? false : $abort_confirmed; }
	if ( 'twice' === Calls::$mode ) { $environment->abort_promotion(); }
	echo json_encode( [ 'refused' => $refused, 'totals' => Calls::$totals, 'layouts' => Calls::$layouts, 'writes' => Calls::$writes, 'readbacks' => Calls::$reads, 'retired' => Calls::$retired, 'abort_confirmed' => $abort_confirmed, 'original' => Calls::$original->persisted, 'foreign' => Calls::$foreign->persisted, 'request_reset' => $cart->reset() && [] === WC_Shipping::current()->packages ], JSON_THROW_ON_ERROR );
}
