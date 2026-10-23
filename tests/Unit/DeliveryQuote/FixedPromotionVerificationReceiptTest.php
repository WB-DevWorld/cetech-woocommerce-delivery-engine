<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteBindCommand, FixedPromotionQuoteVerifyCommand, QuoteOperationProfile, QuoteReceiptVerifier};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteId, QuoteJson, QuoteStoredRow, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\{OperationCompletion, OperationMaterialEvent, OperationRecord};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixedPromotionBindingReceiptTest.php';

/** Exact immutable saved-proof linkage; these pure fixtures grant no native or current funding authority. */
final class FixedPromotionVerificationReceiptTest extends TestCase {
	private PromotionVerifyReceiptFixture $f;
	protected function setUp(): void { $this->f = new PromotionVerifyReceiptFixture(); }

	public function test_exact_four_producers_authenticate_verified_two_without_mutating_original_facts(): void {
		$f = $this->f; $v = new QuoteReceiptVerifier(); $before = [ $f->quote->row(), $f->prepared->row(), $f->binding->row(), $f->command->to_recovery_array() ];
		self::assertTrue( $v->promotion_verification_prerequisites_verified( $f->quote, $f->records( false ), $f->command ) );
		self::assertTrue( $v->promotion_verification_verified( $f->quote, $f->records(), $f->binding, $f->command ) );
		self::assertSame( $before, [ $f->quote->row(), $f->prepared->row(), $f->binding->row(), $f->command->to_recovery_array() ] );
		self::assertSame( 1, $f->records()['verify_binding']->operation_version ); self::assertSame( [ 'binding' ], $f->records()['verify_binding']->event->changed_fields );
		self::assertSame( 1, $f->prepared->revision() ); self::assertSame( 2, $f->binding->revision() );
		self::assertArrayNotHasKey( 'allocation_digest', $f->records()['verify_binding']->completion->result );
	}

	public function test_late_persistence_and_pending_publication_do_not_reinterpret_original_effect_time(): void {
		$f = $this->f; $late = Q::time()->plus_seconds( 3600 );
		foreach ( [ 'issue', 'accept', 'bind', 'verify_binding' ] as $purpose ) { $f->receipt( $purpose, row_changes: [ 'completed_at' => $late->sql(), 'updated_at' => $late->sql(), 'publication_state' => 'pending' ], event_at: $late ); }
		self::assertTrue( ( new QuoteReceiptVerifier() )->promotion_verification_verified( $f->quote, $f->records(), $f->binding, $f->command ) );
		self::assertSame( Q::time()->plus_seconds( 4 )->sql(), $f->binding->row()['verified_at'] );
	}

	public function test_acknowledgement_collects_eight_bounded_locking_reads_in_global_namespace_order(): void {
		$f = $this->f; $s = new PromotionBindReceiptSession( $f->base ); $v = new QuoteReceiptVerifier(); $records = $v->lock_promotion_verification( $s, $f->command );
		self::assertCount( 8, $s->queries ); self::assertSame( 0, $s->writes );
		$names = array_map( static fn( OperationRecord $r ): string => $r->namespace_hash, array_filter( $f->records(), static fn( mixed $v ): bool => $v instanceof OperationRecord ) ); sort( $names, SORT_STRING );
		self::assertSame( $names, $s->namespaces );
		foreach ( $s->queries as $sql ) { self::assertStringContainsString( 'LIMIT 2', $sql ); self::assertStringContainsString( 'FOR UPDATE', $sql ); }
		foreach ( [ 'issue' => 1, 'accept' => 2, 'bind' => 3, 'verify_binding' => 4 ] as $purpose => $seconds ) { self::assertSame( Q::time()->plus_seconds( $seconds )->sql(), $records['event_persisted_at'][$purpose]->sql() ); }
		self::assertTrue( $v->promotion_verification_verified( $f->quote, $records, $f->binding, $f->command ) );
	}

	public function test_pre_effect_collector_uses_six_nonlocking_reads_and_does_not_consult_verification(): void {
		$f = $this->f; $s = new PromotionBindReceiptSession( $f->base ); $v = new QuoteReceiptVerifier(); $records = $v->prerequisites_promotion_verification( $s, $f->command );
		self::assertCount( 6, $s->queries ); self::assertSame( 0, $s->writes ); self::assertArrayNotHasKey( 'verify_binding', $records ); self::assertCount( 3, $records['event_persisted_at'] );
		foreach ( $s->queries as $sql ) { self::assertStringContainsString( 'LIMIT 2', $sql ); self::assertStringNotContainsString( 'FOR UPDATE', $sql ); }
		self::assertTrue( $v->promotion_verification_prerequisites_verified( $f->quote, $records, $f->command ) );
		$generic = new PromotionBindReceiptSession( $f->base ); $old = $v->prerequisites( $generic, $f->command->header(), $f->prepared, [ 'issue', 'accept', 'bind' ] );
		self::assertSame( $s->queries, $generic->queries ); self::assertArrayNotHasKey( 'event_persisted_at', $old );
		self::assertFalse( $v->verify( $f->quote, $old, $f->prepared ) );
	}

	public static function broken_originals(): array {
		$out = []; foreach ( [ 'issue', 'accept', 'bind' ] as $purpose ) { foreach ( [ 'event', 'completed', 'updated', 'publication', 'missing' ] as $fault ) { $out[$purpose . ' ' . $fault] = [ $purpose, $fault ]; } } return $out;
	}
	#[DataProvider( 'broken_originals' )]
	public function test_every_original_chronology_publication_and_link_is_proved_before_effect( string $purpose, string $fault ): void {
		$f = $this->f; $v = new QuoteReceiptVerifier(); $early = Q::time()->plus_seconds( match ( $purpose ) { 'issue' => 0, 'accept' => 1, default => 2 } );
		if ( 'event' === $fault ) { $f->events[$purpose]['created_at'] = $early->sql(); }
		elseif ( 'completed' === $fault || 'updated' === $fault ) { $f->receipt( $purpose, row_changes: [ 'completed' === $fault ? 'completed_at' : 'updated_at' => $early->sql() ] ); }
		elseif ( 'publication' === $fault ) { $packet = json_decode( $f->rows[$purpose]['completion_json'], flags: JSON_THROW_ON_ERROR ); $packet->publication->site_id = 2; $f->rows[$purpose]['completion_json'] = json_encode( $packet, JSON_THROW_ON_ERROR ); }
		else { unset( $f->rows[$purpose] ); }
		$s = new PromotionBindReceiptSession( $f->base ); $records = $v->prerequisites_promotion_verification( $s, $f->command );
		self::assertFalse( $v->promotion_verification_prerequisites_verified( $f->quote, $records, $f->command ), $purpose . ' ' . $fault ); self::assertSame( 0, $s->writes );
		foreach ( $s->queries as $sql ) { self::assertStringNotContainsString( 'FOR UPDATE', $sql ); }
	}

	public static function broken_verification(): array {
		return array_map( static fn( string $v ): array => [ $v ], [ 'missing issue', 'missing accept', 'missing bind', 'missing verification', 'wrong verification type', 'intent', 'target', 'namespace', 'site', 'binding id', 'placement id', 'order id', 'manifest digest', 'snapshot digest', 'context digest', 'native money digest', 'effect time', 'record completed early', 'record updated early', 'event early', 'missing event times', 'missing verification time', 'untyped event time', 'extra event time', 'foreign publication' ] );
	}
	#[DataProvider( 'broken_verification' )]
	public function test_syntactically_valid_but_unlinked_verification_or_unproven_sql_chronology_is_refused( string $case ): void {
		$f = $this->f; $v = new QuoteReceiptVerifier(); $records = $f->records();
		if ( str_starts_with( $case, 'missing ' ) && in_array( substr( $case, 8 ), [ 'issue', 'accept', 'bind', 'verification' ], true ) ) { unset( $records['missing verification' === $case ? 'verify_binding' : substr( $case, 8 )] ); }
		elseif ( 'wrong verification type' === $case ) { $records['verify_binding'] = new \stdClass(); }
		elseif ( in_array( $case, [ 'intent', 'target', 'namespace', 'site' ], true ) ) { $field = match ( $case ) { 'intent' => 'intent_hash', 'target' => 'target_hash', 'namespace' => 'namespace_hash', default => 'site_id' }; $records['verify_binding'] = $f->receipt( row_changes: [ $field => 'site' === $case ? 2 : Q::digest( 'unlinked ' . $case ) ] ); }
		elseif ( in_array( $case, [ 'binding id', 'placement id', 'order id', 'manifest digest', 'snapshot digest', 'context digest', 'native money digest', 'effect time' ], true ) ) {
			[ $field, $value ] = match ( $case ) { 'binding id' => [ 'binding_id', 18 ], 'placement id' => [ 'placement_id', QuoteId::generate()->value() ], 'order id' => [ 'order_id', 701 ], 'manifest digest' => [ 'manifest_digest', Q::digest( 'other manifest' ) ], 'snapshot digest' => [ 'snapshot_digest', Q::digest( 'other snapshot' ) ], 'context digest' => [ 'context_digest', Q::digest( 'other saved context' ) ], 'native money digest' => [ 'native_money_digest', Q::digest( 'other money' ) ], default => [ 'completed_at', Q::time()->plus_seconds( 5 )->epoch_microseconds() ] };
			$records['verify_binding'] = $f->receipt( result_changes: [ $field => $value ] );
		} elseif ( 'record completed early' === $case || 'record updated early' === $case ) { $records['verify_binding'] = $f->receipt( row_changes: [ 'record completed early' === $case ? 'completed_at' : 'updated_at' => Q::time()->plus_seconds( 3 )->sql() ] ); }
		elseif ( 'event early' === $case ) { $records['event_persisted_at']['verify_binding'] = Q::time()->plus_seconds( 3 ); }
		elseif ( 'missing event times' === $case ) { unset( $records['event_persisted_at'] ); }
		elseif ( 'missing verification time' === $case ) { unset( $records['event_persisted_at']['verify_binding'] ); }
		elseif ( 'untyped event time' === $case ) { $records['event_persisted_at']['verify_binding'] = Q::time()->plus_seconds( 4 )->sql(); }
		elseif ( 'extra event time' === $case ) { $records['event_persisted_at']['seal'] = Q::time()->plus_seconds( 5 ); }
		else { $packet = json_decode( $f->rows['verify_binding']['completion_json'], flags: JSON_THROW_ON_ERROR ); $packet->publication->site_id = 2; $f->rows['verify_binding']['completion_json'] = json_encode( $packet, JSON_THROW_ON_ERROR ); $records = $f->records(); }
		self::assertFalse( $v->promotion_verification_verified( $f->quote, $records, $f->binding, $f->command ), $case );
	}

	public function test_original_binding_intent_and_complete_physical_membership_cannot_be_replaced(): void {
		$f = $this->f; $v = new QuoteReceiptVerifier(); $old = $f->base->command;
		$changed = FixedPromotionQuoteBindCommand::create( $old->acceptance(), $f->quote, $f->prepared->row()['order_id'], QuoteId::from_string( $f->prepared->row()['placement_uuid'] ), $f->prepared->mapping(), Q::digest( 'another native order capture' ) );
		foreach ( [ FixedPromotionQuoteVerifyCommand::create( $changed, $f->prepared, $f->command->snapshot_digest(), $f->command->context_digest() ), FixedPromotionQuoteVerifyCommand::create( $old, QuoteBinding::from_row( array_replace( $f->prepared->row(), [ 'id' => 18 ] ), $f->quote ), $f->command->snapshot_digest(), $f->command->context_digest() ) ] as $command ) {
			self::assertFalse( $v->promotion_verification_prerequisites_verified( $f->quote, $f->records( false ), $command ) ); self::assertFalse( $v->promotion_verification_verified( $f->quote, $f->records(), $f->binding, $command ) );
		}
	}

	public function test_actual_verified_membership_saved_digests_and_effect_timestamp_are_exact(): void {
		$f = $this->f; $v = new QuoteReceiptVerifier();
		foreach ( [ [ 'id' => 18 ], [ 'order_id' => 701 ], [ 'snapshot_digest' => Q::digest( 'different snapshot' ) ], [ 'context_digest' => Q::digest( 'different context' ) ], [ 'verified_at' => Q::time()->plus_seconds( 5 )->sql() ], [ 'created_at' => Q::time()->plus_seconds( 2 )->sql() ] ] as $change ) {
			$binding = QuoteBinding::from_row( array_replace( $f->binding->row(), $change ), $f->quote );
			self::assertFalse( $v->promotion_verification_verified( $f->quote, $f->records(), $binding, $f->command ), (string) array_key_first( $change ) );
		}
		$quote = QuoteStoredRow::from_row( array_replace( $f->quote->row(), [ 'id' => 2 ] ) ); self::assertFalse( $v->promotion_verification_verified( $quote, $f->records(), $f->binding, $f->command ) );
	}

	public function test_prepared_one_sealed_three_and_exclusive_hold_deadline_are_not_verified_two(): void {
		$f = $this->f; $v = new QuoteReceiptVerifier();
		$sealed = QuoteBinding::from_row( array_replace( $f->binding->row(), [ 'state' => 'sealed', 'revision' => 3, 'sealed_at' => Q::time()->plus_seconds( 5 )->sql() ] ), $f->quote );
		foreach ( [ $f->prepared, $sealed ] as $other ) { self::assertFalse( $v->promotion_verification_verified( $f->quote, $f->records(), $other, $f->command ) ); }
		$at = $f->command->hold_until(); $binding = QuoteBinding::from_row( array_replace( $f->binding->row(), [ 'verified_at' => $at->sql() ] ), $f->quote );
		$f->receipt( result_changes: [ 'completed_at' => $at->epoch_microseconds() ], event_at: $at );
		self::assertFalse( $v->promotion_verification_verified( $f->quote, $f->records(), $binding, $f->command ) );
	}

	public function test_actual_sql_event_timestamp_is_retained_and_generic_query_semantics_remain_unchanged(): void {
		$f = $this->f; $v = new QuoteReceiptVerifier(); $f->events['verify_binding']['created_at'] = Q::time()->plus_seconds( 3 )->sql();
		$s = new PromotionBindReceiptSession( $f->base ); $records = $v->lock_promotion_verification( $s, $f->command );
		self::assertSame( Q::time()->plus_seconds( 3 )->sql(), $records['event_persisted_at']['verify_binding']->sql() ); self::assertFalse( $v->promotion_verification_verified( $f->quote, $records, $f->binding, $f->command ) );
		$generic = new PromotionBindReceiptSession( $f->base ); $old = $v->lock( $generic, $f->command->header(), $f->binding, [ 'issue', 'accept', 'bind', 'verify_binding' ] );
		self::assertCount( 8, $generic->queries ); self::assertArrayNotHasKey( 'event_persisted_at', $old );
		foreach ( $generic->queries as $sql ) { if ( str_contains( $sql, 'operation_changes' ) ) { self::assertStringNotContainsString( 'FOR UPDATE', $sql ); } }
		self::assertFalse( $v->verify( $f->quote, $old, $f->binding ) ); self::assertFalse( $v->issue_verified( $f->quote, $old ) );
	}
}

/** NEW reusable strict E14 producers over the unchanged pure E13 fixture; no native SQL certification. */
final class PromotionVerifyReceiptFixture {
	public PromotionBindReceiptFixture $base;
	public FixedPromotionQuoteVerifyCommand $command;
	public QuoteStoredRow $quote;
	public QuoteBinding $prepared;
	public QuoteBinding $binding;
	public array $rows;
	public array $events;
	public function __construct() {
		$this->base = new PromotionBindReceiptFixture(); $this->quote = $this->base->quote; $this->prepared = $this->base->binding;
		$this->command = FixedPromotionQuoteVerifyCommand::create( $this->base->command, $this->prepared, Q::digest( 'E14 exact saved snapshot' ), Q::digest( 'E14 exact saved context' ) );
		$this->binding = QuoteBinding::from_row( array_replace( $this->command->binding()->row(), [ 'verified_at' => Q::time()->plus_seconds( 4 )->sql() ] ), $this->quote );
		$this->rows =& $this->base->rows; $this->events =& $this->base->events; $this->receipt();
	}
	public function receipt( string $purpose = 'verify_binding', array $result_changes = [], array $row_changes = [], ?QuoteTime $event_at = null ): OperationRecord {
		if ( 'verify_binding' !== $purpose ) { return $this->base->receipt( $purpose, $result_changes, $row_changes, $event_at ); }
		$identity = $this->command->identity; $profile = QuoteOperationProfile::registry()->get( 'delivery_quote.verify_binding', 1 ); $b = $this->binding->row(); $effect = Q::time()->plus_seconds( 4 );
		$result = array_replace( [ 'quote_id' => $this->quote->header()->id()->value(), 'quote_revision' => 2, 'state' => 'prepared', 'body_digest' => $this->quote->header()->body_digest(), 'owner_digest' => $this->command->owner()->digest(), 'namespace_hash' => $identity->namespace_digest(), 'completed_at' => $effect->epoch_microseconds(), 'control_revision' => 1, 'binding_id' => $this->binding->id(), 'placement_id' => $b['placement_uuid'], 'order_id' => $b['order_id'], 'binding_revision' => 2, 'manifest_digest' => $b['managed_group_manifest_digest'], 'snapshot_digest' => $b['snapshot_digest'], 'context_digest' => $b['context_digest'], 'native_money_digest' => $b['native_money_digest'] ], $result_changes );
		$site = $row_changes['site_id'] ?? 1; $effect = QuoteTime::from_epoch_microseconds( $result['completed_at'] );
		$completion = OperationCompletion::accepted( $profile, $result, [ 'site_id' => $site, 'owner_digest' => $result['owner_digest'], 'quote_id' => $result['quote_id'], 'purpose' => 'private_quote_invalidation' ] );
		$target = array_intersect_key( $result, array_flip( [ 'quote_id', 'body_digest', 'owner_digest', 'namespace_hash', 'quote_revision', 'binding_revision' ] ) );
		$event = OperationMaterialEvent::from_mutation( $profile, $identity, RequestContext::create(), [ 'authority_hash' => hash( 'sha256', $identity->authority ), 'principal_hash' => hash( 'sha256', $identity->principal ) ], $target, 'quote_binding_verified', 1, 2, [ 'binding' ] );
		$row = array_replace( [ 'id' => 4, 'site_id' => $site, 'namespace_hash' => $identity->namespace_digest(), 'intent_hash' => $this->command->intent()->fingerprint(), 'namespace_format' => 1, 'intent_format' => 1, 'record_format' => 1, 'operation' => 'delivery_quote.verify_binding', 'operation_version' => 1, 'target_hash' => hash( 'sha256', 'cetech-operation-target-v1:' . $identity->target_key ), 'state' => 'accepted', 'publication_state' => 'published', 'completion_json' => $completion->to_json(), 'audit_id' => 4, 'row_version' => 2, 'created_at' => Q::time()->sql(), 'updated_at' => $effect->plus_seconds( 1 )->sql(), 'completed_at' => $effect->plus_seconds( 1 )->sql() ], $row_changes );
		$this->rows[$purpose] = $row; $this->events[$purpose] = [ 'id' => 4, 'site_id' => $site, 'operation_id' => 4, 'event_format' => 1, 'event_json' => $event->to_json(), 'created_at' => ( $event_at ?? $effect )->sql() ];
		return OperationRecord::from_row( $row, $profile, $this->events[$purpose] );
	}
	public function records( bool $include_verify = true ): array {
		$out = [ 'event_persisted_at' => [] ];
		foreach ( $include_verify ? [ 'issue', 'accept', 'bind', 'verify_binding' ] : [ 'issue', 'accept', 'bind' ] as $purpose ) {
			$out[$purpose] = OperationRecord::from_row( $this->rows[$purpose], QuoteOperationProfile::registry()->get( 'delivery_quote.' . $purpose, 'issue' === $purpose ? 2 : 1 ), $this->events[$purpose] );
			$out['event_persisted_at'][$purpose] = QuoteTime::parse( $this->events[$purpose]['created_at'] );
		}
		return $out;
	}
}
