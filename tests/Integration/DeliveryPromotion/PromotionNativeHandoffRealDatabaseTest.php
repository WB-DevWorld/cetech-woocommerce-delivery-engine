<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptCommand, FixedPromotionQuoteAcceptInputs, FixedPromotionQuoteBindCommand, FixedPromotionQuoteBindInputs, FixedPromotionQuoteSealCommand, FixedPromotionQuoteVerifyCommand, FixedPromotionQuoteVerifyInputs, QuoteIssueCommand, QuotePreparationAttempt, QuotePreparationCommand, QuotePreparationGate};
use CetechDeliveryEngine\Application\Order\{QuoteNativeOrderFacts, QuoteNativeOrderStageResult};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedPromotionBudgetState;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteId, QuoteOwner, QuoteStoredRow};
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\{EmergencyControlStore, FixedPromotionBudgetRecordCodec};
use CetechDeliveryEngine\Integrations\DeliveryQuote\FixedPromotionNativeOrderEvidence as Native;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\{FixedPromotionQuoteFixture as F, PromotionBudgetSqlFixture as B};
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionBudgetAllocationSqlFixture as A, PromotionNativeHandoffFixture as Pure, PromotionNativeHandoffSqlFixture as Rows, PromotionQuoteAcceptSqlStack as AcceptStack, PromotionQuoteBindOrderPort as Mapping, PromotionQuoteBindSqlStack as BindStack, PromotionQuoteIssueSqlFactory, PromotionQuoteIssueSqlPorts as Ports, PromotionQuoteIssueSqlStack as IssueStack, PromotionQuoteSealSqlStack as SealStack, PromotionQuoteVerifySqlStack as VerifyStack};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteFixtures as Q, QuoteLifecycleProofFactory as Factory};
use CetechDeliveryEngine\Tests\Support\Operation\{OperationProofBarrier, OperationProofProcess};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionNativeHandoffFixture.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionNativeHandoffSqlFixture.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionQuoteSealSqlStack.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionBudgetAllocationSqlFixture.php';

/** Twelve physical InnoDB/current-lock cases. Woo getters, policy/native sources and saved packet creation are synthetic. */
#[Group( 'promotion-commercial-real-db' )]
final class PromotionNativeHandoffRealDatabaseTest extends TestCase {
	private \mysqli $database;
	private string $prefix;
	private array $factories = [];
	private array $processes = [];
	private array $directories = [];
	private array $globals = [];
	private array $fixture;

	protected function setUp(): void {
		foreach ( [ 'current_user', 'blog_id', 'woocommerce', 'cetech_de_n17_native_identity_key' ] as $key ) { $this->globals[$key] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[$key] ?? null ]; }
		$GLOBALS['current_user'] = (object) [ 'ID' => 0, 'caps' => [], 'allcaps' => [], 'roles' => [] ]; $GLOBALS['blog_id'] = 1; $GLOBALS['cetech_de_n17_native_identity_key'] = 'private-promotion-native-handoff-fixture-key'; unset( $GLOBALS['woocommerce'] );
		$this->database = DB::connect(); $this->prefix = DB::prefix(); DB::install( $this->database, $this->prefix ); B::install( $this->database, $this->prefix ); A::publication( $this->database, $this->prefix, 'success' ); Rows::install( $this->database, $this->prefix );
		$this->fixture = Pure::create( [ 'table_prefix' => $this->prefix, 'private_key' => wp_salt( 'auth' ) ] );
	}
	protected function tearDown(): void {
		foreach ( $this->processes as $process ) { try { $process->kill_and_wait(); } catch ( \Throwable ) { $process->kill(); } }
		foreach ( $this->factories as $factory ) { $factory->close_all(); }
		foreach ( $this->directories as $directory ) { OperationProofBarrier::cleanup( $directory ); }
		if ( isset( $this->database, $this->prefix ) ) { DB::cleanup( $this->database, $this->prefix ); $this->database->close(); }
		foreach ( $this->globals as $key => [ $present, $value ] ) { if ( $present ) { $GLOBALS[$key] = $value; } else { unset( $GLOBALS[$key] ); } }
	}
	private function factory( int $site = 1 ): Factory { $factory = new Factory( $this->prefix, $site ); $this->factories[] = $factory; return $factory; }
	private static function authorize( \WC_Order $order ): bool { return $order instanceof \CetechDeliveryEngine\Tests\Support\DeliveryPromotion\PromotionNativeHandoffSqlOrder; }
	private function seed( bool $hpos ): array {
		$f = $this->fixture; Rows::mode( $this->database, $this->prefix, $hpos ); $order = Rows::order( $f['quote'], $f['mapping'], [ $f['component'] => $f['group'] ], $f['destination'] ); Rows::put( $this->database, $this->prefix, $order, $hpos );
		$before = Rows::census( $this->database, $this->prefix ); $guard = Native::capture( $this->factory(), $order, $f['quote'], $f['mapping'], $f['tax_source'], self::authorize( ... ) ); self::assertNotNull( $guard ); self::assertSame( $before, Rows::census( $this->database, $this->prefix ) );
		// This acknowledgment only supplies a typed prepared1 shape. Genuine C03 history is covered separately below.
		$acknowledged = Pure::acknowledged( $f, 100, $f['mapping'], $guard->evidence_digest() ); return [ $order, $guard, $acknowledged->binding ];
	}
	private function proof( Native $guard, QuoteBinding $binding, ?OperationSession $session = null ): bool {
		$factory = null; if ( null === $session ) { $factory = $this->factory(); $session = $factory->open(); self::assertTrue( $session->begin() ); }
		$before = Rows::census( $this->database, $this->prefix ); $result = $guard->verify( $session, $this->fixture['quote'], $binding ); self::assertTrue( $session->in_transaction() ); self::assertFalse( $session->is_retired() );
		self::assertTrue( $session->rollback() ); self::assertTrue( $session->retire() ); self::assertSame( $before, Rows::census( $this->database, $this->prefix ) ); return $result;
	}
	private function sql( string $sql ): void { DB::execute( $this->database, str_replace( '{p}', $this->prefix, $sql ) ); }
	private function changed_meta( bool $hpos, string $key, string $value ): void {
		$table = $this->prefix . ( $hpos ? 'wc_orders_meta' : 'postmeta' ); $column = $hpos ? 'order_id' : 'post_id'; $key = $this->database->real_escape_string( $key ); $value = $this->database->real_escape_string( $value );
		$this->sql( "DELETE FROM `{$table}` WHERE {$column}=100 AND meta_key='{$key}'" ); Rows::insert( $this->database, $table, [ $column => 100, 'meta_key' => $key, 'meta_value' => $value ] );
	}
	private function saved( bool $hpos, \WC_Order $order, QuoteBinding $prepared, QuoteStoredRow $quote ): array {
		// Deliberately synthetic opaque packets: this lane certifies existing physical saved guard locks, not the native snapshot writer.
		$table = $this->prefix . ( $hpos ? 'wc_orders_meta' : 'postmeta' ); $column = $hpos ? 'order_id' : 'post_id'; Rows::meta( $this->database, $table, $column, $order->get_id(), [ '_cetech_de_n17_fixture_packet' => 'synthetic-saved-packet-v1' ] );
		$session = $this->factory()->open(); self::assertTrue( $session->begin() ); $physical = QuoteNativeOrderStageResult::read_physical( $session, $order->get_id(), $hpos ); self::assertTrue( $session->rollback() ); self::assertTrue( $session->retire() );
		$snapshot = Q::digest( 'N17 synthetic saved packet' ); $context = Q::digest( 'N17 synthetic saved context' );
		$guard = new QuoteNativeOrderStageResult( 1, $order->get_id(), $hpos, $prepared->mapping(), $snapshot, $context, $physical, $order );
		$row = $prepared->row(); $row['snapshot_digest'] = $snapshot; $row['context_digest'] = $context; $row['verified_at'] = $row['created_at']; $row['revision'] = 2; return [ $guard, QuoteBinding::from_row( $row, $quote ) ];
	}

	public function test_hpos_native_candidate_capture_is_detached_read_only_and_preserves_exact_rows(): void {
		[ $order, $guard, $binding ] = $this->seed( true ); $digest = $guard->evidence_digest(); self::assertMatchesRegularExpression( '/\A[a-f0-9]{64}\z/D', $digest ); $order->poison = true;
		self::assertTrue( $this->proof( $guard, $binding ) ); self::assertSame( $digest, $guard->evidence_digest() );
		foreach ( $this->factories as $factory ) { foreach ( $factory->transports as $transport ) { foreach ( $transport->sql as $sql ) { self::assertDoesNotMatchRegularExpression( '/\A\s*(?:INSERT|UPDATE|DELETE|COMMIT)\b/i', $sql ); } } }
	}
	public function test_cpt_native_candidate_and_existing_saved_guard_share_exact_mapping_without_getters(): void {
		[ $order, $guard, $binding ] = $this->seed( false ); [ $saved, $verified ] = $this->saved( false, $order, $binding, $this->fixture['quote'] ); $order->poison = true;
		self::assertTrue( $this->proof( $guard, $binding ), 'Candidate excludes only owned snapshot metadata added after bind.' );
		$before = Rows::census( $this->database, $this->prefix ); $session = $this->factory()->open(); self::assertTrue( $session->begin() ); self::assertTrue( $saved->verify( $session, $verified ) ); self::assertTrue( $session->rollback() ); self::assertTrue( $session->retire() ); self::assertSame( $before, Rows::census( $this->database, $this->prefix ) );
	}
	public function test_customer_and_order_key_changes_refuse_in_both_storage_modes(): void {
		foreach ( [ true, false ] as $hpos ) { foreach ( [ 'customer', 'key' ] as $kind ) {
			[ , $guard, $binding ] = $this->seed( $hpos );
			if ( $hpos ) { $this->sql( 'UPDATE `{p}' . ( 'customer' === $kind ? 'wc_orders` SET customer_id=99 WHERE id=100' : "wc_order_operational_data` SET order_key='foreign-order-key' WHERE order_id=100" ) ); }
			else { $this->changed_meta( false, 'customer' === $kind ? '_customer_user' : '_order_key', 'customer' === $kind ? '99' : 'foreign-order-key' ); }
			self::assertFalse( $this->proof( $guard, $binding ) );
		} }
	}
	public function test_product_variation_quantity_and_shipping_group_changes_refuse_without_overwriting(): void {
		foreach ( [ true, false ] as $hpos ) { foreach ( [ '_product_id' => '99', '_variation_id' => '91', '_qty' => '3', 'cetech_de_group_id' => 'foreign|delivery|20', 'shipping_label' => 'Changed physical label' ] as $key => $value ) {
			[ $order, $guard, $binding ] = $this->seed( $hpos ); $item = 'cetech_de_group_id' === $key ? 1501 : 901; $escaped = $this->database->real_escape_string( $value );
			if ( 'shipping_label' === $key ) { $this->sql( "UPDATE `{p}woocommerce_order_items` SET order_item_name='{$escaped}' WHERE order_item_id=1501" ); }
			else { $this->sql( "UPDATE `{p}woocommerce_order_itemmeta` SET meta_value='{$escaped}' WHERE order_item_id={$item} AND meta_key='{$key}'" ); }
			self::assertFalse( $this->proof( $guard, $binding ) ); $before = Rows::census( $this->database, $this->prefix ); self::assertNull( Native::capture( $this->factory(), $order, $this->fixture['quote'], $this->fixture['mapping'], $this->fixture['tax_source'], self::authorize( ... ) ) ); self::assertSame( $before, Rows::census( $this->database, $this->prefix ) );
		} }
	}
	public function test_currency_order_money_shipping_and_tax_mutations_refuse_in_both_modes(): void {
		foreach ( [ true, false ] as $hpos ) { foreach ( [ 'currency', 'total', 'shipping', 'tax', 'line' ] as $kind ) {
			[ , $guard, $binding ] = $this->seed( $hpos );
			if ( 'line' === $kind ) { $this->sql( "UPDATE `{p}woocommerce_order_itemmeta` SET meta_value='26.00' WHERE order_item_id=901 AND meta_key='_line_total'" ); }
			elseif ( $hpos ) { $this->sql( match ( $kind ) { 'currency' => "UPDATE `{p}wc_orders` SET currency='USD' WHERE id=100", 'total' => 'UPDATE `{p}wc_orders` SET total_amount=99 WHERE id=100', 'shipping' => 'UPDATE `{p}wc_order_operational_data` SET shipping_total_amount=12.50 WHERE order_id=100', default => 'UPDATE `{p}wc_order_operational_data` SET shipping_tax_amount=1.90 WHERE order_id=100' } ); }
			else { $this->changed_meta( false, match ( $kind ) { 'currency' => '_order_currency', 'total' => '_order_total', 'shipping' => '_order_shipping', default => '_order_shipping_tax' }, 'currency' === $kind ? 'USD' : '99' ); }
			self::assertFalse( $this->proof( $guard, $binding ) );
		} }
	}
	public function test_current_paid_status_type_and_parent_fences_refuse_refunds_or_paid_orders(): void {
		foreach ( [ true, false ] as $hpos ) { foreach ( [ 'paid', 'status', 'type', 'parent' ] as $kind ) {
			[ , $guard, $binding ] = $this->seed( $hpos );
			if ( $hpos ) { $this->sql( match ( $kind ) { 'paid' => "UPDATE `{p}wc_order_operational_data` SET date_paid_gmt='2026-10-07 05:00:00' WHERE order_id=100", 'status' => "UPDATE `{p}wc_orders` SET status='wc-processing' WHERE id=100", 'type' => "UPDATE `{p}wc_orders` SET type='shop_order_refund' WHERE id=100", default => 'UPDATE `{p}wc_orders` SET parent_order_id=55 WHERE id=100' } ); }
			elseif ( 'paid' === $kind ) { $this->changed_meta( false, '_date_paid', '1791349200' ); }
			else { $this->sql( match ( $kind ) { 'status' => "UPDATE `{p}posts` SET post_status='wc-processing' WHERE ID=100", 'type' => "UPDATE `{p}posts` SET post_type='shop_order_refund' WHERE ID=100", default => 'UPDATE `{p}posts` SET post_parent=55 WHERE ID=100' } ); }
			self::assertFalse( $this->proof( $guard, $binding ) );
		} }
	}
	public function test_missing_duplicate_and_extra_native_rows_fail_closed_at_capture_and_proof(): void {
		foreach ( [ true, false ] as $hpos ) { foreach ( [ 'duplicate_meta', 'missing_item', 'extra_item', 'missing_order' ] as $kind ) {
			[ $order, $guard, $binding ] = $this->seed( $hpos );
			if ( 'duplicate_meta' === $kind ) { Rows::meta( $this->database, $this->prefix . 'woocommerce_order_itemmeta', 'order_item_id', 901, [ '_qty' => '2' ] ); }
			elseif ( 'missing_item' === $kind ) { $this->sql( 'DELETE FROM `{p}woocommerce_order_items` WHERE order_item_id=901' ); }
			elseif ( 'extra_item' === $kind ) { Rows::insert( $this->database, $this->prefix . 'woocommerce_order_items', [ 'order_item_id' => 999, 'order_id' => 100, 'order_item_type' => 'fee', 'order_item_name' => 'Unexpected native fee' ] ); }
			else { $this->sql( 'DELETE FROM `{p}' . ( $hpos ? 'wc_orders` WHERE id=100' : 'posts` WHERE ID=100' ) ); }
			$before = Rows::census( $this->database, $this->prefix ); self::assertNull( Native::capture( $this->factory(), $order, $this->fixture['quote'], $this->fixture['mapping'], $this->fixture['tax_source'], self::authorize( ... ) ) ); self::assertSame( $before, Rows::census( $this->database, $this->prefix ) ); self::assertFalse( $this->proof( $guard, $binding ) );
		} }
	}
	public function test_current_order_lock_overrides_old_repeatable_read_after_second_connection_commit(): void {
		foreach ( [ true, false ] as $hpos ) {
			[ , $guard, $binding ] = $this->seed( $hpos ); $factory = $this->factory(); $session = $factory->open(); self::assertTrue( $session->begin() ); $table = $this->prefix . ( $hpos ? 'wc_orders' : 'posts' ); $field = $hpos ? 'status' : 'post_status'; $id = $hpos ? 'id' : 'ID';
			$old = $session->get_results( "SELECT {$field} FROM `{$table}` WHERE {$id}=100" ); self::assertSame( 'wc-pending', $old[0][$field] ); $this->sql( "UPDATE `{$table}` SET {$field}='wc-processing' WHERE {$id}=100" ); self::assertSame( $old, $session->get_results( "SELECT {$field} FROM `{$table}` WHERE {$id}=100" ) );
			self::assertFalse( $this->proof( $guard, $binding, $session ) ); self::assertNotEmpty( array_filter( $factory->transports[0]->sql, static fn( string $sql ): bool => str_contains( $sql, $table ) && str_contains( $sql, 'FOR UPDATE' ) ) );
		}
	}
	public function test_current_item_and_saved_packet_locks_override_old_repeatable_read(): void {
		foreach ( [ true, false ] as $hpos ) { foreach ( [ 'candidate', 'saved' ] as $kind ) {
			[ $order, $guard, $binding ] = $this->seed( $hpos ); [ $saved, $verified ] = $this->saved( $hpos, $order, $binding, $this->fixture['quote'] ); $session = $this->factory()->open(); self::assertTrue( $session->begin() );
			$select = 'candidate' === $kind ? "SELECT meta_value FROM `{$this->prefix}woocommerce_order_itemmeta` WHERE order_item_id=901 AND meta_key='_qty'" : 'SELECT meta_value FROM `' . $this->prefix . ( $hpos ? 'wc_orders_meta` WHERE order_id=100' : 'postmeta` WHERE post_id=100' ) . " AND meta_key='_cetech_de_n17_fixture_packet'";
			$old = $session->get_results( $select ); self::assertCount( 1, $old );
			if ( 'candidate' === $kind ) { $this->sql( "UPDATE `{p}woocommerce_order_itemmeta` SET meta_value='7' WHERE order_item_id=901 AND meta_key='_qty'" ); }
			else { $this->changed_meta( $hpos, '_cetech_de_n17_fixture_packet', 'newly-committed-packet' ); }
			self::assertSame( $old, $session->get_results( $select ) ); $before = Rows::census( $this->database, $this->prefix ); self::assertFalse( 'candidate' === $kind ? $guard->verify( $session, $this->fixture['quote'], $binding ) : $saved->verify( $session, $verified ) ); self::assertTrue( $session->rollback() ); self::assertTrue( $session->retire() ); self::assertSame( $before, Rows::census( $this->database, $this->prefix ) );
		} }
	}
	public function test_actual_independent_writers_wait_for_candidate_and_saved_locks_until_owner_cleanup(): void {
		foreach ( [ true, false ] as $hpos ) { foreach ( [ 'candidate_order', 'candidate_item', 'saved_packet' ] as $kind ) {
			[ $order, $guard, $binding ] = $this->seed( $hpos ); [ $saved, $verified ] = $this->saved( $hpos, $order, $binding, $this->fixture['quote'] ); $factory = $this->factory(); $session = $factory->open(); self::assertTrue( $session->begin() );
			self::assertTrue( 'saved_packet' === $kind ? $saved->verify( $session, $verified ) : $guard->verify( $session, $this->fixture['quote'], $binding ) );
			$directory = OperationProofBarrier::directory(); $this->directories[] = $directory;
			$process = new OperationProofProcess( [ __DIR__ . '/promotion-native-handoff-process-worker.php', json_encode( [ 'mode' => 'writer', 'prefix' => $this->prefix, 'directory' => $directory, 'hpos' => $hpos, 'kind' => $kind ], JSON_THROW_ON_ERROR ) ] ); $this->processes[] = $process;
			OperationProofProcess::wait_for( $directory . '/dispatched', 20.0 ); $packet = json_decode( (string) file_get_contents( $directory . '/dispatched' ), true, 4, JSON_THROW_ON_ERROR ); self::assertNotSame( getmypid(), $packet['process_id'] );
			$table = $this->prefix . match ( $kind ) { 'candidate_order' => $hpos ? 'wc_orders' : 'posts', 'candidate_item' => 'woocommerce_order_itemmeta', default => $hpos ? 'wc_orders_meta' : 'postmeta' };
			$this->wait_for_lock( $packet['connection_id'], $factory->transports[0]->connection_id(), $table, $directory, $hpos, $kind ); self::assertFileDoesNotExist( $directory . '/finished' ); self::assertTrue( $session->rollback() ); self::assertTrue( $session->retire() ); $result = $process->finish( 10.0 ); $this->processes = array_values( array_filter( $this->processes, static fn( $active ): bool => $active !== $process ) ); self::assertSame( 1, $result['affected'] ); self::assertSame( 'committed', $result['state'] );
		} }
	}
	public function test_genuine_issue_accept_bind_verify_seal_records_keep_held_budget_and_native_rows_exact(): void {
		// Existing synthetic source/policy ports are retained unchanged; only this HPOS candidate and saved SQL proof are native-shaped.
		$source_session = ( new PromotionQuoteIssueSqlFactory( $this->factory() ) )->open(); self::assertTrue( $source_session->begin() ); $fixture = Rows::sources( $source_session, $this->fixture ); self::assertTrue( $source_session->rollback() ); self::assertTrue( $source_session->retire() );
		$state = FixedPromotionBudgetState::create( 1, $fixture['slots'][0]['account_key'], F::rule()->private_facts()['funding_source'], B::money( '3.00' ) ); Rows::insert( $this->database, $this->prefix . 'delivery_engine_promotion_budget_accounts', FixedPromotionBudgetRecordCodec::account_values( $state ) );
		$issue = new IssueStack( $this->prefix, $fixture ); $this->factories[] = $issue->physical; $token = QuoteId::generate()->value(); $original = QuoteIssueCommand::fixed_promotion( $fixture['owner'], $fixture['provider']->inner->context(), $token, $fixture['slots'], Q::time()->plus_seconds( 45 ) );
		$gate = new QuotePreparationGate( $this->factory(), static fn( QuoteOwner $owner, string $operation ): bool => 1 === $owner->site_id() && 'delivery_quote.issue' === $operation, null, new EmergencyControlStore( static fn(): bool => false ) );
		$admission = $gate->admit( QuotePreparationCommand::fixed_promotion( $fixture['owner'], Q::digest( 'N17 declared synthetic draft' ), $token ), QuotePreparationAttempt::generate() ); self::assertSame( 'preparation_allowed', $admission->status ); self::assertTrue( $admission->lease->claim_preparation() );
		$issued = $issue->service->issue_admitted( $original, $admission->lease->bind_issue( $original ), $issue->inputs(), RequestContext::create(), static fn(): bool => true ); self::assertSame( 'accepted', $issued->attempt->outcome->state );
		$accept = new AcceptStack( $this->prefix, $fixture ); $this->factories[] = $accept->physical; $accepted = $accept->service->accept( FixedPromotionQuoteAcceptCommand::from_issue( $issued->command ), $accept->inputs(), RequestContext::create() ); self::assertSame( 'accepted', $accepted->attempt->outcome->state );
		$quote = $accepted->quote; $mapping = Mapping::mapping( $quote, 1001 ); $address = $this->fixture['destination'];
		$order = Rows::order( $quote, $mapping, [ $this->fixture['component'] => $this->fixture['group'] ], $address, 0, 1001 ); Rows::mode( $this->database, $this->prefix, true ); Rows::put( $this->database, $this->prefix, $order, true );
		$tax_source = $fixture['fresh']->native_receipt()->tax_source(); $guard = Native::capture( $this->factory(), $order, $quote, $mapping, $tax_source, self::authorize( ... ) ); self::assertNotNull( $guard );
		$bind_command = FixedPromotionQuoteBindCommand::create( $accepted->command, $quote, 1001, QuoteId::generate(), $mapping, $guard->evidence_digest() ); $bind = new BindStack( $this->prefix, $fixture, $bind_command ); $this->factories[] = $bind->physical;
		$inputs = FixedPromotionQuoteBindInputs::from_sources( FixedPromotionQuoteAcceptInputs::from_sources( $fixture['original'], $fixture['fresh'] ), $guard ); $held = $this->held_bytes(); $bound = $bind->service->bind( $bind_command, $inputs, RequestContext::create() ); self::assertSame( 'accepted', $bound->attempt->outcome->state ); self::assertSame( 1, $bound->binding->revision() );
		[ $saved ] = $this->saved( true, $order, $bound->binding, $quote ); $native = $this->native_bytes(); $prepared = $bound->binding->row();
		$verify_command = FixedPromotionQuoteVerifyCommand::create( $bound->command, $bound->binding, $saved->snapshot_digest(), $saved->context_digest() ); $verify = new VerifyStack( $this->prefix, $fixture, $verify_command ); $this->factories[] = $verify->physical; $verify_inputs = FixedPromotionQuoteVerifyInputs::from_sources( $inputs, $saved ); $order->poison = true;
		$verified = $verify->service->verify( $verify_command, $verify_inputs, RequestContext::create() ); self::assertSame( 'accepted', $verified->attempt->outcome->state ); self::assertSame( 2, $verified->binding->revision() );
		$seal_command = FixedPromotionQuoteSealCommand::create( $verify_command, $verified->binding, 1 ); $seal = new SealStack( $this->prefix, $fixture, $seal_command ); $this->factories[] = $seal->physical; $sealed = $seal->service->seal( $seal_command, $verify_inputs, RequestContext::create() ); self::assertSame( 'accepted', $sealed->attempt->outcome->state ); self::assertSame( 'sealed', $sealed->binding->state() ); self::assertSame( 3, $sealed->binding->revision() );
		self::assertSame( $held, $this->held_bytes() ); self::assertSame( $native, $this->native_bytes() ); self::assertSame( $prepared['created_at'], $sealed->binding->row()['created_at'] ); self::assertSame( $prepared['id'], $sealed->binding->id() );
		$records = DB::row( $this->database, "SELECT COUNT(*) AS n FROM `{$this->prefix}delivery_engine_operation_records` WHERE state='accepted'" ); self::assertGreaterThanOrEqual( 5, (int) $records['n'] ); self::assertSame( 5, (int) DB::scalar( $this->database, "SELECT COUNT(*) FROM `{$this->prefix}delivery_engine_operation_changes`" ) );
	}
	public function test_fresh_process_rebuild_matches_digest_and_authoritative_mode_or_site_change_refuses(): void {
		foreach ( [ true, false ] as $hpos ) {
			[ $order, $guard, $binding ] = $this->seed( $hpos ); $directory = OperationProofBarrier::directory(); $this->directories[] = $directory; $file = $directory . '/native-recovery.json';
			$packet = [ 'quote' => $this->fixture['quote']->row(), 'mapping' => $this->fixture['mapping'], 'tax_source' => $this->fixture['tax_source']->to_private_json(), 'group' => $this->fixture['group'], 'component' => $this->fixture['component'], 'destination' => $this->fixture['destination'], 'binding' => $binding->row() ]; $bytes = json_encode( $packet, JSON_THROW_ON_ERROR ); self::assertSame( strlen( $bytes ), file_put_contents( $file, $bytes, LOCK_EX ) ); self::assertTrue( chmod( $file, 0600 ) );
			$before = Rows::census( $this->database, $this->prefix ); $process = new OperationProofProcess( [ __DIR__ . '/promotion-native-handoff-process-worker.php', json_encode( [ 'mode' => 'recapture', 'prefix' => $this->prefix, 'directory' => $directory, 'hpos' => $hpos ], JSON_THROW_ON_ERROR ) ] ); $this->processes[] = $process; $result = $process->finish( 20.0 ); $this->processes = array_values( array_filter( $this->processes, static fn( $active ): bool => $active !== $process ) ); self::assertTrue( $result['verified'] ); self::assertSame( $guard->evidence_digest(), $result['digest'] ); self::assertNotSame( getmypid(), $result['process_id'] ); self::assertSame( $before, Rows::census( $this->database, $this->prefix ) );
			$wrong = $this->factory( 2 )->open(); self::assertTrue( $wrong->begin() ); self::assertFalse( $guard->verify( $wrong, $this->fixture['quote'], $binding ) ); self::assertTrue( $wrong->rollback() ); self::assertTrue( $wrong->retire() );
			Rows::mode( $this->database, $this->prefix, ! $hpos ); self::assertFalse( $this->proof( $guard, $binding ) ); self::assertNull( Native::capture( $this->factory(), $order, $this->fixture['quote'], $this->fixture['mapping'], $this->fixture['tax_source'], self::authorize( ... ) ) );
		}
	}

	private function held_bytes(): array { $all = Rows::census( $this->database, $this->prefix ); return array_intersect_key( $all, array_flip( [ 'delivery_engine_promotion_budget_accounts', 'delivery_engine_promotion_budget_reservations', 'delivery_engine_delivery_quotes' ] ) ); }
	private function native_bytes(): array { return array_slice( Rows::census( $this->database, $this->prefix ), 0, 8, true ); }
	private function wait_for_lock( int $waiter, int $blocker, string $table, string $directory, bool $hpos, string $kind ): void {
		$deadline = microtime( true ) + 1.5;
		do {
			$count = (int) DB::scalar( $this->database, "SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id WHERE l.lock_table=CONCAT('`',DATABASE(),'`.`{$table}`') AND r.trx_mysql_thread_id={$waiter} AND b.trx_mysql_thread_id={$blocker} AND r.trx_state='LOCK WAIT'" );
			if ( $count > 0 ) { self::assertGreaterThan( 0, $count ); return; }
			// MariaDB11.4 refreshes this observation cache only after >100ms idle; 10ms reads can retain an empty snapshot.
			$remaining = $deadline - microtime( true ); if ( $remaining <= 0 ) { break; } usleep( (int) min( 120000, ceil( $remaining * 1000000 ) ) );
		} while ( microtime( true ) < $deadline );
		$status = [ 'phase' => 'unknown', 'state' => 'unknown', 'errno' => -1 ]; $file = $directory . '/writer-status.json'; clearstatcache( true, $file ); $size = is_file( $file ) ? filesize( $file ) : false;
		if ( is_int( $size ) && $size > 0 && $size <= 512 ) { try { $row = json_decode( (string) file_get_contents( $file ), true, 3, JSON_THROW_ON_ERROR ); if ( is_array( $row ) && array_keys( $row ) === [ 'phase', 'state', 'errno' ] && in_array( $row['phase'], [ 'connected', 'query_dispatched', 'query_reaped', 'committed' ], true ) && in_array( $row['state'], [ 'running', 'failed', 'committed' ], true ) && in_array( $row['errno'], [ -1, 0, 1205, 1213, 2006, 2013 ], true ) ) { $status = $row; } } catch ( \Throwable ) {} }
		self::fail( 'The independent writer did not wait on the exact native owner/table: store=' . ( $hpos ? 'hpos' : 'cpt' ) . ' kind=' . $kind . ' phase=' . $status['phase'] . ' state=' . $status['state'] . ' errno=' . $status['errno'] . '.' );
	}
}
