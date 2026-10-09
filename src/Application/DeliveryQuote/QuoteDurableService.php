<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Application\Operation\DatabaseOperationReadiness;
use CetechDeliveryEngine\Application\Operation\OperationCoordinator;
use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\OperationOutcome;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\DeliveryQuote;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteReference;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStoredRow;
use CetechDeliveryEngine\Domain\Operation\OperationAttemptResult;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\Operation\OperationPhaseObserver;
use CetechDeliveryEngine\Domain\Operation\OperationProfileRegistry;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\EmergencyControlStore;

/** Durable lifecycle; native adapters supply prewarmed placement fences. */
final class QuoteDurableService {
	private \Closure $authorizer;
	private QuoteAdmissionGate $gate;
	private OperationReadiness $readiness;
	private EmergencyControlStore $control;
	private QuotePrivatePublication $publication;
	public function __construct( private OperationConnectionFactory $factory, private QuoteProviderRegistry $providers, callable $authorize, ?QuoteAdmissionGate $gate = null, ?OperationReadiness $readiness = null, private ?OperationPhaseObserver $observer = null, ?EmergencyControlStore $control = null, private ?QuoteCurrentEvidenceGuard $evidence = null, ?QuotePrivatePublication $publication = null ) {
		$this->authorizer = \Closure::fromCallable( $authorize ); $this->readiness = $readiness ?? new DatabaseOperationReadiness(); $this->control = $control ?? new EmergencyControlStore(); $this->publication = $publication ?? new QuoteAdvisoryPublication();
		$this->gate = $gate ?? new QuoteAdmissionGate( $factory, $authorize, null, $this->control );
	}
	public function issue( QuoteIssueCommand $command, QuoteAdmissionAttempt $attempt, RequestContext $request ): QuoteDurableResult {
		if ( ! $this->authorized( $command->owner(), 'delivery_quote.issue' ) ) { return $this->reject( $request, 'not_authorized' ); }
		if ( 'checkout' !== $command->context()->private_facts()['kind'] || ! $command->context()->material_evidence_available() ) { return $this->reject( $request, 'invalid_input' ); }
		if ( null === $this->evidence ) { return $this->reject( $request, 'temporarily_unavailable' ); }
		try { $this->providers->get( $command->provider_code(), $command->provider_version(), $command->profile(), $command->profile_version() ); } catch ( \Throwable ) { return $this->reject( $request, 'temporarily_unavailable' ); }
		try {
			$gate = $this->gate->admit( $command, $attempt );
			if ( 'pending' === $gate->status && $attempt->may_reconcile_capture( $command ) ) { $gate = $this->gate->reconcile( $command, $attempt ); }
			if ( 'completed' === $gate->status ) { return $this->execute( QuoteDurableCommand::issue_probe( $command ), $request ); }
			if ( 'capture_allowed' !== $gate->status || null === $gate->lease ) { return $this->gate_result( $gate, $command, $request ); }
			if ( ! $gate->lease->claim_capture() ) { return $this->pending( QuoteDurableCommand::issue_probe( $command ) ); }
			// Capture runs once, before C03 reservation and before any control lock.
			$terms = $this->providers->capture( $command->provider_code(), $command->provider_version(), $command->profile(), $command->profile_version(), $command->context() );
			$id = QuoteId::generate(); $reference = QuoteReference::generate( $id );
			$header = QuoteHeader::issue( $id, $command->owner(), $command->context(), $terms, $gate->lease->created_at(), $command->namespace_hashes( $id ), $command->profile(), $command->profile_version(), $reference );
			$captured = QuoteDurableCommand::captured_issue( $command, $gate->lease, DeliveryQuote::issue( $header, $command->context(), $terms ), $reference );
			return $this->execute( $captured, $request );
		} catch ( \Throwable ) { return $this->unknown( $request, $this->authorized( $command->owner(), 'delivery_quote.issue' ) ? QuoteDurableCommand::issue_probe( $command ) : null ); }
	}
	/** One charged preparation handoff. Its original recovery envelope is acknowledged before C03. */
	public function issue_admitted( QuoteIssueCommand $command, QuoteAdmissionLease $lease, RequestContext $request, callable $stage ): QuoteDurableResult {
		if ( ! $this->authorized( $command->owner(), 'delivery_quote.issue' ) ) { return $this->reject( $request, 'not_authorized' ); }
		if ( 'checkout' !== $command->context()->private_facts()['kind'] || ! $command->context()->material_evidence_available() || null === $this->evidence || ! $lease->matches( $command ) ) { return $this->reject( $request, 'temporarily_unavailable' ); }
		$original = QuoteDurableCommand::issue_probe( $command );
		try {
			$this->providers->get( $command->provider_code(), $command->provider_version(), $command->profile(), $command->profile_version() );
			if ( ! $lease->claim_capture() ) { return $this->pending( $original ); }
			$terms = $this->providers->capture( $command->provider_code(), $command->provider_version(), $command->profile(), $command->profile_version(), $command->context() );
			$id = QuoteId::generate(); $reference = QuoteReference::generate( $id );
			$header = QuoteHeader::issue( $id, $command->owner(), $command->context(), $terms, $lease->created_at(), $command->namespace_hashes( $id ), $command->profile(), $command->profile_version(), $reference );
			$original = QuoteDurableCommand::issue_probe( $command, $reference, $header );
			$captured = QuoteDurableCommand::captured_issue( $command, $lease, DeliveryQuote::issue( $header, $command->context(), $terms ), $reference );
			if ( true !== $stage( $captured ) ) { return $this->unknown( $request, $this->authorized( $command->owner(), 'delivery_quote.issue' ) ? $original : null ); }
			return $this->execute( $captured, $request );
		} catch ( \Throwable ) { return $this->unknown( $request, $this->authorized( $command->owner(), 'delivery_quote.issue' ) ? $original : null ); }
	}
	public function accept( QuoteOwner $owner, QuoteReference $reference, QuoteHeader $opened, ?QuoteContext $current, RequestContext $request ): QuoteDurableResult { return $this->transition( 'accept', $owner, $reference, $opened, $current, $request ); }
	public function invalidate( QuoteOwner $owner, QuoteReference $reference, QuoteHeader $opened, ?QuoteContext $current, RequestContext $request ): QuoteDurableResult { return $this->transition( 'invalidate', $owner, $reference, $opened, $current, $request ); }
	public function bind( QuoteOwner $owner, QuoteReference $reference, QuoteHeader $opened, QuoteBinding $binding, RequestContext $request, ?QuoteContext $current = null ): QuoteDurableResult { return $this->transition( 'bind', $owner, $reference, $opened, $current, $request, $binding ); }
	public function seal( QuoteOwner $owner, QuoteReference $reference, QuoteHeader $opened, QuoteBinding $binding, RequestContext $request, ?QuoteContext $current = null ): QuoteDurableResult { return $this->transition( 'seal', $owner, $reference, $opened, $current, $request, $binding ); }
	public function verify_binding( QuoteOwner $owner, QuoteReference $reference, QuoteHeader $opened, QuoteBinding $binding, RequestContext $request, ?QuoteContext $current, QuotePlacementSavedEvidenceGuard $saved ): QuoteDurableResult {
		if ( ! $this->authorized( $owner, 'delivery_quote.verify_binding' ) ) { return $this->reject( $request, 'not_authorized' ); }
		try { $command = QuoteDurableCommand::verify_binding( $owner, $reference, $opened, $binding, $saved, $current ); } catch ( \Throwable ) { return $this->reject( $request, 'not_authorized' ); }
		return $this->execute( $command, $request );
	}
	public function seal_placement( QuoteOwner $owner, QuoteReference $reference, QuoteHeader $opened, QuoteBinding $binding, RequestContext $request, ?QuoteContext $current, QuotePlacementProof $proof ): QuoteDurableResult {
		if ( ! $this->authorized( $owner, 'delivery_quote.seal' ) || ! $proof->unchanged() ) { return $this->reject( $request, 'not_authorized' ); }
		try { $command = QuoteDurableCommand::seal_placement( $owner, $reference, $opened, $binding, $proof, $current ); } catch ( \Throwable ) { return $this->reject( $request, 'not_authorized' ); }
		return $this->execute( $command, $request );
	}
	/** A terminal no-effect placement may release its native pointer for explicit new review. */
	public function known_rejected_original_placement( QuotePlacementEvidence $replacement, QuoteStoredRow $original, int $order_id, string $placement_id, ?QuoteBinding $expected, QuotePlacementNoEffectEvidenceGuard $native ): bool {
		return ( new QuotePlacementNoEffectDisposition( $this->factory, $this->readiness, $this->authorizer ) )->known( $replacement, $original, $order_id, $placement_id, $expected, $native );
	}
	/** A terminal final no-effect placement retains the original prepared-two contract. */
	public function known_rejected_placement( QuoteOwner $owner, QuoteReference $reference, QuoteHeader $opened, QuoteBinding $expected, QuotePlacementSavedEvidenceGuard $saved ): bool {
		if ( ! $this->authorized( $owner, 'delivery_quote.read' ) || ! $opened->owner()->equals( $owner ) || ! $opened->matches_reference( $reference ) || 'prepared' !== $expected->state() || 2 !== $expected->revision() || $expected->site_id() !== $owner->site_id() || $expected->row()['quote_uuid'] !== $opened->id()->value() ) { return false; }
		$session = null; $begun = false;
		try {
			$session = $this->factory->open();
			if ( $session->site_id() !== $owner->site_id() || $session->is_retired() || $session->in_transaction() ) { return false; }
			$this->readiness->assert_ready( $session ); if ( ! $session->begin() ) { return false; } $begun = true;
			$tables = array_values( array_unique( [ ...DeliveryQuoteSchema::tables( $session->table_prefix() ), ...$saved->tables( $session ) ] ) );
			if ( ! $session->validate_tables( $tables ) ) { return false; }
			$repository = new DeliveryQuoteRepository( $session ); $discovered = $repository->find_quote( $opened->id() );
			if ( null === $discovered || $discovered->header()->to_private_json() !== $opened->to_private_json() || ! $discovered->header()->matches_reference( $reference ) ) { return false; }
			$binding = $repository->find_binding( $discovered ); if ( null === $binding || $binding->row() !== $expected->row() ) { return false; }
			$verifier = new QuoteReceiptVerifier(); $records = $verifier->lock( $session, $discovered->header(), $binding );
			// Prewarmed native facts precede the quote/binding target locks.
			if ( ! $saved->verify( $session, $binding ) ) { return false; }
			$quote = $repository->find_quote( $opened->id(), true ); $current = null === $quote ? null : $repository->find_binding( $quote, true );
			if ( null === $quote || null === $current || $quote->header()->to_private_json() !== $opened->to_private_json() || $current->row() !== $expected->row()
				|| ! $verifier->placement_rejected( $quote, $records, $current ) || ! $saved->verify( $session, $current ) || ! $session->rollback() ) { return false; }
			$begun = false; return $session->retire() && $this->authorized( $owner, 'delivery_quote.read' );
		} catch ( \Throwable ) { return false; }
		finally { if ( null !== $session ) { if ( $begun && ! $session->is_retired() ) { try { $session->rollback(); } catch ( \Throwable ) {} } if ( ! $session->is_retired() ) { try { $session->retire(); } catch ( \Throwable ) {} } } }
	}
	/** Fresh order-pay admission; historical seal replay alone never admits a payment. */
	public function admit_sealed_placement( QuoteOwner $owner, QuoteReference $reference, QuoteHeader $opened, QuoteBinding $binding, RequestContext $request, ?QuoteContext $current, int $control_revision, \CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutLocalBinding $local, QuotePlacementSavedEvidenceGuard $saved ): bool {
		if ( ! $this->authorized( $owner, 'delivery_quote.seal' ) || 'sealed' !== $binding->state() || 3 !== $binding->revision() || $control_revision < 1 || ! $local->unchanged() || ! $opened->owner()->equals( $owner ) || ! $opened->matches_reference( $reference ) ) { return false; }
		$loaded = $this->load( $owner, $opened->id(), $current, $reference, 'delivery_quote.seal', true, saved: $saved, admission_revision: $control_revision, expected_binding: $binding, local: $local );
		return null !== $loaded && null === $loaded[2] && null !== $loaded[1] && $loaded[1]->row() === $binding->row() && $loaded[0]->header()->to_private_json() === $opened->to_private_json() && $this->authorized( $owner, 'delivery_quote.seal' ) && $local->unchanged();
	}
	public function reconcile( QuoteDurableCommand $command, RequestContext $request ): QuoteDurableResult {
		if ( ! $this->authorized( $command->owner(), $command->identity->operation ) ) { return $this->reject( $request, 'not_authorized' ); }
		$attempt = $this->coordinator( $command )->reconcile( $command->identity, $command, $request ); return $this->finish( $attempt, $command, $request );
	}
	/** Only an acknowledged exact physical read of the original Q06 seal supplies this companion. */
	public function acknowledged_promise_seal( QuoteOwner $owner, QuoteReference $reference, QuoteHeader $opened, QuoteBinding $binding, QuotePlacementSavedEvidenceGuard $saved ): ?PromiseQuoteSealLinkage {
		if ( 2 !== $opened->format_version() || ! $this->authorized( $owner, 'delivery_quote.read' ) || ! $opened->owner()->equals( $owner ) || ! $opened->matches_reference( $reference ) || 'sealed' !== $binding->state() ) { return null; }
		$loaded = $this->load( $owner, $opened->id(), null, $reference, 'delivery_quote.read', saved: $saved );
		if ( null === $loaded || $loaded[0]->header()->to_private_json() !== $opened->to_private_json() || null === $loaded[1] || $loaded[1]->row() !== $binding->row() || ! $this->authorized( $owner, 'delivery_quote.read' ) ) { return null; }
		return ( new QuoteReceiptVerifier() )->promise_seal( $loaded[0], $loaded[4], $loaded[1] );
	}
	public function current( QuoteOwner $owner, QuoteReference $reference, ?QuoteContext $current, RequestContext $request ): QuoteCurrentReadResult {
		if ( ! $this->authorized( $owner, 'delivery_quote.read' ) ) { return QuoteCurrentReadResult::unavailable(); }
		$loaded = $this->load( $owner, $reference->id(), $current, $reference, 'delivery_quote.read', true );
		return null === $loaded ? QuoteCurrentReadResult::unavailable() : QuoteCurrentReadResult::ready( $loaded[0], $loaded[2], $loaded[3] );
	}
	private function transition( string $action, QuoteOwner $owner, QuoteReference $reference, QuoteHeader $opened, ?QuoteContext $current, RequestContext $request, ?QuoteBinding $binding = null ): QuoteDurableResult {
		if ( ! $this->authorized( $owner, 'delivery_quote.' . $action ) ) { return $this->reject( $request, 'not_authorized' ); }
		// Unknown evidence is no invalidation command and cannot occupy its one-shot intent.
		if ( 'invalidate' === $action && ( null === $current || ! $current->material_evidence_available() ) ) { return $this->reject( $request, 'temporarily_unavailable' ); }
		if ( 'invalidate' === $action && hash_equals( $opened->material_digest(), $current->digest() ) ) { return $this->reject( $request, 'invalid_input' ); }
		try { $command = match ( $action ) { 'accept' => QuoteDurableCommand::accept( $owner, $reference, $opened, $current ), 'invalidate' => QuoteDurableCommand::invalidate( $owner, $reference, $opened, $current ), 'bind' => QuoteDurableCommand::bind( $owner, $reference, $opened, $binding, $current ), default => QuoteDurableCommand::seal( $owner, $reference, $opened, $binding, $current ) }; }
		catch ( \Throwable ) { return $this->reject( $request, 'not_authorized' ); }
		return $this->execute( $command, $request );
	}
	private function execute( QuoteDurableCommand $command, RequestContext $request ): QuoteDurableResult {
		if ( ! $this->authorized( $command->owner(), $command->identity->operation ) ) { return $this->reject( $request, 'not_authorized' ); }
		$attempt = $this->coordinator( $command )->attempt( $command->identity, $command, $request ); return $this->finish( $attempt, $command, $request );
	}
	private function coordinator( QuoteDurableCommand $command ): OperationCoordinator {
		$profiles = []; foreach ( QuoteOperationProfile::OPERATIONS as $operation ) { $profiles[] = new QuoteOperationProfile( $operation, $command, $this->authorizer, $this->evidence, $this->control, $this->publication ); }
		$profiles[] = new QuoteOperationProfile( 'delivery_quote.seal', $command, $this->authorizer, $this->evidence, $this->control, $this->publication, 2 );
		return new OperationCoordinator( new OperationProfileRegistry( $profiles ), $this->factory, $this->readiness, $this->observer );
	}
	private function finish( OperationAttemptResult $attempt, QuoteDurableCommand $command, RequestContext $request ): QuoteDurableResult {
		if ( ! $this->authorized( $command->owner(), $command->identity->operation ) ) { return $this->unknown( $request, null ); }
		$loaded = null; $facts = $attempt->completion?->result;
		if ( null !== $facts && in_array( $attempt->completion->state, [ 'accepted', 'not_applicable' ], true ) && hash_equals( $facts['owner_digest'], $command->owner()->digest() ) && hash_equals( $facts['namespace_hash'], $command->identity->namespace_digest() ) ) {
			$loaded = $this->load( $command->owner(), QuoteId::from_string( $facts['quote_id'] ), $command->current_context(), $command->reference(), $command->identity->operation, proof: $command->placement_proof(), saved: $command->saved_evidence() );
			if ( ! $this->authorized( $command->owner(), $command->identity->operation ) ) { return $this->unknown( $request, null ); }
			if ( null !== $loaded && ! hash_equals( $facts['body_digest'], $loaded[0]->header()->body_digest() ) ) { $loaded = null; }
			if ( null === $loaded ) { return $this->unknown( $request, $command ); }
			if ( null !== $command->placement_proof() && ( null === $loaded[1] || ! $command->placement_proof()->matches( $loaded[1] ) || ! $command->placement_proof()->unchanged() || $facts['control_revision'] !== $command->placement_proof()->control_revision() || 'sealed' !== $facts['state'] || 3 !== $facts['binding_revision'] ) ) { return $this->unknown( $request, $command ); }
		}
		return new QuoteDurableResult( $attempt, $command, $loaded[0] ?? null, $loaded[1] ?? null, null === $loaded ? 'quote_unavailable' : $loaded[2] );
	}
	/** Current reads require current evidence; original completion replay preserves authorized history. */
	private function load( QuoteOwner $owner, QuoteId $id, ?QuoteContext $current, ?QuoteReference $reference, string $operation, bool $require_current_evidence = false, ?QuotePlacementProof $proof = null, ?QuotePlacementSavedEvidenceGuard $saved = null, ?int $admission_revision = null, ?QuoteBinding $expected_binding = null, ?\CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutLocalBinding $local = null ): ?array {
		$session = null; $begun = false;
		try {
			if ( ! $this->authorized( $owner, $operation ) ) { return null; } $session = $this->factory->open(); if ( $session->site_id() !== $owner->site_id() || $session->is_retired() || $session->in_transaction() ) { return null; }
			$this->readiness->assert_ready( $session ); if ( ! $session->begin() ) { return null; } $begun = true;
			$this->control->assert_ready( $session, $owner->site_id() ); $tables = array_values( array_unique( [ $this->control->options_table( $session ), ...DeliveryQuoteSchema::tables( $session->table_prefix() ), ...($this->evidence?->tables( $session ) ?? []), ...($proof?->tables( $session ) ?? []), ...($saved?->tables( $session ) ?? []) ] ) ); if ( ! $session->validate_tables( $tables ) ) { return null; }
			$repo = new DeliveryQuoteRepository( $session ); $discovered = $repo->find_quote( $id );
			if ( null === $discovered || ! $discovered->header()->owner()->equals( $owner ) || ( null !== $reference && ! $discovered->header()->matches_reference( $reference ) ) ) { return null; }
			$discovered_binding = null === $discovered->accepted_at() ? null : $repo->find_binding( $discovered );
			$verifier = new QuoteReceiptVerifier(); $receipts = $verifier->lock( $session, $discovered->header(), $discovered_binding );
			$control = $this->control->current( $session ); $evidence_ok = null !== $current && $current->material_evidence_available() && null !== $this->evidence && $this->evidence->verify( $session, $owner, $current );
			if ( null !== $admission_revision && ( ! $control->enabled() || $control->revision !== $admission_revision || null === $local || ! $local->unchanged() ) ) { return null; }
			if ( null !== $proof && ( null === $discovered_binding || ! $proof->verify_saved( $session, $discovered_binding ) ) ) { return null; }
			if ( null !== $saved && ( null === $discovered_binding || ! $saved->verify( $session, $discovered_binding ) ) ) { return null; }
			$quote = $repo->find_quote( $id, true );
			if ( null === $quote || ! $quote->header()->owner()->equals( $owner ) || ( null !== $reference && ! $quote->header()->matches_reference( $reference ) ) || ! $this->authorized( $owner, $operation ) ) { return null; }
			$binding = null === $quote->accepted_at() ? null : $repo->find_binding( $quote, true );
			if ( $quote->header()->to_private_json() !== $discovered->header()->to_private_json() || ( null === $binding ) !== ( null === $discovered_binding ) || ( null !== $binding && $binding->row()['placement_uuid'] !== $discovered_binding->row()['placement_uuid'] ) || ! $verifier->verify( $quote, $receipts, $binding ) ) { return null; }
			if ( null !== $proof && ( null === $binding || ! $proof->matches( $binding ) || ! $proof->unchanged() ) ) { return null; }
			if ( null !== $admission_revision && ( null === $binding || null === $expected_binding || $binding->row() !== $expected_binding->row() || 'accepted' !== $quote->state() || 'sealed' !== $binding->state() || ! $evidence_ok || null === $current || ! hash_equals( $quote->header()->material_digest(), $current->digest() ) || ! $local->unchanged() ) ) { return null; }
			$at = QuoteOperationProfile::time( $session );
			if ( 2 === $quote->header()->format_version() && $require_current_evidence && ( ! $this->evidence instanceof QuoteTimedCurrentEvidenceGuard || null === $current || ! $this->evidence->verify_at( $session, $owner, $current, $at ) ) ) { return null; }
			if ( null !== $admission_revision && ( ! $quote->header()->valid_at( $at ) || ! $quote->terms()->feasibility_at( $at ) || $at->epoch_microseconds() < ( $control->changed_at_epoch ?? 0 ) * 1000000 ) ) { return null; }
			$reason = ! $control->enabled() ? 'checkout_suspended' : ( ! $evidence_ok ? 'quote_unavailable' : ( ! hash_equals( $quote->header()->material_digest(), $current->digest() ) ? 'quote_invalidated' : self::reason( $quote, $at ) ) );
			if ( ! $session->rollback() ) { return null; } $begun = false; if ( ! $session->retire() ) { return null; }
			if ( ! $this->authorized( $owner, $operation ) ) { return null; }
			if ( $require_current_evidence && ! $evidence_ok ) { return null; }
			return [ $quote, $binding, $reason, $at, $receipts ];
		} catch ( \Throwable ) { return null; }
		finally { if ( null !== $session ) { if ( $begun && ! $session->is_retired() ) { try { $session->rollback(); } catch ( \Throwable ) {} } try { $session->retire(); } catch ( \Throwable ) {} } }
	}
	private static function reason( QuoteStoredRow $quote, \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime $at ): ?string {
		if ( 'invalidated' === $quote->state() ) { return 'quote_invalidated'; } if ( 'stripped' === $quote->state() || null === $quote->context() || null === $quote->terms() ) { return 'quote_unavailable'; }
		return DeliveryQuote::issue( $quote->header(), $quote->context(), $quote->terms() )->reason_at( $at );
	}
	private function authorized( QuoteOwner $owner, string $operation ): bool { try { return true === ( $this->authorizer )( $owner, $operation ); } catch ( \Throwable ) { return false; } }
	private function gate_result( QuoteAdmissionResult $result, QuoteIssueCommand $command, RequestContext $request ): QuoteDurableResult {
		if ( ! $this->authorized( $command->owner(), 'delivery_quote.issue' ) ) { return $this->reject( $request, 'not_authorized' ); }
		$probe = QuoteDurableCommand::issue_probe( $command ); if ( 'pending' === $result->status ) { return $this->pending( $probe ); } if ( 'unconfirmed' === $result->status ) { return $this->unknown( $request, $probe ); }
		$code = match ( $result->reason ) { 'intent_conflict' => 'intent_conflict', 'not_authorized' => 'not_authorized', default => 'temporarily_unavailable' };
		return $this->reject( $request, $code, 'not_authorized' === $code ? null : $probe, 'checkout_suspended' === $result->reason ? 'checkout_suspended' : 'quote_unavailable' );
	}
	private function reject( RequestContext $request, string $code, ?QuoteDurableCommand $command = null, string $reason = 'quote_unavailable' ): QuoteDurableResult { return new QuoteDurableResult( new OperationAttemptResult( OperationOutcome::rejected( new ContractError( $code, $request, match ( $code ) { 'not_authorized' => 'contact_support', 'invalid_input', 'intent_conflict' => 'reload_and_submit', default => 'retry_original_request' } ) ) ), $command, null, null, $reason ); }
	private function unknown( RequestContext $request, ?QuoteDurableCommand $command ): QuoteDurableResult { return new QuoteDurableResult( new OperationAttemptResult( OperationOutcome::unconfirmed( new ContractError( 'outcome_unknown', $request, 'reconcile_original_request' ) ) ), $command, null, null, 'quote_unavailable' ); }
	private function pending( QuoteDurableCommand $command ): QuoteDurableResult { return new QuoteDurableResult( new OperationAttemptResult( OperationOutcome::pending() ), $command, null, null, 'quote_unavailable' ); }
}
