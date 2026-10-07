<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\DeliveryQuote;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStoredRow;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\Operation\OperationPhaseObserver;
use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Infrastructure\Persistence\EmergencyControlStore;

/** Explicit mounted cart review. No passive issuance, acceptance, snapshot writer or placement. */
final class CartQuoteService implements CartQuoteReviewService {
	public function __construct( private CartQuoteEnvironment $environment, private QuotePreparationGate $gate, private CartQuoteSessionStore $sessions, private OperationConnectionFactory $factory, private ?OperationReadiness $readiness = null, private ?OperationPhaseObserver $observer = null, private ?EmergencyControlStore $control = null, private ?QuotePrivatePublication $publication = null ) {}
	public function current( RequestContext $request ): CartQuoteResult {
		try {
			$draft = $this->draft(); if ( null === $draft ) { return $this->result( 'no_quote', 0, $request ); }
			$envelope = $this->sessions->load( $draft->owner() ); if ( null === $envelope ) { return $this->result( 'no_quote', 0, $request, refresh: true ); }
			return $this->read( $draft, $envelope, $request );
		} catch ( \Throwable ) { return $this->result( 'unavailable', 0, $request ); }
	}
	public function refresh( string $original_token, int $expected_generation, RequestContext $request ): CartQuoteResult {
		$envelope = null; $lease = null; $handoff = false; $preparation = null;
		try {
			QuoteId::from_string( $original_token ); $this->generation( $expected_generation );
			$draft = $this->draft(); if ( null === $draft || ! $this->environment->authorize( $draft->owner(), 'delivery_quote.issue' ) ) { return $this->result( 'unavailable', 0, $request ); }
			$old = $this->sessions->load( $draft->owner() );
			if ( null !== $old && $old->preparation()->original_token() === $original_token ) {
				if ( $expected_generation !== $old->generation() - 1 || $old->preparation()->draft_digest() !== $draft->draft_digest() ) { return $this->result( 'changed', $old->generation(), $request, refresh: ! $old->pending() ); }
				return $old->pending() ? $this->retry( $old->generation(), $request ) : $this->read( $draft, $old, $request );
			}
			if ( ( $old?->generation() ?? 0 ) !== $expected_generation ) { return $this->result( 'changed', $old?->generation() ?? 0, $request, refresh: ! ( $old?->pending() ?? false ) ); }
			if ( null !== $old && $old->pending() ) { return $this->result( 'unconfirmed', $old->generation(), $request, retry: true ); }
			$preparation = QuotePreparationCommand::create( $draft->owner(), $draft->draft_digest(), $original_token, LegacyFixedBaseQuoteProvider::CODE, 1, LegacyFixedBaseQuoteProvider::CODE, 1 );
			$envelope = CartQuoteSessionEnvelope::begin( $preparation, $expected_generation + 1, $this->sessions->expires_at( $draft->owner() ) );
			if ( ! $this->sessions->compare_and_swap( $draft->owner(), $old, $envelope ) ) { return $this->result( 'unconfirmed', $envelope->generation(), $request, retry: true ); }
			$attempt = QuotePreparationAttempt::generate(); $gate = $this->gate->admit( $preparation, $attempt );
			if ( 'unconfirmed' === $gate->status ) { $gate = $this->gate->reconcile( $preparation, $attempt ); }
			if ( 'preparation_allowed' !== $gate->status || null === $gate->lease ) {
				if ( in_array( $gate->status, [ 'denied' ], true ) ) { return $this->fail( $envelope ) ? $this->result( 'unavailable', $envelope->generation(), $request, refresh: true ) : $this->result( 'unconfirmed', $envelope->generation(), $request, retry: true ); }
				return $this->result( 'unconfirmed', $envelope->generation(), $request, retry: true );
			}
			$lease = $gate->lease; if ( ! $lease->claim_preparation() ) { return $this->result( 'unconfirmed', $envelope->generation(), $request, retry: true ); }
			$prepared = $this->environment->prepare( $draft );
			$fresh = $this->draft(); if ( null === $fresh || ! $draft->owner()->equals( $fresh->owner() ) || ! hash_equals( $draft->draft_digest(), $fresh->draft_digest() ) || ! $prepared->owner()->equals( $draft->owner() ) || ! $this->environment->authorize( $draft->owner(), 'delivery_quote.issue' ) ) { throw new \RuntimeException( 'Cart quote preparation unavailable.' ); }
			$command = $prepared->command( $original_token ); $admission = $lease->bind_issue( $command ); $handoff = true;
			$durable = $this->durable( $prepared->registry(), $prepared->guard() );
			$result = $durable->issue_admitted( $command, $admission, $request, function ( QuoteDurableCommand $captured ) use ( &$envelope ): bool { $staged = $envelope->stage( $captured ); if ( ! $this->sessions->compare_and_swap( $envelope->owner(), $envelope, $staged ) ) { return false; } $envelope = $staged; return true; } );
			return $this->finish( $result, $envelope, $fresh, $request, false );
		} catch ( \Throwable ) {
			if ( null !== $envelope && ! $handoff && null !== $lease && null !== $preparation ) { try { $terminated = $this->gate->terminate( $preparation, $lease ); if ( 'denied' === $terminated->status && $this->fail( $envelope ) ) { return $this->result( 'unavailable', $envelope->generation(), $request, refresh: true ); } } catch ( \Throwable ) {} }
			return $this->result( null === $envelope ? 'unavailable' : 'unconfirmed', $envelope?->generation() ?? 0, $request, retry: null !== $envelope );
		}
	}
	public function confirm( int $expected_generation, RequestContext $request ): CartQuoteResult {
		try {
			$this->generation( $expected_generation ); $draft = $this->draft(); if ( null === $draft ) { return $this->result( 'unavailable', 0, $request ); } $envelope = $this->sessions->load( $draft->owner() );
			if ( null === $envelope || $envelope->generation() !== $expected_generation ) { return $this->result( 'changed', $envelope?->generation() ?? 0, $request, refresh: true ); }
			if ( $envelope->pending() ) { return $this->result( 'unconfirmed', $envelope->generation(), $request, retry: true ); }
			if ( 'confirmed' === $envelope->phase() ) { return $this->read( $draft, $envelope, $request ); }
			if ( 'issued' !== $envelope->phase() || $envelope->preparation()->draft_digest() !== $draft->draft_digest() || ! $this->environment->authorize( $draft->owner(), 'delivery_quote.accept' ) ) { return $this->result( 'changed', $envelope->generation(), $request, refresh: true ); }
			$original = $envelope->original_issue(); $header = $envelope->header(); $reference = $envelope->reference();
			if ( null === $original || null === $header || null === $reference ) { return $this->result( 'unavailable', $envelope->generation(), $request, refresh: true ); }
			$evidence = $this->environment->evidence( $original, $header, $draft ); if ( null === $evidence ) { return $this->result( 'unavailable', $envelope->generation(), $request, refresh: true ); }
			$durable = $this->durable( new QuoteProviderRegistry(), $evidence->guard );
			$read = $durable->current( $draft->owner(), $reference, $evidence->current_context, $request );
			if ( 'ready' !== $read->status || null === $read->quote || null !== $read->reason ) { return $this->read( $draft, $envelope, $request ); }
			$accepting = $envelope->with_phase( 'accepting' ); if ( ! $this->sessions->compare_and_swap( $draft->owner(), $envelope, $accepting ) ) { return $this->result( 'unconfirmed', $envelope->generation(), $request, retry: true ); }
			$result = $durable->accept( $draft->owner(), $reference, $header, $evidence->current_context, $request ); return $this->finish( $result, $accepting, $draft, $request, true );
		} catch ( \Throwable ) { return $this->result( 'unavailable', $this->safe_generation( $expected_generation ), $request ); }
	}
	public function retry( int $expected_generation, RequestContext $request ): CartQuoteResult {
		try {
			$this->generation( $expected_generation ); $draft = $this->draft(); if ( null === $draft ) { return $this->result( 'unavailable', 0, $request ); } $envelope = $this->sessions->load( $draft->owner() );
			if ( null === $envelope || $envelope->generation() !== $expected_generation ) { return $this->result( 'changed', $envelope?->generation() ?? 0, $request, refresh: ! ( $envelope?->pending() ?? false ) ); }
			if ( ! $envelope->pending() ) { return $this->read( $draft, $envelope, $request ); }
			if ( 'preparing' === $envelope->phase() ) { $gate = $this->gate->reconcile( $envelope->preparation() ); if ( 'denied' === $gate->status && $this->fail( $envelope ) ) { return $this->result( 'unavailable', $envelope->generation(), $request, refresh: true ); } return $this->result( 'unconfirmed', $envelope->generation(), $request, retry: true ); }
			$original = $envelope->original_issue(); $header = $envelope->header(); $reference = $envelope->reference(); if ( null === $original || null === $header || null === $reference ) { return $this->result( 'unconfirmed', $envelope->generation(), $request, retry: true ); }
			if ( 'staged' === $envelope->phase() ) {
				$admission = $this->gate->reconcile( $envelope->preparation() );
				// A lost staging acknowledgement sent no C03 effect. Expired current grant closure
				// also fences every possible late effect; a consumed grant is reconciled below.
				if ( 'denied' === $admission->status && 'lease_terminated' === $admission->reason && $this->fail( $envelope ) ) { return $this->result( 'unavailable', $envelope->generation(), $request, refresh: true ); }
			}
			$evidence = $this->environment->evidence( $original, $header, $draft ); $durable = $this->durable( new QuoteProviderRegistry(), $evidence?->guard );
			$accept = 'accepting' === $envelope->phase();
			$command = $accept ? QuoteDurableCommand::accept( $draft->owner(), $reference, $header, $evidence?->current_context ) : QuoteDurableCommand::issue_probe( $original, $reference, $header );
			// Explicit POST retry may send the original accept once if its staging ACK was lost.
			// Header/body/expiry/namespace remain original; fresh evidence is only a write guard.
			$result = $accept && null !== $evidence ? $durable->accept( $draft->owner(), $reference, $header, $evidence->current_context, $request ) : $durable->reconcile( $command, $request );
			return $this->finish( $result, $envelope, $draft, $request, $accept );
		} catch ( \Throwable ) { return $this->result( 'unconfirmed', $this->safe_generation( $expected_generation ), $request, retry: true ); }
	}
	private function read( QuoteCartDraft $draft, CartQuoteSessionEnvelope $envelope, RequestContext $request ): CartQuoteResult {
		if ( $envelope->pending() ) { return $this->result( 'unconfirmed', $envelope->generation(), $request, retry: true ); }
		if ( 'failed' === $envelope->phase() ) { return $this->result( 'unavailable', $envelope->generation(), $request, refresh: true ); }
		if ( ! $envelope->owner()->equals( $draft->owner() ) || ! $this->environment->authorize( $draft->owner(), 'delivery_quote.read' ) ) { return $this->result( 'unavailable', $envelope->generation(), $request ); }
		if ( $envelope->preparation()->draft_digest() !== $draft->draft_digest() ) { return $this->result( 'changed', $envelope->generation(), $request, refresh: true ); }
		$original = $envelope->original_issue(); $header = $envelope->header(); $reference = $envelope->reference(); if ( null === $original || null === $header || null === $reference ) { return $this->result( 'unavailable', $envelope->generation(), $request, refresh: true ); }
		$evidence = $this->environment->evidence( $original, $header, $draft ); if ( null === $evidence ) { return $this->result( 'unavailable', $envelope->generation(), $request, refresh: true ); }
		$read = $this->durable( new QuoteProviderRegistry(), $evidence->guard )->current( $draft->owner(), $reference, $evidence->current_context, $request );
		if ( 'ready' !== $read->status || null === $read->quote || ! $this->environment->authorize( $draft->owner(), 'delivery_quote.read' ) ) { return $this->result( 'unavailable', $envelope->generation(), $request, refresh: true ); }
		return $this->project( $read->quote, $read->reason, $envelope, $request, $read->evaluated_at );
	}
	private function finish( QuoteDurableResult $result, CartQuoteSessionEnvelope $envelope, QuoteCartDraft $draft, RequestContext $request, bool $accept ): CartQuoteResult {
		if ( ! $this->environment->authorize( $draft->owner(), $accept ? 'delivery_quote.accept' : 'delivery_quote.issue' ) ) { return $this->result( 'unavailable', $envelope->generation(), $request ); }
		if ( 'rejected' === $result->attempt->outcome->state ) { return $this->fail( $envelope ) ? $this->result( 'unavailable', $envelope->generation(), $request, refresh: true ) : $this->result( 'unconfirmed', $envelope->generation(), $request, retry: true ); }
		if ( 'accepted' !== $result->attempt->outcome->state || null === $result->quote ) { return $this->result( 'unconfirmed', $envelope->generation(), $request, retry: true ); }
		$next = $envelope->with_phase( $accept ? 'confirmed' : 'issued' );
		if ( ! $this->sessions->compare_and_swap( $draft->owner(), $envelope, $next ) ) { return $this->result( 'unconfirmed', $envelope->generation(), $request, retry: true ); }
		return $this->read( $draft, $next, $request );
	}
	private function project( QuoteStoredRow $row, ?string $reason, CartQuoteSessionEnvelope $envelope, RequestContext $request, ?QuoteTime $evaluated_at ): CartQuoteResult {
		if ( in_array( $reason, [ 'quote_unavailable', 'checkout_suspended' ], true ) || null === $row->context() || null === $row->terms() ) { return $this->result( 'unavailable', $envelope->generation(), $request, refresh: true ); }
		if ( 'quote_invalidated' === $reason || 'invalidated' === $row->state() ) { return $this->result( 'changed', $envelope->generation(), $request, refresh: true ); }
		$model = DeliveryQuote::issue( $row->header(), $row->context(), $row->terms() );
		if ( null !== $row->accepted_at() ) { $model = $model->accept( $row->header()->owner(), $envelope->reference(), $row->context(), $row->accepted_at(), $row->header()->revision(), $row->header()->body_digest(), $row->header()->expires_at() ); }
		if ( null === $evaluated_at ) { return $this->result( 'unavailable', $envelope->generation(), $request ); }
		$projection = QuoteProjection::for_shopper( $model, $evaluated_at, $request );
		if ( 'quote_expired' === $reason || 'expired' === $projection->fields()['status'] ) { return $this->result( 'expired', $envelope->generation(), $request, $projection, true ); }
		if ( ! $projection->fields()['currently_applicable'] ) { return $this->result( 'unavailable', $envelope->generation(), $request, refresh: true ); }
		return $this->result( 'accepted' === $row->state() ? 'confirmed' : 'review_required', $envelope->generation(), $request, $projection, true, 'issued' === $row->state() );
	}
	private function fail( CartQuoteSessionEnvelope $envelope ): bool { try { return ! in_array( $envelope->phase(), [ 'failed', 'confirmed' ], true ) && $this->sessions->compare_and_swap( $envelope->owner(), $envelope, $envelope->with_phase( 'failed' ) ); } catch ( \Throwable ) { return false; } }
	private function draft(): ?QuoteCartDraft { $draft = $this->environment->draft(); if ( null !== $draft && ! $this->environment->authorize( $draft->owner(), 'delivery_quote.read' ) ) { throw new \RuntimeException( 'Cart quote unavailable.' ); } return $draft; }
	private function durable( QuoteProviderRegistry $providers, ?QuoteCurrentEvidenceGuard $guard ): QuoteDurableService { return new QuoteDurableService( $this->factory, $providers, [ $this->environment, 'authorize' ], null, $this->readiness, $this->observer, $this->control, $guard, $this->publication ); }
	private function generation( int $generation ): void { if ( $generation < 0 || $generation >= CartQuoteSessionEnvelope::MAX_GENERATION ) { throw new \InvalidArgumentException( 'Invalid cart quote generation.' ); } }
	private function safe_generation( int $generation ): int { return $generation < 0 || $generation > CartQuoteSessionEnvelope::MAX_GENERATION ? 0 : $generation; }
	private function result( string $status, int $generation, RequestContext $request, ?QuoteProjectionResult $quote = null, bool $refresh = false, bool $confirm = false, bool $retry = false ): CartQuoteResult { return CartQuoteResult::create( $status, $generation, $request, $quote, $refresh, $confirm, $retry ); }
}
