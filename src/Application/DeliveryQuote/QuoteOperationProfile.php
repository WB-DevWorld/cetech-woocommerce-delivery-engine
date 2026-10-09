<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\DeliveryQuote;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBudgetSlot;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStoredRow;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Domain\Operation\OperationCommand;
use CetechDeliveryEngine\Domain\Operation\OperationCompletion;
use CetechDeliveryEngine\Domain\Operation\OperationMaterialEvent;
use CetechDeliveryEngine\Domain\Operation\OperationMutation;
use CetechDeliveryEngine\Domain\Operation\OperationProfile;
use CetechDeliveryEngine\Domain\Operation\OperationProfileRegistry;
use CetechDeliveryEngine\Domain\Operation\OperationRefusal;
use CetechDeliveryEngine\Domain\Operation\OperationSchema;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Domain\Operation\OperationTarget;
use CetechDeliveryEngine\Domain\Operation\OperationTerminalRejectionProfile;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\EmergencyControlStore;

/** Finite lifecycle profiles. No provider capture, Woo CRUD or external IO in this unit. */
final class QuoteOperationProfile implements OperationProfile, OperationTerminalRejectionProfile {
	public const OPERATIONS = [ 'delivery_quote.issue', 'delivery_quote.accept', 'delivery_quote.invalidate', 'delivery_quote.bind', 'delivery_quote.verify_binding', 'delivery_quote.seal' ];
	private ?\Closure $authorizer;
	private ?OperationSession $session = null;
	private ?array $locked = null;
	public function __construct( private string $name, private ?QuoteDurableCommand $bound = null, ?callable $authorize = null, private ?QuoteCurrentEvidenceGuard $evidence = null, private EmergencyControlStore $control = new EmergencyControlStore(), private QuotePrivatePublication $publication = new QuoteAdvisoryPublication(), private int $profile_version = 1 ) {
		if ( ! in_array( $name, self::OPERATIONS, true ) || ! in_array( $profile_version, [ 1, 2 ], true ) || ( 2 === $profile_version && 'delivery_quote.seal' !== $name ) ) { throw new \InvalidArgumentException( 'Unsupported quote operation.' ); }
		$this->authorizer = null === $authorize ? null : \Closure::fromCallable( $authorize );
	}
	/** Hydration-only finite registry; none of these unbound profiles authorizes a write. */
	public static function registry(): OperationProfileRegistry { return new OperationProfileRegistry( [ ...array_map( static fn( string $operation ): self => new self( $operation ), self::OPERATIONS ), new self( 'delivery_quote.seal', profile_version: 2 ) ] ); }
	public function operation(): string { return $this->name; }
	public function version(): int { return $this->profile_version; }
	/** Shopper placement refusals retain their original finite receipt; legacy internal v1 remains retryable. */
	public function rejects_are_terminal(): bool {
		if ( null === $this->bound || $this->bound->identity->operation !== $this->name || $this->bound->identity->operation_version !== $this->version() ) { return false; }
		if ( 'delivery_quote.verify_binding' === $this->name || 'delivery_quote.seal' === $this->name && 2 === $this->version() ) { return true; }
		if ( 'delivery_quote.bind' !== $this->name || null === $this->bound->binding() || null === $this->bound->header() ) { return false; }
		$b = $this->bound->binding()->row(); $names = QuoteDurableCommand::binding_namespaces( $this->bound->owner(), $this->bound->header(), $b['placement_uuid'], true );
		return $b['bind_namespace_hash'] === $names['bind'] && $b['seal_namespace_hash'] === $names['seal'];
	}
	public function authorize( OperationIdentity $identity ): bool {
		try { return null !== $this->bound && null !== $this->authorizer && $identity->operation === $this->name && $identity->operation_version === $this->profile_version && $identity->site_id === $this->bound->owner()->site_id() && hash_equals( $identity->namespace_digest(), $this->bound->identity->namespace_digest() ) && true === ( $this->authorizer )( $this->bound->owner(), $this->name ); } catch ( \Throwable ) { return false; }
	}
	public function validate_command( OperationIdentity $identity, mixed $command ): OperationCommand {
		if ( ! $command instanceof QuoteDurableCommand || null === $this->bound || ! hash_equals( $identity->namespace_digest(), $command->identity->namespace_digest() ) || ! $command->intent()->equals( $this->bound->intent() ) ) { throw new \InvalidArgumentException( 'Invalid bound quote command.' ); } return $command;
	}
	public function transactional_tables( OperationSession $session ): array {
		$this->control->assert_ready( $session, $session->site_id() );
		$additional = [ ...($this->evidence?->tables( $session ) ?? []), ...($this->bound?->placement_proof()?->tables( $session ) ?? []), ...($this->bound?->saved_evidence()?->tables( $session ) ?? []) ];
		if ( ! array_is_list( $additional ) ) { throw new OperationStorageException(); }
		foreach ( $additional as $table ) { if ( ! is_string( $table ) || strlen( $table ) > 64 || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table ) || ! str_starts_with( $table, $session->table_prefix() ) ) { throw new OperationStorageException(); } }
		// P04 adds the three exact P02 source stores to the retained native
		// source/order census. Shared operation receipts count only once.
		$header = $this->bound?->header();
		$limit = null !== $header && 2 === $header->format_version() && 'service_promise_v1' === $header->profile() && 1 === $header->profile_version() ? 38 : 35;
		$additional = array_values( array_unique( $additional ) ); if ( count( $additional ) > $limit ) { throw new OperationStorageException(); }
		sort( $additional, SORT_STRING ); return array_values( array_unique( [ $this->control->options_table( $session ), ...$additional, ...DeliveryQuoteSchema::tables( $session->table_prefix() ) ] ) );
	}
	public function lock_target( OperationSession $session, OperationIdentity $identity, OperationCommand $command ): OperationTarget {
		if ( $command !== $this->bound || ! $this->authorize( $identity ) ) { $this->refuse( 'not_authorized' ); }
		$c = $this->bound; $receipts = [];
		if ( 'delivery_quote.issue' !== $this->name ) {
			$purposes = match ( $this->name ) { 'delivery_quote.accept' => [ 'issue' ], 'delivery_quote.seal' => 2 === $this->version() ? [ 'issue', 'accept', 'bind', 'verify_binding' ] : [ 'issue', 'accept', 'bind' ], 'delivery_quote.verify_binding' => [ 'issue', 'accept', 'bind' ], default => [ 'issue', 'accept' ] };
			$receipts = ( new QuoteReceiptVerifier() )->prerequisites( $session, $c->header(), $c->binding(), $purposes );
		}
		$control = $this->control->current( $session );
		if ( ! $control->enabled() ) { $this->refuse( 'temporarily_unavailable' ); }
		$current = $c->current_context();
		if ( null === $this->evidence || null === $current || ! $current->material_evidence_available() || ! $this->evidence->verify( $session, $c->owner(), $current ) ) { $this->refuse( 'temporarily_unavailable' ); }
		// Saved Woo source fences precede quote/binding target locks. No Woo IO occurs.
		if ( 'delivery_quote.seal' === $this->name && 2 === $this->version() && ( null === $c->placement_proof() || null === $c->binding() || ! $c->placement_proof()->verify( $session, $c->binding(), $control->revision ) ) ) { $this->refuse( 'temporarily_unavailable' ); }
		if ( 'delivery_quote.verify_binding' === $this->name && ( null === $c->saved_evidence() || null === $c->binding() || ! $c->saved_evidence()->verify( $session, $c->binding() ) ) ) { $this->refuse( 'temporarily_unavailable' ); }
		$repo = new DeliveryQuoteRepository( $session ); $lease = null; $quote = null; $binding = null;
		if ( 'delivery_quote.issue' === $this->name ) {
			if ( null === $c->capture() || null === $c->lease() || null === $c->original_issue() || ! $c->lease()->capture_started() ) { $this->refuse( 'temporarily_unavailable' ); }
			$lease = $repo->find_admission( $identity->namespace_digest(), true );
			if ( null === $lease || ! $c->lease()->matches( $c->original_issue() ) || $lease->row() !== $c->lease()->slot()->row() || 'granted' !== $lease->row()['lease_state'] || $lease->row()['server_attempt_digest'] !== $c->lease()->server_attempt_digest() || $lease->row()['admission_intent_digest'] !== $c->lease()->admission_intent_digest() || $lease->row()['principal_hash'] !== $c->owner()->facts()['principal_hash'] ) { $this->refuse( 'intent_conflict' ); }
			$quote = $repo->find_quote( $c->header()->id(), true ); if ( null !== $quote ) { $this->refuse( 'stale_revision' ); }
		} else {
			$h = $c->header(); if ( null === $h || null === $c->reference() ) { $this->refuse( 'invalid_input' ); }
			$quote = $repo->find_quote( $h->id(), true );
			if ( null === $quote || ! $quote->header()->owner()->equals( $c->owner() ) || ! $quote->header()->matches_reference( $c->reference() ) || $quote->header()->to_private_json() !== $h->to_private_json() ) { $this->refuse( 'not_authorized' ); }
			if ( null === $quote->context() || null === $quote->terms() ) { $this->refuse( 'temporarily_unavailable' ); }
			$verifier = new QuoteReceiptVerifier();
			if ( ! $verifier->issue_verified( $quote, $receipts ) || ( null !== $quote->accepted_at() && ! $verifier->verify( $quote, $receipts ) ) ) { throw new OperationStorageException(); }
			if ( in_array( $this->name, [ 'delivery_quote.bind', 'delivery_quote.verify_binding', 'delivery_quote.seal' ], true ) ) { $binding = $repo->find_binding( $quote, true ); if ( null !== $binding && 'delivery_quote.bind' !== $this->name && ! $verifier->verify( $quote, $receipts, $binding ) ) { throw new OperationStorageException(); } }
		}
		if ( 'delivery_quote.seal' === $this->name && 2 === $this->version() && ( null === $binding || ! $c->placement_proof()->matches( $binding ) || ! $c->placement_proof()->unchanged() ) ) { $this->refuse( 'temporarily_unavailable' ); }
		$this->session = $session; $this->locked = [ $repo, $control, $lease, $quote, $binding ];
		return new OperationTarget( new OperationSchema( [ 'control_revision' => 'positive_int', 'quote_revision' => 'nonnegative_int', 'binding_revision' => 'nonnegative_int' ] ), [ 'control_revision' => $control->revision, 'quote_revision' => $quote?->revision() ?? 0, 'binding_revision' => $binding?->revision() ?? 0 ] );
	}
	public function mutate( OperationSession $session, OperationIdentity $identity, OperationCommand $command, OperationTarget $target, RequestContext $request ): OperationMutation {
		if ( $this->session !== $session || null === $this->locked || $command !== $this->bound || ! $this->authorize( $identity ) ) { throw new OperationStorageException(); }
		[ $repo, $control, $lease, $quote, $binding ] = $this->locked; $c = $this->bound;
		if ( $target->facts !== [ 'control_revision' => $control->revision, 'quote_revision' => $quote?->revision() ?? 0, 'binding_revision' => $binding?->revision() ?? 0 ] ) { throw new OperationStorageException(); }
		$at = self::time( $session );
		if ( $at->epoch_microseconds() < ( $control->changed_at_epoch ?? 0 ) * 1000000 ) { $this->refuse( 'temporarily_unavailable' ); }
		if ( 2 === $c->header()?->format_version() && ( ! $this->evidence instanceof QuoteTimedCurrentEvidenceGuard || null === $c->current_context() || ! $this->evidence->verify_at( $session, $c->owner(), $c->current_context(), $at ) ) ) { $this->refuse( 'temporarily_unavailable' ); }
		$before = 1; $after = 2; $fields = [ 'quote' ];
		if ( 'delivery_quote.issue' === $this->name ) {
			if ( $at->compare( $c->lease()->created_at() ) < 0 || $at->compare( $c->lease()->expires_at() ) >= 0 ) { $this->refuse( 'temporarily_unavailable' ); }
			$h = $c->capture()->header(); if ( ! $h->valid_at( $at ) || $h->created_at()->compare( $c->lease()->created_at() ) < 0 || $h->created_at()->compare( $c->lease()->expires_at() ) >= 0 ) { $this->refuse( 'temporarily_unavailable' ); }
			$quote = self::issued_row( $c->capture() ); $id = $repo->insert_quote( $quote ); $quote = QuoteStoredRow::from_row( array_replace( $quote->row(), [ 'id' => $id ] ) );
			$next = QuoteBudgetSlot::from_row( array_replace( $lease->row(), [ 'revision' => 2, 'lease_state' => 'consumed', 'consumed_quote_uuid' => $h->id()->value(), 'consumed_at' => $at->sql(), 'last_seen_at' => $at->sql() ] ), $quote );
			if ( ! $repo->replace_budget( $lease, $next ) ) { throw new OperationStorageException(); } $fields = [ 'quote', 'admission' ];
		} else {
			if ( $at->compare( QuoteTime::parse( $quote->row()['transition_at'] ?? $quote->row()['created_at'] ) ) < 0 ) { $this->refuse( 'temporarily_unavailable' ); }
			$current = $c->current_context(); $same = hash_equals( $quote->header()->material_digest(), $current->digest() );
			if ( 'delivery_quote.accept' === $this->name ) {
				if ( 'issued' !== $quote->state() || ! $same || ! $quote->header()->valid_at( $at ) ) { $this->refuse( 'stale_revision' ); }
				// Q01 applies all native evidence/receipt acceptance checks; no repricing.
				DeliveryQuote::issue( $quote->header(), $quote->context(), $quote->terms() )->accept( $c->owner(), $c->reference(), $current, $at, $c->header()->revision(), $c->header()->body_digest(), $c->header()->expires_at() );
				$next = QuoteStoredRow::from_row( array_replace( $quote->row(), [ 'state' => 'accepted', 'revision' => 2, 'accepted_at' => $at->sql(), 'transition_at' => $at->sql() ] ) );
				$before = $quote->revision(); $after = $next->revision(); if ( ! $repo->replace_quote( $quote, $next ) ) { throw new OperationStorageException(); } $quote = $next;
			} elseif ( 'delivery_quote.invalidate' === $this->name ) {
				if ( 'invalidated' === $quote->state() || $same ) { return $this->no_change( $quote, null, $at, $control->revision ); }
				if ( ! in_array( $quote->state(), [ 'issued', 'accepted' ], true ) ) { $this->refuse( 'stale_revision' ); }
				$next = QuoteStoredRow::from_row( array_replace( $quote->row(), [ 'state' => 'invalidated', 'revision' => $quote->revision() + 1, 'transition_at' => $at->sql() ] ) );
				$before = $quote->revision(); $after = $next->revision(); if ( ! $repo->replace_quote( $quote, $next ) ) { throw new OperationStorageException(); } $quote = $next;
			} else {
				if ( 'accepted' !== $quote->state() || ! $same || ! $quote->header()->valid_at( $at ) || ! $quote->terms()->feasibility_at( $at ) ) { $this->refuse( 'stale_revision' ); }
				$supplied = $c->binding(); if ( null === $supplied ) { $this->refuse( 'invalid_input' ); }
				QuoteBinding::from_row( $supplied->row(), $quote );
				if ( 'delivery_quote.bind' === $this->name ) {
					if ( null !== $binding ) { $this->refuse( 'stale_revision' ); }
					$row = array_replace( $supplied->row(), [ 'created_at' => $at->sql() ] ); $binding = QuoteBinding::from_row( $row, $quote ); $id = $repo->insert_binding( $binding ); $binding = QuoteBinding::from_row( array_replace( $row, [ 'id' => $id ] ), $quote );
				} else {
					$expected_revision = 'delivery_quote.seal' === $this->name && 2 === $this->version() ? 2 : 1;
					if ( null === $binding || 'prepared' !== $binding->state() || $expected_revision !== $binding->revision() || $supplied->id() !== $binding->id() ) { $this->refuse( 'stale_revision' ); }
					$a = $binding->row(); $b = $supplied->row(); foreach ( array_diff( QuoteBinding::FIELDS, [ 'revision', 'snapshot_digest', 'context_digest', 'verified_at' ] ) as $field ) { if ( $a[$field] !== $b[$field] ) { $this->refuse( 'stale_revision' ); } }
					if ( QuoteTime::parse( $b['verified_at'] )->compare( $at ) > 0 ) { $this->refuse( 'temporarily_unavailable' ); }
					if ( 1 === $expected_revision ) { if ( ! $repo->replace_binding( $binding, $supplied ) ) { throw new OperationStorageException(); } }
					elseif ( $a !== $b || ! $c->placement_proof()->verify( $session, $binding, $control->revision ) ) { $this->refuse( 'stale_revision' ); }
					if ( 'delivery_quote.verify_binding' === $this->name ) { $binding = $supplied; $after = 2; }
					else {
						$next = QuoteBinding::from_row( array_replace( $b, [ 'state' => 'sealed', 'revision' => 3, 'sealed_at' => $at->sql() ] ), $quote );
						if ( ! $repo->replace_binding( $supplied, $next ) ) { throw new OperationStorageException(); } $binding = $next; $before = $expected_revision; $after = 3;
					}
				} $fields = [ 'binding' ];
			}
		}
		$result = $this->result( $quote, $binding, $at, $control->revision );
		$event = OperationMaterialEvent::from_mutation( $this, $identity, $request,
			[ 'authority_hash' => hash( 'sha256', $identity->authority ), 'principal_hash' => hash( 'sha256', $identity->principal ) ],
			[ 'quote_id' => $result['quote_id'], 'body_digest' => $result['body_digest'], 'owner_digest' => $result['owner_digest'], 'namespace_hash' => $result['namespace_hash'], 'quote_revision' => $result['quote_revision'], 'binding_revision' => $binding?->revision() ?? 0 ],
			$this->reason_codes()[0], $before, $after, $fields );
		return OperationMutation::changed( OperationCompletion::accepted( $this, $result, [ 'site_id' => $identity->site_id, 'owner_digest' => $result['owner_digest'], 'quote_id' => $result['quote_id'], 'purpose' => 'private_quote_invalidation' ] ), $event );
	}
	public function publish( OperationIdentity $identity, OperationCompletion $completion ): bool {
		$p = $completion->publication; $r = $completion->result;
		if ( ! $this->authorize( $identity ) || null === $p || null === $r || 'accepted' !== $completion->state || $p != [ 'site_id' => $identity->site_id, 'owner_digest' => $r['owner_digest'], 'quote_id' => $r['quote_id'], 'purpose' => 'private_quote_invalidation' ] || $r['namespace_hash'] !== $identity->namespace_digest() || $r['owner_digest'] !== $this->bound->owner()->digest() ) { return false; }
		return $this->publication->invalidate( $p['site_id'], $p['owner_digest'], \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId::from_string( $p['quote_id'] ) );
	}
	public function result_schema(): OperationSchema {
		$fields = [ 'quote_id' => 'uuid', 'quote_revision' => 'positive_int', 'state' => [ 'enum' => [ 'issued', 'accepted', 'invalidated', 'prepared', 'sealed' ] ], 'body_digest' => 'sha256', 'owner_digest' => 'sha256', 'namespace_hash' => 'sha256', 'completed_at' => 'positive_int', 'control_revision' => 'positive_int' ];
		if ( in_array( $this->name, [ 'delivery_quote.bind', 'delivery_quote.verify_binding', 'delivery_quote.seal' ], true ) ) { $fields += [ 'binding_id' => 'positive_int', 'placement_id' => 'uuid', 'order_id' => 'positive_int', 'binding_revision' => 'positive_int', 'manifest_digest' => 'sha256' ]; }
		if ( 'delivery_quote.verify_binding' === $this->name || 2 === $this->version() ) { $fields += [ 'snapshot_digest' => 'sha256', 'context_digest' => 'sha256', 'native_money_digest' => 'sha256' ]; } return new OperationSchema( $fields );
	}
	public function publication_schema(): ?OperationSchema { return new OperationSchema( [ 'site_id' => 'positive_int', 'owner_digest' => 'sha256', 'quote_id' => 'uuid', 'purpose' => [ 'enum' => [ 'private_quote_invalidation' ] ] ] ); }
	public function actor_schema(): OperationSchema { return new OperationSchema( [ 'authority_hash' => 'sha256', 'principal_hash' => 'sha256' ] ); }
	public function target_schema(): OperationSchema { return new OperationSchema( [ 'quote_id' => 'uuid', 'body_digest' => 'sha256', 'owner_digest' => 'sha256', 'namespace_hash' => 'sha256', 'quote_revision' => 'positive_int', 'binding_revision' => 'nonnegative_int' ] ); }
	public function reason_codes(): array { return [ match ( $this->name ) { 'delivery_quote.issue' => 'quote_issued', 'delivery_quote.accept' => 'quote_accepted', 'delivery_quote.invalidate' => 'quote_invalidated', 'delivery_quote.bind' => 'quote_bound', 'delivery_quote.verify_binding' => 'quote_binding_verified', default => 'quote_sealed' } ]; }
	public function changed_fields(): array { return [ 'quote', 'admission', 'binding' ]; }
	public function validate_accepted_facts( OperationCompletion $completion, OperationMaterialEvent $event ): bool {
		if ( 'accepted' !== $completion->state || null === $completion->result || null === $completion->publication || $event->reason_code !== $this->reason_codes()[0] ) { return false; }
		$r = $completion->result; $t = $event->target;
		if ( $completion->publication['owner_digest'] !== $r['owner_digest'] || $completion->publication['quote_id'] !== $r['quote_id'] || 'private_quote_invalidation' !== $completion->publication['purpose'] ) { return false; }
		foreach ( [ 'quote_id', 'body_digest', 'owner_digest', 'namespace_hash', 'quote_revision' ] as $field ) { if ( $r[$field] !== $t[$field] ) { return false; } }
		if ( $event->actor['authority_hash'] !== hash( 'sha256', 'delivery_quote_customer' ) || $event->actor['principal_hash'] !== hash( 'sha256', $r['owner_digest'] ) ) { return false; }
		return match ( $this->name ) {
			'delivery_quote.issue' => 'issued' === $r['state'] && 1 === $r['quote_revision'] && 0 === $t['binding_revision'] && 1 === $event->before_revision && 2 === $event->after_revision && [ 'quote', 'admission' ] === $event->changed_fields,
			'delivery_quote.accept' => 'accepted' === $r['state'] && 2 === $r['quote_revision'] && 0 === $t['binding_revision'] && 1 === $event->before_revision && 2 === $event->after_revision && [ 'quote' ] === $event->changed_fields,
			'delivery_quote.invalidate' => 'invalidated' === $r['state'] && $r['quote_revision'] === $event->after_revision && $event->after_revision === $event->before_revision + 1 && in_array( $r['quote_revision'], [ 2, 3 ], true ) && 0 === $t['binding_revision'] && [ 'quote' ] === $event->changed_fields,
			'delivery_quote.bind' => 'prepared' === $r['state'] && 2 === $r['quote_revision'] && 1 === $r['binding_revision'] && 1 === $t['binding_revision'] && 1 === $event->before_revision && 2 === $event->after_revision && [ 'binding' ] === $event->changed_fields,
			'delivery_quote.verify_binding' => 'prepared' === $r['state'] && 2 === $r['quote_revision'] && 2 === $r['binding_revision'] && 2 === $t['binding_revision'] && 1 === $event->before_revision && 2 === $event->after_revision && [ 'binding' ] === $event->changed_fields,
			'delivery_quote.seal' => 'sealed' === $r['state'] && 2 === $r['quote_revision'] && 3 === $r['binding_revision'] && 3 === $t['binding_revision'] && ( 2 === $this->version() ? 2 : 1 ) === $event->before_revision && 3 === $event->after_revision && [ 'binding' ] === $event->changed_fields,
		};
	}
	private function result( QuoteStoredRow $quote, ?QuoteBinding $binding, QuoteTime $at, int $control_revision ): array {
		$r = [ 'quote_id' => $quote->header()->id()->value(), 'quote_revision' => $quote->revision(), 'state' => $binding?->state() ?? $quote->state(), 'body_digest' => $quote->header()->body_digest(), 'owner_digest' => $quote->header()->owner()->digest(), 'namespace_hash' => $this->bound->identity->namespace_digest(), 'completed_at' => $at->epoch_microseconds(), 'control_revision' => $control_revision ];
		if ( null !== $binding ) { $b = $binding->row(); $r += [ 'binding_id' => $binding->id(), 'placement_id' => $b['placement_uuid'], 'order_id' => $b['order_id'], 'binding_revision' => $binding->revision(), 'manifest_digest' => $b['managed_group_manifest_digest'] ]; if ( 'delivery_quote.verify_binding' === $this->name || 2 === $this->version() ) { foreach ( [ 'snapshot_digest', 'context_digest', 'native_money_digest' ] as $field ) { $r[$field] = $b[$field]; } } } return $r;
	}
	private function no_change( QuoteStoredRow $quote, ?QuoteBinding $binding, QuoteTime $at, int $revision ): OperationMutation { return OperationMutation::unchanged( OperationCompletion::not_applicable( $this, $this->result( $quote, $binding, $at, $revision ) ) ); }
	public static function time( OperationSession $session ): QuoteTime { $r = $session->get_row( 'SELECT UTC_TIMESTAMP(6) AS utc' ); if ( ! is_array( $r ) || ! is_string( $r['utc'] ?? null ) ) { throw new OperationStorageException(); } try { return QuoteTime::parse( $r['utc'] ); } catch ( \Throwable ) { throw new OperationStorageException(); } }
	private static function issued_row( DeliveryQuote $quote ): QuoteStoredRow {
		$h = $quote->header(); $o = $h->owner(); return QuoteStoredRow::from_row( [ 'id' => 1, 'site_id' => $o->site_id(), 'quote_uuid' => $h->id()->value(), 'format_version' => $h->format_version(), 'profile_code' => $h->profile(), 'profile_version' => $h->profile_version(), 'purpose' => $h->purpose(), 'principal_hash' => $o->facts()['principal_hash'], 'owner_digest' => $o->digest(), 'material_digest' => $h->material_digest(), 'body_digest' => $h->body_digest(), 'header_json' => $h->to_private_json(), 'private_body_json' => QuoteJson::encode( [ 'context' => $quote->context()->private_facts(), 'terms' => $quote->terms()->private_facts() ] ), 'issue_namespace_hash' => $h->namespace_hashes()['issue'], 'accept_namespace_hash' => $h->namespace_hashes()['accept'], 'invalidate_namespace_hash' => $h->namespace_hashes()['invalidate'], 'state' => 'issued', 'revision' => 1, 'retention_revision' => 1, 'created_at' => $h->created_at()->sql(), 'expires_at' => $h->expires_at()->sql(), 'accepted_at' => null, 'transition_at' => null ] );
	}
	private function refuse( string $code ): never { throw new OperationRefusal( $code, match ( $code ) { 'not_authorized' => 'contact_support', 'temporarily_unavailable' => 'retry_original_request', default => 'reload_and_submit' } ); }
}
