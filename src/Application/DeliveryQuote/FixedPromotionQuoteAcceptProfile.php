<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetAccountStore, FixedPromotionBudgetCurrentAuthority, FixedPromotionEligibilityBudgetGuard};
use CetechDeliveryEngine\Application\Operation\{OperationReadiness, OperationStorageException};
use CetechDeliveryEngine\Domain\Contracts\{OperationIdentity, RequestContext};
use CetechDeliveryEngine\Domain\DeliveryQuote\{DeliveryQuote, QuoteStoredRow, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\{OperationCommand, OperationCompletion, OperationMaterialEvent, OperationMutation, OperationProfile, OperationRefusal, OperationSchema, OperationSession, OperationTarget, OperationTerminalRejectionProfile};
use CetechDeliveryEngine\Infrastructure\Persistence\{DeliveryQuoteRepository, DeliveryQuoteSchema, EmergencyControlStore, FixedPromotionBudgetOperationReadiness};

/** Explicit internal acceptance. It retains existing holds and never grants payment authority. */
final class FixedPromotionQuoteAcceptProfile implements OperationProfile, OperationTerminalRejectionProfile {

	private ?\Closure $authorizer;
	private QuoteOperationProfile $descriptor;
	private ?OperationSession $session = null;
	private ?array $binding = null;
	private ?array $locked = null;

	public function __construct( private ?FixedPromotionQuoteAcceptCommand $bound = null, ?callable $authorize = null, private ?FixedPromotionQuoteAcceptInputs $inputs = null, private ?FixedPromotionBudgetAccountStore $store = null, private ?FixedPromotionEligibilityBudgetGuard $eligibility = null, private EmergencyControlStore $control = new EmergencyControlStore(), private QuotePrivatePublication $publication = new QuoteAdvisoryPublication(), private OperationReadiness $current_readiness = new FixedPromotionBudgetOperationReadiness() ) {
		$this->authorizer = null === $authorize ? null : \Closure::fromCallable( $authorize );
		$this->descriptor = new QuoteOperationProfile( 'delivery_quote.accept' );
	}
	public function operation(): string { return 'delivery_quote.accept'; }
	public function version(): int { return 1; }
	public function rejects_are_terminal(): bool { return false; }
	public function authorize( OperationIdentity $identity ): bool {
		try { return null !== $this->bound && null !== $this->authorizer && $identity->operation === $this->operation() && $identity->operation_version === $this->version() && $identity->site_id === $this->bound->owner()->site_id() && hash_equals( $identity->namespace_digest(), $this->bound->identity->namespace_digest() ) && true === ( $this->authorizer )( $this->bound->owner(), $this->operation() ); } catch ( \Throwable ) { return false; }
	}
	public function validate_command( OperationIdentity $identity, mixed $command ): OperationCommand {
		if ( ! $command instanceof FixedPromotionQuoteAcceptCommand || null === $this->bound || ! hash_equals( $identity->namespace_digest(), $command->identity->namespace_digest() ) || ! $command->intent()->equals( $this->bound->intent() ) || $command->header()->to_private_json() !== $this->bound->header()->to_private_json() || $command->context()->to_private_json() !== $this->bound->context()->to_private_json() || $command->terms()->to_private_json() !== $this->bound->terms()->to_private_json() || $command->reference()->public_fields() !== $this->bound->reference()->public_fields() ) { throw new \InvalidArgumentException( 'Invalid bound promotion acceptance command.' ); }
		return $command;
	}
	public function transactional_tables( OperationSession $session ): array {
		if ( null === $this->bound ) { throw new OperationStorageException(); }
		$this->pin( $session );
		// Reconciliation only authenticates recorded history. Present funding,
		// publication, control and source dependencies cannot revive mutation.
		if ( null === $this->inputs ) { return DeliveryQuoteSchema::tables( $session->table_prefix() ); }
		$this->assert_mutation_dependencies();
		$this->control->assert_ready( $session, $this->bound->owner()->site_id() ); $this->assert_owned( $session );
		$tables = [ $this->control->options_table( $session ), ...$this->guard()->tables( $session ), ...DeliveryQuoteSchema::tables( $session->table_prefix() ) ];
		$this->assert_owned( $session ); $tables = array_values( array_unique( $tables ) );
		if ( count( $tables ) > 64 ) { throw new OperationStorageException(); }
		foreach ( $tables as $table ) { if ( ! is_string( $table ) || strlen( $table ) > 64 || ! str_starts_with( $table, $session->table_prefix() ) || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table ) ) { throw new OperationStorageException(); } }
		sort( $tables, SORT_STRING ); return $tables;
	}
	public function lock_target( OperationSession $session, OperationIdentity $identity, OperationCommand $command ): OperationTarget {
		if ( $command !== $this->bound || ! $this->authorize( $identity ) ) { $this->refuse( 'not_authorized' ); }
		$this->assert_mutation_dependencies(); $this->assert_owned( $session ); $c = $this->bound;
		// C03 already owns the acceptance namespace. Immutable issue history is a
		// nonlocking prerequisite so its namespace lock order is never reversed.
		$verifier = new QuoteReceiptVerifier(); $receipts = $verifier->prerequisites( $session, $c->header(), null, [ 'issue' ] ); $this->assert_owned( $session );
		$control = $this->control->current( $session ); $this->assert_owned( $session );
		if ( ! $control->enabled() ) { $this->refuse( 'temporarily_unavailable' ); }
		$this->current_readiness->assert_ready( $session ); $this->assert_owned( $session );
		$at = QuoteOperationProfile::time( $session ); $this->assert_owned( $session );
		$source = $this->guard()->source_validity_at( $session, $c->owner(), $c->context(), $at ); $this->assert_owned( $session );
		if ( null === $source ) { $this->refuse( 'temporarily_unavailable' ); }
		$eligible = $this->eligibility->validity_for_requests_at( $session, $c->owner(), $c->header(), $c->context(), $c->terms(), $c->allocation_set()->requests(), $at ); $this->assert_owned( $session );
		if ( null === $eligible || ! $eligible->valid_at( $at ) ) { $this->refuse( 'temporarily_unavailable' ); }
		$initial_validity = QuoteCurrentEvidenceValidity::intersection( $at, $source, $eligible );
		if ( null === $initial_validity ) { $this->refuse( 'temporarily_unavailable' ); }
		// Every account precedes every claim, and all budget participants precede
		// the quote target. No budget mutation or deadline extension is allowed.
		foreach ( $c->allocation_set()->account_keys() as $key ) {
			$account = $this->store->lock_account( $session, $key ); $this->assert_owned( $session );
			if ( null === $account || $account->site_id() !== $c->owner()->site_id() || $account->account_key() !== $key ) { $this->refuse( 'temporarily_unavailable' ); }
		}
		foreach ( $c->allocation_set()->requests() as $request ) {
			$claim = $this->store->lock_reservation_for_account( $session, $request->claim_key(), $request->account_key() ); $this->assert_owned( $session );
			if ( null === $claim || ! $claim->matches( $request ) || 'held' !== $claim->state() || ! $claim->expires_at()->equals( $c->hold_until() ) || ! $claim->is_held_at( $at ) ) { $this->refuse( 'temporarily_unavailable' ); }
		}
		$repo = new DeliveryQuoteRepository( $session ); $quote = $repo->find_quote( $c->header()->id(), true ); $this->assert_owned( $session );
		if ( null === $quote || ! $quote->header()->owner()->equals( $c->owner() ) || ! $quote->header()->matches_reference( $c->reference() ) || $quote->header()->to_private_json() !== $c->header()->to_private_json() || $quote->context()?->to_private_json() !== $c->context()->to_private_json() || $quote->terms()?->to_private_json() !== $c->terms()->to_private_json() ) { $this->refuse( 'not_authorized' ); }
		if ( 'issued' !== $quote->state() || 1 !== $quote->revision() || null !== $quote->accepted_at() ) { $this->refuse( 'stale_revision' ); }
		if ( ! $verifier->promotion_issue_verified( $quote, $receipts, $c->issue() ) ) { throw new OperationStorageException(); }
		$this->locked = [ $repo, $control, $quote, $receipts['issue'], $initial_validity ];
		return new OperationTarget( self::lock_schema(), [ 'control_revision' => $control->revision, 'quote_revision' => 1, 'binding_revision' => 0 ] );
	}
	public function mutate( OperationSession $session, OperationIdentity $identity, OperationCommand $command, OperationTarget $target, RequestContext $request ): OperationMutation {
		if ( $session !== $this->session || null === $this->locked || $command !== $this->bound || ! $this->authorize( $identity ) ) { throw new OperationStorageException(); }
		$this->assert_owned( $session ); [ $repo, $control, $quote, $issue, $initial_validity ] = $this->locked; $c = $this->bound;
		if ( $target->facts !== self::lock_schema()->validate( [ 'control_revision' => $control->revision, 'quote_revision' => $quote->revision(), 'binding_revision' => 0 ] ) ) { throw new OperationStorageException(); }
		$at = QuoteOperationProfile::time( $session ); $this->assert_owned( $session );
		$issued_at = QuoteTime::from_epoch_microseconds( $issue->completion->result['completed_at'] );
		if ( ! $this->within_deadlines( $at, $control->changed_at_epoch, $issued_at ) || ! $initial_validity->valid_at( $at ) ) { $this->refuse( 'temporarily_unavailable' ); }
		$validity = $this->guard()->validity_at( $session, $c->owner(), $c->context(), $at ); $this->assert_owned( $session );
		if ( null === $validity || ! $this->authorize( $identity ) ) { $this->refuse( 'temporarily_unavailable' ); }
		$this->assert_owned( $session );
		// Trusted proofs and authorization may wait. The final uncached owner
		// clock must still lie inside every previously observed exclusive fence.
		$final_at = QuoteOperationProfile::time( $session ); $this->assert_owned( $session );
		if ( $final_at->compare( $at ) < 0 || ! $this->within_deadlines( $final_at, $control->changed_at_epoch, $issued_at ) || ! $initial_validity->valid_at( $final_at ) || ! $validity->valid_at( $final_at ) ) { $this->refuse( 'temporarily_unavailable' ); }
		DeliveryQuote::issue( $quote->header(), $quote->context(), $quote->terms() )->accept_fixed_promotion( $c->owner(), $c->reference(), $c->context(), $final_at, $c->header()->revision(), $c->header()->body_digest(), $c->header()->expires_at() );
		$next = QuoteStoredRow::from_row( array_replace( $quote->row(), [ 'state' => 'accepted', 'revision' => 2, 'accepted_at' => $final_at->sql(), 'transition_at' => $final_at->sql() ] ) );
		// Once the CAS is attempted, every failure belongs to caller rollback of
		// the quote, audit and completion together, including a CAS miss.
		try {
			if ( ! $repo->replace_quote( $quote, $next ) ) { throw new OperationStorageException(); } $this->assert_owned( $session );
			$result = [ 'quote_id' => $c->header()->id()->value(), 'quote_revision' => 2, 'state' => 'accepted', 'body_digest' => $c->header()->body_digest(), 'owner_digest' => $c->owner()->digest(), 'namespace_hash' => $identity->namespace_digest(), 'completed_at' => $final_at->epoch_microseconds(), 'control_revision' => $control->revision ];
			$event = OperationMaterialEvent::from_mutation( $this, $identity, $request, [ 'authority_hash' => hash( 'sha256', $identity->authority ), 'principal_hash' => hash( 'sha256', $identity->principal ) ], [ 'quote_id' => $result['quote_id'], 'body_digest' => $result['body_digest'], 'owner_digest' => $result['owner_digest'], 'namespace_hash' => $result['namespace_hash'], 'quote_revision' => 2, 'binding_revision' => 0 ], 'quote_accepted', 1, 2, [ 'quote' ] );
			return OperationMutation::changed( OperationCompletion::accepted( $this, $result, [ 'site_id' => $identity->site_id, 'owner_digest' => $result['owner_digest'], 'quote_id' => $result['quote_id'], 'purpose' => 'private_quote_invalidation' ] ), $event );
		} catch ( \Throwable ) { throw new OperationStorageException(); }
	}
	public function publish( OperationIdentity $identity, OperationCompletion $completion ): bool {
		$p = $completion->publication; $r = $completion->result; $c = $this->bound;
		if ( ! $this->authorize( $identity ) || null === $c || null === $p || null === $r || 'accepted' !== $completion->state || $p != [ 'site_id' => $identity->site_id, 'owner_digest' => $r['owner_digest'], 'quote_id' => $r['quote_id'], 'purpose' => 'private_quote_invalidation' ] || $r['namespace_hash'] !== $identity->namespace_digest() || $r['owner_digest'] !== $c->owner()->digest() || $r['quote_id'] !== $c->header()->id()->value() || $r['body_digest'] !== $c->header()->body_digest() || 'accepted' !== $r['state'] || 2 !== $r['quote_revision'] ) { return false; }
		$at = QuoteTime::from_epoch_microseconds( $r['completed_at'] );
		if ( ! $c->header()->valid_at( $at ) || $at->compare( $c->hold_until() ) >= 0 ) { return false; }
		return $this->publication->invalidate( $p['site_id'], $p['owner_digest'], $c->header()->id() );
	}
	public function result_schema(): OperationSchema { return $this->descriptor->result_schema(); }
	public function publication_schema(): ?OperationSchema { return $this->descriptor->publication_schema(); }
	public function actor_schema(): OperationSchema { return $this->descriptor->actor_schema(); }
	public function target_schema(): OperationSchema { return $this->descriptor->target_schema(); }
	public function reason_codes(): array { return $this->descriptor->reason_codes(); }
	public function changed_fields(): array { return $this->descriptor->changed_fields(); }
	public function validate_accepted_facts( OperationCompletion $completion, OperationMaterialEvent $event ): bool { return $this->descriptor->validate_accepted_facts( $completion, $event ); }
	private static function lock_schema(): OperationSchema { return new OperationSchema( [ 'control_revision' => 'positive_int', 'quote_revision' => 'nonnegative_int', 'binding_revision' => 'nonnegative_int' ] ); }
	private function guard(): FixedPromotionQuoteCurrentEvidenceGuard {
		$c = $this->bound;
		return FixedPromotionQuoteCurrentEvidenceGuard::from_captured( $c->header(), $c->context(), $c->terms(), $this->inputs->original(), $this->inputs->fresh_native(), FixedPromotionBudgetCurrentAuthority::from_captured( $c->header(), $c->context(), $c->terms(), $c->account_slots(), $this->store, $this->eligibility ) );
	}
	private function within_deadlines( QuoteTime $at, ?int $control_epoch, QuoteTime $issued_at ): bool { return $this->bound->header()->valid_at( $at ) && $at->compare( $this->bound->hold_until() ) < 0 && $at->compare( $issued_at ) >= 0 && $at->epoch_microseconds() >= ( $control_epoch ?? 0 ) * 1000000; }
	private function assert_mutation_dependencies(): void { if ( null === $this->bound || null === $this->inputs || null === $this->store || null === $this->eligibility ) { throw new OperationStorageException(); } }
	private function pin( OperationSession $session ): void { $this->locked = null; $this->binding = [ $session->site_id(), $session->table_prefix(), $session->charset_collate() ]; $this->session = $session; $this->assert_owned( $session ); }
	private function assert_owned( OperationSession $session ): void {
		if ( $session !== $this->session || null === $this->binding || $session->is_retired() || ! $session->in_transaction() || $this->binding !== [ $session->site_id(), $session->table_prefix(), $session->charset_collate() ] || $session->site_id() !== $this->bound?->owner()->site_id() || '' === $session->table_prefix() || '' === $session->charset_collate() ) { throw new OperationStorageException(); }
	}
	private function refuse( string $code ): never { throw new OperationRefusal( $code, match ( $code ) { 'not_authorized' => 'contact_support', 'temporarily_unavailable' => 'retry_original_request', default => 'reload_and_submit' } ); }
}
