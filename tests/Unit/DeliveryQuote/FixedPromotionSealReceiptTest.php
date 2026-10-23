<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteSealCommand, FixedPromotionQuoteVerifyCommand, QuoteOperationProfile, QuoteReceiptVerifier};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteId, QuoteJson, QuoteStoredRow, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\{OperationCompletion, OperationMaterialEvent, OperationRecord};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixedPromotionVerificationReceiptTest.php';

/** Exact immutable sealed history; pure fixtures grant no native, spending or payment authority. */
final class FixedPromotionSealReceiptTest extends TestCase {
	private PromotionSealReceiptFixture $f;
	protected function setUp(): void { $this->f = new PromotionSealReceiptFixture(); }

	public function test_exact_five_producers_authenticate_sealed_three_without_rewriting_original_facts(): void {
		$f = $this->f; $v = new QuoteReceiptVerifier(); $before = [ $f->quote->row(), $f->verified->row(), $f->binding->row(), $f->command->to_recovery_array() ];
		self::assertTrue( $v->promotion_seal_prerequisites_verified( $f->quote, $f->records( false ), $f->command ) );
		self::assertTrue( $v->promotion_seal_verified( $f->quote, $f->records(), $f->binding, $f->command ) );
		self::assertSame( $before, [ $f->quote->row(), $f->verified->row(), $f->binding->row(), $f->command->to_recovery_array() ] );
		self::assertSame( 2, $f->records()['seal']->operation_version ); self::assertSame( [ 'binding' ], $f->records()['seal']->event->changed_fields );
		self::assertSame( 2, $f->verified->revision() ); self::assertSame( 3, $f->binding->revision() );
		foreach ( [ 'snapshot_digest', 'context_digest', 'native_money_digest', 'verified_at' ] as $field ) { self::assertSame( $f->verified->row()[$field], $f->binding->row()[$field] ); }
		self::assertSame( 'retain_original_hold_v1', FixedPromotionQuoteSealCommand::FUNDING_POLICY ); self::assertArrayNotHasKey( 'allocation_digest', $f->records()['seal']->completion->result );
	}

	public function test_late_persistence_and_pending_publication_preserve_original_effect_time(): void {
		$f = $this->f; $late = Q::time()->plus_seconds( 3600 );
		foreach ( [ 'issue', 'accept', 'bind', 'verify_binding', 'seal' ] as $purpose ) { $f->receipt( $purpose, row_changes: [ 'completed_at' => $late->sql(), 'updated_at' => $late->sql(), 'publication_state' => 'pending' ], event_at: $late ); }
		self::assertTrue( ( new QuoteReceiptVerifier() )->promotion_seal_verified( $f->quote, $f->records(), $f->binding, $f->command ) );
		self::assertSame( Q::time()->plus_seconds( 5 )->sql(), $f->binding->row()['sealed_at'] );
	}

	public function test_acknowledgement_collects_ten_bounded_locking_reads_in_global_namespace_order(): void {
		$f = $this->f; $s = new PromotionBindReceiptSession( $f->base->base ); $v = new QuoteReceiptVerifier(); $records = $v->lock_promotion_seal( $s, $f->command );
		self::assertCount( 10, $s->queries ); self::assertSame( 0, $s->writes );
		$names = array_map( static fn( OperationRecord $r ): string => $r->namespace_hash, array_filter( $f->records(), static fn( mixed $v ): bool => $v instanceof OperationRecord ) ); sort( $names, SORT_STRING );
		self::assertSame( $names, $s->namespaces );
		foreach ( $s->queries as $sql ) { self::assertStringContainsString( 'LIMIT 2', $sql ); self::assertStringContainsString( 'FOR UPDATE', $sql ); }
		foreach ( [ 'issue' => 1, 'accept' => 2, 'bind' => 3, 'verify_binding' => 4, 'seal' => 5 ] as $purpose => $seconds ) { self::assertSame( Q::time()->plus_seconds( $seconds )->sql(), $records['event_persisted_at'][$purpose]->sql() ); }
		self::assertTrue( $v->promotion_seal_verified( $f->quote, $records, $f->binding, $f->command ) );
	}

	public function test_pre_effect_collector_uses_eight_nonlocking_reads_without_the_seal_namespace(): void {
		$f = $this->f; $s = new PromotionBindReceiptSession( $f->base->base ); $v = new QuoteReceiptVerifier(); $records = $v->prerequisites_promotion_seal( $s, $f->command );
		self::assertCount( 8, $s->queries ); self::assertSame( 0, $s->writes ); self::assertArrayNotHasKey( 'seal', $records ); self::assertCount( 4, $records['event_persisted_at'] );
		foreach ( $s->queries as $sql ) { self::assertStringContainsString( 'LIMIT 2', $sql ); self::assertStringNotContainsString( 'FOR UPDATE', $sql ); }
		self::assertTrue( $v->promotion_seal_prerequisites_verified( $f->quote, $records, $f->command ) );
		$generic = new PromotionBindReceiptSession( $f->base->base ); $old = $v->prerequisites( $generic, $f->command->header(), $f->verified, [ 'issue', 'accept', 'bind', 'verify_binding' ] );
		self::assertSame( $s->queries, $generic->queries ); self::assertArrayNotHasKey( 'event_persisted_at', $old ); self::assertFalse( $v->verify( $f->quote, $old, $f->verified ) );
	}

	public static function broken_originals(): array {
		$out = []; foreach ( [ 'issue', 'accept', 'bind', 'verify_binding' ] as $purpose ) { foreach ( [ 'event', 'completed', 'updated', 'publication', 'missing' ] as $fault ) { $out[$purpose . ' ' . $fault] = [ $purpose, $fault ]; } } return $out;
	}
	#[DataProvider( 'broken_originals' )]
	public function test_every_original_chronology_publication_and_link_is_authenticated_before_effect( string $purpose, string $fault ): void {
		$f = $this->f; $v = new QuoteReceiptVerifier(); $early = Q::time()->plus_seconds( match ( $purpose ) { 'issue' => 0, 'accept' => 1, 'bind' => 2, default => 3 } );
		if ( 'event' === $fault ) { $f->events[$purpose]['created_at'] = $early->sql(); }
		elseif ( 'completed' === $fault || 'updated' === $fault ) { $f->receipt( $purpose, row_changes: [ 'completed' === $fault ? 'completed_at' : 'updated_at' => $early->sql() ] ); }
		elseif ( 'publication' === $fault ) { $packet = json_decode( $f->rows[$purpose]['completion_json'], flags: JSON_THROW_ON_ERROR ); $packet->publication->site_id = 2; $f->rows[$purpose]['completion_json'] = json_encode( $packet, JSON_THROW_ON_ERROR ); }
		else { unset( $f->rows[$purpose] ); }
		$s = new PromotionBindReceiptSession( $f->base->base ); $records = $v->prerequisites_promotion_seal( $s, $f->command );
		self::assertFalse( $v->promotion_seal_prerequisites_verified( $f->quote, $records, $f->command ), $purpose . ' ' . $fault ); self::assertSame( 0, $s->writes );
		foreach ( $s->queries as $sql ) { self::assertStringNotContainsString( 'FOR UPDATE', $sql ); }
	}

	public static function broken_seal(): array {
		return array_map( static fn( string $v ): array => [ $v ], [ 'missing issue', 'missing accept', 'missing bind', 'missing verify_binding', 'missing seal', 'wrong seal type', 'intent', 'target', 'namespace', 'site', 'binding id', 'placement id', 'order id', 'manifest digest', 'snapshot digest', 'context digest', 'native money digest', 'control revision', 'effect time', 'record completed early', 'record updated early', 'event early', 'missing event times', 'missing seal time', 'untyped event time', 'extra event time', 'foreign publication' ] );
	}
	#[DataProvider( 'broken_seal' )]
	public function test_unlinked_seal_or_unproven_chronology_is_refused( string $case ): void {
		$f = $this->f; $v = new QuoteReceiptVerifier(); $records = $f->records();
		if ( str_starts_with( $case, 'missing ' ) && in_array( substr( $case, 8 ), [ 'issue', 'accept', 'bind', 'verify_binding', 'seal' ], true ) ) { unset( $records[substr( $case, 8 )] ); }
		elseif ( 'wrong seal type' === $case ) { $records['seal'] = new \stdClass(); }
		elseif ( in_array( $case, [ 'intent', 'target', 'namespace', 'site' ], true ) ) { $field = match ( $case ) { 'intent' => 'intent_hash', 'target' => 'target_hash', 'namespace' => 'namespace_hash', default => 'site_id' }; $records['seal'] = $f->receipt( row_changes: [ $field => 'site' === $case ? 2 : Q::digest( 'unlinked ' . $case ) ] ); }
		elseif ( in_array( $case, [ 'binding id', 'placement id', 'order id', 'manifest digest', 'snapshot digest', 'context digest', 'native money digest', 'control revision', 'effect time' ], true ) ) {
			[ $field, $value ] = match ( $case ) { 'binding id' => [ 'binding_id', 18 ], 'placement id' => [ 'placement_id', QuoteId::generate()->value() ], 'order id' => [ 'order_id', 701 ], 'manifest digest' => [ 'manifest_digest', Q::digest( 'other manifest' ) ], 'snapshot digest' => [ 'snapshot_digest', Q::digest( 'other snapshot' ) ], 'context digest' => [ 'context_digest', Q::digest( 'other saved context' ) ], 'native money digest' => [ 'native_money_digest', Q::digest( 'other money' ) ], 'control revision' => [ 'control_revision', 2 ], default => [ 'completed_at', Q::time()->plus_seconds( 6 )->epoch_microseconds() ] };
			$records['seal'] = $f->receipt( result_changes: [ $field => $value ] );
		} elseif ( 'record completed early' === $case || 'record updated early' === $case ) { $records['seal'] = $f->receipt( row_changes: [ 'record completed early' === $case ? 'completed_at' : 'updated_at' => Q::time()->plus_seconds( 4 )->sql() ] ); }
		elseif ( 'event early' === $case ) { $records['event_persisted_at']['seal'] = Q::time()->plus_seconds( 4 ); }
		elseif ( 'missing event times' === $case ) { unset( $records['event_persisted_at'] ); }
		elseif ( 'missing seal time' === $case ) { unset( $records['event_persisted_at']['seal'] ); }
		elseif ( 'untyped event time' === $case ) { $records['event_persisted_at']['seal'] = Q::time()->plus_seconds( 5 )->sql(); }
		elseif ( 'extra event time' === $case ) { $records['event_persisted_at']['invalidate'] = Q::time()->plus_seconds( 6 ); }
		else { $packet = json_decode( $f->rows['seal']['completion_json'], flags: JSON_THROW_ON_ERROR ); $packet->publication->site_id = 2; $f->rows['seal']['completion_json'] = json_encode( $packet, JSON_THROW_ON_ERROR ); $records = $f->records(); }
		self::assertFalse( $v->promotion_seal_verified( $f->quote, $records, $f->binding, $f->command ), $case );
	}

	public function test_original_verification_intent_and_actual_verified_time_cannot_be_substituted(): void {
		$f = $this->f; $v = new QuoteReceiptVerifier(); $old = $f->base->command;
		$changed = FixedPromotionQuoteVerifyCommand::create( $old->binding_command(), $old->prepared_binding(), Q::digest( 'different original saved snapshot' ), $old->context_digest() );
		$different = QuoteBinding::from_row( array_replace( $f->verified->row(), [ 'snapshot_digest' => $changed->snapshot_digest() ] ), $f->quote );
		foreach ( [ FixedPromotionQuoteSealCommand::create( $changed, $different, 1 ), FixedPromotionQuoteSealCommand::create( $old, QuoteBinding::from_row( array_replace( $f->verified->row(), [ 'verified_at' => Q::time()->plus_seconds( 5 )->sql() ] ), $f->quote ), 1 ) ] as $command ) {
			self::assertFalse( $v->promotion_seal_prerequisites_verified( $f->quote, $f->records( false ), $command ) ); self::assertFalse( $v->promotion_seal_verified( $f->quote, $f->records(), $f->binding, $command ) );
		}
		$control = FixedPromotionQuoteSealCommand::create( $old, $f->verified, 2 ); self::assertTrue( $v->promotion_seal_prerequisites_verified( $f->quote, $f->records( false ), $control ) ); self::assertFalse( $v->promotion_seal_verified( $f->quote, $f->records(), $f->binding, $control ) );
	}

	public function test_actual_sealed_membership_digests_and_both_effect_times_are_exact(): void {
		$f = $this->f; $v = new QuoteReceiptVerifier();
		foreach ( [ [ 'id' => 18 ], [ 'order_id' => 701 ], [ 'snapshot_digest' => Q::digest( 'different snapshot' ) ], [ 'context_digest' => Q::digest( 'different context' ) ], [ 'sealed_at' => Q::time()->plus_seconds( 6 )->sql() ], [ 'created_at' => Q::time()->plus_seconds( 2 )->sql() ], [ 'verified_at' => Q::time()->plus_seconds( 5 )->sql() ] ] as $change ) {
			$binding = QuoteBinding::from_row( array_replace( $f->binding->row(), $change ), $f->quote ); self::assertFalse( $v->promotion_seal_verified( $f->quote, $f->records(), $binding, $f->command ), (string) array_key_first( $change ) );
		}
		$quote = QuoteStoredRow::from_row( array_replace( $f->quote->row(), [ 'id' => 2 ] ) ); self::assertFalse( $v->promotion_seal_verified( $quote, $f->records(), $f->binding, $f->command ) );
	}

	public function test_prepared_one_verified_two_and_exclusive_hold_deadline_are_not_sealed_history(): void {
		$f = $this->f; $v = new QuoteReceiptVerifier();
		foreach ( [ $f->base->prepared, $f->verified ] as $other ) { self::assertFalse( $v->promotion_seal_verified( $f->quote, $f->records(), $other, $f->command ) ); }
		$at = $f->command->hold_until(); $binding = QuoteBinding::from_row( array_replace( $f->binding->row(), [ 'sealed_at' => $at->sql() ] ), $f->quote );
		$f->receipt( result_changes: [ 'completed_at' => $at->epoch_microseconds() ], event_at: $at ); self::assertFalse( $v->promotion_seal_verified( $f->quote, $f->records(), $binding, $f->command ) );
	}

	public function test_actual_sql_event_time_is_retained_without_widening_generic_query_semantics(): void {
		$f = $this->f; $v = new QuoteReceiptVerifier(); $f->events['seal']['created_at'] = Q::time()->plus_seconds( 4 )->sql();
		$s = new PromotionBindReceiptSession( $f->base->base ); $records = $v->lock_promotion_seal( $s, $f->command );
		self::assertSame( Q::time()->plus_seconds( 4 )->sql(), $records['event_persisted_at']['seal']->sql() ); self::assertFalse( $v->promotion_seal_verified( $f->quote, $records, $f->binding, $f->command ) );
		$generic = new PromotionBindReceiptSession( $f->base->base ); $old = $v->lock( $generic, $f->command->header(), $f->binding, [ 'issue', 'accept', 'bind', 'verify_binding', 'seal' ] );
		self::assertCount( 10, $generic->queries ); self::assertArrayNotHasKey( 'event_persisted_at', $old );
		foreach ( $generic->queries as $sql ) { if ( str_contains( $sql, 'operation_changes' ) ) { self::assertStringNotContainsString( 'FOR UPDATE', $sql ); } }
		self::assertFalse( $v->verify( $f->quote, $old, $f->binding ) ); self::assertFalse( $v->issue_verified( $f->quote, $old ) ); self::assertNull( $v->promise_seal( $f->quote, $old, $f->binding ) );
	}
}

/** Strict E15 producers over unchanged E14 fixtures; no native or financial authority. */
final class PromotionSealReceiptFixture {
	public PromotionVerifyReceiptFixture $base;
	public FixedPromotionQuoteSealCommand $command;
	public QuoteStoredRow $quote;
	public QuoteBinding $verified;
	public QuoteBinding $binding;
	public array $rows;
	public array $events;
	public function __construct() {
		$this->base = new PromotionVerifyReceiptFixture(); $this->quote = $this->base->quote; $this->verified = $this->base->binding;
		$this->command = FixedPromotionQuoteSealCommand::create( $this->base->command, $this->verified, 1 );
		$this->binding = QuoteBinding::from_row( array_replace( $this->command->binding()->row(), [ 'sealed_at' => Q::time()->plus_seconds( 5 )->sql() ] ), $this->quote );
		$this->rows =& $this->base->rows; $this->events =& $this->base->events; $this->receipt();
	}
	public function receipt( string $purpose = 'seal', array $result_changes = [], array $row_changes = [], ?QuoteTime $event_at = null ): OperationRecord {
		if ( 'seal' !== $purpose ) { return $this->base->receipt( $purpose, $result_changes, $row_changes, $event_at ); }
		$identity = $this->command->identity; $profile = QuoteOperationProfile::registry()->get( 'delivery_quote.seal', 2 ); $b = $this->binding->row(); $effect = Q::time()->plus_seconds( 5 );
		$result = array_replace( [ 'quote_id' => $this->quote->header()->id()->value(), 'quote_revision' => 2, 'state' => 'sealed', 'body_digest' => $this->quote->header()->body_digest(), 'owner_digest' => $this->command->owner()->digest(), 'namespace_hash' => $identity->namespace_digest(), 'completed_at' => $effect->epoch_microseconds(), 'control_revision' => 1, 'binding_id' => $this->binding->id(), 'placement_id' => $b['placement_uuid'], 'order_id' => $b['order_id'], 'binding_revision' => 3, 'manifest_digest' => $b['managed_group_manifest_digest'], 'snapshot_digest' => $b['snapshot_digest'], 'context_digest' => $b['context_digest'], 'native_money_digest' => $b['native_money_digest'] ], $result_changes );
		$site = $row_changes['site_id'] ?? 1; $effect = QuoteTime::from_epoch_microseconds( $result['completed_at'] );
		$completion = OperationCompletion::accepted( $profile, $result, [ 'site_id' => $site, 'owner_digest' => $result['owner_digest'], 'quote_id' => $result['quote_id'], 'purpose' => 'private_quote_invalidation' ] );
		$target = array_intersect_key( $result, array_flip( [ 'quote_id', 'body_digest', 'owner_digest', 'namespace_hash', 'quote_revision', 'binding_revision' ] ) );
		$event = OperationMaterialEvent::from_mutation( $profile, $identity, RequestContext::create(), [ 'authority_hash' => hash( 'sha256', $identity->authority ), 'principal_hash' => hash( 'sha256', $identity->principal ) ], $target, 'quote_sealed', 2, 3, [ 'binding' ] );
		$row = array_replace( [ 'id' => 5, 'site_id' => $site, 'namespace_hash' => $identity->namespace_digest(), 'intent_hash' => $this->command->intent()->fingerprint(), 'namespace_format' => 1, 'intent_format' => 1, 'record_format' => 1, 'operation' => 'delivery_quote.seal', 'operation_version' => 2, 'target_hash' => hash( 'sha256', 'cetech-operation-target-v1:' . $identity->target_key ), 'state' => 'accepted', 'publication_state' => 'published', 'completion_json' => $completion->to_json(), 'audit_id' => 5, 'row_version' => 2, 'created_at' => Q::time()->sql(), 'updated_at' => $effect->plus_seconds( 1 )->sql(), 'completed_at' => $effect->plus_seconds( 1 )->sql() ], $row_changes );
		$this->rows[$purpose] = $row; $this->events[$purpose] = [ 'id' => 5, 'site_id' => $site, 'operation_id' => 5, 'event_format' => 1, 'event_json' => $event->to_json(), 'created_at' => ( $event_at ?? $effect )->sql() ];
		return OperationRecord::from_row( $row, $profile, $this->events[$purpose] );
	}
	public function records( bool $include_seal = true ): array {
		$out = [ 'event_persisted_at' => [] ];
		foreach ( $include_seal ? [ 'issue', 'accept', 'bind', 'verify_binding', 'seal' ] : [ 'issue', 'accept', 'bind', 'verify_binding' ] as $purpose ) {
			$out[$purpose] = OperationRecord::from_row( $this->rows[$purpose], QuoteOperationProfile::registry()->get( 'delivery_quote.' . $purpose, in_array( $purpose, [ 'issue', 'seal' ], true ) ? 2 : 1 ), $this->events[$purpose] );
			$out['event_persisted_at'][$purpose] = QuoteTime::parse( $this->events[$purpose]['created_at'] );
		}
		return $out;
	}
}
