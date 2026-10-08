<?php
declare(strict_types=1);

namespace Automattic\WooCommerce\Utilities {
	final class OrderUtil { public static function custom_orders_table_usage_is_enabled(): bool { return 'cpt' !== \LoggingProbe::$mode; } }
}

namespace {
	require dirname( __DIR__, 3 ) . '/vendor/autoload.php';
	require dirname( __DIR__ ) . '/DeliveryQuote/QuoteStorageFixtures.php';
	use CetechDeliveryEngine\Application\DeliveryQuote\{QuotePlacementSavedEvidenceGuard,QuoteSavedOrderAuthorization};
	use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding;
	use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult,OperationConnectionFactory,OperationSession};
	use CetechDeliveryEngine\Integrations\DeliveryQuote\{QuoteNativeCheckoutLoggingEvidence,QuoteNativeOrderPayContinuation,QuoteOrderPayLocalBinding};
	use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteStorageFixtures;

	/** Isolated native-shape proof only; real Woo HTTP qualification is separate. */
	final class LoggingProbe {
		public static string $mode;
		public static ?QuoteNativeCheckoutLoggingEvidence $episode = null;
		public static array $observed = [];
		public static array $errors = [];
		public static array $current = [];
		public static ?QuoteOrderPayLocalBinding $logging_binding = null;
		public static ?QuoteOrderPayLocalBinding $strict = null;
		public static int $getters = 0;
		public static int $save_depth = 0;
		public static int $save_calls = 0;
		public static bool $sql = false;
		public static ?QuoteNativeOrderPayContinuation $continuation = null;
		public static ?LoggingFactory $continuation_factory = null;
		public static ?bool $phase4_payment = null;
		public static ?bool $phase4_free = null;
		public static bool $relay_refused = false;
		public static function begin( WC_Order $order ): void { self::$episode = QuoteNativeCheckoutLoggingEvidence::begin_classic(); self::$strict = QuoteOrderPayLocalBinding::capture( $order ); }
		public static function observed( WC_Order $order ): void {
			try { $observed = self::$episode?->observe_saved( $order ); self::$observed[] = $observed; if ( $observed?->phase() === 4 ) { self::$logging_binding = QuoteOrderPayLocalBinding::capture_logging( $order, $observed ); } }
			catch ( Throwable $error ) { self::$observed[] = null; self::$errors[] = get_class( $error ); }
		}
		public static function binding(): QuoteBinding {
			$quote = QuoteStorageFixtures::quote( state: 'accepted' ); $binding = QuoteStorageFixtures::binding( $quote, order: 19 );
			return QuoteBinding::from_row( array_replace( $binding->row(), [ 'state' => 'sealed', 'revision' => 3, 'snapshot_digest' => hash( 'sha256', 'snapshot' ), 'context_digest' => hash( 'sha256', 'context' ), 'verified_at' => $quote->accepted_at()->plus_seconds( 1 )->sql(), 'sealed_at' => $quote->accepted_at()->plus_seconds( 2 )->sql() ] ), $quote );
		}
		public static function after_phase4( WC_Order $order ): void {
			$four = self::$observed[0] ?? null; if ( null === $four ) { return; }
			self::$continuation_factory = new LoggingFactory( $order );
			self::$continuation = new QuoteNativeOrderPayContinuation( self::$continuation_factory, $order, self::binding(), new LoggingSavedGuard(), QuoteSavedOrderAuthorization::capture( $order, static fn (): bool => true ), 'native_gateway', logging: $four );
			self::$phase4_payment = self::$continuation->verify(); self::$phase4_free = self::$continuation->verify_free();
		}
		public static function after_phase5(): void {
			if ( null === self::$continuation ) { return; }
			try { $five = self::$observed[1] ?? null; if ( null === $five ) { self::$continuation->reject_logging(); self::$relay_refused = true; } else { self::$continuation->observe_logging( $five ); } }
			catch ( Throwable ) { self::$relay_refused = true; }
		}
	}
	class WC_DateTime extends DateTime {}
	class WC_Meta_Data {
		protected array $data; protected $current_data;
		public function __construct( array $data ) { $this->data = $this->current_data = $data; }
		public function mutate( string $key, mixed $value, bool $pending = false ): void { $this->current_data[$key] = $value; if ( ! $pending ) { $this->data[$key] = $value; } }
	}
	class ForeignLoggingMeta extends WC_Meta_Data {}
	class WC_Data {
		protected array $data; protected array $changes = []; protected array $meta_data = []; protected int $id = 19;
		public function __construct( array $data = [] ) { $this->data = $data; }
		public function set( string $key, mixed $value ): void { $this->changes[$key] = $value; }
		public function raw_set( string $key, mixed $value ): void { $this->data[$key] = $value; }
		public function get_meta_data(): array { ++LoggingProbe::$getters; if ( LoggingProbe::$sql ) { throw new LogicException( 'Native getter called under SQL.' ); } return $this->meta_data; }
	}
	class WC_Order_Item extends WC_Data {}
	class WC_Order_Item_Product extends WC_Order_Item {}
	class WC_Order_Item_Shipping extends WC_Order_Item {}
	class WC_Order_Item_Tax extends WC_Order_Item {}
	class WC_Abstract_Order extends WC_Data {
		protected array $items = [];
		public function get_items( string $type ): array { ++LoggingProbe::$getters; if ( LoggingProbe::$sql ) { throw new LogicException( 'Native item getter called under SQL.' ); } $key = [ 'line_item' => 'line_items', 'shipping' => 'shipping_lines', 'tax' => 'tax_lines', 'fee' => 'fee_lines', 'coupon' => 'coupon_lines' ][$type]; return $this->items[$key] ??= []; }
		public function save(): void {
			++LoggingProbe::$save_depth;
			try {
				if ( 'recursive_save' === LoggingProbe::$mode && 1 === LoggingProbe::$save_depth ) { $this->save(); return; }
				$this->data = array_replace_recursive( $this->data, $this->changes );
				$this->changes = 'pending_changes' === LoggingProbe::$mode ? [ 'total' => '67.70' ] : [];
				$this->data['date_modified'] = 'date_string' === LoggingProbe::$mode ? '2026-10-08 00:00:04' : new WC_DateTime( '2026-10-08 00:00:' . str_pad( (string) ( 3 + LoggingProbe::$save_calls ), 2, '0', STR_PAD_LEFT ), new DateTimeZone( 'UTC' ) );
				LoggingProbe::observed( $this );
			} finally { --LoggingProbe::$save_depth; }
		}
	}
	class WC_Order extends WC_Abstract_Order {
		// Woo redeclares this protected native field; the production owner check must accept it.
		protected array $data;
		public function __construct() {
			parent::__construct( [ 'status' => 'pending', 'customer_id' => 1, 'order_key' => 'PRIVATE-NATIVE-ORDER-KEY', 'total' => str_starts_with( LoggingProbe::$mode, 'free_' ) ? '0.00' : '67.70', 'billing' => [ 'address_1' => 'PRIVATE ORIGINAL' ], 'payment_method' => 'native_gateway', 'payment_method_title' => 'Native payment', 'date_modified' => new WC_DateTime( '2026-10-08 00:00:03', new DateTimeZone( 'UTC' ) ) ] );
			$this->meta_data = [ new WC_Meta_Data( [ 'id' => 1, 'key' => '_cetech_de_quote_reference', 'value' => 'PRIVATE-REFERENCE' ] ), new WC_Meta_Data( [ 'id' => 2, 'key' => '_native_other', 'value' => 'original' ] ) ];
			$this->items = [ 'line_items' => [ 24 => new WC_Order_Item_Product( [ 'total' => '20.00', 'quantity' => 2, 'taxes' => [ 'total' => [ 1 => '2.00' ] ] ] ) ], 'shipping_lines' => [], 'tax_lines' => [], 'fee_lines' => [], 'coupon_lines' => [] ];
		}
		public function save(): void { ++LoggingProbe::$save_calls; parent::save(); }
		public function get_id(): int { return $this->id; }
		public function get_payment_method( string $context ): string { return $this->changes['payment_method'] ?? $this->data['payment_method']; }
		public function get_payment_method_title( string $context ): string { return $this->changes['payment_method_title'] ?? $this->data['payment_method_title']; }
		public function get_total( string $context ): string { return $this->changes['total'] ?? $this->data['total']; }
		public function add_meta_data( string $key, mixed $value, bool $unique ): void {
			if ( $unique ) { foreach ( $this->meta_data as $index => $meta ) { $data = ( new ReflectionProperty( $meta, 'current_data' ) )->getValue( $meta ); if ( $data['key'] === $key ) { unset( $this->meta_data[$index] ); } } }
			$shape = [ 'id' => 100 + LoggingProbe::$save_calls + 1, 'key' => $key, 'value' => $value ];
			if ( 'unsaved_row' === LoggingProbe::$mode ) { $shape['id'] = 0; }
			if ( 'wrong_source' === LoggingProbe::$mode ) { $shape['value'] = 'place-order-debug-ffffffff'; }
			if ( 'row_extra_shape' === LoggingProbe::$mode ) { $shape['extra'] = 'unsupported'; }
			$meta = 'foreign_meta' === LoggingProbe::$mode ? new ForeignLoggingMeta( $shape ) : new WC_Meta_Data( $shape );
			if ( 'pending_row' === LoggingProbe::$mode ) { $meta->mutate( 'value', 'changed', true ); }
			if ( 'tombstone_row' === LoggingProbe::$mode ) { $meta->mutate( 'key', null, true ); }
			$this->meta_data[] = $meta;
			if ( 'duplicate_row' === LoggingProbe::$mode ) { $this->meta_data[] = new WC_Meta_Data( $shape ); }
		}
		public function mutate( string $mode ): void {
			if ( 'money' === $mode ) { $this->set( 'total', '999.99' ); }
			if ( 'billing' === $mode ) { $this->set( 'billing', [ 'address_1' => 'PRIVATE CHANGED' ] ); }
			if ( 'key' === $mode ) { $this->set( 'order_key', 'changed' ); }
			if ( 'status' === $mode ) { $this->set( 'status', 'completed' ); }
			if ( 'method' === $mode ) { $this->set( 'payment_method', 'other' ); }
			if ( 'title' === $mode ) { $this->set( 'payment_method_title', 'Other payment' ); }
			if ( 'date' === $mode ) { $this->raw_set( 'date_modified', new WC_DateTime( '2026-10-09 00:00:04', new DateTimeZone( 'UTC' ) ) ); }
			if ( 'line_money' === $mode ) { $this->items['line_items'][24]->set( 'total', '999.99' ); }
			if ( 'line_tax' === $mode ) { $this->items['line_items'][24]->set( 'taxes', [ 'total' => [] ] ); }
			if ( 'metadata' === $mode ) { $this->meta_data[1]->mutate( 'value', 'changed' ); }
			if ( 'metadata_type' === $mode ) { $this->meta_data[1]->mutate( 'value', 7 ); }
			if ( 'metadata_backup' === $mode ) { $data = ( new ReflectionProperty( $this->meta_data[1], 'data' ) )->getValue( $this->meta_data[1] ); $data['value'] = 'changed-backup-only'; ( new ReflectionProperty( $this->meta_data[1], 'data' ) )->setValue( $this->meta_data[1], $data ); }
			if ( 'metadata_current_null' === $mode ) { ( new ReflectionProperty( $this->meta_data[1], 'current_data' ) )->setValue( $this->meta_data[1], null ); }
			if ( 'metadata_order' === $mode ) { $this->meta_data = array_reverse( $this->meta_data, true ); }
			if ( 'debug' === $mode ) { $this->meta_data[array_key_last( $this->meta_data )]->mutate( 'value', 'forged' ); }
			if ( 'fee' === $mode ) { $this->items['fee_lines'][] = new WC_Order_Item_Product( [ 'total' => '3' ] ); }
		}
	}
	class ForeignLoggingOrder extends WC_Order {}
	final class LoggingSession implements OperationSession {
		public int $reads = 0; public bool $retired = false; public bool $transaction = true;
		public function __construct( public array $rows, public array $dates ) {}
		public function site_id(): int { return 'sql_foreign_site' === LoggingProbe::$mode ? 2 : 1; }
		public function table_prefix(): string { return 'owned_'; }
		public function charset_collate(): string { return 'utf8mb4'; }
		public function begin(): bool { return $this->transaction = true; }
		public function commit(): OperationCommitResult { throw new LogicException( 'Read guard must not commit.' ); }
		public function rollback(): bool { $this->transaction = false; return ! str_ends_with( LoggingProbe::$mode, 'rollback_unknown' ); }
		public function retire(): bool { $this->retired = true; return ! str_ends_with( LoggingProbe::$mode, 'retire_unknown' ); }
		public function is_retired(): bool { return $this->retired; }
		public function in_transaction(): bool { return $this->transaction; }
		public function validate_tables( array $table_names ): bool { return true; }
		public function query( string $sql ): int|false { throw new LogicException( 'Read guard must not write.' ); }
		public function get_row( string $sql ): array|null|false { throw new LogicException( 'Read guard uses exact results.' ); }
		public function get_results( string $sql ): array|false {
			++$this->reads;
			if ( ! str_ends_with( $sql, 'FOR UPDATE' ) ) { throw new LogicException( 'Missing physical fence.' ); }
			if ( in_array( LoggingProbe::$mode, [ 'sql_unknown', 'continuation_unknown' ], true ) ) { $this->retired = true; return false; }
			if ( 'sql_retired_after_read' === LoggingProbe::$mode && 2 === $this->reads ) { $this->retired = true; }
			if ( str_contains( $sql, 'SELECT payment_method,payment_method_title' ) ) { return [ [ 'payment_method' => 'native_gateway', 'payment_method_title' => 'Native payment' ] ]; }
			return str_contains( $sql, "meta_key='_debug_log_source'" ) ? $this->rows : $this->dates;
		}
		public function prepare( string $sql, mixed ...$args ): string { return str_replace( '%d', (string) $args[0], $sql ); }
		public function errno(): int { return 0; }
		public function insert_id(): int { return 0; }
	}
	final class LoggingSavedGuard implements QuotePlacementSavedEvidenceGuard {
		public int $checks = 0;
		public function tables( OperationSession $session ): array { return [ 'owned_saved_facts', 'owned_wc_orders' ]; }
		public function verify( OperationSession $session, QuoteBinding $binding ): bool { ++$this->checks; return 'sql_saved_changed' !== LoggingProbe::$mode; }
	}
	final class LoggingFactory implements OperationConnectionFactory {
		public int $opens = 0; public ?LoggingSession $session = null;
		public function __construct( private WC_Order $order ) {}
		public function open(): OperationSession {
			++$this->opens; $meta = ( new ReflectionProperty( $this->order, 'meta_data' ) )->getValue( $this->order ); $rows = [];
			foreach ( $meta as $member ) { $raw = ( new ReflectionProperty( $member, 'current_data' ) )->getValue( $member ); if ( '_debug_log_source' === ( $raw['key'] ?? null ) ) { $rows[] = [ 'meta_id' => (string) $raw['id'], 'meta_key' => $raw['key'], 'meta_value' => $raw['value'] ]; } }
			$data = ( new ReflectionProperty( $this->order, 'data' ) )->getValue( $this->order );
			$this->session = new LoggingSession( $rows, [ [ 'modified' => gmdate( 'Y-m-d H:i:s', $data['date_modified']->getTimestamp() ) ] ] ); $this->session->transaction = false; return $this->session;
		}
	}

	$GLOBALS['blog_id'] = 1; $GLOBALS['current_user'] = (object) [ 'ID' => 1 ]; LoggingProbe::$mode = $argv[1] ?? 'classic5';
	$directory = sys_get_temp_dir() . '/cetech-native-logging-' . bin2hex( random_bytes( 8 ) ); mkdir( $directory . '/includes', 0700, true ); define( 'WC_ABSPATH', $directory . '/' );
	$logger_source = <<<'PHP'
<?php
function wc_log_order_step( string $message, ?array $context = null, bool $final_step = false, bool $first_step = false ): void {
	static $logging_active = true, $order, $order_uid = '12345678-1234-4234-9234-123456789abc', $order_uid_short = '12345678', $steps = [];
	if ( '__probe_reset_statics' === $message ) { $steps = []; return; }
	if ( $first_step ) {
		$logging_active = 'logging_disabled' !== LoggingProbe::$mode;
		if ( 'bad_uid' === LoggingProbe::$mode ) { $order_uid = 'bad-uuid'; }
		if ( 'bad_short' === LoggingProbe::$mode ) { $order_uid_short = 'ffffffff'; }
	}
	if ( ! $logging_active ) { return; }
	if ( '[Shortcode #3] Checkout posted data validated' === $message ) {
		if ( 'duplicate_steps' === LoggingProbe::$mode ) { $steps[] = $steps[0]; }
		if ( 'foreign_steps' === LoggingProbe::$mode ) { $steps[] = 'foreign plugin step'; }
		if ( 'malformed_steps' === LoggingProbe::$mode ) { $steps[] = [ 'unsupported-shape' ]; }
	}
	if ( ( $context['order_object'] ?? null ) instanceof WC_Order ) {
		$order = $context['order_object'];
		$order->add_meta_data( '_debug_log_source', 'place-order-debug-' . $order_uid_short, true );
		$order->save();
	}
	$steps[] = $message;
}
PHP;
	$checkout_source = <<<'PHP'
<?php
class WC_Checkout {
	public function process_checkout( WC_Order $order ): void {
		wc_log_order_step( '[Shortcode #1] Place Order flow initiated', null, false, true );
		wc_log_order_step( '[Shortcode #2] Session updated with checkout data and totals calculated' );
		wc_log_order_step( '[Shortcode #3] Checkout posted data validated' );
		LoggingProbe::begin( $order );
		if ( 'external_observer' === LoggingProbe::$mode ) { LoggingProbe::observed( $order ); return; }
		if ( 'foreign_caller' === LoggingProbe::$mode ) { logging_foreign_caller( $order ); return; }
		if ( 'wrong_checkout_method' === LoggingProbe::$mode ) { $this->other_method( $order ); return; }
		wc_log_order_step( '[Shortcode #4] Validated/Created customer and created order object', [ 'order_object' => $order ] );
		LoggingProbe::$current[] = ( LoggingProbe::$observed[0] ?? null )?->is_current() ?? false;
		LoggingProbe::after_phase4( $order );
		if ( 'classic4' === LoggingProbe::$mode || str_starts_with( LoggingProbe::$mode, 'mutate_' ) || str_starts_with( LoggingProbe::$mode, 'sql_' ) ) { return; }
		if ( str_starts_with( LoggingProbe::$mode, 'pre5_' ) ) { $order->mutate( substr( LoggingProbe::$mode, 5 ) ); }
		wc_log_order_step( '[Shortcode #5] woocommerce_checkout_order_processed hook ran successfully', [ 'order_object' => $order ] );
		LoggingProbe::$current[] = ( LoggingProbe::$observed[1] ?? null )?->is_current() ?? false;
		LoggingProbe::after_phase5();
	}
	public function other_method( WC_Order $order ): void { wc_log_order_step( '[Shortcode #4] Validated/Created customer and created order object', [ 'order_object' => $order ] ); }
}
class ForeignLoggingCheckout extends WC_Checkout {}
PHP;
	file_put_contents( $directory . '/includes/wc-order-step-logger-functions.php', $logger_source ); file_put_contents( $directory . '/includes/class-wc-checkout.php', $checkout_source );
	if ( 'foreign_logger_path' === LoggingProbe::$mode ) { file_put_contents( $directory . '/foreign-logger.php', $logger_source ); require $directory . '/foreign-logger.php'; } else { require $directory . '/includes/wc-order-step-logger-functions.php'; }
	require $directory . '/includes/class-wc-checkout.php';
	function logging_foreign_caller( WC_Order $order ): void { wc_log_order_step( '[Shortcode #4] Validated/Created customer and created order object', [ 'order_object' => $order ] ); }
	try {
		$order = 'foreign_order' === LoggingProbe::$mode ? new ForeignLoggingOrder() : new WC_Order();
		$checkout = 'foreign_checkout' === LoggingProbe::$mode ? new ForeignLoggingCheckout() : new WC_Checkout(); $checkout->process_checkout( $order );
		$four = LoggingProbe::$observed[0] ?? null; $five = LoggingProbe::$observed[1] ?? null; $last = $five ?? $four;
		$before_getters = LoggingProbe::$getters; $raw_before = $last?->raw_unchanged() ?? false;
		if ( str_starts_with( LoggingProbe::$mode, 'mutate_' ) ) { $order->mutate( substr( LoggingProbe::$mode, 7 ) ); }
		if ( str_starts_with( LoggingProbe::$mode, 'post5_' ) ) { $order->mutate( substr( LoggingProbe::$mode, 6 ) ); }
		if ( str_starts_with( LoggingProbe::$mode, 'free_post5_' ) ) { $order->mutate( substr( LoggingProbe::$mode, 11 ) ); }
		$saved = clone $order; $matches = $last?->matches_saved( $saved ) ?? false;
		$logging_same = null !== $last && ( LoggingProbe::$logging_binding?->logging_unchanged( $last ) ?? false );
		$strict_same = LoggingProbe::$strict?->unchanged() ?? false;
		$native_typed_same = null !== $last && ( LoggingProbe::$strict?->same_native_facts( $order, $last ) ?? false );
		$serialized = $json = true; if ( null !== $last ) { try { serialize( $last ); $serialized = false; } catch ( LogicException ) {} try { json_encode( $last, JSON_THROW_ON_ERROR ); $json = false; } catch ( LogicException ) {} }
		$verified = false; $reads = 0; $saved_checks = 0; $tables = []; $rollback_ack = $retire_ack = true;
		if ( null !== $last ) {
			$row = ( new ReflectionProperty( $last, 'row' ) )->getValue( $last ); $date = ( new ReflectionProperty( $last, 'date' ) )->getValue( $last );
			$rows = [ [ 'meta_id' => (string) $row['id'], 'meta_key' => '_debug_log_source', 'meta_value' => $row['value'] ] ]; $dates = [ [ 'modified' => $date ] ];
			if ( 'sql_row_changed' === LoggingProbe::$mode ) { $rows[0]['meta_value'] = 'forged'; }
			if ( 'sql_row_id_changed' === LoggingProbe::$mode ) { ++$rows[0]['meta_id']; }
			if ( 'sql_duplicate' === LoggingProbe::$mode ) { $rows[] = $rows[0]; }
			if ( 'sql_missing' === LoggingProbe::$mode ) { $rows = []; }
			if ( 'sql_extra_shape' === LoggingProbe::$mode ) { $rows[0]['extra'] = true; }
			if ( 'sql_date_changed' === LoggingProbe::$mode ) { $dates[0]['modified'] = '2026-10-09 00:00:04'; }
			if ( 'sql_statics_changed' === LoggingProbe::$mode ) { wc_log_order_step( '__probe_reset_statics' ); }
			$session = new LoggingSession( $rows, $dates ); if ( 'sql_not_transaction' === LoggingProbe::$mode ) { $session->transaction = false; } if ( 'sql_retired' === LoggingProbe::$mode ) { $session->retired = true; }
			$saved_guard = new LoggingSavedGuard(); $guard = $last->with_saved( $saved_guard ); $tables = $guard->tables( $session ); $binding = QuoteStorageFixtures::binding( QuoteStorageFixtures::quote( state: 'accepted' ), order: 19 );
			LoggingProbe::$sql = true; try { $verified = $guard->verify( $session, $binding ); } finally { LoggingProbe::$sql = false; $rollback_ack = $session->rollback(); $retire_ack = $session->retire(); }
			$reads = $session->reads; $saved_checks = $saved_guard->checks;
		}
		$continuation_verified = false; LoggingProbe::$sql = true;
		try { if ( null !== LoggingProbe::$continuation ) { $continuation_verified = str_starts_with( LoggingProbe::$mode, 'free_' ) ? LoggingProbe::$continuation->verify_free() : LoggingProbe::$continuation->verify(); } } finally { LoggingProbe::$sql = false; }
		echo json_encode( [ 'began' => null !== LoggingProbe::$episode, 'phases' => array_map( static fn ( $entry ): ?int => $entry?->phase(), LoggingProbe::$observed ), 'errors' => LoggingProbe::$errors, 'current_after_steps' => LoggingProbe::$current, 'raw_before' => $raw_before, 'raw_after' => $last?->raw_unchanged() ?? false, 'phase4_raw_after_phase5' => $four?->raw_unchanged() ?? false, 'successor' => null !== $five && null !== $four && $five->successor_of( $four ), 'matches_saved' => $matches, 'logging_same' => $logging_same, 'strict_same' => $strict_same, 'native_typed_same' => $native_typed_same, 'serialization_refused' => $serialized, 'json_refused' => $json, 'verified' => $verified, 'reads' => $reads, 'saved_checks' => $saved_checks, 'tables' => $tables, 'pure_getter_delta' => LoggingProbe::$getters - $before_getters, 'rollback_ack' => $rollback_ack, 'retire_ack' => $retire_ack, 'phase4_payment' => LoggingProbe::$phase4_payment, 'phase4_free' => LoggingProbe::$phase4_free, 'relay_refused' => LoggingProbe::$relay_refused, 'continuation_verified' => $continuation_verified, 'continuation_owners' => LoggingProbe::$continuation_factory?->opens ?? 0, 'continuation_retired' => LoggingProbe::$continuation_factory?->session?->retired ?? false ], JSON_THROW_ON_ERROR );
	} finally { foreach ( glob( $directory . '/includes/*' ) as $path ) { unlink( $path ); } if ( is_file( $directory . '/foreign-logger.php' ) ) { unlink( $directory . '/foreign-logger.php' ); } rmdir( $directory . '/includes' ); rmdir( $directory ); }
}
