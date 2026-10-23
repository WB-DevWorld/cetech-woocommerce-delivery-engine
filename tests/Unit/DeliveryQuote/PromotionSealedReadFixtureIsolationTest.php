<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionSealedReadFixture, PromotionSealedReadSession};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionSealedReadFixture.php';

/** Reusing immutable genuine history never reuses a mutable database, current port or failure callback. */
final class PromotionSealedReadFixtureIsolationTest extends TestCase {
	public function test_sql_corruption_cannot_escape_one_fixture_or_poison_the_next_seed_copy(): void {
		$first = new PromotionSealedReadFixture(); $baseline = $first->bytes(); $second = new PromotionSealedReadFixture();
		self::assertSame( $baseline, $second->bytes() ); self::assertNotSame( $first->db, $second->db ); self::assertNotSame( $first->db->pdo, $second->db->pdo );
		$first->db->pdo->exec( "UPDATE durable_delivery_engine_operation_records SET intent_hash='" . Q::digest( 'one fixture history corruption' ) . "' WHERE id=5" );
		self::assertNull( $first->read() ); self::assertNotNull( $second->read() ); self::assertSame( $baseline, $second->bytes() );
		$third = new PromotionSealedReadFixture(); self::assertSame( $baseline, $third->bytes() ); self::assertNotNull( $third->read() ); self::assertCount( 5, $baseline[2] ); self::assertCount( 5, $baseline[3] ); self::assertSame( $second->bytes(), $third->bytes() );
	}
	public function test_budget_order_saved_and_current_callbacks_are_fresh_for_every_case(): void {
		$first = new PromotionSealedReadFixture(); $second = new PromotionSealedReadFixture(); $baseline = $second->bytes(); $proof = false; $policy = false; $source = false;
		foreach ( [ 'trace', 'budget', 'eligibility', 'order', 'saved', 'inputs' ] as $field ) { self::assertNotSame( $first->$field, $second->$field, $field ); }
		self::assertNotSame( $first->inputs->current()->sources()->original(), $second->inputs->current()->sources()->original() ); self::assertNotSame( $first->inputs->current()->sources()->fresh_native(), $second->inputs->current()->sources()->fresh_native() ); self::assertNotSame( $first->inputs->current()->sources()->fresh_native()->native_receipt(), $second->inputs->current()->sources()->fresh_native()->native_receipt() );
		$first->eligibility->on_proof = static function() use ( &$policy ): void { $policy = true; };
		$first->order->on_proof = static function() use ( $first, &$proof ): void { $proof = true; $key = array_key_first( $first->budget->claims ); $first->budget->claims[$key] = $first->budget->claims[$key]->release( Q::time()->plus_seconds( 6 ) ); $first->order->digest = Q::digest( 'one fixture captured order mutation' ); $first->saved->packet['snapshot_digest'] = Q::digest( 'one fixture saved mutation' ); };
		$first->saved->on_proof = static function(): never { throw new \RuntimeException( 'One fixture only.' ); };
		self::assertNull( $first->read() ); self::assertTrue( $proof ); self::assertTrue( $policy );
		$first->order->digest = $first->command->verification()->binding_command()->order_evidence_digest(); $first->on_hook = static function( PromotionSealedReadSession $session, string $phase ) use ( &$source ): void { if ( 'begin' === $phase ) { $source = true; $session->inner->inner->inner->source->rows['wp_posts'][0]['post_modified_gmt'] = '2026-10-07 05:00:01'; } };
		self::assertNull( $first->read() ); self::assertTrue( $source ); self::assertNotNull( $second->read() ); self::assertSame( $baseline, $second->bytes() ); self::assertNull( $second->eligibility->on_proof ); self::assertNull( $second->order->on_proof ); self::assertNull( $second->saved->on_proof );
		$third = new PromotionSealedReadFixture(); self::assertSame( $baseline, $third->bytes() ); self::assertSame( 0, $third->order->calls ); self::assertSame( 0, $third->saved->calls ); self::assertSame( [], $third->sessions ); self::assertNotNull( $third->read() ); self::assertNotSame( $second->sessions[0], $third->sessions[0] ); self::assertNotSame( $second->sessions[0]->inner->inner->inner->source, $third->sessions[0]->inner->inner->inner->source );
	}
	public function test_shared_and_distinct_account_seed_modes_keep_independent_exact_history(): void {
		$shared = new PromotionSealedReadFixture(); $shared_baseline = $shared->bytes(); $distinct = new PromotionSealedReadFixture( true ); $distinct_baseline = $distinct->bytes();
		self::assertCount( 1, $shared->budget->accounts ); self::assertCount( 2, $distinct->budget->accounts ); self::assertCount( 2, $shared->budget->claims ); self::assertCount( 2, $distinct->budget->claims ); self::assertCount( 5, $distinct_baseline[2] ); self::assertNotSame( $shared_baseline[2], $distinct_baseline[2] ); self::assertNotSame( $shared->db->pdo, $distinct->db->pdo );
		$distinct->db->pdo->exec( 'DELETE FROM durable_delivery_engine_operation_changes WHERE operation_id=4' ); self::assertNull( $distinct->read() ); self::assertNotNull( $shared->read() ); self::assertSame( $shared_baseline, $shared->bytes() );
		$fresh_distinct = new PromotionSealedReadFixture( true ); self::assertSame( $distinct_baseline, $fresh_distinct->bytes() ); self::assertNotNull( $fresh_distinct->read() ); self::assertCount( 2, $fresh_distinct->budget->accounts );
	}
}
