<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteIssueCommand, FixedPromotionQuoteIssueProfile, QuoteAdmissionAttempt, QuoteAdmissionLease, QuoteIssueCommand, QuoteOperationProfile, QuotePrivatePublication};
use CetechDeliveryEngine\Application\Operation\{OperationReadiness, OperationStorageException};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{DeliveryQuote, QuoteBudgetSlot, QuoteContext, QuoteHeader, QuoteId, QuoteReference};
use CetechDeliveryEngine\Domain\Operation\{OperationCompletion, OperationMaterialEvent, OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\{DeliveryQuoteSchema, EmergencyControlStore};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixedPromotionCurrentEvidenceGuardTest.php';

/** Strict private command and finite receipt contracts; no physical Woo/SQL claim. */
final class FixedPromotionQuoteIssueProfileTest extends TestCase {

	private function command( bool $start_capture = true, ?QuoteId $id = null ): FixedPromotionQuoteIssueCommand {
		$provider = F::provider(); $original = QuoteIssueCommand::fixed_promotion( F::owner(), $provider->context(), '71eb41b0-d08d-4a25-9808-6ad03e6aa001', [ [ 'component_key' => F::key(), 'account_key' => Q::digest( 'explicit issue pool' ) ] ], Q::time()->plus_seconds( 120 ) );
		$attempt = QuoteAdmissionAttempt::generate(); self::assertTrue( $attempt->begin( $original ) ); self::assertTrue( $attempt->confirm() );
		$namespace = $original->identity()->namespace_digest();
		$slot = QuoteBudgetSlot::from_row( [ 'id' => 1, 'site_id' => 1, 'format_version' => 1, 'purpose' => 'delivery_quote.issue', 'slot_kind' => 'admission', 'slot_key' => QuoteBudgetSlot::admission_slot_key( $namespace ), 'window_start' => Q::time()->sql(), 'principal_hash' => F::owner()->facts()['principal_hash'], 'attempt_count' => 1, 'revision' => 1, 'created_at' => Q::time()->sql(), 'last_seen_at' => Q::time()->sql(), 'admission_namespace_hash' => $namespace, 'admission_intent_digest' => $original->intent_digest(), 'server_attempt_digest' => $attempt->digest(), 'lease_expires_at' => Q::time()->plus_seconds( 60 )->sql(), 'lease_state' => 'granted', 'consumed_quote_uuid' => null, 'consumed_at' => null ] );
		$lease = QuoteAdmissionLease::confirmed( $slot, $original, $attempt ); if ( $start_capture ) { self::assertTrue( $lease->claim_capture() ); }
		$reference = QuoteReference::generate( $id ?? QuoteId::generate() );
		$header = QuoteHeader::issue( $reference->id(), F::owner(), $provider->context(), $provider->terms(), Q::time(), $original->namespace_hashes( $reference->id() ), 'fixed_promotion_native_v1', 1, $reference );
		return FixedPromotionQuoteIssueCommand::captured( $original, $lease, DeliveryQuote::issue( $header, $provider->context(), $provider->terms() ), $reference );
	}
	private function receipt( FixedPromotionQuoteIssueCommand $command, array $result_changes = [], array $target_changes = [], array $fields = [ 'quote', 'admission', 'promotion_budget' ], array $actor_changes = [] ): array {
		$profile = new FixedPromotionQuoteIssueProfile();
		$result = array_replace( [ 'quote_id' => $command->header()->id()->value(), 'quote_revision' => 1, 'state' => 'issued', 'body_digest' => $command->header()->body_digest(), 'owner_digest' => $command->owner()->digest(), 'namespace_hash' => $command->identity->namespace_digest(), 'completed_at' => Q::time()->plus_seconds( 1 )->epoch_microseconds(), 'control_revision' => 1, 'allocation_digest' => $command->allocation_digest(), 'allocation_count' => count( $command->allocation_set()->requests() ), 'hold_until' => $command->hold_until()->epoch_microseconds() ], $result_changes );
		$target = array_replace( array_intersect_key( $result, array_flip( [ 'quote_id', 'body_digest', 'owner_digest', 'namespace_hash', 'quote_revision', 'allocation_digest', 'allocation_count', 'hold_until' ] ) ) + [ 'binding_revision' => 0 ], $target_changes );
		$completion = OperationCompletion::accepted( $profile, $result, [ 'site_id' => 1, 'owner_digest' => $result['owner_digest'], 'quote_id' => $result['quote_id'], 'purpose' => 'private_quote_invalidation' ] );
		$event = OperationMaterialEvent::from_mutation( $profile, $command->identity, RequestContext::create(), array_replace( [ 'authority_hash' => hash( 'sha256', 'delivery_quote_customer' ), 'principal_hash' => hash( 'sha256', $command->owner()->digest() ) ], $actor_changes ), $target, 'quote_issued', 1, 2, $fields );
		return [ $completion, $event ];
	}
	public function test_capture_keeps_semantic_v2_identity_and_exact_private_allocation(): void {
		$c = $this->command(); self::assertSame( 2, $c->identity->operation_version ); self::assertSame( $c->original_issue()->identity(), $c->identity ); self::assertTrue( $c->intent()->equals( $c->original_issue()->intent() ) );
		self::assertSame( $c->original_issue()->promotion_account_slots(), $c->account_slots() ); self::assertSame( $c->original_issue()->promotion_hold_until(), $c->hold_until() ); self::assertCount( 1, $c->allocation_set()->requests() ); self::assertTrue( $c->lease()->capture_started() );
	}
	public function test_unclaimed_capture_capability_is_not_a_captured_command(): void { $this->expectException( \InvalidArgumentException::class ); $this->command( false ); }
	public function test_explicit_recovery_round_trip_keeps_every_frozen_fact_without_live_capability(): void {
		$c = $this->command(); $recover = FixedPromotionQuoteIssueCommand::from_recovery_array( $c->to_recovery_array() );
		self::assertNull( $recover->lease() ); self::assertNull( $c->as_recovery()->lease() ); self::assertTrue( $recover->intent()->equals( $c->intent() ) ); self::assertSame( $c->identity->namespace_digest(), $recover->identity->namespace_digest() );
		self::assertSame( $c->header()->to_private_json(), $recover->header()->to_private_json() ); self::assertSame( $c->context()->to_private_json(), $recover->context()->to_private_json() ); self::assertSame( $c->terms()->to_private_json(), $recover->terms()->to_private_json() ); self::assertSame( $c->reference()->public_fields(), $recover->reference()->public_fields() ); self::assertSame( $c->allocation_digest(), $recover->allocation_digest() );
	}
	public static function malformed_recovery(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'extra', 'format', 'reference', 'terms', 'oversized', 'object', 'depth' ] ); }
	#[DataProvider( 'malformed_recovery' )]
	public function test_recovery_is_closed_bounded_and_does_not_invoke_objects( string $case ): void {
		$data = $this->command()->to_recovery_array(); $probe = new PromotionRecoveryObjectProbe();
		if ( 'extra' === $case ) { $data['lease'] = []; }
		elseif ( 'format' === $case ) { $data['format_version'] = 2; }
		elseif ( 'reference' === $case ) { $data['reference']['acceptance_handle'] = Q::digest( 'foreign reference' ); }
		elseif ( 'terms' === $case ) { $data['terms_json'] = Q::terms()->to_private_json(); }
		elseif ( 'oversized' === $case ) { $data['terms_json'] = str_repeat( 'a', FixedPromotionQuoteIssueCommand::MAX_RECOVERY_BYTES + 1 ); }
		elseif ( 'object' === $case ) { $data['original_issue']['context'] = $probe; }
		elseif ( 'depth' === $case ) { $nested = []; for ( $i = 0; $i < 30; ++$i ) { $nested = [ $nested ]; } $data['original_issue'] = $nested; }
		try { FixedPromotionQuoteIssueCommand::from_recovery_array( $data ); self::fail( 'Invalid recovery must refuse.' ); } catch ( \InvalidArgumentException ) { self::assertSame( 0, $probe->calls ); }
	}
	public function test_recovery_detaches_input_and_explicit_export_references(): void {
		$data = $this->command()->to_recovery_array(); $handle = $data['reference']['acceptance_handle']; $referenced = &$data['reference']['acceptance_handle']; $c = FixedPromotionQuoteIssueCommand::from_recovery_array( $data ); $referenced = Q::digest( 'later mutation' ); self::assertSame( $handle, $c->reference()->handle() );
		$out = $c->to_recovery_array(); $out['original_issue']['account_slots'][0]['account_key'] = Q::digest( 'another pool' ); self::assertNotSame( $out['original_issue']['account_slots'], $c->account_slots() );
	}
	public function test_implicit_command_exports_and_debug_do_not_expose_funding_or_handles(): void {
		$c = $this->command(); foreach ( [ static fn() => json_encode( $c, JSON_THROW_ON_ERROR ), static fn() => serialize( $c ) ] as $export ) { try { $export(); self::fail( 'Private export must refuse.' ); } catch ( \LogicException $e ) { self::assertStringNotContainsString( $c->reference()->handle(), $e->getMessage() ); } }
		ob_start(); var_dump( $c ); $debug = ob_get_clean(); self::assertStringContainsString( 'redacted', $debug ); self::assertStringNotContainsString( $c->reference()->handle(), $debug ); self::assertStringNotContainsString( $c->account_slots()[0]['account_key'], $debug );
	}
	public function test_unbound_profile_is_hydration_only_and_existing_issue_profile_does_not_accept_v2(): void {
		$c = $this->command(); $p = new FixedPromotionQuoteIssueProfile(); self::assertFalse( $p->authorize( $c->identity ) ); self::assertFalse( $p->rejects_are_terminal() ); self::assertSame( 'delivery_quote.issue', $p->operation() ); self::assertSame( 2, $p->version() );
		$this->expectException( \InvalidArgumentException::class ); new QuoteOperationProfile( 'delivery_quote.issue', profile_version: 2 );
	}
	public function test_bound_validation_refuses_another_capture_with_same_semantic_namespace(): void {
		$a = $this->command(); $data = $a->to_recovery_array(); $ref = QuoteReference::generate( QuoteId::generate() ); $data['reference'] = $ref->public_fields(); $data['header_json'] = QuoteHeader::issue( $ref->id(), $a->owner(), $a->context(), $a->terms(), Q::time(), $a->original_issue()->namespace_hashes( $ref->id() ), 'fixed_promotion_native_v1', 1, $ref )->to_private_json(); $b = FixedPromotionQuoteIssueCommand::from_recovery_array( $data );
		self::assertTrue( $a->intent()->equals( $b->intent() ) ); self::assertSame( $a->identity->namespace_digest(), $b->identity->namespace_digest() ); self::assertNotSame( $a->allocation_digest(), $b->allocation_digest() );
		$p = new FixedPromotionQuoteIssueProfile( $a, static fn(): bool => true ); self::assertSame( $a, $p->validate_command( $a->identity, $a ) ); $this->expectException( \InvalidArgumentException::class ); $p->validate_command( $b->identity, $b );
	}
	public function test_recovery_declares_only_history_tables_without_current_dependencies(): void {
		$c = $this->command()->as_recovery(); $s = new FixedPromotionCurrentSession( [] ); $control = new PromotionHistoryPoisonControl(); $ready = new PromotionHistoryPoisonReadiness();
		$p = new FixedPromotionQuoteIssueProfile( $c, static fn(): bool => true, control: $control, current_readiness: $ready ); self::assertTrue( $p->authorize( $c->identity ) ); self::assertSame( DeliveryQuoteSchema::tables( 'wp_' ), $p->transactional_tables( $s ) ); self::assertSame( [], $s->sql ); self::assertSame( 0, $s->clocks );
		$this->expectException( OperationStorageException::class ); $p->lock_target( $s, $c->identity, $c );
	}
	public function test_version2_receipt_and_event_round_trip_exact_allocation_facts(): void {
		$c = $this->command(); $p = new FixedPromotionQuoteIssueProfile(); [ $completion, $event ] = $this->receipt( $c ); self::assertTrue( $p->validate_accepted_facts( $completion, $event ) );
		self::assertSame( $completion->to_json(), OperationCompletion::from_json( $completion->to_json(), $p )->to_json() ); self::assertSame( $event->to_json(), OperationMaterialEvent::from_json( $event->to_json(), $p )->to_json() );
		self::assertSame( $c->allocation_digest(), $completion->result['allocation_digest'] ); self::assertSame( $c->hold_until()->epoch_microseconds(), $event->target['hold_until'] );
	}
	public static function bad_receipt_links(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'digest', 'count', 'deadline', 'at deadline', 'over count', 'quote revision', 'binding', 'fields', 'actor' ] ); }
	#[DataProvider( 'bad_receipt_links' )]
	public function test_hydration_validator_rejects_mismatched_or_impossible_receipt_relationships( string $case ): void {
		$c = $this->command(); $r = []; $t = []; $fields = [ 'quote', 'admission', 'promotion_budget' ]; $actor = [];
		if ( 'digest' === $case ) { $t['allocation_digest'] = Q::digest( 'foreign allocation' ); }
		elseif ( 'count' === $case ) { $t['allocation_count'] = 2; }
		elseif ( 'deadline' === $case ) { $t['hold_until'] = $c->hold_until()->plus_seconds( 1 )->epoch_microseconds(); }
		elseif ( 'at deadline' === $case ) { $r['completed_at'] = $c->hold_until()->epoch_microseconds(); }
		elseif ( 'over count' === $case ) { $r['allocation_count'] = QuoteContext::MAX_GROUPS + 1; }
		elseif ( 'quote revision' === $case ) { $r['quote_revision'] = 2; }
		elseif ( 'binding' === $case ) { $t['binding_revision'] = 1; }
		elseif ( 'fields' === $case ) { $fields = [ 'quote', 'admission' ]; }
		elseif ( 'actor' === $case ) { $actor['principal_hash'] = Q::digest( 'foreign actor' ); }
		[ $completion, $event ] = $this->receipt( $c, $r, $t, $fields, $actor ); self::assertFalse( ( new FixedPromotionQuoteIssueProfile() )->validate_accepted_facts( $completion, $event ) );
	}
	public function test_v2_receipt_cannot_be_decoded_by_retained_v1_profile(): void {
		[ $completion ] = $this->receipt( $this->command() ); $this->expectException( \InvalidArgumentException::class ); OperationCompletion::from_json( $completion->to_json(), new QuoteOperationProfile( 'delivery_quote.issue' ) );
	}
	public function test_historical_publication_requires_exact_captured_allocation_but_no_live_lease(): void {
		$c = $this->command()->as_recovery(); [ $completion ] = $this->receipt( $c ); $publication = new PromotionIssuePublicationProbe(); $p = new FixedPromotionQuoteIssueProfile( $c, static fn(): bool => true, publication: $publication );
		self::assertTrue( $p->publish( $c->identity, $completion ) ); self::assertSame( [ $c->header()->id()->value() ], $publication->ids );
		[ $foreign ] = $this->receipt( $c, [ 'allocation_digest' => Q::digest( 'wrong funding receipt' ) ] ); self::assertFalse( $p->publish( $c->identity, $foreign ) ); self::assertCount( 1, $publication->ids );
	}
}

final class PromotionRecoveryObjectProbe implements \JsonSerializable { public int $calls = 0; public function jsonSerialize(): mixed { ++$this->calls; return []; } }
final class PromotionHistoryPoisonControl extends EmergencyControlStore { public function assert_ready( OperationSession $session, int $site ): void { throw new \LogicException( 'Historical receipt must not inspect current control.' ); } }
final class PromotionHistoryPoisonReadiness implements OperationReadiness { public function assert_ready( OperationSession $session ): void { throw new \LogicException( 'Historical receipt must not inspect current funding publication.' ); } }
final class PromotionIssuePublicationProbe implements QuotePrivatePublication { public array $ids = []; public function invalidate( int $site_id, string $owner_digest, QuoteId $quote_id ): bool { $this->ids[] = $quote_id->value(); return true; } }
