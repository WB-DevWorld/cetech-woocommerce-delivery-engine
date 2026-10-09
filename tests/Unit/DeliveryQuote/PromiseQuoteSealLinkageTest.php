<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\PromiseQuotePaymentBoundary;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseResult;
use CetechDeliveryEngine\Domain\ServicePromise\Handoff\PromiseHistoricalPacket;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{PromiseQuotePlacementFixture,PromiseQuoteFixtureCurrentEvidence as CurrentFence};
use CetechDeliveryEngine\Tests\Support\ServicePromise\Handoff\PromiseHandoffFixture as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/PromiseQuotePlacementFixtures.php';

/** Original owned lifecycle records, ACK reads and frozen history; native publication is separately qualified. */
final class PromiseQuoteSealLinkageTest extends TestCase {
	protected function setUp(): void { $GLOBALS['blog_id'] = 1; }
	protected function tearDown(): void { unset( $GLOBALS['blog_id'] ); }
	private function fixture(): array { $order = new \WC_Order( [ 'id' => 100 ] ); $f = new PromiseQuotePlacementFixture( $order ); return [ $f, $order ]; }
	public function test_confirm_and_verified_snapshot_are_not_final_acceptance_but_original_ack_seal_is(): void {
		[ $f, $order ] = $this->fixture(); self::assertNull( $f->linkage() ); self::assertSame( 4, $f->factory->count( 'operation_changes' ) );
		$sealed = $f->seal( $order ); self::assertSame( 'accepted', $sealed->attempt->outcome->state ); $link = $f->linkage(); self::assertNotNull( $link );
		$before = $f->factory->pdo->query( 'SELECT * FROM durable_delivery_engine_delivery_quote_bindings' )->fetchAll( \PDO::FETCH_ASSOC );
		$facts = $link->private_facts(); self::assertSame( 'q06_placement_sealed', $facts['final_event']['kind'] ); self::assertSame( F::time()->plus_seconds( 4 )->sql(), $facts['final_event']['occurred_at'] ); self::assertNotSame( $sealed->quote->accepted_at()->sql(), $facts['final_event']['occurred_at'] );
		self::assertSame( $sealed->quote->terms()->promise_packet()->digest(), $facts['promise_packet_digest'] ); self::assertCount( 1, $link->accepted_groups() ); self::assertSame( 'accepted', $link->accepted_groups()[0]['commitment_state'] ); self::assertSame( F::packet()->public_facts(), $link->accepted_groups()[0]['original'] );
		self::assertSame( $link->to_private_json(), $f->linkage()->to_private_json() ); self::assertSame( 5, $f->factory->count( 'operation_records' ) ); self::assertSame( 5, $f->factory->count( 'operation_changes' ) ); self::assertSame( $before, $f->factory->pdo->query( 'SELECT * FROM durable_delivery_engine_delivery_quote_bindings' )->fetchAll( \PDO::FETCH_ASSOC ) );
		foreach ( $f->factory->sessions as $s ) { self::assertTrue( $s->is_retired() ); }
	}
	public function test_publication_delivery_and_later_clock_cannot_rewrite_original_final_event_or_frozen_projection(): void {
		[ $f, $order ] = $this->fixture(); self::assertSame( 'accepted', $f->seal( $order )->attempt->outcome->state ); $link = $f->linkage();
		$f->factory->pdo->exec( "UPDATE durable_delivery_engine_operation_records SET publication_state='pending',row_version=row_version+1,updated_at='2035-01-01 00:00:00.000000' WHERE operation='delivery_quote.seal'" ); $f->factory->utc = F::time()->plus_seconds( 4000 )->sql();
		self::assertSame( $link->to_private_json(), $f->linkage()->to_private_json() ); self::assertSame( $link->accepted_groups(), $f->linkage()->accepted_groups() );
		$boundary = new PromiseQuotePaymentBoundary( $f->sealed->quote, $f->sealed->binding, $link, new CurrentFence( $f->factory ) ); self::assertFalse( $boundary->eligible_at( QuoteTime::parse( $f->factory->utc ) ) );
	}
	public function test_uncertain_seal_never_supplies_payment_linkage_and_exact_original_recovery_writes_no_successor(): void {
		[ $f, $order ] = $this->fixture(); $f->factory->effect_fault = 'lost_ack'; $unknown = $f->seal( $order ); self::assertSame( 'unconfirmed', $unknown->attempt->outcome->state ); self::assertNull( $f->linkage() ); self::assertSame( 3, (int) $f->factory->pdo->query( 'SELECT revision FROM durable_delivery_engine_delivery_quote_bindings' )->fetchColumn() );
		$rows = $f->factory->pdo->query( 'SELECT * FROM durable_delivery_engine_delivery_quote_bindings' )->fetchAll( \PDO::FETCH_ASSOC ); $f->sealed = $recovered = $f->service->reconcile( $unknown->command, \CetechDeliveryEngine\Domain\Contracts\RequestContext::create() ); self::assertSame( 'accepted', $recovered->attempt->outcome->state ); self::assertTrue( $recovered->attempt->replayed ); $link = $f->linkage(); self::assertNotNull( $link ); self::assertSame( F::time()->plus_seconds( 4 )->sql(), $link->private_facts()['final_event']['occurred_at'] );
		$f->sealed = $again = $f->service->reconcile( $unknown->command, \CetechDeliveryEngine\Domain\Contracts\RequestContext::create() ); self::assertSame( $recovered->attempt->completion->to_json(), $again->attempt->completion->to_json() ); self::assertSame( $link->to_private_json(), $f->linkage()->to_private_json() ); self::assertSame( $rows, $f->factory->pdo->query( 'SELECT * FROM durable_delivery_engine_delivery_quote_bindings' )->fetchAll( \PDO::FETCH_ASSOC ) ); self::assertSame( 1, $f->factory->count( 'delivery_quotes' ) ); self::assertSame( 5, $f->factory->count( 'operation_records' ) ); self::assertSame( 5, $f->factory->count( 'operation_changes' ) );
	}
	#[DataProvider( 'original_corruptions' )]
	public function test_missing_changed_or_unknown_original_receipt_never_supplies_a_final_linkage( string $sql ): void {
		[ $f, $order ] = $this->fixture(); self::assertSame( 'accepted', $f->seal( $order )->attempt->outcome->state ); self::assertNotNull( $f->linkage() ); $f->factory->pdo->exec( $sql ); self::assertNull( $f->linkage() ); self::assertSame( 3, (int) $f->factory->pdo->query( 'SELECT revision FROM durable_delivery_engine_delivery_quote_bindings' )->fetchColumn() );
	}
	public static function original_corruptions(): array { return [
		[ "DELETE FROM durable_delivery_engine_operation_changes WHERE operation_id=(SELECT id FROM durable_delivery_engine_operation_records WHERE operation='delivery_quote.seal')" ],
		[ "DELETE FROM durable_delivery_engine_operation_changes WHERE operation_id=(SELECT id FROM durable_delivery_engine_operation_records WHERE operation='delivery_quote.verify_binding')" ],
		[ "UPDATE durable_delivery_engine_operation_records SET target_hash='" . str_repeat( 'a', 64 ) . "' WHERE operation='delivery_quote.seal'" ],
		[ "UPDATE durable_delivery_engine_operation_records SET state='pending',publication_state='none',completion_json=NULL,audit_id=NULL,completed_at=NULL WHERE operation='delivery_quote.seal'" ],
		[ "UPDATE durable_delivery_engine_operation_records SET completion_json='{}' WHERE operation='delivery_quote.seal'" ],
		[ "UPDATE durable_order_facts SET snapshot_digest='" . str_repeat( 'b', 64 ) . "'" ],
	]; }
	public function test_unknown_read_rollback_ack_cannot_release_the_derived_receipt_or_replace_sql_owner(): void {
		[ $f, $order ] = $this->fixture(); self::assertSame( 'accepted', $f->seal( $order )->attempt->outcome->state ); $opens = $f->factory->opens; $f->factory->lose_read_rollback_ack = true; self::assertNull( $f->linkage() ); self::assertSame( $opens + 1, $f->factory->opens ); self::assertTrue( $f->factory->sessions[array_key_last( $f->factory->sessions )]->is_retired() ); self::assertNotNull( $f->linkage() );
	}
	public function test_final_and_payment_carriers_cannot_be_exported_or_hydrated_through_generic_php_serialization(): void {
		[ $f, $order ] = $this->fixture(); self::assertSame( 'accepted', $f->seal( $order )->attempt->outcome->state ); $link = $f->linkage(); $boundary = new PromiseQuotePaymentBoundary( $f->sealed->quote, $f->sealed->binding, $link, new CurrentFence( $f->factory ) );
		foreach ( [ $link, $boundary ] as $carrier ) { foreach ( [ static fn() => serialize( $carrier ), static fn() => json_encode( $carrier, JSON_THROW_ON_ERROR ), static fn() => $carrier->__unserialize( [] ) ] as $export ) { try { $export(); self::fail( 'Private promise carrier was exported or generically hydrated.' ); } catch ( \LogicException ) { self::assertTrue( true ); } } }
	}
	public function test_original_exclusive_acceptance_deadline_is_checked_at_real_seal_clock(): void {
		$order = new \WC_Order( [ 'id' => 100 ] ); $input = F::input( [ 'anchor' => 'order_accepted' ], F::time()->plus_seconds( 5 )->sql() ); $f = new PromiseQuotePlacementFixture( $order, $input );
		$r = $f->seal( $order, F::time()->plus_seconds( 5 ) ); self::assertSame( 'rejected', $r->attempt->outcome->state ); self::assertSame( 'stale_revision', $r->attempt->outcome->error->code ); self::assertSame( 2, (int) $f->factory->pdo->query( 'SELECT revision FROM durable_delivery_engine_delivery_quote_bindings' )->fetchColumn() ); self::assertNull( $f->linkage() );
	}
	public function test_optional_refusal_is_recorded_history_without_an_accepted_promise_receipt(): void {
		$order = new \WC_Order( [ 'id' => 100 ] ); $input = F::input( [ 'promise_required' => false ] ); $result = PromiseResult::from_array( [ 'format_version' => 1, 'state' => 'unavailable', 'input' => $input->private_facts(), 'input_digest' => $input->digest(), 'graph_digest' => $input->policy()->graph()->digest(), 'body' => null, 'reason_codes' => [ 'estimate_unavailable' ] ] ); $packet = PromiseHistoricalPacket::capture( $result, 'Delivery estimate unavailable' );
		$f = new PromiseQuotePlacementFixture( $order, $input, $packet ); self::assertSame( 'accepted', $f->seal( $order )->attempt->outcome->state ); $link = $f->linkage(); self::assertNotNull( $link ); self::assertSame( [], $link->accepted_groups() ); self::assertCount( 1, $link->recorded_groups() ); self::assertSame( 'recorded_refusal', $link->recorded_groups()[0]['commitment_state'] ); self::assertSame( $packet->public_facts(), $link->recorded_groups()[0]['original'] );
		$this->expectException( \InvalidArgumentException::class ); $link->original_reference( $input->private_facts()['material']['group_id'] );
	}
}
