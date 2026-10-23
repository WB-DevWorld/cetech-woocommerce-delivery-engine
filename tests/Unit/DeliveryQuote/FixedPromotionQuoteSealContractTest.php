<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteSealCommand, FixedPromotionQuoteSealResult, FixedPromotionQuoteVerifyCommand, QuoteOperationProfile};
use CetechDeliveryEngine\Domain\Contracts\{ContractError, OperationOutcome, RequestContext};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteId};
use CetechDeliveryEngine\Domain\Operation\{OperationAttemptResult, OperationCompletion};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixedPromotionQuoteVerifyContractTest.php';

/** Pure private shape/recovery only; no current proof, receipt or payment authority. */
final class FixedPromotionQuoteSealContractTest extends TestCase {
	public function test_existing_seal_v2_identity_and_original_verified_membership_are_retained(): void {
		[ $v, $verified ] = PromotionSealContractFixture::facts(); $before = [ $v->to_recovery_array(), $verified->row() ]; $c = FixedPromotionQuoteSealCommand::create( $v, $verified, 7 );
		self::assertSame( [ 'delivery_quote.seal', 2, 'placement:' . $verified->row()['placement_uuid'] ], [ $c->identity->operation, $c->identity->operation_version, $c->identity->target_key ] );
		self::assertSame( $verified->row()['seal_namespace_hash'], $c->identity->namespace_digest() ); self::assertSame( 'retain_original_hold_v1', $c::FUNDING_POLICY ); self::assertSame( 7, $c->control_revision() );
		self::assertSame( $v, $c->verification() ); self::assertSame( $verified, $c->verified_binding() ); self::assertSame( $c, $c->as_recovery() );
		self::assertSame( $v->owner(), $c->owner() ); self::assertSame( $v->header(), $c->header() ); self::assertSame( $v->context(), $c->context() ); self::assertSame( $v->terms(), $c->terms() ); self::assertSame( $v->reference(), $c->reference() );
		self::assertSame( $v->account_slots(), $c->account_slots() ); self::assertSame( $v->allocation_set(), $c->allocation_set() ); self::assertSame( $v->allocation_digest(), $c->allocation_digest() ); self::assertSame( $v->hold_until(), $c->hold_until() ); self::assertSame( $v->snapshot_digest(), $c->snapshot_digest() ); self::assertSame( $v->context_digest(), $c->context_digest() );
		self::assertSame( array_replace( $verified->row(), [ 'state' => 'sealed', 'revision' => 3, 'sealed_at' => $verified->row()['verified_at'] ] ), $c->binding()->row() ); self::assertSame( $before, [ $v->to_recovery_array(), $verified->row() ] );
	}

	public static function changed_intents(): array { return array_map( static fn( string $v ): array => [ $v ], [ 'control', 'verified time', 'physical id', 'created time', 'snapshot', 'context' ] ); }
	#[DataProvider( 'changed_intents' )]
	public function test_each_original_membership_and_control_change_conflicts_with_the_same_namespace( string $case ): void {
		[ $v, $b ] = PromotionSealContractFixture::facts(); $a = FixedPromotionQuoteSealCommand::create( $v, $b, 1 ); $control = 1;
		if ( 'control' === $case ) { $control = 2; }
		elseif ( 'verified time' === $case ) { $b = QuoteBinding::from_row( array_replace( $b->row(), [ 'verified_at' => Q::time()->plus_seconds( 4 )->sql() ] ), $v->binding_command()->quote_record() ); }
		else {
			$p = $v->prepared_binding()->row(); $snapshot = $v->snapshot_digest(); $context = $v->context_digest();
			if ( 'physical id' === $case ) { ++$p['id']; } elseif ( 'created time' === $case ) { $p['created_at'] = Q::time()->plus_seconds( 1 )->sql(); } elseif ( 'snapshot' === $case ) { $snapshot = Q::digest( 'another snapshot' ); } else { $context = Q::digest( 'another context' ); }
			$v = FixedPromotionQuoteVerifyCommand::create( $v->binding_command(), QuoteBinding::from_row( $p, $v->binding_command()->quote_record() ), $snapshot, $context ); [ , $b ] = PromotionVerifyContractFixture::acknowledged( $v );
		}
		$c = FixedPromotionQuoteSealCommand::create( $v, $b, $control ); self::assertSame( $a->identity->namespace_digest(), $c->identity->namespace_digest() ); self::assertFalse( $a->intent()->equals( $c->intent() ) );
	}

	public static function invalid_verified(): array { return array_map( static fn( string $v ): array => [ $v ], [ 'unverified', 'sealed', 'order', 'placement', 'physical id', 'created time', 'bind namespace', 'seal namespace', 'foreign site', 'snapshot', 'context', 'native money', 'verified before created', 'verified at hold', 'verified at expiry', 'zero control', 'negative control' ] ); }
	#[DataProvider( 'invalid_verified' )]
	public function test_only_exact_verified_physical_membership_can_be_captured( string $case ): void {
		[ $v, $b ] = PromotionSealContractFixture::facts(); $q = $v->binding_command()->quote_record(); $r = $b->row(); $control = 1; $this->expectException( \InvalidArgumentException::class );
		if ( 'unverified' === $case ) { $r = $v->prepared_binding()->row(); } elseif ( 'sealed' === $case ) { $r = array_replace( $r, [ 'state' => 'sealed', 'revision' => 3, 'sealed_at' => $r['verified_at'] ] ); }
		elseif ( 'order' === $case ) { ++$r['order_id']; } elseif ( 'placement' === $case ) { $r['placement_uuid'] = QuoteId::generate()->value(); } elseif ( 'physical id' === $case ) { ++$r['id']; } elseif ( 'created time' === $case ) { $r['created_at'] = Q::time()->plus_seconds( 1 )->sql(); }
		elseif ( 'bind namespace' === $case ) { $r['bind_namespace_hash'] = Q::digest( 'foreign bind' ); } elseif ( 'seal namespace' === $case ) { $r['seal_namespace_hash'] = Q::digest( 'foreign seal' ); } elseif ( 'foreign site' === $case ) { $r['site_id'] = 99; }
		elseif ( 'snapshot' === $case ) { $r['snapshot_digest'] = Q::digest( 'foreign snapshot' ); } elseif ( 'context' === $case ) { $r['context_digest'] = Q::digest( 'foreign context' ); } elseif ( 'native money' === $case ) { $r['native_money_digest'] = Q::digest( 'foreign money' ); }
		elseif ( 'verified before created' === $case ) { $r['verified_at'] = Q::time()->plus_seconds( 1 )->sql(); } elseif ( 'verified at hold' === $case ) { $r['verified_at'] = $v->hold_until()->sql(); } elseif ( 'verified at expiry' === $case ) { $r['verified_at'] = $v->header()->expires_at()->sql(); } else { $control = 'zero control' === $case ? 0 : -1; }
		FixedPromotionQuoteSealCommand::create( $v, QuoteBinding::from_row( $r, $q ), $control );
	}

	public function test_private_recovery_is_exact_and_contains_no_live_capabilities(): void {
		$c = PromotionSealContractFixture::command(); $p = $c->to_recovery_array(); $copy = FixedPromotionQuoteSealCommand::from_recovery_array( json_decode( json_encode( $p, JSON_THROW_ON_ERROR ), true, 40, JSON_THROW_ON_ERROR ) );
		self::assertSame( $p, $copy->to_recovery_array() ); self::assertSame( $c->intent()->fingerprint(), $copy->intent()->fingerprint() ); self::assertSame( $c->identity->namespace_digest(), $copy->identity->namespace_digest() ); self::assertSame( $c->binding()->row(), $copy->binding()->row() ); self::assertNull( $copy->verification()->binding_command()->acceptance()->issue()->lease() ); self::assertLessThanOrEqual( $c::MAX_RECOVERY_BYTES, strlen( json_encode( $p, JSON_THROW_ON_ERROR ) ) );
	}

	public static function invalid_recovery(): array { return array_map( static fn( string $v ): array => [ $v ], [ 'extra policy', 'missing', 'version', 'verification', 'verified row', 'numeric physical id', 'control string', 'control float', 'noncanonical time', 'unverified', 'sealed', 'oversized', 'oversized key', 'object', 'depth', 'nodes', 'cycle' ] ); }
	#[DataProvider( 'invalid_recovery' )]
	public function test_closed_recovery_refuses_invalid_or_unbounded_graphs_before_object_execution( string $case ): void {
		$c = PromotionSealContractFixture::command(); $p = $c->to_recovery_array(); $probe = new PromotionBindRecoveryProbe();
		if ( 'extra policy' === $case ) { $p['funding_policy'] = 'commit'; } elseif ( 'missing' === $case ) { unset( $p['control_revision'] ); } elseif ( 'version' === $case ) { $p['format_version'] = '1'; } elseif ( 'verification' === $case ) { $p['verification'] = null; } elseif ( 'verified row' === $case ) { $p['verified_binding'] = null; }
		elseif ( 'numeric physical id' === $case ) { $p['verified_binding']['id'] = '17'; } elseif ( 'control string' === $case ) { $p['control_revision'] = '1'; } elseif ( 'control float' === $case ) { $p['control_revision'] = 1.0; } elseif ( 'noncanonical time' === $case ) { $p['verified_binding']['verified_at'] = '2026-01-01'; }
		elseif ( 'unverified' === $case ) { $p['verified_binding'] = $c->verification()->prepared_binding()->row(); } elseif ( 'sealed' === $case ) { $p['verified_binding'] = $c->binding()->row(); } elseif ( 'oversized' === $case ) { $p['control_revision'] = str_repeat( 'x', $c::MAX_RECOVERY_BYTES + 1 ); } elseif ( 'oversized key' === $case ) { $p[str_repeat( 'x', $c::MAX_RECOVERY_BYTES + 1 )] = 1; }
		elseif ( 'object' === $case ) { $p['control_revision'] = $probe; } elseif ( 'depth' === $case ) { $deep = []; for ( $i = 0; $i < 40; ++$i ) { $deep = [ $deep ]; } $p['verification'] = $deep; } elseif ( 'nodes' === $case ) { $p['verified_binding'] = array_fill( 0, 10001, 0 ); } else { $cycle = []; $cycle[] =& $cycle; $p['verified_binding'] = $cycle; }
		try { FixedPromotionQuoteSealCommand::from_recovery_array( $p ); self::fail( 'Invalid recovery must refuse.' ); } catch ( \InvalidArgumentException ) { self::assertSame( 0, $probe->calls ); }
	}

	public function test_recovery_detaches_caller_array_references(): void {
		$p = PromotionSealContractFixture::command()->to_recovery_array(); $control =& $p['control_revision']; $c = FixedPromotionQuoteSealCommand::from_recovery_array( $p ); $control = 9; self::assertSame( 1, $c->control_revision() ); $copy = $c->to_recovery_array(); $copy['verified_binding']['id'] = 999; self::assertNotSame( $copy, $c->to_recovery_array() );
	}

	public function test_private_exports_and_debug_reveal_no_original_order_or_quote(): void { $c = PromotionSealContractFixture::command(); $this->assert_private( $c, [ $c->reference()->handle(), $c->snapshot_digest(), $c->owner()->digest() ] ); }

	public function test_result_accepts_only_exact_acknowledged_sealed_membership_and_effect_time(): void {
		$c = PromotionSealContractFixture::command(); [ $q, $b, $completion ] = PromotionSealContractFixture::acknowledged( $c ); $attempt = new OperationAttemptResult( OperationOutcome::accepted(), $completion ); $r = new FixedPromotionQuoteSealResult( $attempt, $c, $q, $b );
		self::assertSame( $b, $r->binding ); self::assertSame( $q, $r->quote ); self::assertSame( $c, $r->command ); self::assertSame( $attempt, $r->attempt ); self::assertNotSame( $c->binding()->row()['sealed_at'], $b->row()['sealed_at'] ); $this->assert_private( $r, [ $c->reference()->handle(), $c->owner()->digest() ] );
	}

	public function test_pending_publication_retains_acknowledged_facts_but_unknown_retains_none(): void {
		$c = PromotionSealContractFixture::command(); [ $q, $b, $completion ] = PromotionSealContractFixture::acknowledged( $c ); $error = new ContractError( 'outcome_unknown', RequestContext::create(), 'reconcile_original_request' );
		$r = new FixedPromotionQuoteSealResult( new OperationAttemptResult( OperationOutcome::awaiting_publication( $error ), $completion ), $c, $q, $b ); self::assertSame( $b, $r->binding ); self::assertTrue( $r->attempt->outcome->publication_pending );
		$r = new FixedPromotionQuoteSealResult( new OperationAttemptResult( OperationOutcome::unconfirmed( $error ) ), $c ); self::assertNull( $r->binding ); self::assertNull( $r->quote ); self::assertNull( $r->attempt->completion );
	}

	public static function invalid_results(): array { return array_map( static fn( string $v ): array => [ $v ], [ 'command', 'quote', 'binding', 'prepared', 'physical id', 'order', 'snapshot', 'context', 'verified time', 'effect before verified', 'effect at hold', 'completion namespace', 'completion time', 'completion snapshot', 'completion native money', 'completion control' ] ); }
	#[DataProvider( 'invalid_results' )]
	public function test_result_refuses_partial_foreign_or_inconsistent_physical_facts( string $case ): void {
		$c = PromotionSealContractFixture::command(); [ $q, $b, $completion ] = PromotionSealContractFixture::acknowledged( $c ); $command = $c; $this->expectException( \InvalidArgumentException::class );
		if ( 'command' === $case ) { $command = null; } elseif ( 'quote' === $case ) { $q = null; } elseif ( 'binding' === $case ) { $b = null; } elseif ( 'prepared' === $case ) { $b = $c->verified_binding(); }
		elseif ( str_starts_with( $case, 'completion ' ) ) { $r = $completion->result; $field = match ( $case ) { 'completion namespace' => 'namespace_hash', 'completion time' => 'completed_at', 'completion snapshot' => 'snapshot_digest', 'completion control' => 'control_revision', default => 'native_money_digest' }; $r[$field] = in_array( $field, [ 'completed_at', 'control_revision' ], true ) ? $r[$field] + 1 : Q::digest( 'foreign completion field' ); $completion = OperationCompletion::accepted( new QuoteOperationProfile( 'delivery_quote.seal', profile_version: 2 ), $r, $completion->publication ); }
		else { $r = $b->row(); if ( 'physical id' === $case ) { ++$r['id']; } elseif ( 'order' === $case ) { ++$r['order_id']; } elseif ( 'snapshot' === $case ) { $r['snapshot_digest'] = Q::digest( 'foreign snapshot' ); } elseif ( 'context' === $case ) { $r['context_digest'] = Q::digest( 'foreign context' ); } elseif ( 'verified time' === $case ) { $r['verified_at'] = Q::time()->plus_seconds( 2 )->sql(); } elseif ( 'effect before verified' === $case ) { $r['sealed_at'] = Q::time()->plus_seconds( 2 )->sql(); } else { $r['sealed_at'] = $c->hold_until()->sql(); } $b = QuoteBinding::from_row( $r, $q ); }
		new FixedPromotionQuoteSealResult( new OperationAttemptResult( OperationOutcome::accepted(), $completion ), $command, $q, $b );
	}

	public function test_unknown_cannot_claim_physical_facts_without_an_acknowledged_completion(): void {
		$c = PromotionSealContractFixture::command(); [ $q, $b ] = PromotionSealContractFixture::acknowledged( $c ); $attempt = new OperationAttemptResult( OperationOutcome::unconfirmed( new ContractError( 'outcome_unknown', RequestContext::create(), 'reconcile_original_request' ) ) ); $this->expectException( \InvalidArgumentException::class ); new FixedPromotionQuoteSealResult( $attempt, $c, $q, $b );
	}

	private function assert_private( object $object, array $secrets ): void {
		foreach ( [ static fn() => json_encode( $object, JSON_THROW_ON_ERROR ), static fn() => serialize( $object ), static fn() => $object->__unserialize( [] ) ] as $attempt ) { try { $attempt(); self::fail( 'Private seal values cannot export or hydrate implicitly.' ); } catch ( \LogicException $e ) { foreach ( $secrets as $secret ) { self::assertStringNotContainsString( $secret, $e->getMessage() ); } } }
		ob_start(); var_dump( $object ); $debug = ob_get_clean(); self::assertStringContainsString( 'redacted', $debug ); foreach ( $secrets as $secret ) { self::assertStringNotContainsString( $secret, $debug ); }
	}
}

/** Synthetic authentic-shaped history; physical receipt/current authority remains unproved. */
final class PromotionSealContractFixture {
	public static function facts(): array { $v = PromotionVerifyContractFixture::command(); [ , $b ] = PromotionVerifyContractFixture::acknowledged( $v ); return [ $v, $b ]; }
	public static function command(): FixedPromotionQuoteSealCommand { [ $v, $b ] = self::facts(); return FixedPromotionQuoteSealCommand::create( $v, $b, 1 ); }
	public static function acknowledged( FixedPromotionQuoteSealCommand $c ): array {
		$q = $c->verification()->binding_command()->quote_record(); $at = Q::time()->plus_seconds( 4 ); $b = QuoteBinding::from_row( array_replace( $c->binding()->row(), [ 'sealed_at' => $at->sql() ] ), $q ); $r = $b->row();
		$result = [ 'quote_id' => $c->header()->id()->value(), 'quote_revision' => 2, 'state' => 'sealed', 'body_digest' => $c->header()->body_digest(), 'owner_digest' => $c->owner()->digest(), 'namespace_hash' => $c->identity->namespace_digest(), 'completed_at' => $at->epoch_microseconds(), 'control_revision' => $c->control_revision(), 'binding_id' => $b->id(), 'placement_id' => $r['placement_uuid'], 'order_id' => $r['order_id'], 'binding_revision' => 3, 'manifest_digest' => $r['managed_group_manifest_digest'], 'snapshot_digest' => $c->snapshot_digest(), 'context_digest' => $c->context_digest(), 'native_money_digest' => $r['native_money_digest'] ];
		$completion = OperationCompletion::accepted( new QuoteOperationProfile( 'delivery_quote.seal', profile_version: 2 ), $result, [ 'site_id' => $c->owner()->site_id(), 'owner_digest' => $c->owner()->digest(), 'quote_id' => $c->header()->id()->value(), 'purpose' => 'private_quote_invalidation' ] ); return [ $q, $b, $completion ];
	}
}
