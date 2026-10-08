<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionResult;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionTransport;
use PHPUnit\Framework\TestCase;

/** Qualification protocol tests; actual native placement remains a separate required run. */
final class QuotePlacementQualificationTest extends TestCase {
	public static function setUpBeforeClass(): void { require_once dirname( __DIR__, 3 ) . '/scripts/qualification/opening-quote-placement-support.php'; }
	private function factory(): \CetechQuotePlacementFactory { return ( new \ReflectionClass( \CetechQuotePlacementFactory::class ) )->newInstanceWithoutConstructor(); }

	public function test_fault_cannot_be_spent_by_reservation_reads_quote_acceptance_or_another_site_table(): void {
		$factory = $this->factory(); $factory->mask_next_binding_ack = true; $native = new QuotePlacementFixtureTransport(); $transport = new \CetechQuotePlacementTransport( $native, $factory, 'owned_' );
		foreach ( [ 'INSERT INTO `owned_delivery_engine_operation_records` (id) VALUES(1)', 'SELECT * FROM `owned_delivery_engine_delivery_quote_bindings` FOR UPDATE', "UPDATE `owned_delivery_engine_delivery_quotes` SET state='accepted' WHERE id=1", "UPDATE `foreign_delivery_engine_delivery_quote_bindings` SET state='sealed' WHERE id=1" ] as $sql ) {
			$transport->execute( 'START TRANSACTION' ); $transport->execute( $sql ); self::assertTrue( $transport->execute( 'COMMIT' )->acknowledged );
		}
		self::assertTrue( $factory->mask_next_binding_ack ); self::assertSame( 0, $factory->masked_binding_acks ); self::assertSame( 0, $factory->binding_writes ); self::assertSame( 0, $factory->binding_commits ); self::assertSame( [], $factory->timeline );
	}

	public function test_sent_binding_effect_commit_masks_exactly_once_and_empty_next_commit_remains_acknowledged(): void {
		$factory = $this->factory(); $factory->mask_next_binding_ack = true; $native = new QuotePlacementFixtureTransport(); $transport = new \CetechQuotePlacementTransport( $native, $factory, 'owned_' );
		$transport->execute( 'START TRANSACTION' ); $transport->execute( "UPDATE `owned_delivery_engine_delivery_quote_bindings` SET state='sealed',revision=3 WHERE id=1" ); $result = $transport->execute( 'COMMIT' );
		self::assertFalse( $result->acknowledged ); self::assertTrue( $result->sent ); self::assertSame( 2013, $result->errno ); self::assertSame( 1, $factory->binding_writes ); self::assertSame( 1, $factory->binding_commits ); self::assertSame( 1, $factory->masked_binding_acks ); self::assertSame( 321, $factory->fault_connection );
		self::assertSame( [ 'actual_binding_write', 'actual_binding_commit', 'sent_binding_ack_masked' ], $factory->timeline ); self::assertTrue( $transport->execute( 'COMMIT' )->acknowledged ); self::assertSame( 1, $factory->binding_commits );
		$transport->execute( 'START TRANSACTION' ); $transport->execute( 'INSERT INTO owned_delivery_engine_delivery_quote_bindings (id) VALUES(2)' ); self::assertTrue( $transport->execute( 'COMMIT' )->acknowledged ); self::assertSame( 2, $factory->binding_commits ); self::assertSame( 1, $factory->masked_binding_acks );
	}

	public function test_rollback_or_real_refusal_does_not_invent_sent_ack_loss(): void {
		foreach ( [ 'rollback', 'write_refused', 'commit_refused' ] as $mode ) {
			$factory = $this->factory(); $factory->mask_next_binding_ack = true; $native = new QuotePlacementFixtureTransport(); $transport = new \CetechQuotePlacementTransport( $native, $factory, 'owned_' ); $transport->execute( 'START TRANSACTION' );
			$native->refuse_write = 'write_refused' === $mode; $transport->execute( 'UPDATE `owned_delivery_engine_delivery_quote_bindings` SET revision=2 WHERE id=1' );
			$native->refuse_commit = 'commit_refused' === $mode; $result = $transport->execute( 'rollback' === $mode ? 'ROLLBACK' : 'COMMIT' );
			self::assertSame( 0, $factory->binding_commits ); self::assertSame( 0, $factory->masked_binding_acks ); self::assertTrue( $factory->mask_next_binding_ack ); self::assertNull( $factory->fault_connection );
			if ( 'commit_refused' === $mode ) { self::assertFalse( $result->acknowledged ); self::assertFalse( $result->sent ); }
			$native->refuse_commit = false; self::assertTrue( $transport->execute( 'COMMIT' )->acknowledged ); self::assertSame( 0, $factory->masked_binding_acks );
		}
	}

	public function test_private_read_counter_includes_sent_unknown_reads_and_excludes_foreign_and_unsent_queries(): void {
		$factory = $this->factory(); $native = new QuotePlacementFixtureTransport(); $transport = new \CetechQuotePlacementTransport( $native, $factory, 'owned_' );
		foreach ( [ 'SELECT * FROM `owned_delivery_engine_delivery_quotes` WHERE id=1', 'SELECT b.id FROM owned_delivery_engine_delivery_quote_bindings AS b', 'SELECT q.id FROM owned_delivery_engine_operation_records AS r JOIN `owned_delivery_engine_delivery_quotes` AS q ON q.id=r.id' ] as $sql ) { $transport->execute( $sql ); }
		self::assertSame( 3, $factory->private_quote_reads );
		foreach ( [ 'SELECT * FROM foreign_delivery_engine_delivery_quotes', 'SELECT * FROM owned_delivery_engine_delivery_quotes_other', 'SELECT * FROM owned_delivery_engine_operation_records', 'UPDATE owned_delivery_engine_delivery_quotes SET state=1' ] as $sql ) { $transport->execute( $sql ); }
		self::assertSame( 3, $factory->private_quote_reads );
		$native->unknown_read = true; $transport->execute( 'SELECT * FROM owned_delivery_engine_delivery_quote_bindings' ); self::assertSame( 4, $factory->private_quote_reads );
		$native->unknown_read = false; $native->unsent_read = true; $transport->execute( 'SELECT * FROM owned_delivery_engine_delivery_quotes' ); self::assertSame( 4, $factory->private_quote_reads );
	}

	public function test_receipt_protocol_refuses_private_facts_boolean_substitution_and_invalid_counters(): void {
		$valid = [ 'actual_native_woo_order' => true, 'binding_sealed' => false, 'native_line_count' => 2, 'binding_writes' => 0 ]; self::assertTrue( \CetechQuotePlacementObservation::valid( $valid ) );
		foreach ( [ [], [ 'private_order_key' => 'PRIVATE' ], [ 'binding_sealed' => 'false' ], [ 'acknowledged' => 1 ], [ 'native_line_count' => true ], [ 'gateway_calls' => -1 ], [ 'free_completion_calls' => 100001 ], [ 'snapshot_bytes' => [ 'PRIVATE' ] ], [ 'error_class' => 'PRIVATE-CALLBACK' ] ] as $bad ) { self::assertFalse( \CetechQuotePlacementObservation::valid( $bad ) ); }
		self::assertSame( 'Error', \CetechQuotePlacementObservation::error_class( new \TypeError( 'PRIVATE' ) ) ); self::assertSame( 'InvalidArgumentException', \CetechQuotePlacementObservation::error_class( new \InvalidArgumentException( 'PRIVATE' ) ) ); self::assertSame( 'RuntimeException', \CetechQuotePlacementObservation::error_class( new \LogicException( 'PRIVATE' ) ) );
	}
}

final class QuotePlacementFixtureTransport implements OperationConnectionTransport {
	public bool $refuse_write = false;
	public bool $refuse_commit = false;
	public bool $unknown_read = false;
	public bool $unsent_read = false;
	public function execute( string $sql ): OperationConnectionResult {
		if ( str_starts_with( $sql, 'SELECT' ) && ( $this->unknown_read || $this->unsent_read ) ) { return new OperationConnectionResult( false, $this->unknown_read ); }
		if ( ( $this->refuse_write && str_starts_with( $sql, 'UPDATE' ) ) || ( $this->refuse_commit && 'COMMIT' === $sql ) ) { return new OperationConnectionResult( false, false ); }
		return new OperationConnectionResult( true, true );
	}
	public function connection_id(): int { return 321; }
	public function transaction_state(): ?array { return [ 'in_transaction' => 0 ]; }
	public function escape( string $value ): string { return $value; }
	public function close(): bool { return true; }
}
