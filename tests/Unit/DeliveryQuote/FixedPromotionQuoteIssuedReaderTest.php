<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteIssuedEvidence, QuoteCurrentEvidenceValidity};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryPromotion\{FixedPromotionBudgetReservation, FixedPromotionBudgetState};
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixedPromotionQuotePlacementReaderTest.php';

/** Issued1 transport observations over SQLite receipts; sources and native money remain synthetic. */
final class FixedPromotionQuoteIssuedReaderTest extends TestCase {
	private function fixture(): PromotionPlacementReaderFixture {
		$f = new PromotionPlacementReaderFixture();
		$f->db->pdo->exec( "UPDATE durable_delivery_engine_delivery_quotes SET state='issued',revision=1,accepted_at=NULL,transition_at=NULL" );
		$f->db->pdo->exec( 'DELETE FROM durable_delivery_engine_operation_changes WHERE id=2' );
		$f->db->pdo->exec( 'DELETE FROM durable_delivery_engine_operation_records WHERE id=2' );
		return $f;
	}
	private function read( PromotionPlacementReaderFixture $f ): ?FixedPromotionQuoteIssuedEvidence { return $f->reader()->current_issued( $f->command->issue(), $f->inputs, RequestContext::create() ); }
	public function test_issued_review_authenticates_original_receipt_and_returns_only_after_acknowledged_cleanup(): void {
		$f = $this->fixture(); $before = $f->bytes(); $f->on_authorize = static function( $owner, string $operation, PromotionPlacementReaderFixture $fixture ): void { self::assertFalse( $fixture->db->pdo->inTransaction() ); self::assertSame( 'delivery_quote.read', $operation ); };
		$found = $this->read( $f ); self::assertNotNull( $found ); self::assertSame( $f->quote()->row(), $found->quote_record()->row() );
		self::assertSame( $f->command->issue()->to_recovery_array(), $found->issue()->to_recovery_array() ); self::assertNull( $found->issue()->lease() ); self::assertTrue( $found->owner()->equals( $f->command->owner() ) );
		self::assertSame( $f->command->header()->to_private_json(), $found->header()->to_private_json() ); self::assertSame( $f->command->reference()->public_fields(), $found->reference()->public_fields() );
		self::assertSame( Q::time()->plus_seconds( 3 )->sql(), $found->evaluated_at()->sql() ); self::assertTrue( $found->validity()->valid_at( $found->evaluated_at() ) ); self::assertFalse( $found->validity()->valid_at( $f->command->hold_until() ) );
		self::assertSame( $before, $f->bytes() ); self::assertSame( 0, $f->budget->writes ); self::assertSame( 0, $f->sessions[0]->writes ); self::assertSame( 3, $f->sessions[0]->clock_reads ); self::assertTrue( $f->sessions[0]->is_retired() ); self::assertFalse( $f->sessions[0]->in_transaction() );
		self::assertCount( 1, $f->sessions[0]->receipt_sql ); self::assertStringContainsString( 'FOR UPDATE', $f->sessions[0]->receipt_sql[0] ); self::assertCount( 2, $f->auth_operations );
	}
	public function test_missing_original_issue_refuses_before_current_dependencies(): void {
		$f = $this->fixture(); $f->db->pdo->exec( 'DELETE FROM durable_delivery_engine_operation_changes' ); $f->db->pdo->exec( 'DELETE FROM durable_delivery_engine_operation_records' );
		self::assertNull( $this->read( $f ) ); foreach ( [ 'control', 'publication', 'source', 'eligibility', 'account', 'claim' ] as $event ) { self::assertNotContains( $event, $f->trace->events ); }
		self::assertTrue( $f->sessions[0]->is_retired() );
	}
	public function test_initial_quote_provenance_is_nonlocking_and_current_dependency_locks_precede_final_quote_lock(): void {
		$f = $this->fixture(); $trace = [];
		$f->on_hook = static function( PromotionPlacementReaderSession $session, string $phase ) use ( $f, &$trace ): void {
			if ( in_array( $phase, [ 'receipt', 'control', 'publication', 'quote' ], true ) ) { $trace[] = $phase; }
		};
		self::assertNotNull( $this->read( $f ) );
		$quotes = array_values( array_filter( $f->db->statements, static fn( string $sql ): bool => str_starts_with( $sql, 'SELECT' ) && str_contains( $sql, 'FROM `durable_delivery_engine_delivery_quotes`' ) ) );
		self::assertCount( 2, $quotes ); self::assertStringNotContainsString( 'FOR UPDATE', $quotes[0] ); self::assertStringContainsString( 'FOR UPDATE', $quotes[1] );
		self::assertSame( [ 'receipt', 'quote', 'control', 'publication', 'quote' ], $trace );
		$events = $f->trace->events; self::assertLessThan( max( array_keys( $events, 'quote', true ) ), max( array_keys( $events, 'claim', true ) ) );
	}
	public function test_accepted_history_cannot_be_represented_as_current_issued_review(): void {
		$f = new PromotionPlacementReaderFixture(); self::assertNull( $this->read( $f ) ); self::assertNotContains( 'control', $f->trace->events ); self::assertNotContains( 'account', $f->trace->events );
	}
	public function test_tampered_allocation_receipt_does_not_authenticate_current_issued_observations(): void {
		$f = $this->fixture(); $json = $f->db->pdo->query( 'SELECT completion_json FROM durable_delivery_engine_operation_records WHERE id=1' )->fetchColumn(); $changed = str_replace( $f->command->issue()->allocation_digest(), Q::digest( 'forged allocation' ), $json ); self::assertNotSame( $json, $changed );
		$f->db->pdo->prepare( 'UPDATE durable_delivery_engine_operation_records SET completion_json=? WHERE id=1' )->execute( [ $changed ] );
		self::assertNull( $this->read( $f ) ); self::assertNotContains( 'control', $f->trace->events ); self::assertNotContains( 'source', $f->trace->events );
	}
	#[DataProvider('dependency_refusals')]
	public function test_current_dependency_refusal_does_not_return_review_or_change_money( string $case ): void {
		$f = $this->fixture();
		if ( 'pause' === $case ) { $f->paused = true; }
		elseif ( 'publication' === $case ) { $f->published = false; }
		elseif ( 'eligibility' === $case ) { $f->eligibility->seconds = 0; }
		else { $f->on_hook = static function( PromotionPlacementReaderSession $s, string $phase ): void { if ( 'begin' === $phase ) { $s->inner->inner->source->rows['wp_posts'][0]['post_modified_gmt'] = '2026-10-07 05:00:01'; } }; }
		$before = $f->bytes(); self::assertNull( $this->read( $f ) ); self::assertSame( $before, $f->bytes() ); self::assertNotContains( 'account', $f->trace->events ); self::assertSame( 0, $f->budget->writes );
	}
	public static function dependency_refusals(): array { return [ [ 'pause' ], [ 'publication' ], [ 'eligibility' ], [ 'source' ] ]; }
	#[DataProvider('invalid_holds')]
	public function test_complete_original_deadlines_and_aggregate_held_pool_are_required( string $case ): void {
		$f = $this->fixture(); $key = array_key_first( $f->budget->claims ); $claim = $f->budget->claims[$key]; $account = $claim->request()->account_key();
		if ( 'missing' === $case ) { unset( $f->budget->claims[$key] ); }
		elseif ( 'released' === $case ) { $f->budget->claims[$key] = $claim->release( Q::time()->plus_seconds( 3 ) ); }
		elseif ( 'committed' === $case ) { $f->budget->claims[$key] = $claim->commit( Q::time()->plus_seconds( 3 ) ); }
		elseif ( 'extended' === $case ) { $f->budget->claims[$key] = FixedPromotionBudgetReservation::hold( $claim->request(), Q::time(), Q::time()->plus_seconds( 121 ) ); }
		else { $data = $f->budget->accounts[$account]->private_facts(); if ( 'aggregate' === $case ) { $data['held']['amount'] = '3.00'; } else { $data['funding_source']['code'] = 'foreign_funder'; } $f->budget->accounts[$account] = FixedPromotionBudgetState::from_array( $data ); }
		$before = $f->bytes(); self::assertNull( $this->read( $f ) ); self::assertSame( $before, $f->bytes() ); self::assertSame( 0, $f->budget->writes ); self::assertTrue( $f->sessions[0]->is_retired() );
	}
	public static function invalid_holds(): array { return [ [ 'missing' ], [ 'released' ], [ 'committed' ], [ 'extended' ], [ 'aggregate' ], [ 'funder' ] ]; }
	#[DataProvider('late_clock_refusals')]
	public function test_initial_and_repeated_proofs_cannot_cross_a_deadline_or_move_time_backwards( array $offsets ): void {
		$f = $this->fixture(); $f->on_open = static function( PromotionPlacementReaderSession $s ) use ( $offsets ): void { $s->clock_times = array_map( static fn( int $offset ): QuoteTime => Q::time()->plus_seconds( $offset ), $offsets ); };
		self::assertNull( $this->read( $f ) ); self::assertTrue( $f->sessions[0]->is_retired() ); self::assertSame( 0, $f->sessions[0]->writes );
	}
	public static function late_clock_refusals(): array { return [ [ [ 0 ] ], [ [ 120 ] ], [ [ 3, 120 ] ], [ [ 3, 3, 120 ] ], [ [ 3, 2 ] ], [ [ 3, 4, 3 ] ] ]; }
	public function test_eligibility_and_claims_are_repeated_after_the_initial_proof(): void {
		$f = $this->fixture(); $f->eligibility->on_proof = static function() use ( $f ): void { if ( 2 === $f->eligibility->calls ) { $key = array_key_first( $f->budget->claims ); $f->budget->claims[$key] = $f->budget->claims[$key]->release( Q::time()->plus_seconds( 3 ) ); } };
		self::assertNull( $this->read( $f ) ); self::assertSame( 2, $f->eligibility->calls ); self::assertTrue( $f->sessions[0]->is_retired() );
	}
	#[DataProvider('cleanup_faults')]
	public function test_cleanup_acknowledgements_and_late_owner_fences_are_required( string $fault ): void {
		$f = $this->fixture();
		if ( in_array( $fault, [ 'rollback', 'retire', 'begin' ], true ) ) { $f->on_open = static function( PromotionPlacementReaderSession $s ) use ( $fault ): void { $s->fail = $fault; }; }
		elseif ( 'prefix' === $fault ) { $f->on_hook = static function( PromotionPlacementReaderSession $s, string $phase ): void { if ( 'clock' === $phase && 2 === $s->clock_reads ) { $s->prefix = 'other_'; } }; }
		else { $f->on_authorize = static function( $owner, string $operation, PromotionPlacementReaderFixture $fixture ): void { self::assertFalse( $fixture->db->pdo->inTransaction() ); if ( count( $fixture->auth_operations ) > 1 ) { $fixture->authorized = false; } }; }
		self::assertNull( $this->read( $f ) ); self::assertFalse( $f->db->pdo->inTransaction() ); self::assertSame( 0, $f->sessions[0]->writes );
	}
	public static function cleanup_faults(): array { return [ [ 'rollback' ], [ 'retire' ], [ 'begin' ], [ 'prefix' ], [ 'authorization' ] ]; }
	public function test_initial_authorization_refusal_does_not_open_an_owner(): void { $f = $this->fixture(); $f->authorized = false; self::assertNull( $this->read( $f ) ); self::assertSame( [], $f->sessions ); }
	public function test_carrier_rejects_accepted_rows_and_implicit_disclosure(): void {
		$f = $this->fixture(); $found = $this->read( $f ); self::assertNotNull( $found );
		foreach ( [ static fn() => json_encode( $found, JSON_THROW_ON_ERROR ), static fn() => serialize( $found ) ] as $disclose ) { try { $disclose(); self::fail( 'Implicit disclosure was accepted.' ); } catch ( \LogicException ) { self::assertTrue( true ); } }
		self::assertSame( [ 'private_promotion_issued_evidence' => 'redacted' ], $found->__debugInfo() );
		$accepted = new PromotionPlacementReaderFixture(); $this->expectException( \InvalidArgumentException::class ); new FixedPromotionQuoteIssuedEvidence( $accepted->command->issue(), $accepted->quote(), Q::time()->plus_seconds( 3 ), QuoteCurrentEvidenceValidity::capture( Q::time()->plus_seconds( 3 ), $accepted->command->hold_until() ) );
	}
}
