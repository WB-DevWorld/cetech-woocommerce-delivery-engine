<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteIssueCommand, QuoteIssueCommand, QuoteOperationProfile, QuotePreparationAttempt, QuotePreparationCommand, QuotePreparationGate, QuoteReceiptVerifier};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteHeader, QuoteId, QuoteJson, QuoteReference, QuoteStoredRow, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\{OperationCompletion, OperationMaterialEvent, OperationRecord};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryCommercial/FixedPromotionQuoteFixture.php';
require_once __DIR__ . '/AdmissionGateTest.php';

/** Immutable contracts and a real cheap gate handoff; no ledger or checkout authority. */
final class FixedPromotionIssueContractsTest extends TestCase {
	private const TOKEN = 'a9fa5bde-1b6b-45b4-8fb3-707b304c434a';
	private function slots(): array { return [ [ 'component_key' => F::key(), 'account_key' => Q::digest( 'promotion-account' ) ], [ 'component_key' => F::second_key(), 'account_key' => null ] ]; }
	private function deadline(): QuoteTime { return Q::time()->plus_seconds( 45 ); }
	private function command(): QuoteIssueCommand { return QuoteIssueCommand::fixed_promotion( F::owner(), F::mixed_provider()->context(), self::TOKEN, $this->slots(), $this->deadline() ); }

	public function test_retained_intents_namespaces_and_private_envelope_are_byte_identical(): void {
		$issue = QuoteIssueCommand::create( Q::owner(), Q::context(), 'fixture_v1', 1, 'fixture_v1', 1, 'old-contract-token' );
		$prep = QuotePreparationCommand::create( Q::owner(), Q::digest( 'old-draft' ), self::TOKEN, 'fixture_v1', 1, 'fixture_v1', 1 );
		self::assertSame( 'c48403f98981d0467d259a7e0276d3cb0b90d30b0bc8b961bf32959a6b4ec442', $issue->intent_digest() );
		self::assertSame( 'ca4dbce44bdd52984da3ad97fab087164d87e25dad21c47f4f8d6b656a16d8e6', $issue->identity()->namespace_digest() );
		self::assertSame( [ 'issue' => 'ca4dbce44bdd52984da3ad97fab087164d87e25dad21c47f4f8d6b656a16d8e6', 'accept' => '2acb72c03fc390d379d93301b688b34df6249ba4c518b26c21e11217c2fe6505', 'invalidate' => 'd249266d6b6464c84e11206b8e5b2f693d1846afaf6714e7354cca2f11d4c43c' ], $issue->namespace_hashes( QuoteId::from_string( '10000000-0000-4000-8000-000000000001' ) ) );
		self::assertSame( '339d723a30cd88765eddff448f1a8c0643e2b2f1d6a18fa327c913721e0ec631', $prep->intent_digest() );
		self::assertSame( '8459a1c57b34a5c80ff4edd72fef7669c5f98d5c7bcb218f34027f0f0c981058', $prep->identity()->namespace_digest() );
		self::assertSame( '7de937e68590a684c735df2d21694255cf569ab63c6f701cbc849038bc517584', hash( 'sha256', json_encode( $prep->to_private_array(), JSON_THROW_ON_ERROR ) ) );
		self::assertNull( $issue->promotion_account_slots() ); self::assertNull( $issue->promotion_hold_until() );
	}
	public function test_v2_factories_match_each_other_and_separate_the_old_namespace(): void {
		$issue = $this->command(); $prep = QuotePreparationCommand::fixed_promotion( F::owner(), Q::digest( 'cheap-draft' ), self::TOKEN );
		$old = QuoteIssueCommand::create( F::owner(), $issue->context(), 'fixed_promotion_native_v1', 1, 'fixed_promotion_native_v1', 1, self::TOKEN );
		self::assertSame( 2, $issue->identity()->operation_version ); self::assertSame( 2, $prep->identity()->operation_version ); self::assertTrue( $prep->matches_issue( $issue ) );
		self::assertNotSame( $old->identity()->namespace_digest(), $issue->identity()->namespace_digest() ); self::assertFalse( $prep->matches_issue( $old ) );
		self::assertSame( 'fixed_promotion_native_v1', $issue->provider_code() ); self::assertSame( 1, $issue->provider_version() ); self::assertSame( $issue->provider_code(), $issue->profile() );
	}
	public function test_mapping_order_is_canonical_but_account_and_deadline_change_intent_in_the_same_namespace(): void {
		$p = F::mixed_provider(); $one = QuoteIssueCommand::fixed_promotion( F::owner(), $p->context(), self::TOKEN, $this->slots(), $this->deadline() );
		$reordered = QuoteIssueCommand::fixed_promotion( F::owner(), $p->context(), self::TOKEN, array_reverse( $this->slots() ), $this->deadline() );
		self::assertSame( $one->promotion_account_slots(), $reordered->promotion_account_slots() ); self::assertSame( $one->intent_digest(), $reordered->intent_digest() );
		$slots = $this->slots(); $slots[0]['account_key'] = Q::digest( 'different-account' );
		foreach ( [ QuoteIssueCommand::fixed_promotion( F::owner(), $p->context(), self::TOKEN, $slots, $this->deadline() ), QuoteIssueCommand::fixed_promotion( F::owner(), $p->context(), self::TOKEN, $this->slots(), $this->deadline()->plus_seconds( 1 ) ) ] as $changed ) {
			self::assertSame( $one->identity()->namespace_digest(), $changed->identity()->namespace_digest() ); self::assertNotSame( $one->intent_digest(), $changed->intent_digest() );
		}
	}
	public function test_caller_and_returned_array_references_cannot_rewrite_mapping(): void {
		$slots = $this->slots(); $outside = $slots[0]['account_key']; $slots[0]['account_key'] =& $outside;
		$issue = QuoteIssueCommand::fixed_promotion( F::owner(), F::mixed_provider()->context(), self::TOKEN, $slots, $this->deadline() ); $before = $issue->promotion_account_slots(); $digest = $issue->intent_digest();
		$outside = Q::digest( 'external-mutation' ); $returned = $issue->promotion_account_slots(); $returned[0]['account_key'] = $outside;
		self::assertSame( $before, $issue->promotion_account_slots() ); self::assertSame( $digest, $issue->intent_digest() );
	}
	public static function bad_mapping(): array { return [ 'missing' => [ 'missing' ], 'duplicate' => [ 'duplicate' ], 'foreign' => [ 'foreign' ], 'missing adjusted account' => [ 'null' ], 'fund unadjusted' => [ 'unadjusted' ], 'extra' => [ 'extra' ], 'bad digest' => [ 'digest' ], 'object' => [ 'object' ] ]; }
	#[DataProvider( 'bad_mapping' )]
	public function test_incomplete_foreign_or_noncanonical_mapping_is_refused( string $fault ): void {
		$slots = $this->slots(); match ( $fault ) { 'missing' => array_pop( $slots ), 'duplicate' => $slots[] = $slots[0], 'foreign' => $slots[0]['component_key'] = Q::digest( 'foreign' ), 'null' => $slots[0]['account_key'] = null, 'unadjusted' => $slots[1]['account_key'] = Q::digest( 'account' ), 'extra' => $slots[0]['extra'] = 1, 'digest' => $slots[0]['account_key'] = 'broken', 'object' => $slots = [ 'slot' => $slots[0] ] };
		$this->expectException( \InvalidArgumentException::class ); QuoteIssueCommand::fixed_promotion( F::owner(), F::mixed_provider()->context(), self::TOKEN, $slots, $this->deadline() );
	}
	public function test_recovery_reconstructs_original_intent_without_any_capability(): void {
		$original = $this->command(); $facts = $original->to_promotion_recovery_array(); $restored = QuoteIssueCommand::from_promotion_recovery_array( $facts );
		self::assertSame( [ 'account_slots', 'context', 'format_version', 'hold_until', 'original_token', 'owner' ], array_keys( $facts ) );
		self::assertSame( $original->identity()->namespace_digest(), $restored->identity()->namespace_digest() ); self::assertSame( $original->intent_digest(), $restored->intent_digest() ); self::assertSame( $facts, $restored->to_promotion_recovery_array() );
		self::assertFalse( method_exists( $restored, 'claim_capture' ) );
		$prep = QuotePreparationCommand::fixed_promotion( F::owner(), Q::digest( 'draft' ), self::TOKEN ); $envelope = $prep->to_private_array(); self::assertCount( 8, $envelope ); self::assertSame( 2, $envelope['format_version'] ); self::assertSame( $prep->intent_digest(), QuotePreparationCommand::from_private_array( $envelope )->intent_digest() );
	}
	public static function bad_recovery(): array { return [ 'extra' => [ 'extra' ], 'format type' => [ 'format' ], 'token' => [ 'token' ], 'deadline' => [ 'deadline' ], 'context' => [ 'context' ], 'budget' => [ 'budget' ] ]; }
	#[DataProvider( 'bad_recovery' )]
	public function test_recovery_is_closed_bounded_and_strict( string $fault ): void {
		$facts = $this->command()->to_promotion_recovery_array(); match ( $fault ) { 'extra' => $facts['lease'] = true, 'format' => $facts['format_version'] = '1', 'token' => $facts['original_token'] = 'not-an-original-uuid', 'deadline' => $facts['hold_until'] = 'tomorrow', 'context' => $facts['context'] = Q::context()->private_facts(), 'budget' => $facts['original_token'] = str_repeat( 'x', 65537 ) };
		$this->expectException( \InvalidArgumentException::class ); QuoteIssueCommand::from_promotion_recovery_array( $facts );
	}
	public function test_v2_preparation_envelope_cannot_smuggle_another_profile_or_version(): void {
		$facts = QuotePreparationCommand::fixed_promotion( F::owner(), Q::digest( 'draft' ), self::TOKEN )->to_private_array(); $facts['profile_version'] = 2;
		$this->expectException( \InvalidArgumentException::class ); QuotePreparationCommand::from_private_array( $facts );
	}
	public function test_original_private_token_and_budget_map_do_not_export_implicitly(): void {
		$issue = $this->command(); self::assertSame( [ 'private_quote_issue_command' => 'redacted' ], $issue->__debugInfo() );
		foreach ( [ static fn(): string => json_encode( $issue, JSON_THROW_ON_ERROR ), static fn(): string => serialize( $issue ) ] as $export ) {
			try { $export(); self::fail( 'Private original issue data exported implicitly.' ); } catch ( \LogicException $error ) { self::assertStringNotContainsString( self::TOKEN, $error->getMessage() ); }
		}
	}
	public function test_real_preparation_grant_binds_v2_once_and_keeps_early_admission_intent(): void {
		$factory = new AdmissionUnitFactory(); $control = new AdmissionUnitControl( $factory ); $gate = new QuotePreparationGate( $factory, static fn(): bool => true, static function(): void {}, $control );
		$prep = QuotePreparationCommand::fixed_promotion( F::owner(), Q::digest( 'cheap-draft' ), self::TOKEN ); $attempt = QuotePreparationAttempt::generate(); $grant = $gate->admit( $prep, $attempt ); self::assertSame( 'preparation_allowed', $grant->status );
		$rows = $factory->rows(); $writes = $factory->writes; self::assertTrue( $grant->lease->claim_preparation() ); self::assertFalse( $grant->lease->claim_preparation() ); $issue = $this->command(); $lease = $grant->lease->bind_issue( $issue );
		self::assertTrue( $lease->matches( $issue ) ); self::assertSame( $prep->intent_digest(), $lease->admission_intent_digest() ); self::assertNotSame( $prep->intent_digest(), $issue->intent_digest() );
		self::assertTrue( $lease->claim_capture() ); self::assertFalse( $lease->claim_capture() ); self::assertSame( $rows, $factory->rows() ); self::assertSame( $writes, $factory->writes ); self::assertSame( 0, $factory->c03_statements() );
		$this->expectException( \InvalidArgumentException::class ); $grant->lease->bind_issue( QuoteIssueCommand::from_promotion_recovery_array( $issue->to_promotion_recovery_array() ) );
	}
	public function test_registry_addition_is_hydration_only_and_does_not_widen_old_constructor(): void {
		$profile = QuoteOperationProfile::registry()->get( 'delivery_quote.issue', 2 ); self::assertSame( 2, $profile->version() ); self::assertFalse( $profile->authorize( $this->command()->identity() ) );
		$this->expectException( \InvalidArgumentException::class ); new QuoteOperationProfile( 'delivery_quote.issue', profile_version: 2 );
	}
	/** Full immutable recovery facts, deliberately without a reconstructed live lease. */
	private function captured_fixture(): array {
		$p = F::mixed_provider(); $original = QuoteIssueCommand::fixed_promotion( F::owner(), $p->context(), self::TOKEN, $this->slots(), $this->deadline() );
		$id = QuoteId::from_string( '10000000-0000-4000-8000-000000000001' ); $ref = QuoteReference::generate( $id );
		$h = QuoteHeader::issue( $id, F::owner(), $p->context(), $p->terms(), Q::time(), $original->namespace_hashes( $id ), 'fixed_promotion_native_v1', 1, $ref );
		$command = FixedPromotionQuoteIssueCommand::from_recovery_array( [ 'format_version' => 1, 'original_issue' => $original->to_promotion_recovery_array(), 'header_json' => $h->to_private_json(), 'terms_json' => $p->terms()->to_private_json(), 'reference' => $ref->public_fields() ] );
		$quote = QuoteStoredRow::from_row( [ 'id' => 1, 'site_id' => 1, 'quote_uuid' => $id->value(), 'format_version' => 3, 'profile_code' => $h->profile(), 'profile_version' => 1, 'purpose' => 'checkout', 'principal_hash' => F::owner()->facts()['principal_hash'], 'owner_digest' => F::owner()->digest(), 'material_digest' => $h->material_digest(), 'body_digest' => $h->body_digest(), 'header_json' => $h->to_private_json(), 'private_body_json' => QuoteJson::encode( [ 'context' => $p->context()->private_facts(), 'terms' => $p->terms()->private_facts() ] ), 'issue_namespace_hash' => $h->namespace_hashes()['issue'], 'accept_namespace_hash' => $h->namespace_hashes()['accept'], 'invalidate_namespace_hash' => $h->namespace_hashes()['invalidate'], 'state' => 'issued', 'revision' => 1, 'retention_revision' => 1, 'created_at' => $h->created_at()->sql(), 'expires_at' => $h->expires_at()->sql(), 'accepted_at' => null, 'transition_at' => null ] );
		return [ $command, $quote ];
	}
	private function receipt( FixedPromotionQuoteIssueCommand $command, QuoteStoredRow $quote, int $version = 2, array $facts = [], array $record_changes = [] ): OperationRecord {
		$profile = QuoteOperationProfile::registry()->get( 'delivery_quote.issue', $version ); $h = $quote->header(); $identity = $command->identity; $namespace = $h->namespace_hashes()['issue'];
		$allocation = 2 === $version ? [ 'allocation_digest' => $command->allocation_digest(), 'allocation_count' => count( $command->allocation_set()->requests() ), 'hold_until' => $command->hold_until()->epoch_microseconds() ] : [];
		$allocation = array_replace( $allocation, $facts );
		$result = [ 'quote_id' => $h->id()->value(), 'quote_revision' => 1, 'state' => 'issued', 'body_digest' => $h->body_digest(), 'owner_digest' => $h->owner()->digest(), 'namespace_hash' => $namespace, 'completed_at' => Q::time()->epoch_microseconds(), 'control_revision' => 1, ...$allocation ];
		$completion = OperationCompletion::accepted( $profile, $result, [ 'site_id' => 1, 'owner_digest' => $h->owner()->digest(), 'quote_id' => $h->id()->value(), 'purpose' => 'private_quote_invalidation' ] );
		$event_identity = 2 === $version ? $identity : QuoteIssueCommand::create( $h->owner(), $quote->context(), 'fixed_promotion_native_v1', 1, 'fixed_promotion_native_v1', 1, self::TOKEN )->identity();
		$event = OperationMaterialEvent::from_mutation( $profile, $event_identity, RequestContext::create(), [ 'authority_hash' => hash( 'sha256', 'delivery_quote_customer' ), 'principal_hash' => hash( 'sha256', $h->owner()->digest() ) ], [ 'quote_id' => $h->id()->value(), 'body_digest' => $h->body_digest(), 'owner_digest' => $h->owner()->digest(), 'namespace_hash' => $namespace, 'quote_revision' => 1, 'binding_revision' => 0, ...$allocation ], 'quote_issued', 1, 2, 2 === $version ? [ 'quote', 'admission', 'promotion_budget' ] : [ 'quote', 'admission' ] );
		$row = [ 'id' => 1, 'site_id' => 1, 'namespace_hash' => $namespace, 'intent_hash' => $command->intent()->fingerprint(), 'namespace_format' => 1, 'intent_format' => 1, 'record_format' => 1, 'operation' => 'delivery_quote.issue', 'operation_version' => $version, 'target_hash' => hash( 'sha256', 'cetech-operation-target-v1:' . $identity->target_key ), 'state' => 'accepted', 'publication_state' => 'published', 'completion_json' => $completion->to_json(), 'audit_id' => 1, 'row_version' => 2, 'created_at' => Q::time()->sql(), 'updated_at' => Q::time()->plus_seconds( 1 )->sql(), 'completed_at' => Q::time()->plus_seconds( 1 )->sql() ];
		return OperationRecord::from_row( array_replace( $row, $record_changes ), $profile, [ 'id' => 1, 'site_id' => 1, 'operation_id' => 1, 'event_format' => 1, 'event_json' => $event->to_json(), 'created_at' => Q::time()->sql() ] );
	}
	public function test_dedicated_receipt_authenticates_original_capture_after_the_hold_deadline_without_current_ledger_reads(): void {
		[ $command, $quote ] = $this->captured_fixture(); $v = new QuoteReceiptVerifier(); $record = $this->receipt( $command, $quote );
		self::assertNull( $command->lease() ); self::assertTrue( $v->promotion_issue_verified( $quote, [ 'issue' => $record ], $command ) );
		self::assertFalse( $v->issue_verified( $quote, [ 'issue' => $record ] ) ); self::assertFalse( $v->verify( $quote, [ 'issue' => $record ] ) );
		// The record may be persisted later than the sampled mutation time; SQL clocks are separate reads.
		$late = $this->receipt( $command, $quote, record_changes: [ 'completed_at' => Q::time()->plus_seconds( 3600 )->sql(), 'updated_at' => Q::time()->plus_seconds( 3600 )->sql() ] );
		self::assertTrue( $v->promotion_issue_verified( $quote, [ 'issue' => $late ], $command ) );
	}
	public function test_dedicated_receipt_rejects_v1_missing_record_changed_allocation_or_original_intent(): void {
		[ $command, $quote ] = $this->captured_fixture(); $v = new QuoteReceiptVerifier();
		foreach ( [ null, $this->receipt( $command, $quote, 1 ), $this->receipt( $command, $quote, facts: [ 'allocation_digest' => Q::digest( 'forged mapping' ) ] ), $this->receipt( $command, $quote, facts: [ 'allocation_count' => 2 ] ), $this->receipt( $command, $quote, facts: [ 'hold_until' => $command->hold_until()->plus_seconds( 1 )->epoch_microseconds() ] ), $this->receipt( $command, $quote, record_changes: [ 'intent_hash' => Q::digest( 'other original intent' ) ] ), $this->receipt( $command, $quote, record_changes: [ 'target_hash' => Q::digest( 'other original target' ) ] ), $this->receipt( $command, $quote, record_changes: [ 'completed_at' => QuoteTime::from_epoch_microseconds( Q::time()->epoch_microseconds() - 1 )->sql() ] ) ] as $record ) { self::assertFalse( $v->promotion_issue_verified( $quote, [ 'issue' => $record ], $command ) ); }
		$facts = $command->to_recovery_array(); $facts['original_issue']['account_slots'][0]['account_key'] = null;
		foreach ( $facts['original_issue']['account_slots'] as &$slot ) { if ( F::key() === $slot['component_key'] ) { $slot['account_key'] = Q::digest( 'changed immutable account' ); } } unset( $slot );
		$changed = FixedPromotionQuoteIssueCommand::from_recovery_array( $facts ); self::assertFalse( $v->promotion_issue_verified( $quote, [ 'issue' => $this->receipt( $command, $quote ) ], $changed ) );
	}
	public function test_retained_quote_cannot_be_authenticated_by_a_syntactically_valid_v2_receipt(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteStorageFixtures.php';
		[ $command ] = $this->captured_fixture(); $quote = \CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteStorageFixtures::quote(); $receipt = $this->receipt( $command, $quote ); $v = new QuoteReceiptVerifier();
		self::assertFalse( $v->issue_verified( $quote, [ 'issue' => $receipt ] ) ); self::assertFalse( $v->verify( $quote, [ 'issue' => $receipt ] ) );
	}
}
