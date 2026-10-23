<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetAccountStore, FixedPromotionBudgetCurrentAuthority, FixedPromotionEligibilityBudgetGuard};
use CetechDeliveryEngine\Application\Operation\{OperationReadiness, OperationStorageException};
use CetechDeliveryEngine\Domain\Contracts\{OperationIdentity, RequestContext};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteStoredRow, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\{OperationCommand, OperationCompletion, OperationMaterialEvent, OperationMutation, OperationProfile, OperationRefusal, OperationSchema, OperationSession, OperationTarget, OperationTerminalRejectionProfile};
use CetechDeliveryEngine\Infrastructure\Persistence\{DeliveryQuoteRepository, DeliveryQuoteSchema, EmergencyControlStore, FixedPromotionBudgetOperationReadiness};

/** Explicit saved-binding verification; original holds and physical order bytes remain unchanged. */
final class FixedPromotionQuoteVerifyProfile implements OperationProfile, OperationTerminalRejectionProfile {

	private ?\Closure $authorizer;
	private QuoteOperationProfile $descriptor;
	private ?OperationSession $session = null;
	private ?array $owner_binding = null;
	private ?array $locked = null;

	public function __construct( private ?FixedPromotionQuoteVerifyCommand $bound = null, ?callable $authorize = null, private ?FixedPromotionQuoteVerifyInputs $inputs = null, private ?FixedPromotionBudgetAccountStore $store = null, private ?FixedPromotionEligibilityBudgetGuard $eligibility = null, private EmergencyControlStore $control = new EmergencyControlStore(), private QuotePrivatePublication $publication = new QuoteAdvisoryPublication(), private OperationReadiness $current_readiness = new FixedPromotionBudgetOperationReadiness() ) {
		$this->authorizer = null === $authorize ? null : \Closure::fromCallable( $authorize );
		$this->descriptor = new QuoteOperationProfile( 'delivery_quote.verify_binding' );
	}
	public function operation(): string { return 'delivery_quote.verify_binding'; }
	public function version(): int { return 1; }
	public function rejects_are_terminal(): bool { return false; }
	public function authorize( OperationIdentity $identity ): bool {
		try { return null !== $this->bound && null !== $this->authorizer && $identity->operation === $this->operation() && $identity->operation_version === $this->version() && $identity->site_id === $this->bound->owner()->site_id() && hash_equals( $identity->namespace_digest(), $this->bound->identity->namespace_digest() ) && true === ( $this->authorizer )( $this->bound->owner(), $this->operation() ); } catch ( \Throwable ) { return false; }
	}
	public function validate_command( OperationIdentity $identity, mixed $command ): OperationCommand {
		if ( ! $command instanceof FixedPromotionQuoteVerifyCommand || null === $this->bound || ! hash_equals( $identity->namespace_digest(), $command->identity->namespace_digest() ) || ! $command->intent()->equals( $this->bound->intent() ) || $command->binding_command()->quote_record()->row() !== $this->bound->binding_command()->quote_record()->row() || $command->binding()->row() !== $this->bound->binding()->row() || $command->prepared_binding()->row() !== $this->bound->prepared_binding()->row() || ! $command->binding_command()->intent()->equals( $this->bound->binding_command()->intent() ) || $command->reference()->public_fields() !== $this->bound->reference()->public_fields() ) { throw new \InvalidArgumentException( 'Invalid bound promotion verification command.' ); }
		return $command;
	}
	public function transactional_tables( OperationSession $session ): array {
		if ( null === $this->bound ) { throw new OperationStorageException(); }
		$this->pin( $session );
		// Historical reconciliation cannot require current sources or revive mutation.
		if ( null === $this->inputs ) { return DeliveryQuoteSchema::tables( $session->table_prefix() ); }
		$this->assert_mutation_dependencies(); $this->assert_order_digest( $session );
		$this->control->assert_ready( $session, $this->bound->owner()->site_id() ); $this->assert_owned( $session );
		$guard_tables = $this->guard()->tables( $session ); $this->assert_owned( $session );
		$order_tables = $this->inputs->current()->order_evidence()->tables( $session ); $this->assert_owned( $session ); $this->assert_order_digest( $session );
		$saved_tables = $this->inputs->saved_evidence()->tables( $session ); $this->assert_owned( $session );
		if ( ! array_is_list( $saved_tables ) || count( $saved_tables ) > 35 || ! array_is_list( $order_tables ) || count( $order_tables ) > 35 ) { throw new OperationStorageException(); }
		$tables = [ $this->control->options_table( $session ), ...$guard_tables, ...$order_tables, ...$saved_tables, ...DeliveryQuoteSchema::tables( $session->table_prefix() ) ];
		$this->assert_owned( $session ); $tables = array_values( array_unique( $tables ) );
		if ( count( $tables ) > 64 ) { throw new OperationStorageException(); }
		foreach ( $tables as $table ) { if ( ! is_string( $table ) || strlen( $table ) > 64 || ! str_starts_with( $table, $session->table_prefix() ) || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table ) ) { throw new OperationStorageException(); } }
		sort( $tables, SORT_STRING ); return $tables;
	}
	public function lock_target( OperationSession $session, OperationIdentity $identity, OperationCommand $command ): OperationTarget {
		if ( $command !== $this->bound || ! $this->authorize( $identity ) ) { $this->refuse( 'not_authorized' ); }
		$this->assert_mutation_dependencies(); $this->assert_owned( $session ); $this->assert_order_digest( $session ); $c = $this->bound;
		// C03 owns the verification namespace. Prior immutable producers are nonlocking
		// prerequisites, so no operation namespace lock order is reversed.
		$verifier = new QuoteReceiptVerifier(); $receipts = $verifier->prerequisites_promotion_verification( $session, $c ); $this->assert_owned( $session );
		if ( ! $verifier->promotion_verification_prerequisites_verified( $c->binding_command()->quote_record(), $receipts, $c ) ) { throw new OperationStorageException(); }
		$control = $this->control->current( $session ); $this->assert_owned( $session );
		if ( ! $control->enabled() || $control->site_id !== $c->owner()->site_id() ) { $this->refuse( 'temporarily_unavailable' ); }
		$this->current_readiness->assert_ready( $session ); $this->assert_owned( $session );
		$at = QuoteOperationProfile::time( $session ); $this->assert_owned( $session );
		$source = $this->guard()->source_validity_at( $session, $c->owner(), $c->context(), $at ); $this->assert_owned( $session );
		if ( null === $source ) { $this->refuse( 'temporarily_unavailable' ); }
		$eligible = $this->eligibility->validity_for_requests_at( $session, $c->owner(), $c->header(), $c->context(), $c->terms(), $c->allocation_set()->requests(), $at ); $this->assert_owned( $session );
		if ( null === $eligible || ! $eligible->valid_at( $at ) || ! $this->within_deadlines( $at, $control->changed_at_epoch ) ) { $this->refuse( 'temporarily_unavailable' ); }
		$initial = QuoteCurrentEvidenceValidity::intersection( $at, $source, $eligible );
		if ( null === $initial ) { $this->refuse( 'temporarily_unavailable' ); }
		// Every account precedes every claim; native order locks precede quote/binding.
		foreach ( $c->allocation_set()->account_keys() as $key ) {
			$account = $this->store->lock_account( $session, $key ); $this->assert_owned( $session );
			if ( null === $account || $account->site_id() !== $c->owner()->site_id() || $account->account_key() !== $key ) { $this->refuse( 'temporarily_unavailable' ); }
		}
		foreach ( $c->allocation_set()->requests() as $request ) {
			$claim = $this->store->lock_reservation_for_account( $session, $request->claim_key(), $request->account_key() ); $this->assert_owned( $session );
			if ( null === $claim || ! $claim->matches( $request ) || 'held' !== $claim->state() || ! $claim->expires_at()->equals( $c->hold_until() ) || ! $claim->is_held_at( $at ) ) { $this->refuse( 'temporarily_unavailable' ); }
		}
		if ( ! $this->order_proof( $session, $c->binding_command()->quote_record(), $c->prepared_binding() ) || ! $this->saved_proof( $session, $c->binding() ) ) { $this->refuse( 'temporarily_unavailable' ); }
		$repo = new DeliveryQuoteRepository( $session ); $quote = $repo->find_quote( $c->header()->id(), true ); $this->assert_owned( $session );
		if ( null === $quote || $quote->row() !== $c->binding_command()->quote_record()->row() || ! $verifier->promotion_verification_prerequisites_verified( $quote, $receipts, $c ) ) { throw new OperationStorageException(); }
		QuoteBinding::from_row( $c->binding()->row(), $quote );
		$existing = $repo->find_binding( $quote, true ); $this->assert_owned( $session );
		if ( null === $existing || $existing->row() !== $c->prepared_binding()->row() ) { $this->refuse( 'stale_revision' ); }
		$this->locked = [ $repo, $control, $quote, $existing, $initial ];
		return new OperationTarget( self::lock_schema(), [ 'control_revision' => $control->revision, 'quote_revision' => 2, 'binding_revision' => 1 ] );
	}
	public function mutate( OperationSession $session, OperationIdentity $identity, OperationCommand $command, OperationTarget $target, RequestContext $request ): OperationMutation {
		if ( $session !== $this->session || null === $this->locked || $command !== $this->bound || ! $this->authorize( $identity ) ) { throw new OperationStorageException(); }
		$this->assert_owned( $session ); $this->assert_order_digest( $session ); [ $repo, $control, $quote, $prepared, $initial ] = $this->locked; $c = $this->bound;
		if ( $target->facts !== self::lock_schema()->validate( [ 'control_revision' => $control->revision, 'quote_revision' => 2, 'binding_revision' => 1 ] ) ) { throw new OperationStorageException(); }
		$at = QuoteOperationProfile::time( $session ); $this->assert_owned( $session );
		if ( ! $this->within_deadlines( $at, $control->changed_at_epoch ) || ! $initial->valid_at( $at ) ) { $this->refuse( 'temporarily_unavailable' ); }
		$validity = $this->guard()->validity_at( $session, $c->owner(), $c->context(), $at ); $this->assert_owned( $session );
		if ( null === $validity || ! $this->order_proof( $session, $quote, $prepared ) || ! $this->saved_proof( $session, $c->binding() ) || ! $this->authorize( $identity ) ) { $this->refuse( 'temporarily_unavailable' ); }
		$this->assert_owned( $session );
		// Order, saved-byte and current-source proofs can wait. Nothing extends their original fence.
		$final_at = QuoteOperationProfile::time( $session ); $this->assert_owned( $session );
		if ( $final_at->compare( $at ) < 0 || ! $this->within_deadlines( $final_at, $control->changed_at_epoch ) || ! $initial->valid_at( $final_at ) || ! $validity->valid_at( $final_at ) ) { $this->refuse( 'temporarily_unavailable' ); }
		$next = QuoteBinding::from_row( array_replace( $c->binding()->row(), [ 'verified_at' => $final_at->sql() ] ), $quote );
		// Any failure after a CAS attempt belongs to caller rollback of binding,
		// audit and completion together, including a lost replacement acknowledgement.
		try {
			if ( ! $repo->replace_binding( $prepared, $next ) ) { throw new OperationStorageException(); } $this->assert_owned( $session );
			$binding = $next; $b = $binding->row(); $id = $binding->id();
			$result = [ 'quote_id' => $c->header()->id()->value(), 'quote_revision' => 2, 'state' => 'prepared', 'body_digest' => $c->header()->body_digest(), 'owner_digest' => $c->owner()->digest(), 'namespace_hash' => $identity->namespace_digest(), 'completed_at' => $final_at->epoch_microseconds(), 'control_revision' => $control->revision, 'binding_id' => $id, 'placement_id' => $b['placement_uuid'], 'order_id' => $b['order_id'], 'binding_revision' => 2, 'manifest_digest' => $b['managed_group_manifest_digest'], 'snapshot_digest' => $b['snapshot_digest'], 'context_digest' => $b['context_digest'], 'native_money_digest' => $b['native_money_digest'] ];
			$event = OperationMaterialEvent::from_mutation( $this, $identity, $request, [ 'authority_hash' => hash( 'sha256', $identity->authority ), 'principal_hash' => hash( 'sha256', $identity->principal ) ], [ 'quote_id' => $result['quote_id'], 'body_digest' => $result['body_digest'], 'owner_digest' => $result['owner_digest'], 'namespace_hash' => $result['namespace_hash'], 'quote_revision' => 2, 'binding_revision' => 2 ], 'quote_binding_verified', 1, 2, [ 'binding' ] );
			return OperationMutation::changed( OperationCompletion::accepted( $this, $result, [ 'site_id' => $identity->site_id, 'owner_digest' => $result['owner_digest'], 'quote_id' => $result['quote_id'], 'purpose' => 'private_quote_invalidation' ] ), $event );
		} catch ( \Throwable ) { throw new OperationStorageException(); }
	}
	public function publish( OperationIdentity $identity, OperationCompletion $completion ): bool {
		$p = $completion->publication; $r = $completion->result; $c = $this->bound;
		if ( ! $this->authorize( $identity ) || null === $c || null === $p || null === $r || 'accepted' !== $completion->state || $p != [ 'site_id' => $identity->site_id, 'owner_digest' => $r['owner_digest'], 'quote_id' => $r['quote_id'], 'purpose' => 'private_quote_invalidation' ] || $r['namespace_hash'] !== $identity->namespace_digest() || $r['owner_digest'] !== $c->owner()->digest() || $r['quote_id'] !== $c->header()->id()->value() || $r['body_digest'] !== $c->header()->body_digest() || 'prepared' !== $r['state'] || 2 !== $r['quote_revision'] || 2 !== $r['binding_revision'] ) { return false; }
		$b = $c->binding()->row();
		foreach ( [ 'placement_id' => $b['placement_uuid'], 'order_id' => $b['order_id'], 'manifest_digest' => $b['managed_group_manifest_digest'], 'binding_id' => $b['id'], 'snapshot_digest' => $b['snapshot_digest'], 'context_digest' => $b['context_digest'], 'native_money_digest' => $b['native_money_digest'] ] as $key => $value ) { if ( $r[$key] !== $value ) { return false; } }
		$at = QuoteTime::from_epoch_microseconds( $r['completed_at'] );
		if ( ! $this->within_deadlines( $at, null ) ) { return false; }
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
		$c = $this->bound; $sources = $this->inputs->current()->sources();
		return FixedPromotionQuoteCurrentEvidenceGuard::from_captured( $c->header(), $c->context(), $c->terms(), $sources->original(), $sources->fresh_native(), FixedPromotionBudgetCurrentAuthority::from_captured( $c->header(), $c->context(), $c->terms(), $c->account_slots(), $this->store, $this->eligibility ) );
	}
	private function order_proof( OperationSession $session, QuoteStoredRow $quote, QuoteBinding $candidate ): bool {
		$this->assert_order_digest( $session ); $valid = $this->inputs->current()->order_evidence()->verify( $session, $quote, $candidate ); $this->assert_owned( $session ); $this->assert_order_digest( $session ); return true === $valid;
	}
	private function saved_proof( OperationSession $session, QuoteBinding $candidate ): bool {
		$this->assert_order_digest( $session ); $valid = $this->inputs->saved_evidence()->verify( $session, $candidate ); $this->assert_owned( $session ); $this->assert_order_digest( $session ); return true === $valid;
	}
	private function assert_order_digest( OperationSession $session ): void {
		$digest = $this->inputs->current()->order_evidence()->evidence_digest(); $this->assert_owned( $session );
		if ( ! hash_equals( $this->bound->binding_command()->order_evidence_digest(), $digest ) ) { $this->refuse( 'temporarily_unavailable' ); }
	}
	private function within_deadlines( QuoteTime $at, ?int $control_epoch ): bool { return $this->bound->header()->valid_at( $at ) && $at->compare( $this->bound->hold_until() ) < 0 && $at->compare( QuoteTime::parse( $this->bound->prepared_binding()->row()['created_at'] ) ) >= 0 && $at->epoch_microseconds() >= ( $control_epoch ?? 0 ) * 1000000; }
	private function assert_mutation_dependencies(): void { if ( null === $this->bound || null === $this->inputs || null === $this->store || null === $this->eligibility ) { throw new OperationStorageException(); } }
	private function pin( OperationSession $session ): void { $this->locked = null; $this->owner_binding = [ $session->site_id(), $session->table_prefix(), $session->charset_collate() ]; $this->session = $session; $this->assert_owned( $session ); }
	private function assert_owned( OperationSession $session ): void { if ( $session !== $this->session || null === $this->owner_binding || $session->is_retired() || ! $session->in_transaction() || $this->owner_binding !== [ $session->site_id(), $session->table_prefix(), $session->charset_collate() ] || $session->site_id() !== $this->bound?->owner()->site_id() || '' === $session->table_prefix() || '' === $session->charset_collate() ) { throw new OperationStorageException(); } }
	private function refuse( string $code ): never { throw new OperationRefusal( $code, match ( $code ) { 'not_authorized' => 'contact_support', 'temporarily_unavailable' => 'retry_original_request', default => 'reload_and_submit' } ); }
}
