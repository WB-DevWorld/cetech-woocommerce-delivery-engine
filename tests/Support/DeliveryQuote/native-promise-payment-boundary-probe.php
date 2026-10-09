<?php
declare(strict_types=1);

namespace Automattic\WooCommerce\Utilities {
	final class OrderUtil { public static function custom_orders_table_usage_is_enabled(): bool { return str_starts_with( \PromisePaymentProbe::$mode, 'hpos_' ); } }
}
namespace {
	require dirname( __DIR__, 3 ) . '/vendor/autoload.php';
	require __DIR__ . '/PromiseQuotePlacementFixtures.php';
	use CetechDeliveryEngine\Application\DeliveryQuote\{PromiseQuotePaymentBoundary,QuoteSavedOrderAuthorization};
	use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
	use CetechDeliveryEngine\Integrations\DeliveryQuote\QuoteNativeOrderPayContinuation;
	use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{PromiseQuotePlacementFixture,QuoteDurableFixtureFactory,PromiseQuoteFixtureCurrentEvidence};
	use CetechDeliveryEngine\Tests\Support\ServicePromise\Handoff\PromiseHandoffFixture as F;

	/** Actual continuation and owned SQLite receipts; native shapes supply no live WordPress authority. */
	final class PromisePaymentProbe { public static string $mode; public static ?QuoteDurableFixtureFactory $factory = null; public static int $owned_getters = 0; public static function getter(): void { if ( self::$factory?->pdo->inTransaction() ) { ++self::$owned_getters; throw new LogicException( 'Native getter in SQL owner.' ); } } }
	class WC_DateTime extends DateTime {}
	class WC_Meta_Data { protected array $data = []; protected array $current_data = []; }
	class WC_Data { protected int $id = 100; protected array $data = []; protected array $changes = []; protected array $meta_data = []; }
	class WC_Order_Item_Product extends WC_Data {}
	class WC_Order_Item_Shipping extends WC_Data {}
	class WC_Order_Item_Tax extends WC_Data {}
	class WC_Abstract_Order extends WC_Data { protected array $items = [ 'line_items' => [], 'shipping_lines' => [], 'tax_lines' => [], 'fee_lines' => [], 'coupon_lines' => [] ]; }
	class WC_Order extends WC_Abstract_Order {
		protected array $data;
		public function __construct() { $this->data = [ 'customer_id' => 7, 'order_key' => 'PRIVATE-FIXTURE-ORDER-KEY', 'status' => 'pending', 'total' => str_contains( PromisePaymentProbe::$mode, '_free' ) ? '0.00' : '67.70', 'payment_method' => 'native_gateway', 'payment_method_title' => 'Native payment', 'billing' => [ 'address_1' => 'PRIVATE-ORIGINAL' ], 'date_modified' => null ]; }
		public function get_id(): int { PromisePaymentProbe::getter(); return $this->id; }
		public function get_payment_method( string $context = 'view' ): string { PromisePaymentProbe::getter(); return $this->data['payment_method']; }
		public function get_payment_method_title( string $context = 'view' ): string { PromisePaymentProbe::getter(); return $this->data['payment_method_title']; }
		public function get_total( string $context = 'view' ): string { PromisePaymentProbe::getter(); return $this->data['total']; }
	}

	PromisePaymentProbe::$mode = $argv[1] ?? 'hpos_paid_before'; $GLOBALS['blog_id'] = 1; $GLOBALS['current_user'] = (object) [ 'ID' => 7 ]; $_GET = $_POST = $_COOKIE = []; $order = new WC_Order();
	$deadline = F::time()->plus_seconds( 5 ); $f = new PromiseQuotePlacementFixture( $order, F::input( [ 'anchor' => 'order_accepted' ], $deadline->sql() ) ); PromisePaymentProbe::$factory = $factory = $f->factory;
	if ( 'accepted' !== $f->seal( $order )->attempt->outcome->state || null === ( $link = $f->linkage() ) ) { throw new LogicException( 'Original acknowledged seal unavailable.' ); }
	$factory->pdo->exec( 'CREATE TABLE durable_wc_orders(id INTEGER PRIMARY KEY,payment_method TEXT,payment_method_title TEXT)' ); $factory->pdo->exec( "INSERT INTO durable_wc_orders VALUES(100,'native_gateway','Native payment')" );
	$factory->pdo->exec( 'CREATE TABLE durable_postmeta(meta_id INTEGER PRIMARY KEY,post_id INTEGER,meta_key TEXT,meta_value TEXT)' ); $factory->pdo->exec( "INSERT INTO durable_postmeta VALUES(1,100,'_payment_method','native_gateway'),(2,100,'_payment_method_title','Native payment')" );
	$clock = QuoteTime::from_epoch_microseconds( $deadline->epoch_microseconds() - 1 ); $captures = 0; $inside_clock = 0;
	$source_deadline = QuoteTime::from_epoch_microseconds( F::time()->epoch_microseconds() + 4500000 );
	if ( str_contains( PromisePaymentProbe::$mode, '_source_expiry' ) ) { $update = $factory->pdo->prepare( 'UPDATE durable_fence SET valid_until=? WHERE id=1' ); $update->execute( [ $source_deadline->sql() ] ); $clock = QuoteTime::from_epoch_microseconds( $source_deadline->epoch_microseconds() - 1 ); }
	if ( str_contains( PromisePaymentProbe::$mode, '_initial_equal' ) ) { $clock = $deadline; }
	if ( str_contains( PromisePaymentProbe::$mode, '_stalled_equal' ) ) { $factory->on_retire = static function() use ( &$clock, $deadline ): void { $clock = $deadline; }; }
	if ( str_contains( PromisePaymentProbe::$mode, '_source_expiry_stalled_equal' ) ) { $factory->on_retire = static function() use ( &$clock, $source_deadline ): void { $clock = $source_deadline; }; }
	if ( str_contains( PromisePaymentProbe::$mode, '_source_changed' ) ) { $factory->pdo->exec( "UPDATE durable_fence SET context_digest='" . str_repeat( 'a', 64 ) . "'" ); }
	if ( str_contains( PromisePaymentProbe::$mode, '_rollback_unknown' ) ) { $factory->lose_read_rollback_ack = true; }
	$boundary = new PromiseQuotePaymentBoundary( $f->sealed->quote, $f->sealed->binding, $link, new PromiseQuoteFixtureCurrentEvidence( $factory ), static function() use ( &$clock, &$captures, &$inside_clock, $factory ): QuoteTime { ++$captures; if ( $factory->pdo->inTransaction() ) { ++$inside_clock; throw new LogicException( 'Clock inside SQL owner.' ); } return $clock; } );
	$auth = QuoteSavedOrderAuthorization::capture( $order, static fn( WC_Order $candidate ): bool => 100 === $candidate->get_id() );
	$continuation = new QuoteNativeOrderPayContinuation( $factory, $order, $f->sealed->binding, $f->saved, $auth, 'native_gateway', promise: $boundary ); $opens = $factory->opens; $statements = count( $factory->statements ); $rows = $factory->count( 'operation_records' ); $events = $factory->count( 'operation_changes' );
	$allowed = str_contains( PromisePaymentProbe::$mode, '_free' ) ? $continuation->verify_free() : $continuation->verify(); $entries = 0; if ( $allowed ) { $continuation->delegate(); ++$entries; }
	$queries = array_slice( $factory->statements, $statements ); $writes = array_values( array_filter( $queries, static fn( string $sql ): bool => preg_match( '/\A(?:INSERT|UPDATE|DELETE)/', $sql ) === 1 ) );
	echo json_encode( [ 'allowed' => $allowed, 'gateway_or_free_entries' => $entries, 'delegated' => $continuation->delegated(), 'owners' => $factory->opens - $opens, 'clock_captures' => $captures, 'clock_inside_owner' => $inside_clock, 'owned_native_getters' => PromisePaymentProbe::$owned_getters, 'all_retired' => array_reduce( $factory->sessions, static fn( bool $all, $s ): bool => $all && $s->is_retired(), true ), 'writes' => count( $writes ), 'original_records_unchanged' => $rows === $factory->count( 'operation_records' ), 'original_events_unchanged' => $events === $factory->count( 'operation_changes' ), 'final_clock' => $clock->sql(), 'deadline' => $deadline->sql(), 'source_deadline' => $source_deadline->sql(), 'binding_revision' => (int) $factory->pdo->query( 'SELECT revision FROM durable_delivery_engine_delivery_quote_bindings' )->fetchColumn() ], JSON_THROW_ON_ERROR );
}
