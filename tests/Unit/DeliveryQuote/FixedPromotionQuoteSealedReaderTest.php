<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryPromotion\{FixedPromotionBudgetReservation, FixedPromotionBudgetState};
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionSealedReadFixture, PromotionSealedReadSession};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionSealedReadFixture.php';

/** Current proof uses real SQLite history/targets and explicitly synthetic adapters, never Woo payment. */
final class FixedPromotionQuoteSealedReaderTest extends TestCase {
	public function test_exact_sealed_linkage_returns_detached_current_observations_without_a_write(): void {
		$f = new PromotionSealedReadFixture(); $before = $f->bytes(); $e = $f->read();
		self::assertNotNull( $e ); self::assertSame( $f->quote()->row(), $e->quote_record()->row() ); self::assertSame( $f->binding()->row(), $e->binding()->row() ); self::assertSame( Q::time()->plus_seconds( 5 )->sql(), $e->sealed_at()->sql() ); self::assertSame( Q::time()->plus_seconds( 6 )->sql(), $e->evaluated_at()->sql() ); self::assertSame( 1, $e->control_revision() );
		self::assertSame( $f->command->context()->to_private_json(), $e->context()->to_private_json() ); self::assertSame( $f->command->terms()->to_private_json(), $e->terms()->to_private_json() ); self::assertTrue( $e->validity()->valid_at( $e->evaluated_at() ) ); self::assertTrue( array_values( $f->budget->accounts )[0]->remaining()->zero() );
		self::assertSame( $before, $f->bytes() ); self::assertSame( 0, $f->budget->writes ); self::assertCount( 1, $f->sessions ); $s = $f->sessions[0]; self::assertSame( 0, $s->writes ); self::assertSame( 3, $s->clock_reads ); self::assertSame( 1, $s->rollback_calls ); self::assertSame( 1, $s->retire_calls ); self::assertTrue( $s->inner->is_retired() ); self::assertFalse( $s->inner->in_transaction() ); self::assertSame( 2, $f->order->calls ); self::assertSame( 2, $f->saved->calls ); self::assertSame( [ $s, $s ], $f->order->owners ); self::assertSame( [ $s, $s ], $f->saved->owners ); foreach ( $f->auth_operations as $operation ) { self::assertSame( 'delivery_quote.read', $operation ); }
	}
	public function test_five_history_locks_precede_current_proofs_and_all_sorted_accounts_precede_claims(): void {
		$f = new PromotionSealedReadFixture( true ); self::assertNotNull( $f->read() ); $events = $f->trace->events;
		foreach ( [ 'receipt', 'control', 'publication', 'source', 'eligibility', 'account', 'claim', 'order', 'saved', 'quote', 'binding' ] as $event ) { self::assertContains( $event, $events ); }
		self::assertLessThan( array_search( 'control', $events, true ), max( array_keys( $events, 'receipt', true ) ) );
		foreach ( [ [ 'control', 'publication' ], [ 'publication', 'source' ], [ 'source', 'eligibility' ], [ 'eligibility', 'account' ], [ 'account', 'claim' ], [ 'claim', 'order' ], [ 'order', 'saved' ], [ 'saved', 'quote' ], [ 'quote', 'binding' ] ] as [ $a, $b ] ) { self::assertLessThan( array_search( $b, $events, true ), array_search( $a, $events, true ) ); }
		$accounts = array_keys( $f->budget->accounts ); sort( $accounts, SORT_STRING ); $claims = array_keys( $f->budget->claims ); sort( $claims, SORT_STRING ); self::assertCount( 2, $accounts ); self::assertSame( $accounts, array_slice( $f->budget->account_reads, 0, 2 ) ); self::assertSame( $claims, array_slice( $f->budget->claim_reads, 0, 2 ) ); self::assertCount( 5, $f->sessions[0]->receipt_sql ); foreach ( $f->sessions[0]->receipt_sql as $sql ) { self::assertStringContainsString( 'FOR UPDATE', $sql ); }
	}
	public function test_current_resumed_revision_is_independent_of_original_seal_revision(): void {
		$f = new PromotionSealedReadFixture(); self::assertSame( 1, $f->command->control_revision() ); $f->current_revision = 3; $before = $f->bytes(); $e = $f->read( 3 ); self::assertNotNull( $e ); self::assertSame( 3, $e->control_revision() ); self::assertSame( 1, $f->command->control_revision() ); self::assertSame( $before, $f->bytes() );
	}
	public static function invalid_holds(): array { return array_map( static fn( string $v ): array => [ $v ], [ 'missing', 'released', 'committed', 'shorter', 'extended', 'other request', 'aggregate shortage', 'funding mismatch' ] ); }
	#[DataProvider( 'invalid_holds' )]
	public function test_exact_original_held_claims_and_current_aggregate_funding_remain_required( string $case ): void {
		$f = new PromotionSealedReadFixture(); $key = array_key_first( $f->budget->claims ); $claim = $f->budget->claims[$key]; $account = $claim->request()->account_key();
		if ( 'missing' === $case ) { unset( $f->budget->claims[$key] ); }
		elseif ( 'released' === $case ) { $f->budget->claims[$key] = $claim->release( Q::time()->plus_seconds( 6 ) ); }
		elseif ( 'committed' === $case ) { $f->budget->claims[$key] = $claim->commit( Q::time()->plus_seconds( 6 ) ); }
		elseif ( in_array( $case, [ 'shorter', 'extended' ], true ) ) { $f->budget->claims[$key] = FixedPromotionBudgetReservation::hold( $claim->request(), Q::time(), Q::time()->plus_seconds( 'shorter' === $case ? 119 : 121 ) ); }
		elseif ( 'other request' === $case ) { $f->budget->claims[$key] = array_values( $f->budget->claims )[1]; }
		else { $data = $f->budget->accounts[$account]->private_facts(); if ( 'aggregate shortage' === $case ) { $data['held']['amount'] = '3.00'; } else { $data['funding_source']['code'] = 'foreign_funding'; } $f->budget->accounts[$account] = FixedPromotionBudgetState::from_array( $data ); }
		$before = $f->bytes(); self::assertNull( $f->read() ); self::assertSame( $before, $f->bytes() ); self::assertSame( 0, $f->budget->writes ); self::assertSame( 0, $f->sessions[0]->writes ); self::assertTrue( $f->sessions[0]->inner->is_retired() );
	}
	public static function invalid_control(): array { return [ [ 0, false, 1 ], [ -1, false, 1 ], [ 1, true, 2 ], [ 1, false, 3 ], [ 3, false, 1 ] ]; }
	#[DataProvider( 'invalid_control' )]
	public function test_expected_current_control_is_positive_enabled_and_exact( int $expected, bool $paused, int $actual ): void {
		$f = new PromotionSealedReadFixture(); $f->paused = $paused; $f->current_revision = $actual; $before = $f->bytes(); self::assertNull( $f->read( $expected ) ); self::assertSame( $before, $f->bytes() ); self::assertNotContains( 'publication', $f->trace->events ); self::assertNotContains( 'source', $f->trace->events ); self::assertSame( 0, $f->order->calls ); self::assertSame( 0, $f->saved->calls ); if ( $expected < 1 ) { self::assertSame( [], $f->sessions ); }
	}
	public function test_missing_current_publication_refuses_before_source_or_budget(): void {
		$f = new PromotionSealedReadFixture(); $f->published = false; $before = $f->bytes(); self::assertNull( $f->read() ); self::assertNotContains( 'source', $f->trace->events ); self::assertNotContains( 'account', $f->trace->events ); self::assertSame( $before, $f->bytes() );
	}
	public function test_unknown_current_eligibility_refuses_before_budget_and_order(): void {
		$f = new PromotionSealedReadFixture(); $f->eligibility->seconds = 0; $before = $f->bytes(); self::assertNull( $f->read() ); self::assertNotContains( 'account', $f->trace->events ); self::assertSame( 0, $f->order->calls ); self::assertSame( $before, $f->bytes() );
	}
	public function test_changed_original_source_refuses_before_budget_or_order(): void {
		$f = new PromotionSealedReadFixture(); $f->on_hook = static function( PromotionSealedReadSession $s, string $phase ): void { if ( 'begin' === $phase ) { $s->inner->inner->inner->source->rows['wp_posts'][0]['post_modified_gmt'] = '2026-10-07 05:00:01'; } }; $before = $f->bytes(); self::assertNull( $f->read() ); self::assertNotContains( 'account', $f->trace->events ); self::assertSame( 0, $f->order->calls ); self::assertSame( $before, $f->bytes() );
	}
	public function test_late_claim_extension_is_refused_even_while_inside_original_deadlines(): void {
		$f = new PromotionSealedReadFixture(); $f->saved->on_proof = static function( $s, int $calls ) use ( $f ): void { if ( 1 !== $calls ) { return; } $key = array_key_first( $f->budget->claims ); $claim = $f->budget->claims[$key]; $f->budget->claims[$key] = FixedPromotionBudgetReservation::hold( $claim->request(), Q::time(), Q::time()->plus_seconds( 121 ) ); };
		$before_quote = $f->quote()->row(); $before_binding = $f->binding()->row(); self::assertNull( $f->read() ); self::assertSame( $before_quote, $f->quote()->row() ); self::assertSame( $before_binding, $f->binding()->row() ); self::assertSame( 1, $f->order->calls ); self::assertSame( 1, $f->saved->calls ); self::assertSame( 0, $f->sessions[0]->writes );
	}
}
