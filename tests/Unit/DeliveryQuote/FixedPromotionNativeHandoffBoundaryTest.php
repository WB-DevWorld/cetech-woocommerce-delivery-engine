<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetService, FixedPromotionBudgetStore};
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteBindResult, FixedPromotionQuoteOrderEvidenceGuard, PromiseQuotePlacementActivation, QuoteNativeTaxSource, QuotePlacementEvidence};
use CetechDeliveryEngine\Application\Order\{DeliveryQuoteSnapshotEnvelope, DeliveryQuoteSnapshotReadiness, OrderDeliverySnapshot, QuoteNativeOrderFacts, QuoteNativeOrderStager};
use CetechDeliveryEngine\Domain\Contracts\{ContractError, OperationOutcome, RequestContext};
use CetechDeliveryEngine\Domain\Operation\{OperationAttemptResult, OperationConnectionFactory, OperationSession};
use CetechDeliveryEngine\Integrations\DeliveryQuote\FixedPromotionNativeOrderEvidence;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionNativeHandoffFixture as F, PromotionNativeUnitFixture as M};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionNativeHandoffFixture.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionNativeUnitFixture.php';

/** Explicit native adapters grant no access, packet replacement, payment or funding authority. */
final class FixedPromotionNativeHandoffBoundaryTest extends TestCase {

	private array $saved_globals;
	private array $saved_modes;
	protected function setUp(): void {
		$this->saved_globals = [];
		foreach ( [ 'current_user', 'blog_id', 'woocommerce', 'cetech_de_test_options' ] as $name ) { $this->saved_globals[$name] = [ array_key_exists( $name, $GLOBALS ), $GLOBALS[$name] ?? null ]; }
		$this->saved_globals['_GET'] = $_GET; $this->saved_globals['_POST'] = $_POST; $this->saved_globals['_COOKIE'] = $_COOKIE;
		$this->saved_modes = []; if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) { foreach ( [ 'enabled', 'hpos' ] as $property ) { if ( property_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', $property ) ) { $this->saved_modes[$property] = \Automattic\WooCommerce\Utilities\OrderUtil::${$property}; } } }
		$GLOBALS['current_user'] = (object) [ 'ID' => 0, 'caps' => [], 'allcaps' => [], 'roles' => [] ]; $GLOBALS['blog_id'] = 1; unset( $GLOBALS['woocommerce'] ); $_GET = []; $_POST = []; $_COOKIE = [];
	}
	protected function tearDown(): void {
		foreach ( [ 'current_user', 'blog_id', 'woocommerce', 'cetech_de_test_options' ] as $name ) { [ $exists, $value ] = $this->saved_globals[$name]; if ( $exists ) { $GLOBALS[$name] = $value; } else { unset( $GLOBALS[$name] ); } }
		$_GET = $this->saved_globals['_GET']; $_POST = $this->saved_globals['_POST']; $_COOKIE = $this->saved_globals['_COOKIE'];
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) { foreach ( [ 'enabled', 'hpos' ] as $property ) { if ( property_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', $property ) ) { \Automattic\WooCommerce\Utilities\OrderUtil::${$property} = $this->saved_modes[$property] ?? false; } } }
	}
	private function refuse( callable $call ): void {
		try { $call(); self::fail( 'The native handoff must refuse without writing.' ); }
		catch ( \RuntimeException|\UnexpectedValueException $error ) { self::assertContains( $error->getMessage(), [ 'Saved quote facts unavailable.', 'Saved quote authorization unavailable.' ] ); }
	}
	private function model( bool $hpos = false ): array {
		$model = M::create( $hpos ); self::assertInstanceOf( FixedPromotionNativeOrderEvidence::class, $model['guard'] );
		$model['ack'] = F::acknowledged( $model['fixture'], 100, $model['mapping'], $model['guard']->evidence_digest() );
		$model['stager'] = new QuoteNativeOrderStager( $model['factory'], static fn(): \WC_Order => clone $model['order'] ); return $model;
	}
	private function stage( array $model, ?FixedPromotionQuoteBindResult $ack = null, ?QuoteNativeTaxSource $tax_source = null ): mixed {
		return $model['stager']->stage_promotion( $model['order'], $ack ?? $model['ack'], $model['fixture']['draft'], $tax_source ?? $model['fixture']['tax_source'], 'classic', static fn( \WC_Order $candidate ): bool => $candidate === $model['order'] );
	}
	private function bytes( array $model ): array { return [ $model['order']->meta, array_map( static fn( object $item ): array => $item->meta, $model['order']->lines ), $model['order']->saves, array_map( static fn( object $item ): int => $item->saves, $model['order']->lines ) ]; }

	public function test_all_explicit_entries_authorize_exact_order_before_native_reads_or_sql(): void {
		$f = F::create(); $ack = F::acknowledged( $f, 100, $f['mapping'], Q::digest( 'untrusted-native' ) ); $order = new PromotionBoundaryReadTrap(); $calls = 0;
		$factory = $this->createMock( OperationConnectionFactory::class ); $factory->expects( self::never() )->method( 'open' ); $stager = new QuoteNativeOrderStager( $factory );
		$deny = static function( \WC_Order $seen ) use ( $order, &$calls ): bool { self::assertSame( $order, $seen ); ++$calls; return false; };
		self::assertNull( FixedPromotionNativeOrderEvidence::capture( $factory, $order, $f['quote'], $f['mapping'], $f['tax_source'], $deny ) );
		foreach ( [ static fn() => $stager->promotion_mapping( $order, $f['evidence'], $deny ), static fn() => $stager->stage_promotion( $order, $ack, $f['draft'], $f['tax_source'], 'order_pay', $deny ), static fn() => $stager->saved_promotion_guard( $order, $f['quote'], $ack->binding, $deny ) ] as $call ) { $this->refuse( $call ); }
		self::assertSame( 4, $calls ); self::assertSame( 0, $order->reads );
	}

	public static function revoked_identities(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'guest query key', 'guest post key', 'staff capabilities', 'actor object', 'site' ] ); }
	#[DataProvider( 'revoked_identities' )]
	public function test_authorizer_cannot_replace_identity_while_returning_true( string $case ): void {
		$f = F::create(); $order = new PromotionBoundaryReadTrap(); $calls = 0; $factory = $this->createMock( OperationConnectionFactory::class ); $factory->expects( self::never() )->method( 'open' );
		$authorize = static function() use ( &$calls, $case ): bool {
			if ( 2 === ++$calls ) { match ( $case ) { 'guest query key' => $_GET['key'] = 'foreign-private-order-key', 'guest post key' => $_POST['key'] = 'foreign-private-order-key', 'staff capabilities' => $GLOBALS['current_user']->allcaps = [ 'manage_woocommerce' => true ], 'actor object' => $GLOBALS['current_user'] = clone $GLOBALS['current_user'], 'site' => $GLOBALS['blog_id'] = 2 }; } return true;
		};
		self::assertNull( FixedPromotionNativeOrderEvidence::capture( $factory, $order, $f['quote'], $f['mapping'], $f['tax_source'], $authorize ) ); self::assertSame( 2, $calls ); self::assertSame( 0, $order->reads );
	}

	public static function incomplete_outcomes(): array { return [ [ 'pending' ], [ 'rejected' ], [ 'unconfirmed' ], [ 'awaiting publication' ] ]; }
	#[DataProvider( 'incomplete_outcomes' )]
	public function test_unconfirmed_or_incomplete_bind_never_authorizes_native_staging( string $case ): void {
		$f = F::create(); $good = F::acknowledged( $f, 100, $f['mapping'], Q::digest( 'untrusted-native' ) ); $order = new PromotionBoundaryReadTrap();
		$unknown = new ContractError( 'outcome_unknown', RequestContext::create(), 'reconcile_original_request' );
		$result = match ( $case ) {
			'pending' => new FixedPromotionQuoteBindResult( new OperationAttemptResult( OperationOutcome::pending() ) ),
			'rejected' => new FixedPromotionQuoteBindResult( new OperationAttemptResult( OperationOutcome::rejected( new ContractError( 'not_authorized', RequestContext::create(), 'contact_support' ) ) ) ),
			'unconfirmed' => new FixedPromotionQuoteBindResult( new OperationAttemptResult( OperationOutcome::unconfirmed( $unknown ) ) ),
			'awaiting publication' => new FixedPromotionQuoteBindResult( new OperationAttemptResult( OperationOutcome::awaiting_publication( $unknown ), $good->attempt->completion ), $good->command, $good->quote, $good->binding ),
		};
		$factory = $this->createMock( OperationConnectionFactory::class ); $factory->expects( self::never() )->method( 'open' );
		$this->refuse( static fn() => ( new QuoteNativeOrderStager( $factory ) )->stage_promotion( $order, $result, $f['draft'], $f['tax_source'], 'classic', static fn(): bool => true ) ); self::assertSame( 0, $order->reads );
	}

	public static function ineligible_native_orders(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'paid flag', 'paid time', 'processing status', 'native actor callback', 'native key callback', 'native site callback', 'native mode callback' ] ); }
	#[DataProvider( 'ineligible_native_orders' )]
	public function test_paid_or_changed_native_order_cannot_receive_new_packets( string $case ): void {
		$m = $this->model(); $order = $m['order']; $factory = $m['factory']; $before = $this->bytes( $m );
		if ( 'paid flag' === $case ) { $order->paid = true; }
		elseif ( 'paid time' === $case ) { $order->date_paid = '2026-10-23 12:00:00'; }
		elseif ( 'processing status' === $case ) { $order->state = 'processing'; }
		else {
			$order->on_get = static function( object $current ) use ( $case ): void { $current->on_get = null;
				if ( 'native mode callback' === $case ) { foreach ( [ 'enabled', 'hpos' ] as $property ) { if ( property_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', $property ) ) { \Automattic\WooCommerce\Utilities\OrderUtil::${$property} = true; } } }
				else { match ( $case ) { 'native actor callback' => $GLOBALS['current_user'] = clone $GLOBALS['current_user'], 'native key callback' => $current->change_auth( 'order_key', 'foreign-private-order-key' ), 'native site callback' => $GLOBALS['blog_id'] = 2 }; }
			};
		}
		$this->refuse( fn() => $this->stage( $m ) ); self::assertSame( $before, $this->bytes( $m ) ); self::assertSame( 0, $factory->queries ); self::assertSame( 0, $factory->commits );
	}

	public static function unknown_packets(): array { return [ [ 'order marker' ], [ 'order packet' ], [ 'draft packet' ], [ 'line marker' ], [ 'line packet' ] ]; }
	#[DataProvider( 'unknown_packets' )]
	public function test_unknown_or_partial_protected_packets_are_preserved_byte_for_byte( string $case ): void {
		$m = $this->model(); $order = $m['order']; $line = $order->lines[0]; $unknown = '{"format_version":99,"private":"PRIVATE-N17-UNKNOWN-BYTES"}';
		match ( $case ) { 'order marker' => $order->meta[DeliveryQuoteSnapshotEnvelope::META_FORMAT] = '99', 'order packet' => $order->meta[OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT] = $unknown, 'draft packet' => $order->meta[QuoteNativeOrderFacts::META_DRAFT] = $unknown, 'line marker' => $line->meta[DeliveryQuoteSnapshotEnvelope::META_FORMAT] = '99', 'line packet' => $line->meta[OrderDeliverySnapshot::META_LINE_SNAPSHOT] = $unknown };
		$before = $this->bytes( $m ); $this->refuse( fn() => $this->stage( $m ) ); self::assertSame( $before, $this->bytes( $m ) ); self::assertSame( 0, $m['factory']->queries ); self::assertSame( 0, $m['factory']->commits );
	}

	public static function foreign_original_facts(): array { return [ [ 'candidate digest' ], [ 'tax source' ] ]; }
	#[DataProvider( 'foreign_original_facts' )]
	public function test_original_native_digest_and_original_tax_source_cannot_be_substituted( string $case ): void {
		$m = $this->model(); $before = $this->bytes( $m ); $ack = $m['ack']; $tax = $m['fixture']['tax_source'];
		if ( 'candidate digest' === $case ) { $ack = F::acknowledged( $m['fixture'], 100, $m['mapping'], Q::digest( 'foreign-native-order' ) ); }
		else { $facts = $tax->private_facts(); $facts['source']['selectors']['customer_id'] = 91; $tax = QuoteNativeTaxSource::from_private_facts( $facts ); }
		$this->refuse( fn() => $this->stage( $m, $ack, $tax ) ); self::assertSame( $before, $this->bytes( $m ) ); self::assertSame( 0, $m['factory']->queries ); self::assertSame( 0, $m['factory']->commits );
	}

	public function test_detached_guard_is_private_scalar_data_and_sql_verification_invokes_no_native_callback(): void {
		$m = $this->model(); $guard = $m['guard']; $factory = $m['factory'];
		$inspect = function( mixed $value ) use ( &$inspect ): void { self::assertFalse( is_object( $value ) || is_resource( $value ), 'A detached guard retained a live participant.' ); if ( is_array( $value ) ) { foreach ( $value as $child ) { $inspect( $child ); } } };
		foreach ( ( new \ReflectionObject( $guard ) )->getProperties() as $property ) { if ( ! $property->isStatic() ) { $inspect( $property->getValue( $guard ) ); } }
		foreach ( [ static fn() => json_encode( $guard, JSON_THROW_ON_ERROR ), static fn() => serialize( $guard ) ] as $persist ) { try { $persist(); self::fail( 'A native guard must remain private.' ); } catch ( \LogicException ) { self::assertTrue( true ); } }
		self::assertSame( [ 'private_native_promotion_order' => 'redacted' ], $guard->__debugInfo() );
		foreach ( [ 'PRIVATE-N17-ADDRESS', 'wc_order_private_promotion_unit', $m['fixture']['evidence']->reference()->handle() ] as $private ) { self::assertStringNotContainsString( $private, print_r( $guard, true ) ); }
		$session = $factory->open(); self::assertTrue( $session->begin() ); $native_reads = $m['order']->gets; $closed = [ $factory->rollbacks, $factory->retirements, $factory->commits, $factory->queries ]; $offset = count( $factory->sql );
		$m['order']->on_get = static function(): never { throw new \LogicException( 'Current SQL proof must not invoke a native getter.' ); };
		self::assertTrue( $guard->verify( $session, $m['fixture']['quote'], $m['candidate'] ) ); self::assertSame( $native_reads, $m['order']->gets ); self::assertTrue( $session->in_transaction() ); self::assertFalse( $session->is_retired() );
		self::assertSame( $closed, [ $factory->rollbacks, $factory->retirements, $factory->commits, $factory->queries ] );
		foreach ( array_slice( $factory->sql, $offset ) as $sql ) { self::assertStringStartsWith( 'SELECT ', $sql ); self::assertStringEndsWith( ' FOR UPDATE', $sql ); }
		$m['order']->on_get = null; self::assertTrue( $session->rollback() ); self::assertTrue( $session->retire() );
	}

	public function test_explicit_native_receipts_do_not_open_legacy_payment_funding_or_default_adoption(): void {
		$m = $this->model(); $f = $m['fixture']; $ack = $m['ack']; $calls = 0; $before = get_option( PromiseQuotePlacementActivation::OPTION, null );
		$factory = $this->createMock( OperationConnectionFactory::class ); $factory->expects( self::never() )->method( 'open' ); $stager = new QuoteNativeOrderStager( $factory );
		try { new QuotePlacementEvidence( $f['quote'], $f['evidence']->reference(), $f['evidence']->header(), $f['evidence']->context(), $f['evidence']->guard(), static function() use ( &$calls ): bool { ++$calls; return true; }, $f['quote']->accepted_at() ); self::fail( 'The retained carrier must remain closed to promotion format3.' ); }
		catch ( \RuntimeException $error ) { self::assertSame( 'Delivery quote placement unavailable.', $error->getMessage() ); }
		foreach ( [ static fn() => $stager->mapping( $m['order'], $f['evidence'] ), static fn() => $stager->stage( $m['order'], $f['evidence'], $ack->binding, 'order_pay' ), static fn() => $stager->saved_guard( $m['order'], $f['quote'], $ack->binding ) ] as $legacy ) { try { $legacy(); self::fail( 'An explicit receipt must not open retained entrypoints.' ); } catch ( \TypeError|\UnexpectedValueException ) { self::assertTrue( true ); } }
		$session = $this->createMock( OperationSession::class ); foreach ( [ 'site_id', 'table_prefix', 'in_transaction', 'is_retired', 'query', 'get_row', 'get_results' ] as $method ) { $session->expects( self::never() )->method( $method ); }
		$store = $this->createMock( FixedPromotionBudgetStore::class ); foreach ( [ 'tables', 'lock_account', 'lock_reservation', 'replace_account', 'insert_reservation', 'replace_reservation' ] as $method ) { $store->expects( self::never() )->method( $method ); }
		$budget = new FixedPromotionBudgetService( $store, static function() use ( &$calls ): bool { ++$calls; return true; } );
		foreach ( [ $m['guard'], $f['evidence'], $ack ] as $observation ) { foreach ( [ 'commit', 'release' ] as $action ) { try { $budget->$action( $session, $observation, Q::time() ); self::fail( 'Native receipts are not funding instructions.' ); } catch ( \TypeError ) { self::assertTrue( true ); } } foreach ( [ 'authorize_payment', 'commit', 'release', 'admit_sealed_placement' ] as $capability ) { self::assertFalse( method_exists( $observation, $capability ) ); } }
		$contract = DeliveryQuoteSnapshotReadiness::fixed_promotion_contract(); self::assertTrue( $contract['reader_only'] ); self::assertFalse( $contract['writer_enabled'] ); self::assertSame( 0, $calls ); self::assertSame( $before, get_option( PromiseQuotePlacementActivation::OPTION, null ) );
	}
}

/** Getter traps prove access fences precede even harmless native prewarming. */
final class PromotionBoundaryReadTrap extends \WC_Order {
	public int $id = 100;
	public array $changes = [];
	public int $reads = 0;
	public function __construct() { parent::__construct( [ 'id' => 100, 'customer_id' => 0, 'order_key' => 'private-n17-boundary-order-key' ] ); }
	public function get_id(): int { ++$this->reads; throw new \LogicException( 'Native getters must follow exact-order authorization.' ); }
	public function get_items( string $type = '' ): array { ++$this->reads; throw new \LogicException( 'Native getters must follow exact-order authorization.' ); }
	public function get_meta( string $key, bool $single = true ): mixed { ++$this->reads; throw new \LogicException( 'Private packets must follow exact-order authorization.' ); }
}
