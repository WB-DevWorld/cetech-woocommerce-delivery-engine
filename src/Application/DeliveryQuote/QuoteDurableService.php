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

/** Internal durable fixture lifecycle. No shopper hooks, provider discovery or native placement. */
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
	public function accept( QuoteOwner $owner, QuoteReference $reference, QuoteHeader $opened, ?QuoteContext $current, RequestContext $request ): QuoteDurableResult { return $this->transition( 'accept', $owner, $reference, $opened, $current, $request ); }
	public function invalidate( QuoteOwner $owner, QuoteReference $reference, QuoteHeader $opened, ?QuoteContext $current, RequestContext $request ): QuoteDurableResult { return $this->transition( 'invalidate', $owner, $reference, $opened, $current, $request ); }
	public function bind( QuoteOwner $owner, QuoteReference $reference, QuoteHeader $opened, QuoteBinding $binding, RequestContext $request, ?QuoteContext $current = null ): QuoteDurableResult { return $this->transition( 'bind', $owner, $reference, $opened, $current, $request, $binding ); }
	public function seal( QuoteOwner $owner, QuoteReference $reference, QuoteHeader $opened, QuoteBinding $binding, RequestContext $request, ?QuoteContext $current = null ): QuoteDurableResult { return $this->transition( 'seal', $owner, $reference, $opened, $current, $request, $binding ); }
	public function reconcile( QuoteDurableCommand $command, RequestContext $request ): QuoteDurableResult {
		if ( ! $this->authorized( $command->owner(), $command->identity->operation ) ) { return $this->reject( $request, 'not_authorized' ); }
		$attempt = $this->coordinator( $command )->reconcile( $command->identity, $command, $request ); return $this->finish( $attempt, $command, $request );
	}
	public function current( QuoteOwner $owner, QuoteReference $reference, ?QuoteContext $current, RequestContext $request ): QuoteCurrentReadResult {
		if ( ! $this->authorized( $owner, 'delivery_quote.read' ) ) { return QuoteCurrentReadResult::unavailable(); }
		$loaded = $this->load( $owner, $reference->id(), $current, $reference, 'delivery_quote.read', true );
		return null === $loaded ? QuoteCurrentReadResult::unavailable() : QuoteCurrentReadResult::ready( $loaded[0], $loaded[2] );
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
		return new OperationCoordinator( new OperationProfileRegistry( $profiles ), $this->factory, $this->readiness, $this->observer );
	}
	private function finish( OperationAttemptResult $attempt, QuoteDurableCommand $command, RequestContext $request ): QuoteDurableResult {
		if ( ! $this->authorized( $command->owner(), $command->identity->operation ) ) { return $this->unknown( $request, null ); }
		$loaded = null; $facts = $attempt->completion?->result;
		if ( null !== $facts && in_array( $attempt->completion->state, [ 'accepted', 'not_applicable' ], true ) && hash_equals( $facts['owner_digest'], $command->owner()->digest() ) && hash_equals( $facts['namespace_hash'], $command->identity->namespace_digest() ) ) {
			$loaded = $this->load( $command->owner(), QuoteId::from_string( $facts['quote_id'] ), $command->current_context(), $command->reference(), $command->identity->operation );
			if ( ! $this->authorized( $command->owner(), $command->identity->operation ) ) { return $this->unknown( $request, null ); }
			if ( null !== $loaded && ! hash_equals( $facts['body_digest'], $loaded[0]->header()->body_digest() ) ) { $loaded = null; }
			if ( null === $loaded ) { return $this->unknown( $request, $command ); }
		}
		return new QuoteDurableResult( $attempt, $command, $loaded[0] ?? null, $loaded[1] ?? null, null === $loaded ? 'quote_unavailable' : $loaded[2] );
	}
	/** Current reads require current evidence; original completion replay preserves authorized history. */
	private function load( QuoteOwner $owner, QuoteId $id, ?QuoteContext $current, ?QuoteReference $reference, string $operation, bool $require_current_evidence = false ): ?array {
		$session = null; $begun = false;
		try {
			if ( ! $this->authorized( $owner, $operation ) ) { return null; } $session = $this->factory->open(); if ( $session->site_id() !== $owner->site_id() || $session->is_retired() || $session->in_transaction() ) { return null; }
			$this->readiness->assert_ready( $session ); if ( ! $session->begin() ) { return null; } $begun = true;
			$this->control->assert_ready( $session, $owner->site_id() ); if ( ! $session->validate_tables( [ $this->control->options_table( $session ), ...DeliveryQuoteSchema::tables( $session->table_prefix() ), ...($this->evidence?->tables( $session ) ?? []) ] ) ) { return null; }
			$repo = new DeliveryQuoteRepository( $session ); $discovered = $repo->find_quote( $id );
			if ( null === $discovered || ! $discovered->header()->owner()->equals( $owner ) || ( null !== $reference && ! $discovered->header()->matches_reference( $reference ) ) ) { return null; }
			$discovered_binding = null === $discovered->accepted_at() ? null : $repo->find_binding( $discovered );
			$verifier = new QuoteReceiptVerifier(); $receipts = $verifier->lock( $session, $discovered->header(), $discovered_binding );
			$control = $this->control->current( $session ); $evidence_ok = null !== $current && $current->material_evidence_available() && null !== $this->evidence && $this->evidence->verify( $session, $owner, $current );
			$quote = $repo->find_quote( $id, true );
			if ( null === $quote || ! $quote->header()->owner()->equals( $owner ) || ( null !== $reference && ! $quote->header()->matches_reference( $reference ) ) || ! $this->authorized( $owner, $operation ) ) { return null; }
			$binding = null === $quote->accepted_at() ? null : $repo->find_binding( $quote, true );
			if ( $quote->header()->to_private_json() !== $discovered->header()->to_private_json() || ( null === $binding ) !== ( null === $discovered_binding ) || ( null !== $binding && $binding->row()['placement_uuid'] !== $discovered_binding->row()['placement_uuid'] ) || ! $verifier->verify( $quote, $receipts, $binding ) ) { return null; }
			$at = QuoteOperationProfile::time( $session );
			$reason = ! $control->enabled() ? 'checkout_suspended' : ( ! $evidence_ok ? 'quote_unavailable' : ( ! hash_equals( $quote->header()->material_digest(), $current->digest() ) ? 'quote_invalidated' : self::reason( $quote, $at ) ) );
			if ( ! $session->rollback() ) { return null; } $begun = false; if ( ! $session->retire() ) { return null; }
			if ( ! $this->authorized( $owner, $operation ) ) { return null; }
			if ( $require_current_evidence && ! $evidence_ok ) { return null; }
			return [ $quote, $binding, $reason ];
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
