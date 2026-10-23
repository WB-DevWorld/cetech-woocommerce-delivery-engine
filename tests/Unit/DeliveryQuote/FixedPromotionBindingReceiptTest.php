<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptCommand, FixedPromotionQuoteBindCommand, FixedPromotionQuoteIssueCommand, QuoteIssueCommand, QuoteOperationProfile, QuoteReceiptVerifier};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteHeader, QuoteId, QuoteJson, QuoteStoredRow, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationCompletion, OperationMaterialEvent, OperationRecord, OperationSession};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteFixtures as Q, QuoteStorageFixtures as S};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryCommercial/FixedPromotionQuoteFixture.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteStorageFixtures.php';

/** Pure immutable linkage over strict producer codecs; no current source, order, budget or clock port. */
final class FixedPromotionBindingReceiptTest extends TestCase {
	private PromotionBindReceiptFixture $f;
	protected function setUp(): void { $this->f = new PromotionBindReceiptFixture(); }

	public function test_exact_original_accepted_quote_and_prepared_binding_authenticate_historical_association_only(): void {
		$f = $this->f; $records = $f->records(); $before = [ $f->quote->row(), $f->binding->row(), $f->command->to_recovery_array() ];
		self::assertTrue( ( new QuoteReceiptVerifier() )->promotion_binding_verified( $f->quote, $records, $f->binding, $f->command ) );
		self::assertSame( $before, [ $f->quote->row(), $f->binding->row(), $f->command->to_recovery_array() ] );
		self::assertSame( 1, $records['bind']->operation_version ); self::assertSame( [ 'binding' ], $records['bind']->event->changed_fields );
		self::assertArrayNotHasKey( 'allocation_digest', $records['bind']->completion->result ); self::assertArrayNotHasKey( 'order_evidence_digest', $records['bind']->completion->result );
	}

	public function test_current_unavailability_and_late_persistence_cannot_rewrite_the_original_effect_time(): void {
		$f = $this->f; $late = Q::time()->plus_seconds( 3600 );
		foreach ( [ 'issue', 'accept', 'bind' ] as $purpose ) { $f->receipt( $purpose, row_changes: [ 'completed_at' => $late->sql(), 'updated_at' => $late->sql(), 'publication_state' => 'pending' ], event_at: $late ); }
		self::assertTrue( ( new QuoteReceiptVerifier() )->promotion_binding_verified( $f->quote, $f->records(), $f->binding, $f->command ) );
		self::assertSame( Q::time()->plus_seconds( 3 )->sql(), $f->binding->row()['created_at'] );
	}

	public static function broken_links(): array {
		return array_map( static fn( string $v ): array => [ $v ], [ 'missing issue', 'missing accept', 'missing bind', 'wrong bind type', 'bind intent', 'bind target', 'bind namespace', 'bind site', 'binding id', 'placement id', 'order id', 'manifest digest', 'bind effect time', 'bind record persisted before effect', 'bind updated before effect', 'issue event before effect', 'accept event before effect', 'bind event before effect', 'missing event times', 'missing bind time', 'untyped event time', 'extra event time' ] );
	}
	#[DataProvider( 'broken_links' )]
	public function test_syntactically_valid_but_unlinked_or_unproven_binding_history_is_refused( string $case ): void {
		$f = $this->f; $records = $f->records();
		if ( str_starts_with( $case, 'missing ' ) && in_array( substr( $case, 8 ), [ 'issue', 'accept', 'bind' ], true ) ) { unset( $records[substr( $case, 8 )] ); }
		elseif ( 'wrong bind type' === $case ) { $records['bind'] = []; }
		elseif ( in_array( $case, [ 'bind intent', 'bind target', 'bind namespace', 'bind site' ], true ) ) {
			$field = match ( $case ) { 'bind intent' => 'intent_hash', 'bind target' => 'target_hash', 'bind namespace' => 'namespace_hash', default => 'site_id' };
			$records['bind'] = $f->receipt( 'bind', row_changes: [ $field => 'site_id' === $field ? 2 : Q::digest( 'foreign binding ' . $field ) ] );
		} elseif ( in_array( $case, [ 'binding id', 'placement id', 'order id', 'manifest digest', 'bind effect time' ], true ) ) {
			[ $field, $value ] = match ( $case ) { 'binding id' => [ 'binding_id', 99 ], 'placement id' => [ 'placement_id', QuoteId::generate()->value() ], 'order id' => [ 'order_id', 777 ], 'manifest digest' => [ 'manifest_digest', Q::digest( 'other mapping' ) ], default => [ 'completed_at', Q::time()->plus_seconds( 4 )->epoch_microseconds() ] };
			$records['bind'] = $f->receipt( 'bind', result_changes: [ $field => $value ] );
		} elseif ( 'bind record persisted before effect' === $case || 'bind updated before effect' === $case ) {
			$field = 'bind updated before effect' === $case ? 'updated_at' : 'completed_at';
			$records['bind'] = $f->receipt( 'bind', row_changes: [ $field => Q::time()->plus_seconds( 2 )->sql() ] );
		} elseif ( str_contains( $case, 'event before effect' ) ) {
			$purpose = explode( ' ', $case )[0]; $records['event_persisted_at'][$purpose] = Q::time()->plus_seconds( match ( $purpose ) { 'issue' => 0, 'accept' => 1, default => 2 } );
		} elseif ( 'missing event times' === $case ) { unset( $records['event_persisted_at'] ); }
		elseif ( 'missing bind time' === $case ) { unset( $records['event_persisted_at']['bind'] ); }
		elseif ( 'untyped event time' === $case ) { $records['event_persisted_at']['bind'] = Q::time()->plus_seconds( 3 )->sql(); }
		else { $records['event_persisted_at']['seal'] = Q::time()->plus_seconds( 3 ); }
		self::assertFalse( ( new QuoteReceiptVerifier() )->promotion_binding_verified( $f->quote, $records, $f->binding, $f->command ), $case );
	}

	public function test_changed_original_order_capture_or_item_membership_cannot_reuse_the_binding_receipt(): void {
		$f = $this->f; $v = new QuoteReceiptVerifier(); $mapping = $f->command->binding()->mapping();
		foreach ( [ 'capture', 'mapping' ] as $case ) {
			$changed = $mapping; if ( 'mapping' === $case ) { $changed['groups'][0]['lines'][0]['item_id'] += 100; }
			$command = FixedPromotionQuoteBindCommand::create( $f->acceptance, $f->quote, 700, $f->placement, $changed, Q::digest( 'capture' === $case ? 'other native order evidence' : 'native order evidence' ) );
			self::assertSame( $f->command->identity->namespace_digest(), $command->identity->namespace_digest() ); self::assertNotSame( $f->command->intent()->fingerprint(), $command->intent()->fingerprint() );
			self::assertFalse( $v->promotion_binding_verified( $f->quote, $f->records(), $f->binding, $command ) );
		}
	}

	public function test_actual_binding_logical_fields_and_original_accepted_capture_must_match_exactly(): void {
		$f = $this->f; $v = new QuoteReceiptVerifier();
		$other = QuoteBinding::from_row( array_replace( $f->binding->row(), [ 'order_id' => 701 ] ), $f->quote );
		self::assertFalse( $v->promotion_binding_verified( $f->quote, $f->records(), $other, $f->command ) );
		$other_quote = QuoteStoredRow::from_row( array_replace( $f->quote->row(), [ 'id' => 2 ] ) );
		self::assertFalse( $v->promotion_binding_verified( $other_quote, $f->records(), $f->binding, $f->command ) );
	}

	public function test_exclusive_original_deadline_and_later_binding_states_do_not_authenticate_prepared_one(): void {
		$f = $this->f; $v = new QuoteReceiptVerifier(); $records = $f->records();
		$at = $f->command->hold_until(); $binding = QuoteBinding::from_row( array_replace( $f->binding->row(), [ 'created_at' => $at->sql() ] ), $f->quote );
		$records['bind'] = $f->receipt( 'bind', result_changes: [ 'completed_at' => $at->epoch_microseconds() ] ); $records['event_persisted_at']['bind'] = $at;
		self::assertFalse( $v->promotion_binding_verified( $f->quote, $records, $binding, $f->command ) );
		$verified = QuoteBinding::from_row( array_replace( $f->binding->row(), [ 'revision' => 2, 'snapshot_digest' => Q::digest( 'snapshot' ), 'context_digest' => Q::digest( 'saved native context' ), 'verified_at' => Q::time()->plus_seconds( 4 )->sql() ] ), $f->quote );
		$sealed = QuoteBinding::from_row( array_replace( $verified->row(), [ 'revision' => 3, 'state' => 'sealed', 'sealed_at' => Q::time()->plus_seconds( 5 )->sql() ] ), $f->quote );
		foreach ( [ $verified, $sealed ] as $later ) { self::assertFalse( $v->promotion_binding_verified( $f->quote, $f->records(), $later, $f->command ) ); }
	}

	public function test_dedicated_collector_reuses_the_six_bounded_reads_and_retains_exact_sql_event_times(): void {
		$f = $this->f; $session = new PromotionBindReceiptSession( $f ); $v = new QuoteReceiptVerifier();
		$records = $v->lock_promotion_binding( $session, $f->command );
		self::assertCount( 6, $session->queries ); self::assertSame( 0, $session->writes );
		$expected = [ $f->acceptance->header()->namespace_hashes()['issue'], $f->acceptance->header()->namespace_hashes()['accept'], $f->command->binding()->row()['bind_namespace_hash'] ]; sort( $expected, SORT_STRING );
		self::assertSame( $expected, $session->namespaces );
		foreach ( $session->queries as $sql ) { self::assertStringContainsString( 'FOR UPDATE', $sql ); self::assertStringContainsString( 'LIMIT 2', $sql ); }
		foreach ( [ 'issue' => 1, 'accept' => 2, 'bind' => 3 ] as $purpose => $seconds ) { self::assertSame( Q::time()->plus_seconds( $seconds )->sql(), $records['event_persisted_at'][$purpose]->sql() ); }
		self::assertTrue( $v->promotion_binding_verified( $f->quote, $records, $f->binding, $f->command ) );
	}

	public function test_pre_effect_prerequisites_use_exactly_four_nonlocking_reads_without_bind_or_extra_queries(): void {
		$f = $this->f; $session = new PromotionBindReceiptSession( $f ); $v = new QuoteReceiptVerifier();
		$records = $v->prerequisites_promotion_binding( $session, $f->command );
		self::assertCount( 4, $session->queries ); self::assertSame( 0, $session->writes ); self::assertArrayNotHasKey( 'bind', $records );
		$expected = [ $f->acceptance->header()->namespace_hashes()['issue'], $f->acceptance->header()->namespace_hashes()['accept'] ]; sort( $expected, SORT_STRING );
		self::assertSame( $expected, $session->namespaces ); self::assertCount( 2, $records['event_persisted_at'] );
		foreach ( $session->queries as $sql ) { self::assertStringNotContainsString( 'FOR UPDATE', $sql ); self::assertStringContainsString( 'LIMIT 2', $sql ); }
		foreach ( [ 'issue' => 1, 'accept' => 2 ] as $purpose => $seconds ) { self::assertSame( Q::time()->plus_seconds( $seconds )->sql(), $records['event_persisted_at'][$purpose]->sql() ); }
		self::assertTrue( $v->promotion_binding_prerequisites_verified( $f->quote, $records, $f->command ) );
		$generic = new PromotionBindReceiptSession( $f ); $old = $v->prerequisites( $generic, $f->command->header(), $f->binding, [ 'issue', 'accept' ] );
		self::assertArrayNotHasKey( 'event_persisted_at', $old ); self::assertSame( $session->queries, $generic->queries );
	}

	public static function broken_prerequisite_chronology(): array {
		return array_map( static fn( string $v ): array => [ $v ], [ 'issue event before effect', 'accept event before effect', 'issue completed before effect', 'accept completed before effect', 'issue updated before effect', 'accept updated before effect', 'missing event times', 'missing issue time', 'missing accept time', 'untyped event time', 'extra bind time', 'foreign publication', 'changed accepted capture' ] );
	}
	#[DataProvider( 'broken_prerequisite_chronology' )]
	public function test_pre_effect_prerequisites_refuse_unproven_original_chronology_without_mutation( string $case ): void {
		$f = $this->f; $v = new QuoteReceiptVerifier();
		if ( str_contains( $case, 'before effect' ) ) {
			[ $purpose, $field ] = explode( ' ', $case ); $early = Q::time()->plus_seconds( 'issue' === $purpose ? 0 : 1 );
			if ( 'event' === $field ) { $f->events[$purpose]['created_at'] = $early->sql(); }
			else { $f->receipt( $purpose, row_changes: [ 'completed' === $field ? 'completed_at' : 'updated_at' => $early->sql() ] ); }
		} elseif ( 'foreign publication' === $case ) {
			$completion = json_decode( $f->rows['accept']['completion_json'], flags: JSON_THROW_ON_ERROR );
			$completion->publication->site_id = 2;
			$f->rows['accept']['completion_json'] = json_encode( $completion, JSON_THROW_ON_ERROR );
		}
		$session = new PromotionBindReceiptSession( $f ); $records = $v->prerequisites_promotion_binding( $session, $f->command );
		if ( 'missing event times' === $case ) { unset( $records['event_persisted_at'] ); }
		elseif ( 'missing issue time' === $case ) { unset( $records['event_persisted_at']['issue'] ); }
		elseif ( 'missing accept time' === $case ) { unset( $records['event_persisted_at']['accept'] ); }
		elseif ( 'untyped event time' === $case ) { $records['event_persisted_at']['accept'] = Q::time()->plus_seconds( 2 )->sql(); }
		elseif ( 'extra bind time' === $case ) { $records['event_persisted_at']['bind'] = Q::time()->plus_seconds( 3 ); }
		$quote = 'changed accepted capture' === $case ? QuoteStoredRow::from_row( array_replace( $f->quote->row(), [ 'id' => 2 ] ) ) : $f->quote;
		self::assertFalse( $v->promotion_binding_prerequisites_verified( $quote, $records, $f->command ), $case );
		self::assertCount( 4, $session->queries ); self::assertSame( 0, $session->writes );
		foreach ( $session->queries as $sql ) { self::assertStringNotContainsString( 'FOR UPDATE', $sql ); }
	}

	public function test_event_persistence_time_is_the_actual_sql_row_and_generic_collection_remains_unchanged(): void {
		$f = $this->f; $f->events['bind']['created_at'] = Q::time()->plus_seconds( 2 )->sql(); $v = new QuoteReceiptVerifier();
		$session = new PromotionBindReceiptSession( $f ); $records = $v->lock_promotion_binding( $session, $f->command );
		self::assertSame( Q::time()->plus_seconds( 2 )->sql(), $records['event_persisted_at']['bind']->sql() ); self::assertFalse( $v->promotion_binding_verified( $f->quote, $records, $f->binding, $f->command ) );
		$generic = new PromotionBindReceiptSession( $f ); $old = $v->lock( $generic, $f->command->header(), $f->binding, [ 'issue', 'accept', 'bind' ] );
		self::assertArrayNotHasKey( 'event_persisted_at', $old ); self::assertCount( 6, $generic->queries );
		foreach ( $generic->queries as $sql ) { if ( str_contains( $sql, 'operation_changes' ) ) { self::assertStringNotContainsString( 'FOR UPDATE', $sql ); } }
		self::assertFalse( $v->verify( $f->quote, $old, $f->binding ) ); self::assertFalse( $v->issue_verified( $f->quote, $old ) );
	}
}

/** Reusable NEW pure E13 fixture; it claims no native order capture or current funding authority. */
final class PromotionBindReceiptFixture {
	public FixedPromotionQuoteAcceptCommand $acceptance;
	public FixedPromotionQuoteBindCommand $command;
	public QuoteStoredRow $quote;
	public QuoteBinding $binding;
	public QuoteId $placement;
	public array $rows = [];
	public array $events = [];
	public function __construct() {
		$p = F::mixed_provider(); $id = QuoteId::from_string( '10000000-0000-4000-8000-000000000013' ); $reference = Q::reference( $id );
		$slots = [ [ 'component_key' => F::key(), 'account_key' => Q::digest( 'E13 receipt held pool' ) ], [ 'component_key' => F::second_key(), 'account_key' => null ] ];
		$original = QuoteIssueCommand::fixed_promotion( F::owner(), $p->context(), 'a9fa5bde-1b6b-45b4-8fb3-707b304c4313', $slots, Q::time()->plus_seconds( 45 ) );
		$header = QuoteHeader::issue( $id, F::owner(), $p->context(), $p->terms(), Q::time(), $original->namespace_hashes( $id ), 'fixed_promotion_native_v1', 1, $reference );
		$issue = FixedPromotionQuoteIssueCommand::from_recovery_array( [ 'format_version' => 1, 'original_issue' => $original->to_promotion_recovery_array(), 'header_json' => $header->to_private_json(), 'terms_json' => $p->terms()->to_private_json(), 'reference' => $reference->public_fields() ] );
		$this->acceptance = FixedPromotionQuoteAcceptCommand::from_issue( $issue );
		$this->quote = QuoteStoredRow::from_row( array_replace( S::quote( state: 'accepted' )->row(), [ 'quote_uuid' => $id->value(), 'format_version' => 3, 'profile_code' => $header->profile(), 'material_digest' => $header->material_digest(), 'body_digest' => $header->body_digest(), 'header_json' => $header->to_private_json(), 'private_body_json' => QuoteJson::encode( [ 'context' => $p->context()->private_facts(), 'terms' => $p->terms()->private_facts() ] ), 'issue_namespace_hash' => $header->namespace_hashes()['issue'], 'accept_namespace_hash' => $header->namespace_hashes()['accept'], 'invalidate_namespace_hash' => $header->namespace_hashes()['invalidate'], 'created_at' => $header->created_at()->sql(), 'expires_at' => $header->expires_at()->sql(), 'accepted_at' => Q::time()->plus_seconds( 2 )->sql(), 'transition_at' => Q::time()->plus_seconds( 2 )->sql() ] ) );
		$this->placement = QuoteId::from_string( '20000000-0000-4000-8000-000000000013' );
		$this->command = FixedPromotionQuoteBindCommand::create( $this->acceptance, $this->quote, 700, $this->placement, S::binding( $this->quote )->mapping(), Q::digest( 'native order evidence' ) );
		$this->binding = QuoteBinding::from_row( array_replace( $this->command->binding()->row(), [ 'id' => 17, 'created_at' => Q::time()->plus_seconds( 3 )->sql() ] ), $this->quote );
		foreach ( [ 'issue', 'accept', 'bind' ] as $purpose ) { $this->receipt( $purpose ); }
	}
	public function receipt( string $purpose, array $result_changes = [], array $row_changes = [], ?QuoteTime $event_at = null ): OperationRecord {
		$command = match ( $purpose ) { 'issue' => $this->acceptance->issue(), 'accept' => $this->acceptance, default => $this->command };
		$id = match ( $purpose ) { 'issue' => 1, 'accept' => 2, default => 3 }; $effect = Q::time()->plus_seconds( $id ); $identity = $command->identity;
		$profile = QuoteOperationProfile::registry()->get( 'delivery_quote.' . $purpose, 'issue' === $purpose ? 2 : 1 );
		$extra = 'issue' === $purpose ? [ 'allocation_digest' => $this->acceptance->allocation_digest(), 'allocation_count' => count( $this->acceptance->allocation_set()->requests() ), 'hold_until' => $this->acceptance->hold_until()->epoch_microseconds() ] : ( 'bind' === $purpose ? [ 'binding_id' => $this->binding->id(), 'placement_id' => $this->placement->value(), 'order_id' => 700, 'binding_revision' => 1, 'manifest_digest' => $this->binding->row()['managed_group_manifest_digest'] ] : [] );
		$result = array_replace( [ 'quote_id' => $this->quote->header()->id()->value(), 'quote_revision' => 'issue' === $purpose ? 1 : 2, 'state' => match ( $purpose ) { 'issue' => 'issued', 'accept' => 'accepted', default => 'prepared' }, 'body_digest' => $this->quote->header()->body_digest(), 'owner_digest' => $this->acceptance->owner()->digest(), 'namespace_hash' => $identity->namespace_digest(), 'completed_at' => $effect->epoch_microseconds(), 'control_revision' => 1, ...$extra ], $result_changes );
		$site = $row_changes['site_id'] ?? 1; $effect = QuoteTime::from_epoch_microseconds( $result['completed_at'] );
		$completion = OperationCompletion::accepted( $profile, $result, [ 'site_id' => $site, 'owner_digest' => $result['owner_digest'], 'quote_id' => $result['quote_id'], 'purpose' => 'private_quote_invalidation' ] );
		$target = array_intersect_key( $result, array_flip( [ 'quote_id', 'body_digest', 'owner_digest', 'namespace_hash', 'quote_revision', 'allocation_digest', 'allocation_count', 'hold_until' ] ) ) + [ 'binding_revision' => 'bind' === $purpose ? 1 : 0 ];
		$event = OperationMaterialEvent::from_mutation( $profile, $identity, RequestContext::create(), [ 'authority_hash' => hash( 'sha256', $identity->authority ), 'principal_hash' => hash( 'sha256', $identity->principal ) ], $target, match ( $purpose ) { 'issue' => 'quote_issued', 'accept' => 'quote_accepted', default => 'quote_bound' }, 1, 2, match ( $purpose ) { 'issue' => [ 'quote', 'admission', 'promotion_budget' ], 'accept' => [ 'quote' ], default => [ 'binding' ] } );
		$row = array_replace( [ 'id' => $id, 'site_id' => $site, 'namespace_hash' => $identity->namespace_digest(), 'intent_hash' => $command->intent()->fingerprint(), 'namespace_format' => 1, 'intent_format' => 1, 'record_format' => 1, 'operation' => 'delivery_quote.' . $purpose, 'operation_version' => 'issue' === $purpose ? 2 : 1, 'target_hash' => hash( 'sha256', 'cetech-operation-target-v1:' . $identity->target_key ), 'state' => 'accepted', 'publication_state' => 'published', 'completion_json' => $completion->to_json(), 'audit_id' => $id, 'row_version' => 2, 'created_at' => Q::time()->sql(), 'updated_at' => $effect->plus_seconds( 1 )->sql(), 'completed_at' => $effect->plus_seconds( 1 )->sql() ], $row_changes );
		$this->rows[$purpose] = $row; $this->events[$purpose] = [ 'id' => $id, 'site_id' => $site, 'operation_id' => $id, 'event_format' => 1, 'event_json' => $event->to_json(), 'created_at' => ( $event_at ?? $effect )->sql() ];
		return OperationRecord::from_row( $row, $profile, $this->events[$purpose] );
	}
	public function records(): array {
		$out = [ 'event_persisted_at' => [] ];
		foreach ( [ 'issue', 'accept', 'bind' ] as $purpose ) { $out[$purpose] = OperationRecord::from_row( $this->rows[$purpose], QuoteOperationProfile::registry()->get( 'delivery_quote.' . $purpose, 'issue' === $purpose ? 2 : 1 ), $this->events[$purpose] ); $out['event_persisted_at'][$purpose] = QuoteTime::parse( $this->events[$purpose]['created_at'] ); }
		return $out;
	}
}

/** Finite already-owned transport: literal strict rows only, with no clock or mutation surface. */
final class PromotionBindReceiptSession implements OperationSession {
	public array $queries = []; public array $namespaces = []; public int $writes = 0;
	public function __construct( private PromotionBindReceiptFixture $f ) {}
	public function site_id(): int { return 1; } public function table_prefix(): string { return 'receipt_'; } public function charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
	public function is_retired(): bool { return false; } public function in_transaction(): bool { return true; }
	public function begin(): bool { throw new \LogicException( 'The collector never starts a transaction.' ); } public function rollback(): bool { throw new \LogicException( 'The collector never rolls back.' ); } public function retire(): bool { throw new \LogicException( 'The collector never retires the caller.' ); } public function commit(): OperationCommitResult { throw new \LogicException( 'The collector never commits.' ); }
	public function validate_tables( array $tables ): bool { return true; } public function errno(): int { return 0; } public function insert_id(): int { return 0; }
	public function query( string $sql ): int|false { ++$this->writes; throw new \LogicException( 'The collector cannot mutate.' ); } public function get_row( string $sql ): array|null|false { throw new \LogicException( 'The collector cannot read a clock.' ); }
	public function prepare( string $sql, mixed ...$args ): string { return preg_replace_callback( '/%[ds]/', static function( array $m ) use ( &$args ): string { $v = array_shift( $args ); return '%d' === $m[0] ? (string) $v : "'" . str_replace( "'", "''", (string) $v ) . "'"; }, $sql ); }
	public function get_results( string $sql ): array|false {
		$this->queries[] = $sql;
		if ( str_contains( $sql, 'operation_records' ) && preg_match( "/namespace_hash='([0-9a-f]{64})'/", $sql, $m ) ) { $this->namespaces[] = $m[1]; foreach ( $this->f->rows as $row ) { if ( $row['namespace_hash'] === $m[1] ) { return [ $row + [ 'oversized' => 0 ] ]; } } return []; }
		if ( str_contains( $sql, 'operation_changes' ) && preg_match( '/operation_id=([0-9]+)/', $sql, $m ) ) { foreach ( $this->f->events as $event ) { if ( $event['operation_id'] === (int) $m[1] ) { return [ $event + [ 'oversized' => 0 ] ]; } } return []; }
		throw new \LogicException( 'Unexpected receipt SQL.' );
	}
}
