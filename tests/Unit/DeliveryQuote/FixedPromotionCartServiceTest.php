<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetAccountStore, FixedPromotionEligibilityBudgetGuard, FixedPromotionNativeCapture, FixedPromotionNativeRequests};
use CetechDeliveryEngine\Application\DeliveryQuote\{CartQuoteService, FixedPromotionCartQuoteEnvironment, FixedPromotionNativeCartPreparedCapture, FixedPromotionQuoteAcceptInputs, FixedPromotionQuoteAcceptService, FixedPromotionQuoteIssueService, FixedPromotionQuoteIssueCommand, FixedPromotionQuotePlacementEvidenceReader, LegacyQuotePreparedCapture, LegacyQuoteSourcePlan, NativeCartQuotePreparation, QuoteCartCurrentEvidence, QuoteCartDraft, QuoteIssueCommand, QuoteNativeCaptureSource, QuoteNativeState, QuotePreparationGate, QuotePreparationCommand, QuoteProviderRegistry};
use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Domain\Contracts\{ContractError, RequestContext};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteId, QuoteOwner, QuoteReference, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\{OperationCompletion, OperationConnectionFactory, OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\{LegacyQuoteSourceSnapshotReader, WpdbOperationRecordRepository};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{CartQuoteFixtureSessions, CartQuoteFixtures, LegacyQuoteProviderFixtures as L, QuoteDurableFixtureFactory, QuoteFixtureControl, QuoteFixturePublication, QuoteFixtures as Q};
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/CartQuoteFixtures.php';
require_once __DIR__ . '/FixedPromotionCurrentEvidenceGuardTest.php';

/** Real admission and promotion service orchestration; native/database fault ports are explicit. */
final class FixedPromotionCartServiceTest extends TestCase {
	private const TOKEN = '71eb41b0-d08d-4a25-9808-6ad03e6aa001';
	private QuoteDurableFixtureFactory $gate_factory;
	private PromotionCartEnvironmentProbe $environment;
	private CartQuoteFixtureSessions $sessions;
	private PromotionCartConnectionProbe $effects;
	private PromotionCartConnectionProbe $accepts;
	private PromotionCartConnectionProbe $reads;
	private ?OperationConnectionFactory $history_factory = null;
	private bool $throw_issuer = false;
	private function service( bool $mounted = true ): CartQuoteService {
		$this->gate_factory = new QuoteDurableFixtureFactory(); $this->environment = new PromotionCartEnvironmentProbe(); $this->sessions = new CartQuoteFixtureSessions(); $this->effects = new PromotionCartConnectionProbe(); $this->accepts = new PromotionCartConnectionProbe(); $this->reads = new PromotionCartConnectionProbe();
		$this->history_factory = null; $this->throw_issuer = false; return $this->compose( $mounted );
	}
	private function compose( bool $mounted = true ): CartQuoteService {
		$control = new QuoteFixtureControl( $this->gate_factory ); $gate = new QuotePreparationGate( $this->gate_factory, [ $this->environment, 'authorize' ], static function(): void {}, $control );
		$ready = new class implements OperationReadiness { public function assert_ready( OperationSession $session ): void {} };
		$store = $this->createMock( FixedPromotionBudgetAccountStore::class ); $eligibility = $this->createMock( FixedPromotionEligibilityBudgetGuard::class );
		foreach ( [ 'lock_account', 'lock_reservation', 'lock_reservation_for_account', 'replace_account', 'insert_reservation', 'replace_reservation' ] as $method ) { $store->expects( $this->never() )->method( $method ); }
		$eligibility->expects( $this->never() )->method( 'validity_for_requests_at' );
		$issuer = function( QuoteProviderRegistry $registry ) use ( $store, $eligibility, $ready ): FixedPromotionQuoteIssueService { if ( $this->throw_issuer ) { throw new \RuntimeException( 'Declared composition failure before handoff.' ); } return new FixedPromotionQuoteIssueService( $this->history_factory ?? $this->effects, $registry, $store, $eligibility, [ $this->environment, 'authorize' ], static fn(): bool => true, $ready ); };
		$accept = new FixedPromotionQuoteAcceptService( $this->accepts, $store, $eligibility, [ $this->environment, 'authorize' ] ); $reader = new FixedPromotionQuotePlacementEvidenceReader( $this->reads, $store, $eligibility, [ $this->environment, 'authorize' ] );
		return new CartQuoteService( $this->environment, $gate, $this->sessions, $this->gate_factory, $ready, null, $control, new QuoteFixturePublication(), $mounted ? $issuer : null, $mounted ? $accept : null, $mounted ? $reader : null );
	}
	private function installed_original( bool $accepting = false ): void {
		$draft = $this->environment->draft(); $prepared = $this->environment->prepare_promotion_at( $draft, Q::time() ); $original = $prepared->command( self::TOKEN ); $id = QuoteId::generate(); $ref = QuoteReference::generate( $id );
		$header = QuoteHeader::issue( $id, $draft->owner(), $prepared->context(), $prepared->terms(), Q::time(), $original->namespace_hashes( $id ), 'fixed_promotion_native_v1', 1, $ref );
		$recovery = FixedPromotionQuoteIssueCommand::from_recovery_array( [ 'format_version' => 1, 'original_issue' => $original->to_promotion_recovery_array(), 'header_json' => $header->to_private_json(), 'terms_json' => $prepared->terms()->to_private_json(), 'reference' => $ref->public_fields() ] );
		$begin = \CetechDeliveryEngine\Application\DeliveryQuote\CartQuoteSessionEnvelope::begin( QuotePreparationCommand::fixed_promotion( $draft->owner(), $draft->draft_digest(), self::TOKEN ), 1, $this->sessions->expires_at( $draft->owner() ) );
		$this->sessions->current = $begin->stage_promotion( $recovery )->with_phase( 'issued' ); if ( $accepting ) { $this->sessions->current = $this->sessions->current->with_phase( 'accepting' ); }
	}
	public function test_default_off_missing_promotion_dependencies_refuses_before_admission_or_native_capture(): void {
		$service = $this->service( false ); $result = $service->refresh( self::TOKEN, 0, RequestContext::create() )->shopper_facts();
		self::assertSame( 'unavailable', $result['status'] ); self::assertNull( $result['quote'] ); self::assertSame( 0, $this->environment->preparations ); self::assertSame( 0, $this->effects->opens ); self::assertNull( $this->sessions->current ); self::assertSame( 0, $this->gate_factory->count( 'delivery_quote_budget_windows' ) ); self::assertNull( $service->promotion_placement( RequestContext::create() ) );
	}
	public function test_early_admission_precedes_preparation_and_exact_captureless_stage_precedes_c03_open(): void {
		$service = $this->service(); $this->environment->before_prepare = function(): void { self::assertSame( 3, $this->gate_factory->count( 'delivery_quote_budget_windows' ) ); self::assertSame( 0, $this->effects->opens ); self::assertSame( 'preparing', $this->sessions->current->phase() ); foreach ( $this->gate_factory->sessions as $session ) { self::assertTrue( $session->is_retired() ); } };
		$this->effects->before_open = function(): void { self::assertSame( 'staged', $this->sessions->current->phase() ); self::assertNotNull( $this->sessions->current->promotion_issue() ); self::assertNull( $this->sessions->current->promotion_issue()->lease() ); self::assertSame( 2, $this->sessions->current->original_issue()->identity()->operation_version ); self::assertTrue( Q::time()->plus_seconds( 45 )->equals( $this->sessions->current->promotion_issue()->hold_until() ) ); };
		$result = $service->refresh( self::TOKEN, 0, RequestContext::create() )->shopper_facts(); self::assertSame( 'unavailable', $result['status'] ); self::assertSame( 1, $this->effects->opens ); self::assertSame( 1, $this->environment->preparations ); self::assertSame( 'failed', $this->sessions->current->phase() ); self::assertSame( 0, $this->gate_factory->count( 'operation_records' ) ); self::assertSame( 1, $this->environment->aborts ); self::assertFalse( $this->environment->discounted_cache ); self::assertNotNull( $this->sessions->current->promotion_issue() );
	}
	public function test_lost_stage_ack_has_no_effect_and_retry_only_reconciles_original_history(): void {
		$service = $this->service(); $this->sessions->lose_stage_ack = true;
		$first = $service->refresh( self::TOKEN, 0, RequestContext::create() )->shopper_facts(); self::assertSame( 'unconfirmed', $first['status'] ); self::assertTrue( $first['can_retry'] ); self::assertFalse( $first['can_refresh'] ); self::assertSame( 'staged', $this->sessions->current->phase() ); self::assertSame( 0, $this->effects->opens );
		$original = $this->sessions->current->to_private_json(); $captures = $this->environment->native_source->captures;
		$this->effects->before_open = function() use ( $original ): void { self::assertSame( $original, $this->sessions->current->to_private_json() ); };
		self::assertSame( 'unconfirmed', $service->retry( 1, RequestContext::create() )->shopper_facts()['status'] ); self::assertSame( $original, $this->sessions->current->to_private_json() ); self::assertSame( 1, $this->effects->opens ); self::assertSame( 1, $this->environment->preparations ); self::assertSame( $captures, $this->environment->native_source->captures ); self::assertSame( 0, $this->environment->current_inputs ); self::assertSame( 0, $this->environment->aborts ); self::assertTrue( $this->environment->discounted_cache );
		self::assertSame( 'unconfirmed', $service->refresh( '71eb41b0-d08d-4a25-9808-6ad03e6aa002', 1, RequestContext::create() )->shopper_facts()['status'] ); self::assertSame( 1, $this->environment->preparations ); self::assertNull( $service->promotion_placement( RequestContext::create() ) );
	}
	public function test_confirmation_requires_current_issued_proof_before_accepting_cas_or_accept_effect(): void {
		$service = $this->service(); $this->installed_original(); $original = $this->sessions->current->to_private_json();
		$result = $service->confirm( 1, RequestContext::create() )->shopper_facts(); self::assertSame( 'unavailable', $result['status'] ); self::assertFalse( $result['can_confirm'] ); self::assertNull( $result['quote'] ); self::assertSame( $original, $this->sessions->current->to_private_json() ); self::assertSame( 0, $this->accepts->opens ); self::assertSame( 0, $this->effects->opens ); self::assertSame( 2, $this->reads->opens ); self::assertSame( 0, $this->sessions->writes );
	}
	public function test_accepting_retry_is_history_only_with_no_new_current_inputs_or_acceptance(): void {
		$service = $this->service(); $this->installed_original( true ); $original = $this->sessions->current->to_private_json(); $captures = $this->environment->native_source->captures;
		$result = $service->retry( 1, RequestContext::create() )->shopper_facts(); self::assertSame( 'unconfirmed', $result['status'] ); self::assertSame( $original, $this->sessions->current->to_private_json() ); self::assertSame( 1, $this->accepts->opens ); self::assertSame( 0, $this->effects->opens ); self::assertSame( 0, $this->reads->opens ); self::assertSame( 0, $this->environment->current_inputs ); self::assertSame( $captures, $this->environment->native_source->captures ); self::assertSame( 0, $this->sessions->writes ); self::assertNull( $service->promotion_placement( RequestContext::create() ) );
	}

	public function test_issuer_construction_failure_after_preparation_cleans_native_packet_and_terminates_before_handoff(): void {
		$service = $this->service(); $this->throw_issuer = true; $result = $service->refresh( self::TOKEN, 0, RequestContext::create() )->shopper_facts();
		self::assertSame( 'unavailable', $result['status'] ); self::assertSame( 1, $this->environment->aborts ); self::assertFalse( $this->environment->discounted_cache ); self::assertSame( 'failed', $this->sessions->current->phase() ); self::assertNull( $this->sessions->current->promotion_issue() ); self::assertSame( 0, $this->effects->opens ); self::assertSame( 0, $this->gate_factory->count( 'operation_records' ) );
	}
	public function test_fresh_request_authoritative_issue_rejection_aborts_existing_native_packet_without_preparing_again(): void {
		$service = $this->service(); $this->sessions->lose_stage_ack = true; self::assertSame( 'unconfirmed', $service->refresh( self::TOKEN, 0, RequestContext::create() )->shopper_facts()['status'] );
		$issue = $this->sessions->current->promotion_issue(); $recovery = $issue->to_recovery_array();
		// Seed a declared historical no-effect receipt through the actual bounded repository.
		$session = $this->gate_factory->open(); self::assertTrue( $session->begin() ); $repo = new WpdbOperationRecordRepository( $session ); self::assertTrue( $repo->insert_pending( $issue->identity, $issue->intent() ) );
		$repo->write_completion( $repo->lock( $issue->identity ), $issue->identity, $issue->intent(), OperationCompletion::rejected( new ContractError( 'temporarily_unavailable', RequestContext::create(), 'retry_original_request' ) ), null ); $session->commit(); self::assertTrue( $session->retire() );
		$this->environment = new PromotionCartEnvironmentProbe(); $this->environment->discounted_cache = true; $this->history_factory = $this->gate_factory; $fresh = $this->compose();
		$result = $fresh->retry( 1, RequestContext::create() )->shopper_facts(); self::assertSame( 'unavailable', $result['status'] ); self::assertSame( 1, $this->environment->aborts ); self::assertFalse( $this->environment->discounted_cache ); self::assertSame( 0, $this->environment->preparations ); self::assertSame( 0, $this->environment->current_inputs ); self::assertSame( 'failed', $this->sessions->current->phase() ); self::assertSame( $recovery, $this->sessions->current->promotion_issue()->to_recovery_array() ); self::assertSame( 1, $this->gate_factory->count( 'operation_records' ) ); self::assertSame( 0, $this->gate_factory->count( 'operation_changes' ) );
	}

	public function test_unacknowledged_issue_rejection_cleanup_keeps_staged_recovery_unconfirmed(): void {
		$service = $this->service(); $this->environment->abort_ack = false; $result = $service->refresh( self::TOKEN, 0, RequestContext::create() )->shopper_facts();
		self::assertSame( 'unconfirmed', $result['status'] ); self::assertFalse( $result['can_refresh'] ); self::assertTrue( $result['can_retry'] ); self::assertSame( 'staged', $this->sessions->current->phase() ); self::assertNotNull( $this->sessions->current->promotion_issue() ); self::assertTrue( $this->environment->discounted_cache ); self::assertSame( 1, $this->environment->aborts );
	}
	public function test_unacknowledged_pre_handoff_cleanup_does_not_claim_failure_complete(): void {
		$service = $this->service(); $this->throw_issuer = true; $this->environment->abort_ack = false;
		self::assertSame( 'unconfirmed', $service->refresh( self::TOKEN, 0, RequestContext::create() )->shopper_facts()['status'] ); self::assertSame( 'preparing', $this->sessions->current->phase() ); self::assertTrue( $this->environment->discounted_cache ); self::assertSame( 0, $this->effects->opens );
		$this->gate_factory->utc = '2026-10-07 05:01:00.000000'; self::assertSame( 'unconfirmed', $service->retry( 1, RequestContext::create() )->shopper_facts()['status'] ); self::assertSame( 'preparing', $this->sessions->current->phase() );
		$this->environment->abort_ack = true; self::assertSame( 'unavailable', $service->retry( 1, RequestContext::create() )->shopper_facts()['status'] ); self::assertSame( 'failed', $this->sessions->current->phase() ); self::assertFalse( $this->environment->discounted_cache ); self::assertSame( 0, $this->effects->opens );
	}

	public function test_original_staged_packet_is_invalidated_only_after_its_effect_grant_is_authoritatively_terminated(): void {
		$service = $this->service(); $this->sessions->lose_stage_ack = true; self::assertSame( 'unconfirmed', $service->refresh( self::TOKEN, 0, RequestContext::create() )->shopper_facts()['status'] ); $recovery = $this->sessions->current->promotion_issue()->to_recovery_array();
		self::assertSame( 0, $this->environment->aborts ); self::assertTrue( $this->environment->discounted_cache ); $this->gate_factory->utc = '2026-10-07 05:01:00.000000';
		self::assertSame( 'unavailable', $service->retry( 1, RequestContext::create() )->shopper_facts()['status'] ); self::assertSame( 1, $this->environment->aborts ); self::assertFalse( $this->environment->discounted_cache ); self::assertSame( 'failed', $this->sessions->current->phase() ); self::assertSame( $recovery, $this->sessions->current->promotion_issue()->to_recovery_array() ); self::assertSame( 0, $this->effects->opens );
	}

}

final class PromotionCartConnectionProbe implements OperationConnectionFactory {
	public int $opens = 0; public ?\Closure $before_open = null;
	public function open(): OperationSession { ++$this->opens; if ( null !== $this->before_open ) { ( $this->before_open )(); } throw new \RuntimeException( 'Declared pre-effect owner-open fault.' ); }
}
final class PromotionCartNativeProbe implements QuoteNativeCaptureSource {
	public int $captures = 0; private ?string $captured = null;
	public function __construct( private QuoteOwner $owner, private array $facts ) {}
	public function current_owner(): QuoteOwner { return $this->owner; }
	public function capture(): QuoteNativeState { ++$this->captures; $this->captured = json_encode( $this->facts, JSON_THROW_ON_ERROR ); return QuoteNativeState::from_array( $this->facts ); }
	public function unchanged(): bool { return null !== $this->captured && $this->captured === json_encode( $this->facts, JSON_THROW_ON_ERROR ); }
}
final class PromotionCartEnvironmentProbe implements FixedPromotionCartQuoteEnvironment {
	private QuoteCartDraft $draft; private ?FixedPromotionNativeCartPreparedCapture $prepared = null;
	public int $preparations = 0; public int $current_inputs = 0; public int $aborts = 0; public bool $discounted_cache = false; public bool $abort_ack = true; public ?\Closure $before_prepare = null; public ?PromotionCartNativeProbe $native_source = null;
	public function __construct() { $this->draft = CartQuoteFixtures::draft(); }
	public function draft(): ?QuoteCartDraft { return $this->draft; }
	public function authorize( QuoteOwner $owner, string $operation ): bool { return $owner->equals( $this->draft->owner() ); }
	public function profile(): string { return 'fixed_promotion_native_v1'; }
	public function prepare( QuoteCartDraft $draft ): LegacyQuotePreparedCapture { throw new \LogicException( 'Generic preparation must stay closed.' ); }
	public function prepare_at( QuoteCartDraft $draft, QuoteTime $at ): LegacyQuotePreparedCapture { throw new \LogicException( 'Generic preparation must stay closed.' ); }
	public function evidence( QuoteIssueCommand $original, QuoteHeader $header, QuoteCartDraft $draft ): ?QuoteCartCurrentEvidence { throw new \LogicException( 'Generic evidence must stay closed.' ); }
	public function prepare_promotion_at( QuoteCartDraft $draft, QuoteTime $at ): FixedPromotionNativeCartPreparedCapture {
		++$this->preparations; if ( null !== $this->before_prepare ) { ( $this->before_prepare )(); }
		$owner = $draft->owner(); $data = F::original_context()->private_facts(); $data['destination']['key_epoch'] = $owner->key_epoch(); $context = QuoteContext::from_array( $data ); $template = L::plan(); $session = new FixedPromotionCurrentSession( [ L::card() ] );
		$plan = LegacyQuoteSourcePlan::create( $owner, $context, $template->member_proofs(), $template->rate_ranges(), $template->fences() ); $source = ( new LegacyQuoteSourceSnapshotReader() )->capture( $session, $owner, $context, $plan ); $data = $context->private_facts();
		$data['groups'][0]['policy_digest'] = $source->policy_digest(); $data['groups'][0]['candidate_digest'] = $source->candidate_digest( 20, 50, 'GHS' ); $data['groups'][0]['candidate_count'] = $source->candidate_count( 20, 50, 'GHS' ); $context = QuoteContext::from_array( $data ); $source = $source->bind_context( $context );
		$data = F::original_terms()->private_facts(); $data['groups'][0]['policy_digest'] = $source->policy_digest(); $terms = QuoteTerms::from_array( $data ); $header = QuoteHeader::issue( QuoteId::generate(), $owner, $context, $terms, $at, [ 'issue' => Q::digest( 'cart fixture issue' ), 'accept' => Q::digest( 'cart fixture accept' ), 'invalidate' => Q::digest( 'cart fixture invalidate' ) ], 'legacy_fixed_base_v1' );
		$requests = FixedPromotionNativeRequests::from_captured( $header, $context, $terms, [ [ 'component_key' => F::key(), 'plan' => F::plan( $terms ) ] ], [ [ 'component_key' => F::key(), 'legacy_group_id' => 'in_store|delivery|20' ] ] );
		$facts = F::facts(); $facts['key_epoch'] = $owner->key_epoch(); $this->native_source = new PromotionCartNativeProbe( $owner, $facts ); $native = ( new FixedPromotionNativeCapture( $this->native_source ) )->capture( $owner, $requests );
		$this->discounted_cache = true;
		return $this->prepared = new FixedPromotionNativeCartPreparedCapture( $source, $native, F::rules(), [ [ 'component_key' => F::key(), 'account_key' => Q::digest( 'explicit isolated cart allocation' ) ] ], $at->plus_seconds( 45 ) );
	}
	public function abort_promotion(): void { ++$this->aborts; if ( ! $this->abort_ack ) { throw new \RuntimeException( 'Declared unacknowledged native session cleanup.' ); } $this->discounted_cache = false; }
	public function promotion_inputs( QuoteIssueCommand $original, QuoteCartDraft $draft ): ?FixedPromotionQuoteAcceptInputs { ++$this->current_inputs; return $this->prepared?->accept_inputs(); }
}
