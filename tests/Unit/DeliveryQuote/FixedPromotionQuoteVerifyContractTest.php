<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteBindCommand, FixedPromotionQuoteBindInputs, FixedPromotionQuoteAcceptInputs, FixedPromotionQuoteVerifyCommand, FixedPromotionQuoteVerifyInputs, FixedPromotionQuoteVerifyResult, QuoteDurableCommand, QuoteOperationProfile, QuotePlacementSavedEvidenceGuard};
use CetechDeliveryEngine\Domain\Contracts\{ContractError, OperationOutcome, RequestContext};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteId};
use CetechDeliveryEngine\Domain\Operation\{OperationAttemptResult, OperationCompletion, OperationSession};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixedPromotionQuoteBindCommandTest.php';

/** Pure bounded recovery/result contracts, not receipt, saved-order or current-budget authority. */
final class FixedPromotionQuoteVerifyContractTest extends TestCase {
	public function test_existing_verification_namespace_is_retained_but_original_binding_and_saved_proof_are_in_the_intent(): void {
		[ $bind, $prepared ] = PromotionVerifyContractFixture::facts(); $before = [ $bind->to_recovery_array(), $prepared->row() ];
		$c = FixedPromotionQuoteVerifyCommand::create( $bind, $prepared, Q::digest( 'saved snapshot' ), Q::digest( 'saved context' ) );
		$generic = QuoteDurableCommand::verify_binding( $c->owner(), $c->reference(), $c->header(), $c->binding(), new PromotionVerifySavedPoisonPort(), $c->context() );
		self::assertSame( [ 'delivery_quote.verify_binding', 1, 'placement:' . $prepared->row()['placement_uuid'] ], [ $c->identity->operation, $c->identity->operation_version, $c->identity->target_key ] );
		self::assertSame( QuoteDurableCommand::verification_namespace( $c->owner(), $c->header(), $prepared->row()['placement_uuid'] ), $c->identity->namespace_digest() );
		self::assertSame( $generic->identity->namespace_digest(), $c->identity->namespace_digest() ); self::assertFalse( $generic->intent()->equals( $c->intent() ) );
		self::assertSame( $bind, $c->binding_command() ); self::assertSame( $prepared, $c->prepared_binding() ); self::assertSame( $c, $c->as_recovery() );
		self::assertSame( $bind->owner(), $c->owner() ); self::assertSame( $bind->header(), $c->header() ); self::assertSame( $bind->context(), $c->context() ); self::assertSame( $bind->terms(), $c->terms() ); self::assertSame( $bind->reference(), $c->reference() );
		self::assertSame( $bind->account_slots(), $c->account_slots() ); self::assertSame( $bind->allocation_set(), $c->allocation_set() ); self::assertSame( $bind->allocation_digest(), $c->allocation_digest() ); self::assertSame( $bind->hold_until(), $c->hold_until() );
		self::assertSame( array_replace( $prepared->row(), [ 'revision' => 2, 'snapshot_digest' => Q::digest( 'saved snapshot' ), 'context_digest' => Q::digest( 'saved context' ), 'verified_at' => $prepared->row()['created_at'] ] ), $c->binding()->row() );
		self::assertNull( $c->binding()->row()['sealed_at'] ); self::assertSame( $before, [ $bind->to_recovery_array(), $prepared->row() ] );
	}

	public static function changed_intents(): array { return array_map( static fn( string $v ): array => [ $v ], [ 'snapshot', 'context', 'physical id', 'physical created time', 'original order evidence' ] ); }
	#[DataProvider( 'changed_intents' )]
	public function test_saved_proof_and_original_physical_membership_changes_conflict_with_the_same_namespace( string $case ): void {
		[ $bind, $prepared ] = PromotionVerifyContractFixture::facts(); $snapshot = Q::digest( 'saved snapshot' ); $context = Q::digest( 'saved context' ); $a = FixedPromotionQuoteVerifyCommand::create( $bind, $prepared, $snapshot, $context );
		if ( 'snapshot' === $case ) { $snapshot = Q::digest( 'different snapshot' ); }
		elseif ( 'context' === $case ) { $context = Q::digest( 'different context' ); }
		elseif ( 'physical id' === $case ) { $prepared = QuoteBinding::from_row( array_replace( $prepared->row(), [ 'id' => 18 ] ), $bind->quote_record() ); }
		elseif ( 'physical created time' === $case ) { $prepared = QuoteBinding::from_row( array_replace( $prepared->row(), [ 'created_at' => Q::time()->plus_seconds( 3 )->sql() ] ), $bind->quote_record() ); }
		else { $b = $bind->binding()->row(); $bind = FixedPromotionQuoteBindCommand::create( $bind->acceptance(), $bind->quote_record(), $b['order_id'], QuoteId::from_string( $b['placement_uuid'] ), $bind->binding()->mapping(), Q::digest( 'different trusted native candidate' ) ); }
		$b = FixedPromotionQuoteVerifyCommand::create( $bind, $prepared, $snapshot, $context ); self::assertSame( $a->identity->namespace_digest(), $b->identity->namespace_digest() ); self::assertFalse( $a->intent()->equals( $b->intent() ) );
	}

	public static function invalid_prepared(): array { return array_map( static fn( string $v ): array => [ $v ], [ 'verified', 'sealed', 'wrong order', 'wrong placement', 'wrong binding namespace', 'legacy seal namespace', 'foreign site', 'created at hold', 'created at quote expiry', 'created before acceptance', 'zero physical id', 'bad snapshot', 'bad context' ] ); }
	#[DataProvider( 'invalid_prepared' )]
	public function test_only_the_exact_original_unverified_binding_can_be_captured( string $case ): void {
		[ $bind, $prepared ] = PromotionVerifyContractFixture::facts(); $r = $prepared->row(); $snapshot = Q::digest( 'saved snapshot' ); $context = Q::digest( 'saved context' );
		$this->expectException( \InvalidArgumentException::class );
		if ( 'verified' === $case || 'sealed' === $case ) { $r = array_replace( $r, [ 'revision' => 2, 'snapshot_digest' => $snapshot, 'context_digest' => $context, 'verified_at' => $r['created_at'] ] ); if ( 'sealed' === $case ) { $r = array_replace( $r, [ 'state' => 'sealed', 'revision' => 3, 'sealed_at' => $r['created_at'] ] ); } }
		elseif ( 'wrong order' === $case ) { ++$r['order_id']; }
		elseif ( 'wrong placement' === $case ) { $r['placement_uuid'] = QuoteId::generate()->value(); }
		elseif ( 'wrong binding namespace' === $case ) { $r['bind_namespace_hash'] = Q::digest( 'other bind' ); }
		elseif ( 'legacy seal namespace' === $case ) { $r['seal_namespace_hash'] = QuoteDurableCommand::binding_namespaces( $bind->owner(), $bind->header(), $r['placement_uuid'] )['seal']; }
		elseif ( 'foreign site' === $case ) { $r['site_id'] = 99; }
		elseif ( 'created at hold' === $case ) { $r['created_at'] = $bind->hold_until()->sql(); }
		elseif ( 'created at quote expiry' === $case ) { $r['created_at'] = $bind->header()->expires_at()->sql(); }
		elseif ( 'created before acceptance' === $case ) { $r['created_at'] = $bind->quote_record()->accepted_at()->plus_seconds( -1 )->sql(); }
		elseif ( 'zero physical id' === $case ) { $r['id'] = 0; }
		elseif ( 'bad snapshot' === $case ) { $snapshot = str_repeat( 'A', 64 ); }
		else { $context = 'short'; }
		FixedPromotionQuoteVerifyCommand::create( $bind, QuoteBinding::from_row( $r, $bind->quote_record() ), $snapshot, $context );
	}

	public function test_recovery_is_exact_canonical_and_contains_no_live_capability(): void {
		$c = PromotionVerifyContractFixture::command(); $p = $c->to_recovery_array(); $copy = FixedPromotionQuoteVerifyCommand::from_recovery_array( json_decode( json_encode( $p, JSON_THROW_ON_ERROR ), true, 40, JSON_THROW_ON_ERROR ) );
		self::assertSame( $p, $copy->to_recovery_array() ); self::assertSame( $c->intent()->fingerprint(), $copy->intent()->fingerprint() ); self::assertSame( $c->identity->namespace_digest(), $copy->identity->namespace_digest() ); self::assertSame( $c->binding()->row(), $copy->binding()->row() );
		self::assertNull( $copy->binding_command()->acceptance()->issue()->lease() ); self::assertLessThanOrEqual( FixedPromotionQuoteVerifyCommand::MAX_RECOVERY_BYTES, strlen( json_encode( $p, JSON_THROW_ON_ERROR ) ) );
	}

	public static function invalid_recovery(): array { return array_map( static fn( string $v ): array => [ $v ], [ 'extra', 'missing', 'version', 'binding command', 'prepared', 'numeric physical id', 'noncanonical time', 'verified', 'snapshot digest', 'oversized', 'oversized key', 'object', 'depth', 'float', 'nodes', 'cycle' ] ); }
	#[DataProvider( 'invalid_recovery' )]
	public function test_closed_recovery_refuses_invalid_or_unbounded_graphs_without_executing_objects( string $case ): void {
		$c = PromotionVerifyContractFixture::command(); $p = $c->to_recovery_array(); $probe = new PromotionBindRecoveryProbe();
		if ( 'extra' === $case ) { $p['saved_port'] = []; } elseif ( 'missing' === $case ) { unset( $p['context_digest'] ); } elseif ( 'version' === $case ) { $p['format_version'] = '1'; }
		elseif ( 'binding command' === $case ) { $p['binding_command'] = null; } elseif ( 'prepared' === $case ) { $p['prepared_binding'] = null; }
		elseif ( 'numeric physical id' === $case ) { $p['prepared_binding']['id'] = '17'; }
		elseif ( 'noncanonical time' === $case ) { $p['prepared_binding']['created_at'] = '2026-01-01'; }
		elseif ( 'verified' === $case ) { $p['prepared_binding'] = $c->binding()->row(); }
		elseif ( 'snapshot digest' === $case ) { $p['snapshot_digest'] = str_repeat( 'A', 64 ); }
		elseif ( 'oversized' === $case ) { $p['snapshot_digest'] = str_repeat( 'x', FixedPromotionQuoteVerifyCommand::MAX_RECOVERY_BYTES + 1 ); }
		elseif ( 'oversized key' === $case ) { $p[str_repeat( 'x', FixedPromotionQuoteVerifyCommand::MAX_RECOVERY_BYTES + 1 )] = 1; }
		elseif ( 'object' === $case ) { $p['snapshot_digest'] = $probe; }
		elseif ( 'depth' === $case ) { $deep = []; for ( $i = 0; $i < 40; ++$i ) { $deep = [ $deep ]; } $p['binding_command'] = $deep; }
		elseif ( 'float' === $case ) { $p['prepared_binding']['id'] = 17.0; }
		elseif ( 'nodes' === $case ) { $p['prepared_binding'] = array_fill( 0, 10001, 0 ); }
		else { $cycle = []; $cycle[] =& $cycle; $p['prepared_binding'] = $cycle; }
		try { FixedPromotionQuoteVerifyCommand::from_recovery_array( $p ); self::fail( 'Invalid recovery must refuse.' ); } catch ( \InvalidArgumentException ) { self::assertSame( 0, $probe->calls ); }
	}

	public function test_recovery_detaches_external_array_references(): void {
		$p = PromotionVerifyContractFixture::command()->to_recovery_array(); $digest =& $p['snapshot_digest']; $original = $digest; $c = FixedPromotionQuoteVerifyCommand::from_recovery_array( $p ); $digest = Q::digest( 'caller changed digest' );
		self::assertSame( $original, $c->snapshot_digest() ); $copy = $c->to_recovery_array(); $copy['prepared_binding']['id'] = 999; self::assertNotSame( $copy, $c->to_recovery_array() );
	}

	public function test_inputs_retain_explicit_current_and_saved_ports_without_calling_them(): void {
		$f = new FixedPromotionCurrentFixture(); $order = new PromotionBindOrderPoisonPort(); $saved = new PromotionVerifySavedPoisonPort(); $sources = FixedPromotionQuoteAcceptInputs::from_sources( $f->snapshot, $f->native ); $current = FixedPromotionQuoteBindInputs::from_sources( $sources, $order );
		$reads = $f->native_source->reads; $inputs = FixedPromotionQuoteVerifyInputs::from_sources( $current, $saved ); self::assertSame( $current, $inputs->current() ); self::assertSame( $saved, $inputs->saved_evidence() ); self::assertSame( 0, $order->calls ); self::assertSame( 0, $saved->calls ); self::assertSame( $reads, $f->native_source->reads ); self::assertSame( [], $f->session->sql );
		$this->assert_private( $inputs, [] ); self::assertSame( 0, $saved->calls ); self::assertSame( 0, $order->calls );
	}

	public function test_private_exports_and_debug_never_expose_original_order_or_quote(): void {
		$c = PromotionVerifyContractFixture::command(); $this->assert_private( $c, [ $c->reference()->handle(), $c->snapshot_digest(), $c->owner()->digest() ] );
	}

	public function test_result_accepts_only_acknowledged_exact_verified_membership_and_effect_time(): void {
		$c = PromotionVerifyContractFixture::command(); [ $q, $b, $completion ] = PromotionVerifyContractFixture::acknowledged( $c ); $attempt = new OperationAttemptResult( OperationOutcome::accepted(), $completion );
		$r = new FixedPromotionQuoteVerifyResult( $attempt, $c, $q, $b ); self::assertSame( $b, $r->binding ); self::assertSame( $q, $r->quote ); self::assertSame( $c, $r->command ); self::assertSame( $attempt, $r->attempt );
		self::assertNotSame( $c->binding()->row()['verified_at'], $b->row()['verified_at'] ); $this->assert_private( $r, [ $c->reference()->handle(), $c->owner()->digest() ] );
	}

	public function test_pending_publication_retains_exact_acknowledged_facts_but_unknown_retains_none(): void {
		$c = PromotionVerifyContractFixture::command(); [ $q, $b, $completion ] = PromotionVerifyContractFixture::acknowledged( $c ); $error = new ContractError( 'outcome_unknown', RequestContext::create(), 'reconcile_original_request' );
		$pending = new FixedPromotionQuoteVerifyResult( new OperationAttemptResult( OperationOutcome::awaiting_publication( $error ), $completion ), $c, $q, $b ); self::assertSame( $b, $pending->binding ); self::assertTrue( $pending->attempt->outcome->publication_pending );
		$unknown = new FixedPromotionQuoteVerifyResult( new OperationAttemptResult( OperationOutcome::unconfirmed( $error ) ), $c ); self::assertNull( $unknown->quote ); self::assertNull( $unknown->binding ); self::assertNull( $unknown->attempt->completion );
	}

	public static function invalid_results(): array { return array_map( static fn( string $v ): array => [ $v ], [ 'missing command', 'missing quote', 'missing binding', 'unverified', 'sealed', 'physical id', 'created time', 'order', 'snapshot', 'context', 'effect before created', 'effect at hold', 'completion namespace', 'completion time', 'completion snapshot', 'completion native money' ] ); }
	#[DataProvider( 'invalid_results' )]
	public function test_result_refuses_partial_foreign_or_inconsistent_facts( string $case ): void {
		$c = PromotionVerifyContractFixture::command(); [ $q, $b, $completion ] = PromotionVerifyContractFixture::acknowledged( $c ); $command = $c;
		$this->expectException( \InvalidArgumentException::class );
		if ( 'missing command' === $case ) { $command = null; } elseif ( 'missing quote' === $case ) { $q = null; } elseif ( 'missing binding' === $case ) { $b = null; }
		elseif ( 'unverified' === $case ) { $b = $c->prepared_binding(); }
		elseif ( 'sealed' === $case ) { $b = QuoteBinding::from_row( array_replace( $b->row(), [ 'state' => 'sealed', 'revision' => 3, 'sealed_at' => $b->row()['verified_at'] ] ), $q ); }
		elseif ( str_starts_with( $case, 'completion ' ) ) { $r = $completion->result; $field = match ( $case ) { 'completion namespace' => 'namespace_hash', 'completion time' => 'completed_at', 'completion snapshot' => 'snapshot_digest', default => 'native_money_digest' }; $r[$field] = 'completed_at' === $field ? $r[$field] + 1 : Q::digest( 'foreign completion field' ); $completion = OperationCompletion::accepted( new QuoteOperationProfile( 'delivery_quote.verify_binding' ), $r, $completion->publication ); }
		else {
			$r = $b->row();
			if ( 'physical id' === $case ) { ++$r['id']; } elseif ( 'created time' === $case ) { $r['created_at'] = Q::time()->plus_seconds( 1 )->sql(); } elseif ( 'order' === $case ) { ++$r['order_id']; }
			elseif ( 'snapshot' === $case ) { $r['snapshot_digest'] = Q::digest( 'other saved snapshot' ); } elseif ( 'context' === $case ) { $r['context_digest'] = Q::digest( 'other saved context' ); }
			elseif ( 'effect before created' === $case ) { $r['verified_at'] = Q::time()->plus_seconds( 1 )->sql(); } else { $r['verified_at'] = $c->hold_until()->sql(); }
			$b = QuoteBinding::from_row( $r, $q );
		}
		new FixedPromotionQuoteVerifyResult( new OperationAttemptResult( OperationOutcome::accepted(), $completion ), $command, $q, $b );
	}

	public function test_unconfirmed_cannot_claim_saved_physical_facts_without_acknowledged_completion(): void {
		$c = PromotionVerifyContractFixture::command(); [ $q, $b ] = PromotionVerifyContractFixture::acknowledged( $c ); $attempt = new OperationAttemptResult( OperationOutcome::unconfirmed( new ContractError( 'outcome_unknown', RequestContext::create(), 'reconcile_original_request' ) ) );
		$this->expectException( \InvalidArgumentException::class ); new FixedPromotionQuoteVerifyResult( $attempt, $c, $q, $b );
	}

	private function assert_private( object $object, array $secrets ): void {
		foreach ( [ static fn() => json_encode( $object, JSON_THROW_ON_ERROR ), static fn() => serialize( $object ), static fn() => $object->__unserialize( [] ) ] as $attempt ) { try { $attempt(); self::fail( 'Private verification values cannot export or hydrate implicitly.' ); } catch ( \LogicException $error ) { foreach ( $secrets as $secret ) { self::assertStringNotContainsString( $secret, $error->getMessage() ); } } }
		ob_start(); var_dump( $object ); $debug = ob_get_clean(); self::assertStringContainsString( 'redacted', $debug ); foreach ( $secrets as $secret ) { self::assertStringNotContainsString( $secret, $debug ); }
	}
}

/** Synthetic accepted/bound shape; no physical receipt or current authority claim. */
final class PromotionVerifyContractFixture {
	public static function facts(): array {
		$bind = PromotionBindContractFixture::command(); $prepared = QuoteBinding::from_row( array_replace( $bind->binding()->row(), [ 'id' => 17, 'created_at' => Q::time()->plus_seconds( 2 )->sql() ] ), $bind->quote_record() ); return [ $bind, $prepared ];
	}
	public static function command(): FixedPromotionQuoteVerifyCommand { [ $bind, $prepared ] = self::facts(); return FixedPromotionQuoteVerifyCommand::create( $bind, $prepared, Q::digest( 'saved snapshot' ), Q::digest( 'saved context' ) ); }
	public static function acknowledged( FixedPromotionQuoteVerifyCommand $c ): array {
		$q = $c->binding_command()->quote_record(); $at = Q::time()->plus_seconds( 3 ); $b = QuoteBinding::from_row( array_replace( $c->binding()->row(), [ 'verified_at' => $at->sql() ] ), $q ); $row = $b->row();
		$result = [ 'quote_id' => $c->header()->id()->value(), 'quote_revision' => 2, 'state' => 'prepared', 'body_digest' => $c->header()->body_digest(), 'owner_digest' => $c->owner()->digest(), 'namespace_hash' => $c->identity->namespace_digest(), 'completed_at' => $at->epoch_microseconds(), 'control_revision' => 1, 'binding_id' => $b->id(), 'placement_id' => $row['placement_uuid'], 'order_id' => $row['order_id'], 'binding_revision' => 2, 'manifest_digest' => $row['managed_group_manifest_digest'], 'snapshot_digest' => $c->snapshot_digest(), 'context_digest' => $c->context_digest(), 'native_money_digest' => $row['native_money_digest'] ];
		$completion = OperationCompletion::accepted( new QuoteOperationProfile( 'delivery_quote.verify_binding' ), $result, [ 'site_id' => $c->owner()->site_id(), 'owner_digest' => $c->owner()->digest(), 'quote_id' => $c->header()->id()->value(), 'purpose' => 'private_quote_invalidation' ] ); return [ $q, $b, $completion ];
	}
}

final class PromotionVerifySavedPoisonPort implements QuotePlacementSavedEvidenceGuard {
	public int $calls = 0;
	public function tables( OperationSession $session ): array { ++$this->calls; throw new \LogicException( 'No saved SQL in pure contract creation.' ); }
	public function verify( OperationSession $session, QuoteBinding $binding ): bool { ++$this->calls; throw new \LogicException( 'No saved proof in pure contract creation.' ); }
}
