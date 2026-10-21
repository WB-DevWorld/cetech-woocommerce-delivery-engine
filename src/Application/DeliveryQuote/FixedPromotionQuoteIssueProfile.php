<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetAccountStore, FixedPromotionBudgetCurrentAuthority, FixedPromotionEligibilityBudgetGuard, FixedPromotionQuotePreparationService};
use CetechDeliveryEngine\Application\Operation\{OperationReadiness, OperationStorageException};
use CetechDeliveryEngine\Domain\Contracts\{OperationIdentity, RequestContext};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBudgetSlot, QuoteContext, QuoteJson, QuoteStoredRow, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\{OperationCommand, OperationCompletion, OperationMaterialEvent, OperationMutation, OperationProfile, OperationRefusal, OperationSchema, OperationSession, OperationTarget, OperationTerminalRejectionProfile};
use CetechDeliveryEngine\Infrastructure\Persistence\{DeliveryQuoteRepository, DeliveryQuoteSchema, EmergencyControlStore, FixedPromotionBudgetOperationReadiness};

/** Explicit internal issued-3 adopter. No native mount, acceptance or payment authority. */
final class FixedPromotionQuoteIssueProfile implements OperationProfile, OperationTerminalRejectionProfile {

	private ?\Closure $authorizer;
	private ?OperationSession $session = null;
	private ?array $binding = null;
	private ?array $locked = null;

	public function __construct( private ?FixedPromotionQuoteIssueCommand $bound = null, ?callable $authorize = null, private ?FixedPromotionQuotePreparationService $preparation = null, private ?FixedPromotionQuoteIssueInputs $inputs = null, private ?FixedPromotionBudgetAccountStore $store = null, private ?FixedPromotionEligibilityBudgetGuard $eligibility = null, private EmergencyControlStore $control = new EmergencyControlStore(), private QuotePrivatePublication $publication = new QuoteAdvisoryPublication(), private OperationReadiness $current_readiness = new FixedPromotionBudgetOperationReadiness() ) {
		$this->authorizer = null === $authorize ? null : \Closure::fromCallable( $authorize );
	}
	public function operation(): string { return 'delivery_quote.issue'; }
	public function version(): int { return 2; }
	public function rejects_are_terminal(): bool { return false; }
	public function authorize( OperationIdentity $identity ): bool {
		try { return null !== $this->bound && null !== $this->authorizer && $identity->operation === $this->operation() && $identity->operation_version === $this->version() && $identity->site_id === $this->bound->owner()->site_id() && hash_equals( $identity->namespace_digest(), $this->bound->identity->namespace_digest() ) && true === ( $this->authorizer )( $this->bound->owner(), $this->operation() ); } catch ( \Throwable ) { return false; }
	}
	public function validate_command( OperationIdentity $identity, mixed $command ): OperationCommand {
		if ( ! $command instanceof FixedPromotionQuoteIssueCommand || null === $this->bound || ! hash_equals( $identity->namespace_digest(), $command->identity->namespace_digest() ) || ! $command->intent()->equals( $this->bound->intent() ) || $command->header()->to_private_json() !== $this->bound->header()->to_private_json() || $command->terms()->to_private_json() !== $this->bound->terms()->to_private_json() || $command->allocation_digest() !== $this->bound->allocation_digest() ) { throw new \InvalidArgumentException( 'Invalid bound promotion issue command.' ); }
		return $command;
	}
	public function transactional_tables( OperationSession $session ): array {
		if ( null === $this->bound ) { throw new OperationStorageException(); } $this->pin( $session );
		// Reconciliation reads recorded history without a live capture, policy,
		// source or current funding proof. It never enters lock_target/mutate.
		if ( null === $this->bound->lease() ) { return DeliveryQuoteSchema::tables( $session->table_prefix() ); }
		$this->assert_mutation_dependencies();
		$this->control->assert_ready( $session, $this->bound->owner()->site_id() ); $this->assert_owned( $session );
		$tables = [ $this->control->options_table( $session ), ...$this->source_guard()->tables( $session ), ...DeliveryQuoteSchema::tables( $session->table_prefix() ) ];
		$this->assert_owned( $session ); $tables = array_values( array_unique( $tables ) );
		if ( count( $tables ) > 64 ) { throw new OperationStorageException(); }
		foreach ( $tables as $table ) { if ( ! is_string( $table ) || strlen( $table ) > 64 || ! str_starts_with( $table, $session->table_prefix() ) || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table ) ) { throw new OperationStorageException(); } }
		sort( $tables, SORT_STRING ); return $tables;
	}
	public function lock_target( OperationSession $session, OperationIdentity $identity, OperationCommand $command ): OperationTarget {
		if ( $command !== $this->bound || ! $this->authorize( $identity ) ) { $this->refuse( 'not_authorized' ); }
		$this->assert_mutation_dependencies(); $this->assert_owned( $session ); $c = $this->bound;
		$control = $this->control->current( $session ); $this->assert_owned( $session );
		if ( ! $control->enabled() ) { $this->refuse( 'temporarily_unavailable' ); }
		// Current publication locks follow control, inside this same owned unit.
		$this->current_readiness->assert_ready( $session ); $this->assert_owned( $session );
		$at = QuoteOperationProfile::time( $session ); $this->assert_owned( $session );
		// No holds are written here. Sources and eligibility precede every account,
		// every account precedes every claim, and targets are acquired last.
		$source = $this->source_guard()->source_validity_at( $session, $c->owner(), $c->context(), $at ); $this->assert_owned( $session );
		if ( null === $source ) { $this->refuse( 'temporarily_unavailable' ); }
		$eligible = $this->eligibility->validity_for_requests_at( $session, $c->owner(), $c->header(), $c->context(), $c->terms(), $c->allocation_set()->requests(), $at ); $this->assert_owned( $session );
		if ( null === $eligible || ! $eligible->valid_at( $at ) ) { $this->refuse( 'temporarily_unavailable' ); }
		foreach ( $c->allocation_set()->account_keys() as $key ) {
			$account = $this->store->lock_account( $session, $key ); $this->assert_owned( $session );
			if ( null === $account || $account->site_id() !== $c->owner()->site_id() || $account->account_key() !== $key ) { $this->refuse( 'temporarily_unavailable' ); }
		}
		foreach ( $c->allocation_set()->requests() as $request ) { $this->store->lock_reservation_for_account( $session, $request->claim_key(), $request->account_key() ); $this->assert_owned( $session ); }
		$lease = $c->lease();
		if ( null === $lease || ! $lease->capture_started() || ! $lease->matches( $c->original_issue() ) ) { $this->refuse( 'temporarily_unavailable' ); }
		$repo = new DeliveryQuoteRepository( $session );
		$admission = $repo->find_admission( $identity->namespace_digest(), true ); $this->assert_owned( $session );
		if ( null === $admission || $admission->row() !== $lease->slot()->row() || 'granted' !== $admission->row()['lease_state'] || $admission->row()['server_attempt_digest'] !== $lease->server_attempt_digest() || $admission->row()['admission_intent_digest'] !== $lease->admission_intent_digest() || $admission->row()['principal_hash'] !== $c->owner()->facts()['principal_hash'] ) { $this->refuse( 'intent_conflict' ); }
		$quote = $repo->find_quote( $c->header()->id(), true ); $this->assert_owned( $session );
		if ( null !== $quote ) { $this->refuse( 'stale_revision' ); }
		$this->session = $session; $this->locked = [ $repo, $control, $admission ];
		return new OperationTarget( self::lock_schema(), [ 'control_revision' => $control->revision, 'quote_revision' => 0, 'admission_revision' => $admission->revision() ] );
	}
	public function mutate( OperationSession $session, OperationIdentity $identity, OperationCommand $command, OperationTarget $target, RequestContext $request ): OperationMutation {
		if ( $session !== $this->session || null === $this->locked || $command !== $this->bound || ! $this->authorize( $identity ) ) { throw new OperationStorageException(); }
		$this->assert_owned( $session ); [ $repo, $control, $admission ] = $this->locked; $c = $this->bound;
		if ( $target->facts !== self::lock_schema()->validate( [ 'control_revision' => $control->revision, 'quote_revision' => 0, 'admission_revision' => $admission->revision() ] ) ) { throw new OperationStorageException(); }
		$at = QuoteOperationProfile::time( $session ); $this->assert_owned( $session );
		if ( ! $this->within_deadlines( $at, $control->changed_at_epoch ) ) { $this->refuse( 'temporarily_unavailable' ); }
		// Preparation may write holds. Every subsequent failure requires rollback of
		// the complete C03 unit, including a wait crossing the final validity fence.
		try {
			$prepared = $this->preparation->prepare( $session, $c->owner(), $c->header(), $c->context(), $c->terms(), $this->inputs->original(), $this->inputs->fresh_native(), $c->account_slots(), $at, $c->hold_until() ); $this->assert_owned( $session );
			if ( ! $this->authorize( $identity ) ) { throw new OperationStorageException(); } $this->assert_owned( $session );
			$final_at = QuoteOperationProfile::time( $session ); $this->assert_owned( $session );
			if ( $final_at->compare( $at ) < 0 || ! $this->within_deadlines( $final_at, $control->changed_at_epoch ) || ! $prepared->validity()->valid_at( $final_at ) || $prepared->allocation_set()->header()->to_private_json() !== $c->header()->to_private_json() || $prepared->allocation_set()->account_slots() !== $c->account_slots() || ! $c->lease()->matches( $c->original_issue() ) ) { throw new OperationStorageException(); }
			$quote = $this->issued_row(); $id = $repo->insert_quote( $quote ); $this->assert_owned( $session );
			$quote = QuoteStoredRow::from_row( array_replace( $quote->row(), [ 'id' => $id ] ) );
			$next = QuoteBudgetSlot::from_row( array_replace( $admission->row(), [ 'revision' => 2, 'lease_state' => 'consumed', 'consumed_quote_uuid' => $c->header()->id()->value(), 'consumed_at' => $final_at->sql(), 'last_seen_at' => $final_at->sql() ] ), $quote );
			if ( ! $repo->replace_budget( $admission, $next ) ) { throw new OperationStorageException(); } $this->assert_owned( $session );
			$result = [ 'quote_id' => $c->header()->id()->value(), 'quote_revision' => 1, 'state' => 'issued', 'body_digest' => $c->header()->body_digest(), 'owner_digest' => $c->owner()->digest(), 'namespace_hash' => $identity->namespace_digest(), 'completed_at' => $final_at->epoch_microseconds(), 'control_revision' => $control->revision, ...$this->allocation_facts() ];
			$event = OperationMaterialEvent::from_mutation( $this, $identity, $request, [ 'authority_hash' => hash( 'sha256', $identity->authority ), 'principal_hash' => hash( 'sha256', $identity->principal ) ], [ 'quote_id' => $result['quote_id'], 'body_digest' => $result['body_digest'], 'owner_digest' => $result['owner_digest'], 'namespace_hash' => $result['namespace_hash'], 'quote_revision' => 1, 'binding_revision' => 0, ...$this->allocation_facts() ], 'quote_issued', 1, 2, [ 'quote', 'admission', 'promotion_budget' ] );
			return OperationMutation::changed( OperationCompletion::accepted( $this, $result, [ 'site_id' => $identity->site_id, 'owner_digest' => $result['owner_digest'], 'quote_id' => $result['quote_id'], 'purpose' => 'private_quote_invalidation' ] ), $event );
		} catch ( \Throwable ) { throw new OperationStorageException(); }
	}
	public function publish( OperationIdentity $identity, OperationCompletion $completion ): bool {
		$p = $completion->publication; $r = $completion->result; $c = $this->bound;
		if ( ! $this->authorize( $identity ) || null === $c || null === $p || null === $r || 'accepted' !== $completion->state || $p != [ 'site_id' => $identity->site_id, 'owner_digest' => $r['owner_digest'], 'quote_id' => $r['quote_id'], 'purpose' => 'private_quote_invalidation' ] || $r['namespace_hash'] !== $identity->namespace_digest() || $r['owner_digest'] !== $c->owner()->digest() || $r['quote_id'] !== $c->header()->id()->value() || $r['body_digest'] !== $c->header()->body_digest() || array_intersect_key( $r, $this->allocation_facts() ) !== $this->allocation_facts() ) { return false; }
		return $this->publication->invalidate( $p['site_id'], $p['owner_digest'], $c->header()->id() );
	}
	public function result_schema(): OperationSchema { return new OperationSchema( [ 'quote_id' => 'uuid', 'quote_revision' => 'positive_int', 'state' => [ 'enum' => [ 'issued' ] ], 'body_digest' => 'sha256', 'owner_digest' => 'sha256', 'namespace_hash' => 'sha256', 'completed_at' => 'positive_int', 'control_revision' => 'positive_int', ...self::allocation_schema() ] ); }
	public function publication_schema(): ?OperationSchema { return new OperationSchema( [ 'site_id' => 'positive_int', 'owner_digest' => 'sha256', 'quote_id' => 'uuid', 'purpose' => [ 'enum' => [ 'private_quote_invalidation' ] ] ] ); }
	public function actor_schema(): OperationSchema { return new OperationSchema( [ 'authority_hash' => 'sha256', 'principal_hash' => 'sha256' ] ); }
	public function target_schema(): OperationSchema { return new OperationSchema( [ 'quote_id' => 'uuid', 'body_digest' => 'sha256', 'owner_digest' => 'sha256', 'namespace_hash' => 'sha256', 'quote_revision' => 'positive_int', 'binding_revision' => 'nonnegative_int', ...self::allocation_schema() ] ); }
	public function reason_codes(): array { return [ 'quote_issued' ]; }
	public function changed_fields(): array { return [ 'quote', 'admission', 'promotion_budget' ]; }
	public function validate_accepted_facts( OperationCompletion $completion, OperationMaterialEvent $event ): bool {
		if ( 'accepted' !== $completion->state || null === $completion->result || null === $completion->publication || $event->operation !== $this->operation() || $event->operation_version !== $this->version() || 'quote_issued' !== $event->reason_code ) { return false; }
		$r = $completion->result; $t = $event->target; $p = $completion->publication;
		if ( $p['owner_digest'] !== $r['owner_digest'] || $p['quote_id'] !== $r['quote_id'] || 'private_quote_invalidation' !== $p['purpose'] || $r['completed_at'] >= $r['hold_until'] || $r['allocation_count'] > QuoteContext::MAX_GROUPS ) { return false; }
		foreach ( [ 'quote_id', 'body_digest', 'owner_digest', 'namespace_hash', 'quote_revision', 'allocation_digest', 'allocation_count', 'hold_until' ] as $field ) { if ( $r[$field] !== $t[$field] ) { return false; } }
		return 'issued' === $r['state'] && 1 === $r['quote_revision'] && 0 === $t['binding_revision'] && 1 === $event->before_revision && 2 === $event->after_revision && [ 'quote', 'admission', 'promotion_budget' ] === $event->changed_fields && $event->actor['authority_hash'] === hash( 'sha256', 'delivery_quote_customer' ) && $event->actor['principal_hash'] === hash( 'sha256', $r['owner_digest'] );
	}
	private static function allocation_schema(): array { return [ 'allocation_digest' => 'sha256', 'allocation_count' => 'positive_int', 'hold_until' => 'positive_int' ]; }
	private function allocation_facts(): array { return [ 'allocation_digest' => $this->bound->allocation_digest(), 'allocation_count' => count( $this->bound->allocation_set()->requests() ), 'hold_until' => $this->bound->hold_until()->epoch_microseconds() ]; }
	private static function lock_schema(): OperationSchema { return new OperationSchema( [ 'control_revision' => 'positive_int', 'quote_revision' => 'nonnegative_int', 'admission_revision' => 'positive_int' ] ); }
	private function source_guard(): FixedPromotionQuoteCurrentEvidenceGuard {
		$c = $this->bound;
		return FixedPromotionQuoteCurrentEvidenceGuard::from_captured( $c->header(), $c->context(), $c->terms(), $this->inputs->original(), $this->inputs->fresh_native(), FixedPromotionBudgetCurrentAuthority::from_captured( $c->header(), $c->context(), $c->terms(), $c->account_slots(), $this->store, $this->eligibility ) );
	}
	private function within_deadlines( QuoteTime $at, ?int $control_epoch ): bool {
		$c = $this->bound; $lease = $c->lease();
		return null !== $lease && $lease->capture_started() && $lease->matches( $c->original_issue() ) && $at->epoch_microseconds() >= ( $control_epoch ?? 0 ) * 1000000 && $at->compare( $lease->created_at() ) >= 0 && $at->compare( $lease->expires_at() ) < 0 && $at->compare( $c->hold_until() ) < 0 && $c->header()->valid_at( $at ) && $c->header()->created_at()->compare( $lease->created_at() ) >= 0 && $c->header()->created_at()->compare( $lease->expires_at() ) < 0;
	}
	private function assert_mutation_dependencies(): void { if ( null === $this->bound || null === $this->bound->lease() || null === $this->preparation || null === $this->inputs || null === $this->store || null === $this->eligibility ) { throw new OperationStorageException(); } }
	private function pin( OperationSession $session ): void {
		$this->locked = null; $this->binding = [ $session->site_id(), $session->table_prefix(), $session->charset_collate() ]; $this->session = $session; $this->assert_owned( $session );
	}
	private function assert_owned( OperationSession $session ): void {
		if ( $session !== $this->session || null === $this->binding || $session->is_retired() || ! $session->in_transaction() || $this->binding !== [ $session->site_id(), $session->table_prefix(), $session->charset_collate() ] || $session->site_id() !== $this->bound?->owner()->site_id() || '' === $session->table_prefix() || '' === $session->charset_collate() ) { throw new OperationStorageException(); }
	}
	private function issued_row(): QuoteStoredRow {
		$c = $this->bound; $h = $c->header(); $o = $c->owner();
		return QuoteStoredRow::from_row( [ 'id' => 1, 'site_id' => $o->site_id(), 'quote_uuid' => $h->id()->value(), 'format_version' => $h->format_version(), 'profile_code' => $h->profile(), 'profile_version' => $h->profile_version(), 'purpose' => $h->purpose(), 'principal_hash' => $o->facts()['principal_hash'], 'owner_digest' => $o->digest(), 'material_digest' => $h->material_digest(), 'body_digest' => $h->body_digest(), 'header_json' => $h->to_private_json(), 'private_body_json' => QuoteJson::encode( [ 'context' => $c->context()->private_facts(), 'terms' => $c->terms()->private_facts() ] ), 'issue_namespace_hash' => $h->namespace_hashes()['issue'], 'accept_namespace_hash' => $h->namespace_hashes()['accept'], 'invalidate_namespace_hash' => $h->namespace_hashes()['invalidate'], 'state' => 'issued', 'revision' => 1, 'retention_revision' => 1, 'created_at' => $h->created_at()->sql(), 'expires_at' => $h->expires_at()->sql(), 'accepted_at' => null, 'transition_at' => null ] );
	}
	private function refuse( string $code ): never { throw new OperationRefusal( $code, match ( $code ) { 'not_authorized' => 'contact_support', 'temporarily_unavailable' => 'retry_original_request', default => 'reload_and_submit' } ); }
}
