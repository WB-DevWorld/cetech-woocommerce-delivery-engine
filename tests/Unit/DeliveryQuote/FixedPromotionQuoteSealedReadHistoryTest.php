<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteBindInputs, FixedPromotionQuoteOrderEvidenceGuard, FixedPromotionQuoteSealCommand, FixedPromotionQuoteVerifyInputs, QuotePlacementSavedEvidenceGuard};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteStoredRow};
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\PromotionSealedReadFixture;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionSealedReadFixture.php';

/** Owned SQLite receipts and explicit synthetic ports; no native or payment acceptance. */
final class FixedPromotionQuoteSealedReadHistoryTest extends TestCase {

	private PromotionSealedReadFixture $f;
	protected function setUp(): void { $this->f = new PromotionSealedReadFixture(); }

	public static function broken_originals(): array {
		$out = [];
		foreach ( [ 'issue' => 1, 'accept' => 2, 'bind' => 3, 'verify_binding' => 4, 'seal' => 5 ] as $purpose => $id ) {
			foreach ( [ 'missing', 'intent', 'event time', 'completed time', 'updated time', 'publication' ] as $fault ) { $out[$purpose . ' ' . $fault] = [ $id, $fault ]; }
		}
		return $out;
	}

	#[DataProvider( 'broken_originals' )]
	public function test_every_original_producer_is_authenticated_before_current_dependency_proofs( int $id, string $fault ): void {
		if ( 'missing' === $fault ) { $this->f->db->pdo->exec( 'DELETE FROM durable_delivery_engine_operation_records WHERE id=' . $id ); }
		elseif ( 'intent' === $fault ) { $this->update( 'operation_records', [ 'intent_hash' => Q::digest( 'different original intent' ) ], $id ); }
		elseif ( 'event time' === $fault ) { $this->update( 'operation_changes', [ 'created_at' => Q::time()->plus_seconds( $id - 1 )->sql() ], $id ); }
		elseif ( 'completed time' === $fault || 'updated time' === $fault ) { $this->update( 'operation_records', [ 'completed time' === $fault ? 'completed_at' : 'updated_at' => Q::time()->plus_seconds( $id - 1 )->sql() ], $id ); }
		else { $this->change_completion( $id, static function( \stdClass $p ): void { $p->publication->site_id = 2; } ); }
		$before = $this->bytes(); self::assertNull( $this->f->read() );
		foreach ( [ 'control', 'publication', 'source', 'eligibility', 'account', 'claim', 'order', 'saved', 'quote', 'binding' ] as $phase ) { self::assertNotContains( $phase, $this->f->trace->events, $phase ); }
		self::assertSame( 0, $this->f->order->calls ); self::assertSame( 0, $this->f->saved->calls ); $this->assert_unchanged( $before );
	}

	public function test_pending_advisory_publications_are_original_history_without_any_recovery_write(): void {
		$this->f->db->pdo->exec( "UPDATE durable_delivery_engine_operation_records SET publication_state='pending'" );
		$before = $this->bytes(); $evidence = $this->f->read(); self::assertNotNull( $evidence );
		self::assertSame( $this->f->binding()->row(), $evidence->binding()->row() ); $this->assert_unchanged( $before );
		self::assertSame( 5, (int) $this->f->db->pdo->query( "SELECT COUNT(*) FROM durable_delivery_engine_operation_records WHERE publication_state='pending'" )->fetchColumn() );
	}

	public static function broken_seal_identity(): array { return [ [ 'target_hash' ], [ 'namespace_hash' ], [ 'site_id' ], [ 'audit_id' ] ]; }
	#[DataProvider( 'broken_seal_identity' )]
	public function test_the_final_seal_is_not_inferred_from_an_unlinked_accepted_row( string $field ): void {
		$this->update( 'operation_records', [ $field => in_array( $field, [ 'site_id', 'audit_id' ], true ) ? 99 : Q::digest( 'unlinked final seal' ) ], 5 );
		$before = $this->bytes(); self::assertNull( $this->f->read() ); self::assertNotContains( 'control', $this->f->trace->events ); $this->assert_unchanged( $before );
	}

	public function test_historical_control_revision_and_expected_current_revision_are_independent(): void {
		self::assertSame( 1, $this->f->command->control_revision() ); $this->f->current_revision = 3; $before = $this->bytes();
		$evidence = $this->f->read( 3 ); self::assertNotNull( $evidence ); self::assertSame( 3, $evidence->control_revision() ); self::assertSame( 1, $this->f->command->control_revision() );
		$this->assert_unchanged( $before );
	}

	public function test_an_original_revision_cannot_substitute_for_the_explicit_current_revision(): void {
		$this->f->current_revision = 3; $before = $this->bytes(); self::assertNull( $this->f->read( 1 ) );
		self::assertContains( 'control', $this->f->trace->events ); self::assertNotContains( 'source', $this->f->trace->events ); self::assertSame( 0, $this->f->order->calls ); $this->assert_unchanged( $before );
	}

	public function test_current_revision_cannot_rewrite_the_original_seal_intent(): void {
		$c = $this->f->command; $this->f->command = FixedPromotionQuoteSealCommand::create( $c->verification(), $c->verified_binding(), 3 ); $this->f->current_revision = 3;
		$before = $this->bytes(); self::assertNull( $this->f->read( 3 ) ); self::assertNotContains( 'control', $this->f->trace->events ); $this->assert_unchanged( $before );
	}

	public function test_actual_seal_effect_and_original_byte_proofs_are_used_instead_of_the_placeholder(): void {
		[ $order, $saved ] = $this->capture_exact_ports(); $before = $this->bytes(); $evidence = $this->f->read(); self::assertNotNull( $evidence );
		self::assertSame( Q::time()->plus_seconds( 5 )->sql(), $evidence->sealed_at()->sql() ); self::assertNotSame( $this->f->command->binding()->row()['sealed_at'], $evidence->binding()->row()['sealed_at'] );
		self::assertSame( [ $this->f->command->verification()->prepared_binding()->row(), $this->f->command->verification()->prepared_binding()->row() ], $order->candidates );
		self::assertSame( [ $this->f->command->verified_binding()->row(), $this->f->command->verified_binding()->row() ], $saved->candidates );
		self::assertSame( [ $this->f->sessions[0], $this->f->sessions[0] ], $order->owners ); self::assertSame( $order->owners, $saved->owners ); $this->assert_unchanged( $before );
	}

	public static function changed_targets(): array {
		return array_map( static fn( string $v ): array => [ $v ], [ 'quote id', 'quote accepted time', 'binding id', 'order id', 'snapshot digest', 'context digest', 'native money digest', 'created time', 'verified time', 'sealed time', 'prepared state' ] );
	}
	#[DataProvider( 'changed_targets' )]
	public function test_actual_physical_targets_must_equal_the_authenticated_reconstructed_sealed_three( string $case ): void {
		if ( 'quote id' === $case ) { $this->update( 'delivery_quotes', [ 'id' => 7 ], 1 ); }
		elseif ( 'quote accepted time' === $case ) { $this->update( 'delivery_quotes', [ 'accepted_at' => Q::time()->plus_seconds( 3 )->sql(), 'transition_at' => Q::time()->plus_seconds( 3 )->sql() ], 1 ); }
		else {
			$changes = match ( $case ) {
				'binding id' => [ 'id' => 17 ], 'order id' => [ 'order_id' => 502 ],
				'snapshot digest' => [ 'snapshot_digest' => Q::digest( 'different actual snapshot' ) ], 'context digest' => [ 'context_digest' => Q::digest( 'different actual context' ) ], 'native money digest' => [ 'native_money_digest' => Q::digest( 'different actual native money' ) ],
				'created time' => [ 'created_at' => Q::time()->plus_seconds( 4 )->sql() ], 'verified time' => [ 'verified_at' => Q::time()->plus_seconds( 5 )->sql() ], 'sealed time' => [ 'sealed_at' => Q::time()->plus_seconds( 6 )->sql() ],
				default => [ 'state' => 'prepared', 'revision' => 2, 'sealed_at' => null ],
			};
			$id = (int) $this->f->db->pdo->query( 'SELECT id FROM durable_delivery_engine_delivery_quote_bindings' )->fetchColumn(); $this->update( 'delivery_quote_bindings', $changes, $id );
		}
		$before = $this->bytes(); self::assertNull( $this->f->read() ); self::assertContains( 'order', $this->f->trace->events ); self::assertContains( 'saved', $this->f->trace->events ); $this->assert_unchanged( $before );
	}

	public static function changed_byte_proofs(): array { return array_map( static fn( string $v ): array => [ $v ], [ 'order owner', 'order id', 'order mapping', 'order native money', 'saved snapshot', 'saved context', 'saved native money', 'saved order', 'saved mapping', 'saved unavailable' ] ); }
	#[DataProvider( 'changed_byte_proofs' )]
	public function test_original_order_and_saved_bytes_must_still_prove_before_actual_target_locks( string $case ): void {
		if ( str_starts_with( $case, 'order ' ) ) {
			$field = match ( $case ) { 'order owner' => 'owner_digest', 'order id' => 'order_id', 'order mapping' => 'mapping', default => 'native_money_digest' };
			$this->f->order->saved[$field] = 'order_id' === $field ? 502 : ( 'mapping' === $field ? [ 'format_version' => 1, 'groups' => [] ] : Q::digest( 'changed original order bytes' ) );
		} elseif ( 'saved unavailable' === $case ) { $this->f->saved->allow = false; }
		else { $field = match ( $case ) { 'saved snapshot' => 'snapshot_digest', 'saved context' => 'context_digest', 'saved native money' => 'native_money_digest', 'saved order' => 'order_id', default => 'mapping_json' }; $this->f->saved->packet[$field] = 'order_id' === $field ? 502 : Q::digest( 'changed saved bytes' ); }
		$before = $this->bytes(); self::assertNull( $this->f->read() ); self::assertNotContains( 'quote', $this->f->trace->events ); self::assertNotContains( 'binding', $this->f->trace->events ); $this->assert_unchanged( $before );
	}

	public static function replaced_order_identity(): array { return [ [ 'entry' ], [ 'publication' ], [ 'eligibility' ], [ 'order initial' ], [ 'saved initial' ], [ 'order late' ], [ 'saved late' ] ]; }
	#[DataProvider( 'replaced_order_identity' )]
	public function test_every_order_and_saved_callback_keeps_the_original_evidence_digest_pinned( string $phase ): void {
		$changed = false; $change = function() use ( &$changed ): void { $changed = true; $this->f->order->digest = Q::digest( 'replaced candidate-order identity' ); };
		if ( 'entry' === $phase ) { $change(); }
		elseif ( 'publication' === $phase ) { $this->f->on_hook = static function( $s, string $point ) use ( $change ): void { if ( 'publication' === $point ) { $change(); } }; }
		elseif ( 'eligibility' === $phase ) { $this->f->eligibility->on_proof = $change; }
		else { $late = str_ends_with( $phase, 'late' ); $port = str_starts_with( $phase, 'order' ) ? $this->f->order : $this->f->saved; $port->on_proof = static function( $s, int $calls ) use ( $change, $late ): void { if ( $calls === ( $late ? 2 : 1 ) ) { $change(); } }; }
		$before = $this->bytes(); self::assertNull( $this->f->read() ); self::assertTrue( $changed ); $this->assert_unchanged( $before );
	}

	public static function clock_fences(): array { return array_map( static fn( string $v ): array => [ $v ], [ 'initial before seal', 'initial hold expiry', 'initial header expiry', 'late backwards', 'late before seal', 'late hold expiry', 'final backwards', 'final hold expiry', 'final header expiry' ] ); }
	#[DataProvider( 'clock_fences' )]
	public function test_all_sql_observations_follow_the_actual_seal_without_reversing_or_extending_original_deadlines( string $case ): void {
		$initial = $late = $final = Q::time()->plus_seconds( 6 );
		if ( 'initial before seal' === $case ) { $initial = $late = $final = Q::time()->plus_seconds( 4 ); }
		elseif ( 'initial hold expiry' === $case ) { $initial = $late = $final = $this->f->command->hold_until(); }
		elseif ( 'initial header expiry' === $case ) { $initial = $late = $final = $this->f->command->header()->expires_at(); }
		elseif ( 'late backwards' === $case ) { $late = Q::time()->plus_seconds( 5 ); }
		elseif ( 'late before seal' === $case ) { $late = Q::time()->plus_seconds( 4 ); }
		elseif ( 'late hold expiry' === $case ) { $late = $final = $this->f->command->hold_until(); }
		elseif ( 'final backwards' === $case ) { $late = Q::time()->plus_seconds( 7 ); }
		elseif ( 'final hold expiry' === $case ) { $final = $this->f->command->hold_until(); }
		else { $final = $this->f->command->header()->expires_at(); }
		$this->f->on_open = static function( $s ) use ( $initial, $late, $final ): void { $s->clock_times = [ $initial, $late, $final ]; };
		$before = $this->bytes(); self::assertNull( $this->f->read() ); self::assertGreaterThanOrEqual( 1, $this->f->sessions[0]->clock_reads ); $this->assert_unchanged( $before );
	}

	public function test_an_observation_at_the_actual_seal_time_is_permitted_with_original_deadlines_intact(): void {
		$this->f->on_open = static function( $s ): void { $s->clock_times = array_fill( 0, 3, Q::time()->plus_seconds( 5 ) ); };
		$before = $this->bytes(); $evidence = $this->f->read(); self::assertNotNull( $evidence ); self::assertSame( $evidence->sealed_at()->sql(), $evidence->evaluated_at()->sql() ); $this->assert_unchanged( $before );
	}

	public static function earliest_policy_fences(): array { return [ [ 'initial' ], [ 'late' ], [ 'saved wait' ], [ 'order wait' ] ]; }
	#[DataProvider( 'earliest_policy_fences' )]
	public function test_later_callbacks_cannot_extend_the_earliest_source_or_eligibility_interval( string $phase ): void {
		$this->f->on_open = static function( $s ): void { $s->clock_times = [ Q::time()->plus_seconds( 6 ), Q::time()->plus_seconds( 6 ), Q::time()->plus_seconds( 7 ) ]; };
		if ( 'initial' === $phase ) {
			$this->f->eligibility->seconds = 1; $this->f->on_hook = function( $s, string $point ): void { if ( 'quote' === $point ) { $this->f->eligibility->seconds = 300; } };
		} elseif ( 'late' === $phase ) { $this->f->on_hook = function( $s, string $point ): void { if ( 'quote' === $point ) { $this->f->eligibility->seconds = 1; } }; }
		else {
			$port = 'saved wait' === $phase ? $this->f->saved : $this->f->order;
			$port->on_proof = function( $s, int $calls ): void { if ( 2 === $calls ) { $s->clock_times[2] = $this->f->command->hold_until(); } };
		}
		$before = $this->bytes(); self::assertNull( $this->f->read() ); self::assertSame( 3, $this->f->sessions[0]->clock_reads ); self::assertSame( 2, $this->f->order->calls ); self::assertSame( 2, $this->f->saved->calls ); $this->assert_unchanged( $before );
	}

	public function test_a_changed_late_original_source_cannot_survive_saved_byte_continuity(): void {
		$this->f->saved->on_proof = static function( $s, int $calls ): void { if ( 1 === $calls ) { $s->inner->inner->inner->source->rows['wp_posts'][0]['post_modified_gmt'] = '2026-10-07 05:00:01'; } };
		$before = $this->bytes(); self::assertNull( $this->f->read() ); self::assertSame( 1, $this->f->saved->calls ); $this->assert_unchanged( $before );
	}

	private function capture_exact_ports(): array {
		$order = new class( $this->f->order, $this->f->command->verification()->prepared_binding()->row() ) implements FixedPromotionQuoteOrderEvidenceGuard {
			public array $candidates = []; public array $owners = [];
			public function __construct( private FixedPromotionQuoteOrderEvidenceGuard $inner, private array $expected ) {}
			public function evidence_digest(): string { return $this->inner->evidence_digest(); } public function tables( OperationSession $s ): array { return $this->inner->tables( $s ); }
			public function verify( OperationSession $s, QuoteStoredRow $q, QuoteBinding $b ): bool { $this->candidates[] = $b->row(); $this->owners[] = $s; return $b->row() === $this->expected && $this->inner->verify( $s, $q, $b ); }
		};
		$saved = new class( $this->f->saved, $this->f->command->verified_binding()->row() ) implements QuotePlacementSavedEvidenceGuard {
			public array $candidates = []; public array $owners = [];
			public function __construct( private QuotePlacementSavedEvidenceGuard $inner, private array $expected ) {} public function tables( OperationSession $s ): array { return $this->inner->tables( $s ); }
			public function verify( OperationSession $s, QuoteBinding $b ): bool { $this->candidates[] = $b->row(); $this->owners[] = $s; return $b->row() === $this->expected && $this->inner->verify( $s, $b ); }
		};
		$this->f->inputs = FixedPromotionQuoteVerifyInputs::from_sources( FixedPromotionQuoteBindInputs::from_sources( $this->f->inputs->current()->sources(), $order ), $saved ); return [ $order, $saved ];
	}

	private function update( string $suffix, array $changes, int $id ): void { $set = implode( ',', array_map( static fn( string $field ): string => $field . '=?', array_keys( $changes ) ) ); $this->f->db->pdo->prepare( 'UPDATE durable_delivery_engine_' . $suffix . ' SET ' . $set . ' WHERE id=?' )->execute( [ ...array_values( $changes ), $id ] ); }
	private function change_completion( int $id, \Closure $change ): void { $q = $this->f->db->pdo->prepare( 'SELECT completion_json FROM durable_delivery_engine_operation_records WHERE id=?' ); $q->execute( [ $id ] ); $packet = json_decode( (string) $q->fetchColumn(), flags: JSON_THROW_ON_ERROR ); $change( $packet ); $this->update( 'operation_records', [ 'completion_json' => json_encode( $packet, JSON_THROW_ON_ERROR ) ], $id ); }
	private function bytes(): array { $rows = []; foreach ( [ 'delivery_quotes', 'delivery_quote_bindings', 'operation_records', 'operation_changes' ] as $suffix ) { $rows[$suffix] = $this->f->db->pdo->query( 'SELECT * FROM durable_delivery_engine_' . $suffix . ' ORDER BY id' )->fetchAll( \PDO::FETCH_ASSOC ); } return [ $rows, array_map( static fn( $v ): string => $v->to_private_json(), $this->f->budget->accounts ), array_map( static fn( $v ): string => $v->to_private_json(), $this->f->budget->claims ), $this->f->order->saved, $this->f->saved->packet ]; }
	private function assert_unchanged( array $before ): void { self::assertSame( $before, $this->bytes() ); self::assertSame( 0, $this->f->budget->writes ); foreach ( $this->f->sessions as $s ) { self::assertSame( 0, $s->writes ); self::assertTrue( $s->inner->is_retired() ); self::assertFalse( $s->inner->in_transaction() ); } }
}
