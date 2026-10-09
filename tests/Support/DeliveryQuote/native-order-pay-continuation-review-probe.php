<?php
declare(strict_types=1);

namespace Automattic\WooCommerce\Utilities {
	final class OrderUtil { public static function custom_orders_table_usage_is_enabled(): bool { return 'cpt_paid' !== \ContinuationProbe::$mode; } }
}
namespace {
	require dirname( __DIR__, 3 ) . '/vendor/autoload.php';
	require __DIR__ . '/QuoteStorageFixtures.php';
	use CetechDeliveryEngine\Application\DeliveryQuote\{QuotePlacementSavedEvidenceGuard,QuoteSavedOrderAuthorization};
	use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding;
	use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult,OperationConnectionFactory,OperationSession};
	use CetechDeliveryEngine\Integrations\DeliveryQuote\{QuoteNativeCheckoutLoggingEvidence,QuoteNativeOrderPayContinuation};
	use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteStorageFixtures;

	/** Isolated native shape only. No network, current control/source lookup, or payment side effect. */
	final class ContinuationProbe {
		public static string $mode; public static string $physical_money; public static ?QuoteNativeCheckoutLoggingEvidence $episode = null; public static ?QuoteNativeCheckoutLoggingEvidence $four = null; public static ?QuoteNativeCheckoutLoggingEvidence $five = null; public static ?QuoteNativeOrderPayContinuation $continuation = null; public static ContinuationFactory $factory; public static bool $observation_refused = false; public static int $owned_getters = 0; public static bool $owned = false;
		public static function observed( WC_Order $order ): void {
			$receipt = self::$episode?->observe_saved( $order ); if ( null === $receipt ) { return; }
			if ( 4 === $receipt->phase() ) { self::$four = $receipt; }
			else { self::$five = $receipt; try { self::$continuation?->observe_logging( $receipt ); if ( 'duplicate5' === self::$mode ) { self::$continuation?->observe_logging( $receipt ); } } catch ( Throwable ) { self::$observation_refused = true; } }
		}
		public static function admit( WC_Order $order, bool $saved = false ): void {
			$quote = QuoteStorageFixtures::quote( state: 'accepted' ); $row = QuoteStorageFixtures::binding( $quote, order: 19 )->row();
			$binding = QuoteBinding::from_row( array_replace( $row, [ 'state' => 'sealed', 'revision' => 3, 'snapshot_digest' => hash( 'sha256', 'snapshot' ), 'context_digest' => hash( 'sha256', 'context' ), 'verified_at' => $row['created_at'], 'sealed_at' => $row['created_at'] ] ), $quote );
			$authorization = QuoteSavedOrderAuthorization::capture( $order, static fn( WC_Order $candidate ): bool => 19 === $candidate->get_id() && 7 === $candidate->get_customer_id() );
			self::$physical_money = $order->get_total( 'edit' );
			self::$continuation = new QuoteNativeOrderPayContinuation( self::$factory, $order, $binding, new ContinuationSavedGuard( self::$physical_money ), $authorization, 'native_gateway', $saved, $saved ? null : self::$four );
			if ( $saved ) { self::$continuation->title( 'Native payment' ); }
		}
		public static function getter(): void { if ( self::$owned ) { ++self::$owned_getters; throw new LogicException( 'Native getter inside owned read.' ); } }
	}
	class WC_DateTime extends DateTime {}
	class WC_Meta_Data { protected array $data; protected array $current_data; public function __construct( array $data ) { $this->data = $this->current_data = $data; } public function mutate( bool $backup = false ): void { if ( $backup ) { $this->data['value'] = 'changed'; } else { $this->data['value'] = $this->current_data['value'] = 'changed'; } } }
	class WC_Data { protected int $id = 19; protected array $data; protected array $changes = []; protected array $meta_data; public function __construct( array $data ) { $this->data = $data; $this->meta_data = [ new WC_Meta_Data( [ 'id' => 1, 'key' => '_native_other', 'value' => 'original' ] ) ]; } public function get_meta_data(): array { ContinuationProbe::getter(); return $this->meta_data; } }
	class WC_Order_Item_Product extends WC_Data {}
	class WC_Order_Item_Shipping extends WC_Data {}
	class WC_Order_Item_Tax extends WC_Data {}
	class WC_Abstract_Order extends WC_Data {
		protected array $items = [ 'line_items' => [], 'shipping_lines' => [], 'tax_lines' => [], 'fee_lines' => [], 'coupon_lines' => [] ];
		public function get_items( string $type ): array { ContinuationProbe::getter(); return $this->items[ [ 'line_item' => 'line_items', 'shipping' => 'shipping_lines', 'tax' => 'tax_lines', 'fee' => 'fee_lines', 'coupon' => 'coupon_lines' ][$type] ]; }
		public function save(): void { $this->data['date_modified'] = new WC_DateTime( '2026-10-08 00:00:05', new DateTimeZone( 'UTC' ) ); $this->changes = []; ContinuationProbe::observed( $this ); }
	}
	class WC_Order extends WC_Abstract_Order {
		protected array $data; private int $save_count = 0;
		public function __construct() { parent::__construct( [ 'customer_id' => 7, 'order_key' => 'PRIVATE-NATIVE-ORDER-KEY', 'status' => 'pending', 'total' => str_contains( ContinuationProbe::$mode, 'free' ) && 'forced_free' !== ContinuationProbe::$mode ? '0.00' : '67.70', 'billing' => [ 'address_1' => 'PRIVATE-ORIGINAL' ], 'payment_method' => 'saved_native_method' === ContinuationProbe::$mode ? 'old_native_gateway' : 'native_gateway', 'payment_method_title' => 'saved_native_method' === ContinuationProbe::$mode ? 'Old payment' : 'Native payment', 'date_modified' => new WC_DateTime( '2026-10-08 00:00:03', new DateTimeZone( 'UTC' ) ) ] ); }
		public function get_id(): int { ContinuationProbe::getter(); return $this->id; } public function get_customer_id(): int { ContinuationProbe::getter(); return $this->data['customer_id']; } public function get_payment_method( string $context = 'view' ): string { ContinuationProbe::getter(); return $this->data['payment_method']; } public function get_payment_method_title( string $context = 'view' ): string { ContinuationProbe::getter(); return $this->data['payment_method_title']; } public function get_total( string $context = 'view' ): string { ContinuationProbe::getter(); return $this->data['total']; }
		public function save(): void { ++$this->save_count; parent::save(); }
		public function add_meta_data( string $key, string $value, bool $unique ): void { if ( $unique ) { foreach ( $this->meta_data as $index => $meta ) { $raw = ( new ReflectionProperty( $meta, 'current_data' ) )->getValue( $meta ); if ( $raw['key'] === $key ) { unset( $this->meta_data[$index] ); } } } $this->meta_data[] = new WC_Meta_Data( [ 'key' => $key, 'value' => $value, 'id' => 100 + $this->save_count ] ); }
		public function mutate( string $mode ): void { if ( 'money' === $mode ) { $this->data['total'] = '999.99'; } elseif ( 'metadata' === $mode || 'metadata_backup' === $mode ) { $this->meta_data[0]->mutate( 'metadata_backup' === $mode ); } elseif ( 'date' === $mode ) { $this->data['date_modified'] = new WC_DateTime( '2030-10-08 00:00:01', new DateTimeZone( 'UTC' ) ); } elseif ( 'method' === $mode ) { $this->data['payment_method'] = 'other'; } elseif ( 'title' === $mode ) { $this->data['payment_method_title'] = 'Other payment'; } }
		public function native_method_save(): void { $this->data['payment_method'] = 'native_gateway'; $this->data['payment_method_title'] = 'Native payment'; $this->data['date_modified'] = new WC_DateTime( '2026-10-08 00:00:05', new DateTimeZone( 'UTC' ) ); }
	}
	final class ContinuationFactory implements OperationConnectionFactory { public int $owners = 0; public ?ContinuationSession $last = null; public function open(): OperationSession { ++$this->owners; return $this->last = new ContinuationSession(); } }
	final class ContinuationSession implements OperationSession {
		public bool $retired = false; public bool $transaction = false; public int $reads = 0; public int $rollbacks = 0; public int $retirements = 0; public int $writes = 0;
		public function site_id(): int { return 1; } public function table_prefix(): string { return 'owned_'; } public function charset_collate(): string { return 'utf8mb4'; }
		public function begin(): bool { ContinuationProbe::$owned = true; if ( 'begin_unknown' === ContinuationProbe::$mode ) { $this->retired = true; return false; } return $this->transaction = true; }
		public function commit(): OperationCommitResult { throw new LogicException( 'Read must never commit.' ); }
		public function rollback(): bool { ++$this->rollbacks; $this->transaction = false; ContinuationProbe::$owned = false; return 'rollback_unknown' !== ContinuationProbe::$mode; }
		public function retire(): bool { ++$this->retirements; $this->retired = true; ContinuationProbe::$owned = false; if ( 'retire_mutation' === ContinuationProbe::$mode ) { $GLOBALS['continuation_order']->mutate( 'money' ); } return 'retire_unknown' !== ContinuationProbe::$mode; }
		public function is_retired(): bool { return $this->retired; } public function in_transaction(): bool { return $this->transaction; } public function validate_tables( array $tables ): bool { return 'validate_unknown' !== ContinuationProbe::$mode; }
		public function query( string $sql ): int|false { ++$this->writes; throw new LogicException( 'No write allowed.' ); } public function get_row( string $sql ): array|null|false { throw new LogicException( 'Exact finite result sets only.' ); }
		public function get_results( string $sql ): array|false {
			++$this->reads; if ( ! str_ends_with( $sql, 'FOR UPDATE' ) ) { throw new LogicException( 'Missing owned physical fence.' ); } if ( 'read_unknown' === ContinuationProbe::$mode ) { $this->retired = true; return false; }
			if ( str_contains( $sql, 'total_amount' ) ) { return [ [ 'total_amount' => 'physical_saved_changed' === ContinuationProbe::$mode ? '999.99' : ContinuationProbe::$physical_money ] ]; }
			if ( str_contains( $sql, "meta_key='_debug_log_source'" ) ) { $receipt = ContinuationProbe::$five ?? ContinuationProbe::$four; $row = ( new ReflectionProperty( $receipt, 'row' ) )->getValue( $receipt ); return [ [ 'meta_id' => (string) $row['id'], 'meta_key' => '_debug_log_source', 'meta_value' => 'physical_logger_changed' === ContinuationProbe::$mode ? 'forged' : $row['value'] ] ]; }
			if ( str_contains( $sql, 'modified' ) ) { $receipt = ContinuationProbe::$five ?? ContinuationProbe::$four; return [ [ 'modified' => 'physical_date_changed' === ContinuationProbe::$mode ? '2030-10-08 00:00:05' : ( new ReflectionProperty( $receipt, 'date' ) )->getValue( $receipt ) ] ]; }
			$method = 'physical_method_changed' === ContinuationProbe::$mode ? 'other' : 'native_gateway'; if ( str_contains( $sql, "meta_key IN ('_payment_method'" ) ) { return [ [ 'meta_key' => '_payment_method', 'meta_value' => $method ], [ 'meta_key' => '_payment_method_title', 'meta_value' => 'Native payment' ] ]; } return [ [ 'payment_method' => $method, 'payment_method_title' => 'Native payment' ] ];
		}
		public function prepare( string $sql, mixed ...$args ): string { return str_replace( '%d', (string) $args[0], $sql ); } public function errno(): int { return 0; } public function insert_id(): int { return 0; }
	}
	final class ContinuationSavedGuard implements QuotePlacementSavedEvidenceGuard { public function __construct( private string $expected_money ) {} public function tables( OperationSession $session ): array { return [ 'owned_wc_orders' ]; } public function verify( OperationSession $session, QuoteBinding $binding ): bool { return $session->get_results( 'SELECT total_amount FROM owned_wc_orders WHERE id=19 FOR UPDATE' ) === [ [ 'total_amount' => $this->expected_money ] ]; } }

	ContinuationProbe::$mode = $argv[1] ?? 'classic_paid'; ContinuationProbe::$factory = new ContinuationFactory(); $GLOBALS['blog_id'] = 1; $GLOBALS['current_user'] = (object) [ 'ID' => 7 ]; $_GET = $_POST = $_COOKIE = []; $GLOBALS['continuation_order'] = $order = new WC_Order();
	$directory = sys_get_temp_dir() . '/cetech-continuation-review-' . bin2hex( random_bytes( 8 ) ); mkdir( $directory . '/includes', 0700, true ); define( 'WC_ABSPATH', $directory . '/' );
	$logger = <<<'PHP'
<?php
function wc_log_order_step( string $message, ?array $context = null ): void { static $logging_active = true, $order, $order_uid = '12345678-1234-4234-9234-123456789abc', $order_uid_short = '12345678', $steps = []; if ( isset( $context['order_object'] ) ) { $order = $context['order_object']; $order->add_meta_data( '_debug_log_source', 'place-order-debug-' . $order_uid_short, true ); $order->save(); } $steps[] = $message; }
PHP;
	$checkout = <<<'PHP'
<?php
class WC_Checkout { public function process_checkout( WC_Order $order ): void {
	wc_log_order_step( '[Shortcode #1] Place Order flow initiated' ); wc_log_order_step( '[Shortcode #2] Session updated with checkout data and totals calculated' ); wc_log_order_step( '[Shortcode #3] Checkout posted data validated' ); ContinuationProbe::$episode = QuoteNativeCheckoutLoggingEvidence::begin_classic();
	wc_log_order_step( '[Shortcode #4] Validated/Created customer and created order object', [ 'order_object' => $order ] ); ContinuationProbe::admit( $order );
	if ( str_starts_with( ContinuationProbe::$mode, 'phase4_' ) ) { return; } if ( str_starts_with( ContinuationProbe::$mode, 'before5_' ) ) { $order->mutate( substr( ContinuationProbe::$mode, 8 ) ); }
	wc_log_order_step( '[Shortcode #5] woocommerce_checkout_order_processed hook ran successfully', [ 'order_object' => $order ] );
} }
PHP;
	// The generated native paths exercise the same strict provenance code as the separate logger matrix.
	$checkout = str_replace( 'QuoteNativeCheckoutLoggingEvidence::', '\\CetechDeliveryEngine\\Integrations\\DeliveryQuote\\QuoteNativeCheckoutLoggingEvidence::', $checkout ); file_put_contents( $directory . '/includes/wc-order-step-logger-functions.php', $logger ); file_put_contents( $directory . '/includes/class-wc-checkout.php', $checkout ); require $directory . '/includes/wc-order-step-logger-functions.php'; require $directory . '/includes/class-wc-checkout.php';
	try {
		if ( 'saved_native_method' === ContinuationProbe::$mode ) { ContinuationProbe::admit( $order, true ); $order->native_method_save(); } else { ( new WC_Checkout() )->process_checkout( $order ); }
		if ( str_starts_with( ContinuationProbe::$mode, 'after5_' ) ) { $order->mutate( substr( ContinuationProbe::$mode, 7 ) ); } if ( 'auth_changed' === ContinuationProbe::$mode ) { $GLOBALS['current_user'] = (object) [ 'ID' => 8 ]; } if ( 'later_pause' === ContinuationProbe::$mode ) { $GLOBALS['simulated_current_control_revision'] = 99; $GLOBALS['simulated_current_control_paused'] = true; }
		$free = str_contains( ContinuationProbe::$mode, 'free' ); $allowed = $free ? ContinuationProbe::$continuation->verify_free() : ContinuationProbe::$continuation->verify(); $gateway = 0; if ( $allowed ) { ContinuationProbe::$continuation->delegate(); ++$gateway; } $session = ContinuationProbe::$factory->last;
		echo json_encode( [ 'allowed' => $allowed, 'delegated' => ContinuationProbe::$continuation->delegated(), 'gateway_entries' => $gateway, 'owners' => ContinuationProbe::$factory->owners, 'reads' => $session?->reads ?? 0, 'rollbacks' => $session?->rollbacks ?? 0, 'retirements' => $session?->retirements ?? 0, 'retired' => $session?->retired ?? false, 'writes' => $session?->writes ?? 0, 'owned_native_getters' => ContinuationProbe::$owned_getters, 'receipt5_raw' => ContinuationProbe::$five?->raw_unchanged() ?? false, 'receipt5_current' => ContinuationProbe::$five?->is_current() ?? false, 'observation_refused' => ContinuationProbe::$observation_refused ], JSON_THROW_ON_ERROR );
	} finally { foreach ( glob( $directory . '/includes/*' ) as $path ) { unlink( $path ); } rmdir( $directory . '/includes' ); rmdir( $directory ); }
}
