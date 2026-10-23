<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptCommand, FixedPromotionQuoteIssueCommand, FixedPromotionQuotePlacementEvidence, FixedPromotionQuotePlacementReadResult, QuoteCurrentEvidenceGuard, QuoteCurrentEvidenceValidity, QuoteIssueCommand};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteId, QuoteJson, QuoteOwner, QuoteReference, QuoteStoredRow, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteFixtures as Q, QuoteStorageFixtures};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryCommercial/FixedPromotionQuoteFixture.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteStorageFixtures.php';

/** Pure structural observations only. Synthetic capture/history confer no current funding authority. */
final class FixedPromotionQuotePlacementCarriersTest extends TestCase {

	private function fixture(): array {
		$p = F::mixed_provider(); $slots = [ [ 'component_key' => F::key(), 'account_key' => Q::digest( 'private placement pool' ) ], [ 'component_key' => F::second_key(), 'account_key' => null ] ];
		$original = QuoteIssueCommand::fixed_promotion( F::owner(), $p->context(), '71eb41b0-d08d-4a25-9808-6ad03e6aa002', $slots, Q::time()->plus_seconds( 45 ) );
		$id = QuoteId::generate(); $ref = QuoteReference::generate( $id ); $h = QuoteHeader::issue( $id, F::owner(), $p->context(), $p->terms(), Q::time(), $original->namespace_hashes( $id ), 'fixed_promotion_native_v1', 1, $ref );
		$issue = FixedPromotionQuoteIssueCommand::from_recovery_array( [ 'format_version' => 1, 'original_issue' => $original->to_promotion_recovery_array(), 'header_json' => $h->to_private_json(), 'terms_json' => $p->terms()->to_private_json(), 'reference' => $ref->public_fields() ] );
		$c = FixedPromotionQuoteAcceptCommand::from_issue( $issue ); $at = Q::time()->plus_seconds( 1 )->sql();
		$row = QuoteStoredRow::from_row( [ 'id' => 1, 'site_id' => 1, 'quote_uuid' => $h->id()->value(), 'format_version' => 3, 'profile_code' => $h->profile(), 'profile_version' => 1, 'purpose' => 'checkout', 'principal_hash' => $c->owner()->facts()['principal_hash'], 'owner_digest' => $c->owner()->digest(), 'material_digest' => $h->material_digest(), 'body_digest' => $h->body_digest(), 'header_json' => $h->to_private_json(), 'private_body_json' => QuoteJson::encode( [ 'context' => $c->context()->private_facts(), 'terms' => $c->terms()->private_facts() ] ), 'issue_namespace_hash' => $h->namespace_hashes()['issue'], 'accept_namespace_hash' => $h->namespace_hashes()['accept'], 'invalidate_namespace_hash' => $h->namespace_hashes()['invalidate'], 'state' => 'accepted', 'revision' => 2, 'retention_revision' => 1, 'created_at' => $h->created_at()->sql(), 'expires_at' => $h->expires_at()->sql(), 'accepted_at' => $at, 'transition_at' => $at ] );
		$guard = new PromotionPlacementCarrierPoisonGuard();
		return [ $c, $row, QuoteCurrentEvidenceValidity::capture( Q::time(), Q::time()->plus_seconds( 30 ) ), $guard ];
	}
	private function evidence(): FixedPromotionQuotePlacementEvidence { [ $c, $row, $validity, $guard ] = $this->fixture(); return new FixedPromotionQuotePlacementEvidence( $c, $row, Q::time()->plus_seconds( 2 ), $validity, $guard ); }

	public function test_complete_observations_preserve_original_private_facts_without_using_any_port(): void {
		[ $c, $row, $validity, $guard ] = $this->fixture(); $before = [ $row->row(), $c->to_recovery_array() ]; $at = Q::time()->plus_seconds( 2 );
		$e = new FixedPromotionQuotePlacementEvidence( $c, $row, $at, $validity, $guard );
		self::assertSame( $c->owner(), $e->owner() ); self::assertSame( $c->reference(), $e->reference() ); self::assertSame( $row->header(), $e->header() ); self::assertSame( $row->context(), $e->context() ); self::assertSame( $row->terms(), $e->terms() );
		self::assertSame( $row, $e->quote_record() ); self::assertSame( $row->accepted_at()->sql(), $e->accepted_at()->sql() ); self::assertSame( $at, $e->evaluated_at() ); self::assertSame( $validity, $e->validity() ); self::assertSame( $guard, $e->guard() );
		self::assertSame( $before, [ $row->row(), $c->to_recovery_array() ] ); self::assertSame( 0, $guard->calls ); self::assertSame( $c->terms()->display_money(), $e->terms()->display_money() );
	}
	public static function time_edges(): array { return [ 'before accepted' => [ 'before', false ], 'at accepted' => [ 'accepted', true ], 'last source microsecond' => [ 'last source', true ], 'source expiry' => [ 'source expiry', false ], 'hold expiry' => [ 'hold', false ], 'quote expiry' => [ 'quote', false ] ]; }
	#[DataProvider( 'time_edges' )]
	public function test_observation_time_must_cover_acceptance_and_every_exclusive_fence( string $edge, bool $allowed ): void {
		[ $c, $row, $validity, $guard ] = $this->fixture();
		$at = match ( $edge ) { 'before' => Q::time(), 'accepted' => $row->accepted_at(), 'last source' => QuoteTime::from_epoch_microseconds( Q::time()->plus_seconds( 30 )->epoch_microseconds() - 1 ), 'source expiry' => Q::time()->plus_seconds( 30 ), 'hold' => $c->hold_until(), default => $c->header()->expires_at() };
		// For the hold/header cases, current source validity deliberately still covers the event.
		if ( in_array( $edge, [ 'hold', 'quote' ], true ) ) { $validity = QuoteCurrentEvidenceValidity::capture( Q::time(), $c->header()->expires_at()->plus_seconds( 1 ) ); }
		if ( ! $allowed ) { $this->expectException( \InvalidArgumentException::class ); }
		$e = new FixedPromotionQuotePlacementEvidence( $c, $row, $at, $validity, $guard );
		self::assertSame( $at, $e->evaluated_at() ); self::assertSame( 0, $guard->calls );
	}
	public function test_an_observation_before_the_source_was_observed_is_refused(): void {
		[ $c, $row, , $guard ] = $this->fixture(); $validity = QuoteCurrentEvidenceValidity::capture( Q::time()->plus_seconds( 3 ), Q::time()->plus_seconds( 30 ) );
		$this->expectException( \InvalidArgumentException::class ); new FixedPromotionQuotePlacementEvidence( $c, $row, Q::time()->plus_seconds( 2 ), $validity, $guard );
	}
	public static function wrong_rows(): array { return [ [ 'issued' ], [ 'foreign accepted' ], [ 'legacy accepted' ] ]; }
	#[DataProvider( 'wrong_rows' )]
	public function test_only_exact_accepted_format_three_facts_can_be_carried( string $case ): void {
		[ $c, $row, $validity, $guard ] = $this->fixture();
		if ( 'issued' === $case ) { $row = QuoteStoredRow::from_row( array_replace( $row->row(), [ 'state' => 'issued', 'revision' => 1, 'accepted_at' => null, 'transition_at' => null ] ) ); }
		elseif ( 'foreign accepted' === $case ) { [ , $row ] = $this->fixture(); }
		else { $row = QuoteStorageFixtures::quote( state: 'accepted' ); }
		$this->expectException( \InvalidArgumentException::class ); new FixedPromotionQuotePlacementEvidence( $c, $row, Q::time()->plus_seconds( 2 ), $validity, $guard );
	}
	public function test_read_result_preserves_ready_observations_and_clean_unavailability(): void {
		$e = $this->evidence(); $ready = new FixedPromotionQuotePlacementReadResult( 'ready', null, $e ); self::assertSame( 'ready', $ready->status ); self::assertNull( $ready->reason ); self::assertSame( $e, $ready->evidence );
		foreach ( [ null, 'temporarily_unavailable', 'not_authorized' ] as $reason ) { $unavailable = new FixedPromotionQuotePlacementReadResult( 'unavailable', $reason ); self::assertSame( 'unavailable', $unavailable->status ); self::assertSame( $reason, $unavailable->reason ); self::assertNull( $unavailable->evidence ); }
	}
	public static function invalid_results(): array { return [ [ 'unknown status' ], [ 'ready missing evidence' ], [ 'ready with reason' ], [ 'unavailable with evidence' ], [ 'private free text' ], [ 'unbounded reason' ] ]; }
	#[DataProvider( 'invalid_results' )]
	public function test_inconsistent_or_unbounded_result_dispositions_refuse( string $case ): void {
		$e = $this->evidence(); $status = 'unavailable'; $reason = null; $evidence = null;
		if ( 'unknown status' === $case ) { $status = 'accepted'; }
		elseif ( 'ready missing evidence' === $case ) { $status = 'ready'; }
		elseif ( 'ready with reason' === $case ) { $status = 'ready'; $reason = 'temporarily_unavailable'; $evidence = $e; }
		elseif ( 'unavailable with evidence' === $case ) { $evidence = $e; }
		elseif ( 'private free text' === $case ) { $reason = 'private account 123'; }
		else { $reason = str_repeat( 'x', 65 ); }
		$this->expectException( \InvalidArgumentException::class ); new FixedPromotionQuotePlacementReadResult( $status, $reason, $evidence );
	}
	public static function private_objects(): array { return [ [ 'evidence' ], [ 'ready result' ], [ 'unavailable result' ] ]; }
	#[DataProvider( 'private_objects' )]
	public function test_private_observations_cannot_be_persisted_or_debugged_implicitly( string $kind ): void {
		$e = $this->evidence(); $object = match ( $kind ) { 'evidence' => $e, 'ready result' => new FixedPromotionQuotePlacementReadResult( 'ready', null, $e ), default => new FixedPromotionQuotePlacementReadResult( 'unavailable', 'temporarily_unavailable' ) };
		foreach ( [ static fn() => json_encode( $object, JSON_THROW_ON_ERROR ), static fn() => serialize( $object ), static fn() => $object->__unserialize( [] ) ] as $attempt ) { try { $attempt(); self::fail( 'Private observations exported or hydrated implicitly.' ); } catch ( \LogicException $error ) { self::assertStringNotContainsString( $e->reference()->handle(), $error->getMessage() ); } }
		ob_start(); var_dump( $object ); $debug = ob_get_clean(); self::assertStringContainsString( 'redacted', $debug ); self::assertStringNotContainsString( $e->reference()->handle(), $debug ); self::assertStringNotContainsString( Q::digest( 'private placement pool' ), $debug ); self::assertStringNotContainsString( 'private_fixed_funder', $debug );
	}
}

final class PromotionPlacementCarrierPoisonGuard implements QuoteCurrentEvidenceGuard {
	public int $calls = 0;
	public function tables( OperationSession $session ): array { ++$this->calls; throw new \LogicException( 'Carrier construction cannot perform a current read.' ); }
	public function verify( OperationSession $session, QuoteOwner $owner, QuoteContext $context ): bool { ++$this->calls; throw new \LogicException( 'Carrier access cannot grant current authority.' ); }
}
