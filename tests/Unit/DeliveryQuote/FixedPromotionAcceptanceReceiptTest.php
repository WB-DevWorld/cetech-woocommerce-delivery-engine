<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptCommand, FixedPromotionQuoteIssueCommand, QuoteDurableCommand, QuoteIssueCommand, QuoteOperationProfile, QuoteReceiptVerifier};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteHeader, QuoteId, QuoteJson, QuoteReference, QuoteStoredRow, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\{OperationCompletion, OperationMaterialEvent, OperationRecord};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryCommercial/FixedPromotionQuoteFixture.php';

/** Pure historical linkage over actual strict row/receipt codecs; no SQL or current funding authority. */
final class FixedPromotionAcceptanceReceiptTest extends TestCase {

	private FixedPromotionQuoteAcceptCommand $command;
	private QuoteStoredRow $issued;
	private QuoteStoredRow $accepted;

	protected function setUp(): void {
		$provider = F::mixed_provider(); $id = QuoteId::from_string( '10000000-0000-4000-8000-000000000001' ); $ref = Q::reference( $id );
		$slots = [ [ 'component_key' => F::key(), 'account_key' => Q::digest( 'acceptance receipt pool' ) ], [ 'component_key' => F::second_key(), 'account_key' => null ] ];
		$original = QuoteIssueCommand::fixed_promotion( F::owner(), $provider->context(), 'a9fa5bde-1b6b-45b4-8fb3-707b304c434a', $slots, Q::time()->plus_seconds( 45 ) );
		$header = QuoteHeader::issue( $id, F::owner(), $provider->context(), $provider->terms(), Q::time(), $original->namespace_hashes( $id ), 'fixed_promotion_native_v1', 1, $ref );
		$issue = FixedPromotionQuoteIssueCommand::from_recovery_array( [ 'format_version' => 1, 'original_issue' => $original->to_promotion_recovery_array(), 'header_json' => $header->to_private_json(), 'terms_json' => $provider->terms()->to_private_json(), 'reference' => $ref->public_fields() ] );
		$this->command = FixedPromotionQuoteAcceptCommand::from_issue( $issue );
		$this->issued = QuoteStoredRow::from_row( [ 'id' => 1, 'site_id' => 1, 'quote_uuid' => $id->value(), 'format_version' => 3, 'profile_code' => $header->profile(), 'profile_version' => 1, 'purpose' => 'checkout', 'principal_hash' => F::owner()->facts()['principal_hash'], 'owner_digest' => F::owner()->digest(), 'material_digest' => $header->material_digest(), 'body_digest' => $header->body_digest(), 'header_json' => $header->to_private_json(), 'private_body_json' => QuoteJson::encode( [ 'context' => $provider->context()->private_facts(), 'terms' => $provider->terms()->private_facts() ] ), 'issue_namespace_hash' => $header->namespace_hashes()['issue'], 'accept_namespace_hash' => $header->namespace_hashes()['accept'], 'invalidate_namespace_hash' => $header->namespace_hashes()['invalidate'], 'state' => 'issued', 'revision' => 1, 'retention_revision' => 1, 'created_at' => $header->created_at()->sql(), 'expires_at' => $header->expires_at()->sql(), 'accepted_at' => null, 'transition_at' => null ] );
		$this->accepted = $this->accepted_at( Q::time()->plus_seconds( 2 ) );
	}
	private function accepted_at( QuoteTime $at ): QuoteStoredRow { return QuoteStoredRow::from_row( array_replace( $this->issued->row(), [ 'state' => 'accepted', 'revision' => 2, 'accepted_at' => $at->sql(), 'transition_at' => $at->sql() ] ) ); }
	/** Every mismatch here remains syntactically valid under its immutable producer descriptor. */
	private function receipt( string $action, array $result_changes = [], array $row_changes = [], int $issue_version = 2 ): OperationRecord {
		$issue = 'issue' === $action; $identity = $issue ? $this->command->issue()->identity : $this->command->identity;
		$intent = $issue ? $this->command->issue()->intent() : $this->command->intent();
		$profile = QuoteOperationProfile::registry()->get( 'delivery_quote.' . $action, $issue ? $issue_version : 1 );
		$header = $this->command->header(); $owner = $this->command->owner(); $effect = Q::time()->plus_seconds( $issue ? 1 : 2 );
		$allocation = $issue && 2 === $issue_version ? [ 'allocation_digest' => $this->command->allocation_digest(), 'allocation_count' => 1, 'hold_until' => $this->command->hold_until()->epoch_microseconds() ] : [];
		$result = array_replace( [ 'quote_id' => $header->id()->value(), 'quote_revision' => $issue ? 1 : 2, 'state' => $issue ? 'issued' : 'accepted', 'body_digest' => $header->body_digest(), 'owner_digest' => $owner->digest(), 'namespace_hash' => $identity->namespace_digest(), 'completed_at' => $effect->epoch_microseconds(), 'control_revision' => 1, ...$allocation ], $result_changes );
		$site = $row_changes['site_id'] ?? 1;
		$completion = OperationCompletion::accepted( $profile, $result, [ 'site_id' => $site, 'owner_digest' => $result['owner_digest'], 'quote_id' => $result['quote_id'], 'purpose' => 'private_quote_invalidation' ] );
		$target = array_intersect_key( $result, array_flip( [ 'quote_id', 'body_digest', 'owner_digest', 'namespace_hash', 'quote_revision', 'allocation_digest', 'allocation_count', 'hold_until' ] ) ) + [ 'binding_revision' => 0 ];
		$event_identity = $identity;
		if ( $issue && 1 === $issue_version ) { $event_identity = $this->command->issue()->original_issue(); $event_identity = QuoteIssueCommand::create( $owner, $event_identity->context(), $event_identity->provider_code(), 1, $event_identity->profile(), 1, 'old-version' )->identity(); }
		$event = OperationMaterialEvent::from_mutation( $profile, $event_identity, RequestContext::create(), [ 'authority_hash' => hash( 'sha256', 'delivery_quote_customer' ), 'principal_hash' => hash( 'sha256', $owner->digest() ) ], $target, $issue ? 'quote_issued' : 'quote_accepted', 1, 2, $issue ? ( 2 === $issue_version ? [ 'quote', 'admission', 'promotion_budget' ] : [ 'quote', 'admission' ] ) : [ 'quote' ] );
		$effect = QuoteTime::from_epoch_microseconds( $result['completed_at'] ); $id = $issue ? 1 : 2;
		$row = array_replace( [ 'id' => $id, 'site_id' => $site, 'namespace_hash' => $identity->namespace_digest(), 'intent_hash' => $intent->fingerprint(), 'namespace_format' => 1, 'intent_format' => 1, 'record_format' => 1, 'operation' => 'delivery_quote.' . $action, 'operation_version' => $issue ? $issue_version : 1, 'target_hash' => hash( 'sha256', 'cetech-operation-target-v1:' . $identity->target_key ), 'state' => 'accepted', 'publication_state' => 'published', 'completion_json' => $completion->to_json(), 'audit_id' => $id, 'row_version' => 2, 'created_at' => Q::time()->sql(), 'updated_at' => $effect->plus_seconds( 1 )->sql(), 'completed_at' => $effect->plus_seconds( 1 )->sql() ], $row_changes );
		return OperationRecord::from_row( $row, $profile, [ 'id' => $id, 'site_id' => $site, 'operation_id' => $id, 'event_format' => 1, 'event_json' => $event->to_json(), 'created_at' => $effect->sql() ] );
	}
	private function records(): array { return [ 'issue' => $this->receipt( 'issue' ), 'accept' => $this->receipt( 'accept' ) ]; }

	public function test_exact_original_issue_and_acceptance_authenticate_accepted_history_without_current_ports(): void {
		$verifier = new QuoteReceiptVerifier(); $records = $this->records();
		self::assertTrue( $verifier->promotion_acceptance_verified( $this->accepted, $records, $this->command ) );
		self::assertNull( $this->command->issue()->lease() ); self::assertSame( 1, $records['accept']->operation_version ); self::assertSame( [ 'quote' ], $records['accept']->event->changed_fields );
		self::assertArrayNotHasKey( 'allocation_digest', $records['accept']->completion->result ); self::assertArrayNotHasKey( 'hold_until', $records['accept']->event->target );
	}
	public function test_public_issued_only_and_generic_readers_do_not_authenticate_accepted_format_three(): void {
		$v = new QuoteReceiptVerifier(); $records = $this->records();
		self::assertTrue( $v->promotion_issue_verified( $this->issued, $records, $this->command->issue() ) ); self::assertFalse( $v->promotion_issue_verified( $this->accepted, $records, $this->command->issue() ) );
		foreach ( [ $this->issued, $this->accepted ] as $row ) { self::assertFalse( $v->verify( $row, $records ) ); self::assertFalse( $v->issue_verified( $row, $records ) ); }
		self::assertFalse( $v->promotion_acceptance_verified( $this->issued, $records, $this->command ) );
	}
	public function test_late_sql_persistence_is_historical_and_preserves_the_original_acceptance_time(): void {
		$records = $this->records(); $records['accept'] = $this->receipt( 'accept', row_changes: [ 'completed_at' => Q::time()->plus_seconds( 3600 )->sql(), 'updated_at' => Q::time()->plus_seconds( 3600 )->sql() ] );
		self::assertTrue( ( new QuoteReceiptVerifier() )->promotion_acceptance_verified( $this->accepted, $records, $this->command ) ); self::assertSame( Q::time()->plus_seconds( 2 )->sql(), $this->accepted->accepted_at()->sql() );
	}
	public static function invalid_links(): array {
		return array_map( static fn( string $case ): array => [ $case ], [ 'missing issue', 'missing accept', 'wrong issue type', 'wrong accept type', 'issue version1', 'issue intent', 'accept intent', 'issue target', 'accept target', 'issue site', 'accept site', 'allocation digest', 'allocation count', 'allocation deadline', 'accept effect time', 'effect before issue', 'effect at hold deadline', 'accept persisted before effect', 'issue persisted before effect', 'accept namespace', 'accept body' ] );
	}
	#[DataProvider( 'invalid_links' )]
	public function test_syntactically_valid_but_unlinked_history_is_refused( string $case ): void {
		$records = $this->records(); $accepted = $this->accepted;
		if ( 'missing issue' === $case ) { unset( $records['issue'] ); }
		elseif ( 'missing accept' === $case ) { unset( $records['accept'] ); }
		elseif ( 'wrong issue type' === $case ) { $records['issue'] = []; }
		elseif ( 'wrong accept type' === $case ) { $records['accept'] = []; }
		elseif ( 'issue version1' === $case ) { $records['issue'] = $this->receipt( 'issue', issue_version: 1 ); }
		elseif ( in_array( $case, [ 'issue intent', 'accept intent', 'issue target', 'accept target' ], true ) ) { [ $action, $field ] = explode( ' ', $case ); $records[$action] = $this->receipt( $action, row_changes: [ 'intent' === $field ? 'intent_hash' : 'target_hash' => Q::digest( 'different immutable ' . $case ) ] ); }
		elseif ( 'issue site' === $case || 'accept site' === $case ) { $action = explode( ' ', $case )[0]; $records[$action] = $this->receipt( $action, row_changes: [ 'site_id' => 2 ] ); }
		elseif ( 'allocation digest' === $case ) { $records['issue'] = $this->receipt( 'issue', [ 'allocation_digest' => Q::digest( 'different original allocation' ) ] ); }
		elseif ( 'allocation count' === $case ) { $records['issue'] = $this->receipt( 'issue', [ 'allocation_count' => 2 ] ); }
		elseif ( 'allocation deadline' === $case ) { $records['issue'] = $this->receipt( 'issue', [ 'hold_until' => Q::time()->plus_seconds( 44 )->epoch_microseconds() ] ); }
		elseif ( 'accept effect time' === $case ) { $records['accept'] = $this->receipt( 'accept', [ 'completed_at' => Q::time()->plus_seconds( 3 )->epoch_microseconds() ] ); }
		elseif ( 'effect before issue' === $case ) { $records['issue'] = $this->receipt( 'issue', [ 'completed_at' => Q::time()->plus_seconds( 3 )->epoch_microseconds() ] ); }
		elseif ( 'effect at hold deadline' === $case ) { $accepted = $this->accepted_at( $this->command->hold_until() ); $records['accept'] = $this->receipt( 'accept', [ 'completed_at' => $this->command->hold_until()->epoch_microseconds() ] ); }
		elseif ( 'accept persisted before effect' === $case || 'issue persisted before effect' === $case ) { $action = explode( ' ', $case )[0]; $effect = Q::time()->plus_seconds( 'issue' === $action ? 1 : 2 ); $records[$action] = $this->receipt( $action, row_changes: [ 'completed_at' => QuoteTime::from_epoch_microseconds( $effect->epoch_microseconds() - 1 )->sql() ] ); }
		elseif ( 'accept namespace' === $case ) { $records['accept'] = $this->receipt( 'accept', [ 'namespace_hash' => Q::digest( 'other acceptance namespace' ) ] ); }
		elseif ( 'accept body' === $case ) { $records['accept'] = $this->receipt( 'accept', [ 'body_digest' => Q::digest( 'other accepted body' ) ] ); }
		self::assertFalse( ( new QuoteReceiptVerifier() )->promotion_acceptance_verified( $accepted, $records, $this->command ), $case );
	}
	public function test_changed_account_or_deadline_recovery_cannot_authenticate_original_accepted_history(): void {
		$records = $this->records(); $v = new QuoteReceiptVerifier();
		foreach ( [ 'account', 'deadline' ] as $case ) {
			$data = $this->command->to_recovery_array();
			if ( 'deadline' === $case ) { $data['issue']['original_issue']['hold_until'] = Q::time()->plus_seconds( 44 )->sql(); }
			else { foreach ( $data['issue']['original_issue']['account_slots'] as &$slot ) { if ( null !== $slot['account_key'] ) { $slot['account_key'] = Q::digest( 'changed selected account' ); } } unset( $slot ); }
			$changed = FixedPromotionQuoteAcceptCommand::from_recovery_array( $data ); self::assertSame( $this->command->identity->namespace_digest(), $changed->identity->namespace_digest() ); self::assertNotSame( $this->command->intent()->fingerprint(), $changed->intent()->fingerprint() );
			self::assertFalse( $v->promotion_acceptance_verified( $this->accepted, $records, $changed ) );
		}
	}
	public function test_new_canonical_secret_for_same_quote_and_original_issue_intent_cannot_disclose_history(): void {
		$data = $this->command->to_recovery_array(); $ref = QuoteReference::generate( $this->command->header()->id() );
		$header = QuoteHeader::issue( $ref->id(), $this->command->owner(), $this->command->context(), $this->command->terms(), $this->command->header()->created_at(), $this->command->header()->namespace_hashes(), 'fixed_promotion_native_v1', 1, $ref );
		$data['issue']['header_json'] = $header->to_private_json(); $data['issue']['reference'] = $ref->public_fields(); $changed = FixedPromotionQuoteAcceptCommand::from_recovery_array( $data );
		self::assertSame( $this->command->issue()->intent()->fingerprint(), $changed->issue()->intent()->fingerprint() ); self::assertSame( $this->command->identity->namespace_digest(), $changed->identity->namespace_digest() );
		self::assertFalse( ( new QuoteReceiptVerifier() )->promotion_acceptance_verified( $this->accepted, $this->records(), $changed ) );
	}
	public function test_retained_accept_v1_namespace_intent_and_completion_bytes_match_the_e10_baseline(): void {
		$id = QuoteId::from_string( '10000000-0000-4000-8000-000000000001' ); $ref = Q::reference( $id );
		$issue = QuoteIssueCommand::create( Q::owner(), Q::context(), 'fixture_v1', 1, 'fixture_v1', 1, 'old-contract-token' );
		$header = QuoteHeader::issue( $id, Q::owner(), Q::context(), Q::terms(), Q::time(), $issue->namespace_hashes( $id ), 'fixture_v1', 1, $ref ); $command = QuoteDurableCommand::accept( Q::owner(), $ref, $header, Q::context() );
		self::assertSame( '2acb72c03fc390d379d93301b688b34df6249ba4c518b26c21e11217c2fe6505', $command->identity->namespace_digest() );
		self::assertSame( 'a9716b2b2334b01b226c466e01cae7016c08d0fc115d11074b0e5909d6be54df', $command->intent()->fingerprint() );
		$result = [ 'quote_id' => $id->value(), 'quote_revision' => 2, 'state' => 'accepted', 'body_digest' => $header->body_digest(), 'owner_digest' => Q::owner()->digest(), 'namespace_hash' => $command->identity->namespace_digest(), 'completed_at' => Q::time()->plus_seconds( 1 )->epoch_microseconds(), 'control_revision' => 1 ];
		$completion = OperationCompletion::accepted( new QuoteOperationProfile( 'delivery_quote.accept' ), $result, [ 'site_id' => 1, 'owner_digest' => Q::owner()->digest(), 'quote_id' => $id->value(), 'purpose' => 'private_quote_invalidation' ] );
		self::assertSame( 'a7908bae81ae5ffaf40c8151ee2da95792c6d5a2a076e451790b6b06a06fdc4c', hash( 'sha256', $completion->to_json() ) );
	}
}
