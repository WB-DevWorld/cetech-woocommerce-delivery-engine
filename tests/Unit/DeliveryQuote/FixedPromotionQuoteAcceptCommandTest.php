<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptCommand, FixedPromotionQuoteAcceptInputs, FixedPromotionQuoteAcceptResult, FixedPromotionQuoteIssueCommand, QuoteAdmissionAttempt, QuoteAdmissionLease, QuoteDurableCommand, QuoteIssueCommand, QuoteOperationProfile};
use CetechDeliveryEngine\Domain\Contracts\OperationOutcome;
use CetechDeliveryEngine\Domain\DeliveryQuote\{DeliveryQuote, QuoteBudgetSlot, QuoteHeader, QuoteId, QuoteJson, QuoteReference, QuoteStoredRow};
use CetechDeliveryEngine\Domain\Operation\{OperationAttemptResult, OperationCompletion};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteFixtures as Q, QuoteStorageFixtures};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixedPromotionCurrentEvidenceGuardTest.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteStorageFixtures.php';

/** Pure bounded recovery/identity contracts. Fixture facts confer no physical funding authority. */
final class FixedPromotionQuoteAcceptCommandTest extends TestCase {

	private function issue(): FixedPromotionQuoteIssueCommand {
		$p = F::mixed_provider(); $slots = [ [ 'component_key' => F::key(), 'account_key' => Q::digest( 'explicit acceptance pool' ) ], [ 'component_key' => F::second_key(), 'account_key' => null ] ];
		$original = QuoteIssueCommand::fixed_promotion( F::owner(), $p->context(), '71eb41b0-d08d-4a25-9808-6ad03e6aa001', $slots, Q::time()->plus_seconds( 45 ) );
		$id = QuoteId::generate(); $ref = QuoteReference::generate( $id ); $h = QuoteHeader::issue( $id, F::owner(), $p->context(), $p->terms(), Q::time(), $original->namespace_hashes( $id ), 'fixed_promotion_native_v1', 1, $ref );
		return FixedPromotionQuoteIssueCommand::from_recovery_array( [ 'format_version' => 1, 'original_issue' => $original->to_promotion_recovery_array(), 'header_json' => $h->to_private_json(), 'terms_json' => $p->terms()->to_private_json(), 'reference' => $ref->public_fields() ] );
	}
	private function accepted_attempt( FixedPromotionQuoteAcceptCommand $c ): OperationAttemptResult {
		$p = new QuoteOperationProfile( 'delivery_quote.accept' );
		$r = [ 'quote_id' => $c->header()->id()->value(), 'quote_revision' => 2, 'state' => 'accepted', 'body_digest' => $c->header()->body_digest(), 'owner_digest' => $c->owner()->digest(), 'namespace_hash' => $c->identity->namespace_digest(), 'completed_at' => Q::time()->plus_seconds( 1 )->epoch_microseconds(), 'control_revision' => 1 ];
		return new OperationAttemptResult( OperationOutcome::accepted(), OperationCompletion::accepted( $p, $r, [ 'site_id' => 1, 'owner_digest' => $c->owner()->digest(), 'quote_id' => $c->header()->id()->value(), 'purpose' => 'private_quote_invalidation' ] ) );
	}
	private function stored( FixedPromotionQuoteAcceptCommand $c, bool $accepted ): QuoteStoredRow {
		$h = $c->header(); $at = $accepted ? Q::time()->plus_seconds( 1 )->sql() : null;
		return QuoteStoredRow::from_row( [ 'id' => 1, 'site_id' => 1, 'quote_uuid' => $h->id()->value(), 'format_version' => 3, 'profile_code' => $h->profile(), 'profile_version' => 1, 'purpose' => 'checkout', 'principal_hash' => $c->owner()->facts()['principal_hash'], 'owner_digest' => $c->owner()->digest(), 'material_digest' => $h->material_digest(), 'body_digest' => $h->body_digest(), 'header_json' => $h->to_private_json(), 'private_body_json' => QuoteJson::encode( [ 'context' => $c->context()->private_facts(), 'terms' => $c->terms()->private_facts() ] ), 'issue_namespace_hash' => $h->namespace_hashes()['issue'], 'accept_namespace_hash' => $h->namespace_hashes()['accept'], 'invalidate_namespace_hash' => $h->namespace_hashes()['invalidate'], 'state' => $accepted ? 'accepted' : 'issued', 'revision' => $accepted ? 2 : 1, 'retention_revision' => 1, 'created_at' => $h->created_at()->sql(), 'expires_at' => $h->expires_at()->sql(), 'accepted_at' => $at, 'transition_at' => $at ] );
	}
	public function test_acceptance_keeps_the_existing_header_v1_namespace_with_a_distinct_bound_intent(): void {
		$issue = $this->issue(); $c = FixedPromotionQuoteAcceptCommand::from_issue( $issue ); $generic = QuoteDurableCommand::accept( $c->owner(), $c->reference(), $c->header(), $c->context() );
		self::assertSame( 'delivery_quote.accept', $c->identity->operation ); self::assertSame( 1, $c->identity->operation_version ); self::assertSame( $c->header()->namespace_hashes()['accept'], $c->identity->namespace_digest() ); self::assertSame( $generic->identity->namespace_digest(), $c->identity->namespace_digest() ); self::assertFalse( $generic->intent()->equals( $c->intent() ) );
		self::assertSame( $issue->header(), $c->header() ); self::assertSame( $issue->context(), $c->context() ); self::assertSame( $issue->terms(), $c->terms() ); self::assertSame( $issue->reference(), $c->reference() ); self::assertSame( $issue->account_slots(), $c->account_slots() ); self::assertSame( $issue->allocation_digest(), $c->allocation_digest() ); self::assertSame( $issue->hold_until(), $c->hold_until() );
	}
	public function test_a_live_issue_capability_is_removed_from_every_returned_projection(): void {
		$issued = $this->issue(); $original = $issued->original_issue(); $attempt = QuoteAdmissionAttempt::generate(); self::assertTrue( $attempt->begin( $original ) ); self::assertTrue( $attempt->confirm() ); $namespace = $original->identity()->namespace_digest();
		$slot = QuoteBudgetSlot::from_row( array_replace( QuoteStorageFixtures::budget()->row(), [ 'admission_namespace_hash' => $namespace, 'slot_key' => QuoteBudgetSlot::admission_slot_key( $namespace ), 'admission_intent_digest' => $original->intent_digest(), 'server_attempt_digest' => $attempt->digest() ] ) );
		$lease = QuoteAdmissionLease::confirmed( $slot, $original, $attempt ); self::assertTrue( $lease->claim_capture() );
		$live = FixedPromotionQuoteIssueCommand::captured( $original, $lease, DeliveryQuote::issue( $issued->header(), $issued->context(), $issued->terms() ), $issued->reference() ); $c = FixedPromotionQuoteAcceptCommand::from_issue( $live );
		self::assertSame( $lease, $live->lease() ); self::assertNull( $c->issue()->lease() ); self::assertNull( $c->as_recovery()->issue()->lease() ); self::assertNull( FixedPromotionQuoteAcceptCommand::from_recovery_array( $c->to_recovery_array() )->issue()->lease() );
	}
	public function test_recovery_is_deterministic_and_preserves_the_complete_original_capture(): void {
		$c = FixedPromotionQuoteAcceptCommand::from_issue( $this->issue() ); $copy = FixedPromotionQuoteAcceptCommand::from_recovery_array( $c->to_recovery_array() );
		self::assertSame( $c, $c->as_recovery() ); self::assertSame( $c->to_recovery_array(), $copy->to_recovery_array() ); self::assertSame( $c->intent()->fingerprint(), $copy->intent()->fingerprint() ); self::assertSame( $c->identity->namespace_digest(), $copy->identity->namespace_digest() ); self::assertSame( $c->issue()->intent()->fingerprint(), $copy->issue()->intent()->fingerprint() ); self::assertSame( $c->header()->to_private_json(), $copy->header()->to_private_json() ); self::assertSame( $c->reference()->public_fields(), $copy->reference()->public_fields() );
	}
	public function test_mapping_order_is_canonical_while_account_and_deadline_changes_conflict(): void {
		$c = FixedPromotionQuoteAcceptCommand::from_issue( $this->issue() ); $packet = $c->to_recovery_array(); $packet['issue']['original_issue']['account_slots'] = array_reverse( $packet['issue']['original_issue']['account_slots'] );
		self::assertSame( $c->intent()->fingerprint(), FixedPromotionQuoteAcceptCommand::from_recovery_array( $packet )->intent()->fingerprint() );
		foreach ( [ 'account', 'deadline' ] as $fault ) {
			$packet = $c->to_recovery_array(); if ( 'account' === $fault ) { foreach ( $packet['issue']['original_issue']['account_slots'] as &$slot ) { if ( null !== $slot['account_key'] ) { $slot['account_key'] = Q::digest( 'another explicit account' ); } } unset( $slot ); } else { $packet['issue']['original_issue']['hold_until'] = Q::time()->plus_seconds( 44 )->sql(); }
			$other = FixedPromotionQuoteAcceptCommand::from_recovery_array( $packet ); self::assertSame( $c->identity->namespace_digest(), $other->identity->namespace_digest() ); self::assertFalse( $c->intent()->equals( $other->intent() ) );
		}
	}
	public function test_another_canonical_header_reference_has_a_different_intent_in_the_same_namespace(): void {
		$c = FixedPromotionQuoteAcceptCommand::from_issue( $this->issue() ); $packet = $c->to_recovery_array(); $ref = QuoteReference::generate( $c->header()->id() );
		$packet['issue']['reference'] = $ref->public_fields(); $packet['issue']['header_json'] = QuoteHeader::issue( $ref->id(), $c->owner(), $c->context(), $c->terms(), $c->header()->created_at(), $c->header()->namespace_hashes(), $c->header()->profile(), 1, $ref )->to_private_json();
		$other = FixedPromotionQuoteAcceptCommand::from_recovery_array( $packet ); self::assertSame( $c->issue()->intent()->fingerprint(), $other->issue()->intent()->fingerprint() ); self::assertSame( $c->identity->namespace_digest(), $other->identity->namespace_digest() ); self::assertNotSame( $c->allocation_digest(), $other->allocation_digest() ); self::assertFalse( $c->intent()->equals( $other->intent() ) );
	}
	public static function invalid_recovery(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'extra', 'missing', 'version', 'issue', 'account', 'unadjusted account', 'token', 'reference', 'oversized', 'object', 'depth', 'float', 'nodes' ] ); }
	#[DataProvider( 'invalid_recovery' )]
	public function test_closed_recovery_refuses_malformed_or_unbounded_input_without_calling_objects( string $case ): void {
		$data = FixedPromotionQuoteAcceptCommand::from_issue( $this->issue() )->to_recovery_array(); $probe = new PromotionAcceptanceRecoveryObjectProbe();
		if ( 'extra' === $case ) { $data['inputs'] = []; }
		elseif ( 'missing' === $case ) { unset( $data['issue'] ); }
		elseif ( 'version' === $case ) { $data['format_version'] = '1'; }
		elseif ( 'issue' === $case ) { $data['issue'] = null; }
		elseif ( 'account' === $case ) { $data['issue']['original_issue']['account_slots'] = []; }
		elseif ( 'unadjusted account' === $case ) { foreach ( $data['issue']['original_issue']['account_slots'] as &$slot ) { if ( null === $slot['account_key'] ) { $slot['account_key'] = Q::digest( 'unadjusted group cannot be funded' ); } } unset( $slot ); }
		elseif ( 'token' === $case ) { $data['issue']['original_issue']['original_token'] = 'not-an-original-uuid'; }
		elseif ( 'reference' === $case ) { $data['issue']['reference']['acceptance_handle'] = Q::digest( 'foreign handle' ); }
		elseif ( 'oversized' === $case ) { $data['issue']['terms_json'] = str_repeat( 'x', FixedPromotionQuoteAcceptCommand::MAX_RECOVERY_BYTES + 1 ); }
		elseif ( 'object' === $case ) { $data['issue']['original_issue']['context'] = $probe; }
		elseif ( 'depth' === $case ) { $deep = []; for ( $i = 0; $i < 40; ++$i ) { $deep = [ $deep ]; } $data['issue'] = $deep; }
		elseif ( 'float' === $case ) { $data['issue']['original_issue']['hold_until'] = INF; }
		else { $data['issue'] = array_fill( 0, 10001, 0 ); }
		try { FixedPromotionQuoteAcceptCommand::from_recovery_array( $data ); self::fail( 'Malformed recovery must refuse.' ); } catch ( \InvalidArgumentException ) { self::assertSame( 0, $probe->calls ); }
	}
	public function test_recovery_arrays_cannot_rewrite_the_immutable_command_through_references(): void {
		$data = FixedPromotionQuoteAcceptCommand::from_issue( $this->issue() )->to_recovery_array(); $handle =& $data['issue']['reference']['acceptance_handle']; $original = $handle; $c = FixedPromotionQuoteAcceptCommand::from_recovery_array( $data ); $handle = Q::digest( 'external later mutation' );
		self::assertSame( $original, $c->reference()->handle() ); $out = $c->to_recovery_array(); $out['issue']['original_issue']['hold_until'] = Q::time()->plus_seconds( 3 )->sql(); self::assertNotSame( $out, $c->to_recovery_array() );
	}
	public function test_implicit_exports_and_debug_do_not_expose_private_original_facts(): void {
		$c = FixedPromotionQuoteAcceptCommand::from_issue( $this->issue() ); foreach ( [ static fn() => json_encode( $c, JSON_THROW_ON_ERROR ), static fn() => serialize( $c ) ] as $export ) { try { $export(); self::fail( 'Private recovery exported implicitly.' ); } catch ( \LogicException $error ) { self::assertStringNotContainsString( $c->reference()->handle(), $error->getMessage() ); } }
		ob_start(); var_dump( $c ); $debug = ob_get_clean(); self::assertStringContainsString( 'redacted', $debug ); self::assertStringNotContainsString( $c->reference()->handle(), $debug ); self::assertStringNotContainsString( Q::digest( 'explicit acceptance pool' ), $debug );
	}
	public function test_current_inputs_keep_explicit_ports_without_executing_them_and_cannot_be_exported(): void {
		$f = new FixedPromotionCurrentFixture(); $reads = $f->native_source->reads; $inputs = FixedPromotionQuoteAcceptInputs::from_sources( $f->snapshot, $f->native );
		self::assertSame( $f->snapshot, $inputs->original() ); self::assertSame( $f->native, $inputs->fresh_native() ); self::assertSame( $reads, $f->native_source->reads ); self::assertSame( [], $f->session->sql );
		foreach ( [ static fn() => json_encode( $inputs, JSON_THROW_ON_ERROR ), static fn() => serialize( $inputs ) ] as $export ) { try { $export(); self::fail( 'Ephemeral current inputs exported.' ); } catch ( \LogicException ) { self::assertSame( $reads, $f->native_source->reads ); } }
		self::assertSame( [ 'private_promotion_accept_inputs' => 'redacted' ], $inputs->__debugInfo() );
	}
	public function test_pending_result_carries_no_claimed_acceptance(): void {
		$r = new FixedPromotionQuoteAcceptResult( new OperationAttemptResult( OperationOutcome::pending() ) ); self::assertSame( 'pending', $r->attempt->outcome->state ); self::assertNull( $r->quote ); self::assertNull( $r->command );
	}
	public function test_accepted_attempt_without_a_physically_acknowledged_row_is_invalid(): void {
		$c = FixedPromotionQuoteAcceptCommand::from_issue( $this->issue() ); $this->expectException( \InvalidArgumentException::class ); new FixedPromotionQuoteAcceptResult( $this->accepted_attempt( $c ), $c );
	}
	public function test_issued_or_foreign_rows_cannot_be_presented_as_accepted_history(): void {
		$c = FixedPromotionQuoteAcceptCommand::from_issue( $this->issue() ); foreach ( [ $this->stored( $c, false ), QuoteStorageFixtures::quote( state: 'accepted' ) ] as $wrong ) { try { new FixedPromotionQuoteAcceptResult( $this->accepted_attempt( $c ), $c, $wrong ); self::fail( 'Invalid accepted result.' ); } catch ( \InvalidArgumentException ) { self::assertNotSame( $c->header()->to_private_json(), 'accepted' === $wrong->state() ? $wrong->header()->to_private_json() : '' ); } }
	}
	public function test_exact_accepted_history_is_an_explicit_private_result(): void {
		$c = FixedPromotionQuoteAcceptCommand::from_issue( $this->issue() ); $quote = $this->stored( $c, true ); $r = new FixedPromotionQuoteAcceptResult( $this->accepted_attempt( $c ), $c, $quote );
		self::assertSame( $quote, $r->quote ); self::assertSame( $c, $r->command ); self::assertNull( $r->command->issue()->lease() );
		foreach ( [ static fn() => json_encode( $r, JSON_THROW_ON_ERROR ), static fn() => serialize( $r ) ] as $export ) { try { $export(); self::fail( 'Accepted history exported implicitly.' ); } catch ( \LogicException ) { self::assertSame( [ 'private_promotion_accept_result' => 'redacted' ], $r->__debugInfo() ); } }
	}
}

final class PromotionAcceptanceRecoveryObjectProbe implements \JsonSerializable { public int $calls = 0; public function jsonSerialize(): mixed { ++$this->calls; return []; } }
