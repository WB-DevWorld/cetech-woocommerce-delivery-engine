<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryQuote;

/** Pure immutable lifecycle model. Acceptance does not reserve or place an order. */
final readonly class DeliveryQuote implements \JsonSerializable {

	private function __construct(
		private QuoteHeader $original_header,
		private ?QuoteContext $original_context,
		private ?QuoteTerms $original_terms,
		private string $stored_state,
		private int $transition_revision,
		private ?QuoteTime $acceptance_time = null,
		private ?QuoteTime $transition_time = null
	) {}

	public static function issue( QuoteHeader $header, QuoteContext $context, QuoteTerms $terms ): self {
		$header->assert_body( $context, $terms );
		return new self( $header, $context, $terms, 'issued', 1 );
	}

	public function header(): QuoteHeader { return $this->original_header; }
	public function context(): ?QuoteContext { return $this->original_context; }
	public function terms(): ?QuoteTerms { return $this->original_terms; }
	public function state(): string { return $this->stored_state; }
	public function revision(): int { return $this->transition_revision; }
	public function accepted_at(): ?QuoteTime { return $this->acceptance_time; }
	public function customer_label(): string {
		$groups = $this->original_terms?->display_money() ?? [];
		return 1 === count( $groups ) ? $groups[0]['customer_label'] : 'Delivery';
	}

	/** Expiry is derived; it never erases an earlier accepted receipt. */
	public function current_status( QuoteTime $at ): string {
		if ( in_array( $this->stored_state, [ 'invalidated', 'stripped' ], true ) ) { return $this->stored_state; }
		return $at->compare( $this->original_header->expires_at() ) >= 0 ? 'expired' : $this->stored_state;
	}
	public function reason_at( QuoteTime $at ): ?string {
		if ( 'stripped' === $this->stored_state ) { return 'quote_unavailable'; }
		if ( 'invalidated' === $this->stored_state ) { return 'quote_invalidated'; }
		if ( $at->compare( $this->original_header->created_at() ) < 0 || ( null !== $this->transition_time && $at->compare( $this->transition_time ) < 0 ) ) { return 'quote_unavailable'; }
		if ( $at->compare( $this->original_header->expires_at() ) >= 0 ) { return 'quote_expired'; }
		if ( ! $this->original_context?->checkout_acceptable() || ! $this->original_terms?->checkout_acceptable() || ! $this->supported_terms() ) { return 'quote_unavailable'; }
		if ( ! $this->original_terms->feasibility_at( $at ) ) { return 'quote_unavailable'; }
		return null;
	}
	public function usable_at( QuoteTime $at ): bool { return null === $this->reason_at( $at ); }

	/** Exact server-resolved original envelope; no new token or expiry is accepted. */
	public function accept( QuoteOwner $owner, QuoteReference $reference, QuoteContext $current_context, QuoteTime $at, int $opened_revision, string $body_digest, QuoteTime $original_expiry ): self {
		$this->guard_original( $owner, $reference, $opened_revision, $body_digest, $original_expiry );
		if ( ! hash_equals( $this->original_header->material_digest(), $current_context->digest() ) || ! $current_context->checkout_acceptable() ) { self::refuse(); }
		if ( 'accepted' === $this->stored_state ) {
			// Original acceptance replay is historical, even after absolute expiry.
			if ( $at->compare( $this->acceptance_time ) < 0 ) { self::refuse(); }
			return $this;
		}
		if ( 'issued' !== $this->stored_state || ! $this->usable_at( $at ) ) { self::refuse(); }
		return new self( $this->original_header, $this->original_context, $this->original_terms, 'accepted', 2, $at, $at );
	}

	/** A confirmed differing current context ends applicability without repricing. */
	public function invalidate( QuoteOwner $owner, QuoteReference $reference, QuoteContext $current_context, QuoteTime $at, int $opened_revision, string $body_digest, QuoteTime $original_expiry ): self {
		$this->guard_original( $owner, $reference, $opened_revision, $body_digest, $original_expiry );
		if ( ! $current_context->material_evidence_available() || 'stripped' === $this->stored_state || hash_equals( $this->original_header->material_digest(), $current_context->digest() )
			|| $at->compare( $this->original_header->created_at() ) < 0 || ( null !== $this->transition_time && $at->compare( $this->transition_time ) < 0 ) ) { self::refuse(); }
		if ( 'invalidated' === $this->stored_state ) { return $this; }
		return new self( $this->original_header, $this->original_context, $this->original_terms, 'invalidated', $this->transition_revision + 1, $this->acceptance_time, $at );
	}

	/**
	 * Models stripping only. It proves no absence of durable/history references.
	 * A future retention adopter must establish all reference and commit guards.
	 */
	public function strip_for_model( QuoteOwner $owner, QuoteReference $reference, QuoteTime $at, int $opened_revision, string $body_digest, QuoteTime $original_expiry ): self {
		$this->guard_original( $owner, $reference, $opened_revision, $body_digest, $original_expiry );
		if ( null !== $this->acceptance_time || $at->compare( $this->original_header->expires_at()->plus_seconds( 1800 ) ) < 0
			|| ( null !== $this->transition_time && $at->compare( $this->transition_time ) < 0 ) ) { self::refuse(); }
		if ( 'stripped' === $this->stored_state ) { return $this; }
		return new self( $this->original_header, null, null, 'stripped', $this->transition_revision + 1, null, $at );
	}

	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized delivery quote projection is required.' ); }

	private function guard_original( QuoteOwner $owner, QuoteReference $reference, int $revision, string $body_digest, QuoteTime $expiry ): void {
		if ( ! $this->original_header->owner()->equals( $owner ) || ! $this->original_header->matches_reference( $reference )
			|| $revision !== $this->original_header->revision() || ! hash_equals( $this->original_header->body_digest(), $body_digest ) || ! $this->original_header->expires_at()->equals( $expiry ) ) { self::refuse(); }
	}
	private function supported_terms(): bool {
		if ( null === $this->original_terms ) { return false; }
		if ( 2 === $this->original_header->format_version() && ( 'service_promise_v1' !== $this->original_header->profile() || null === $this->original_terms->promise_packet() ) ) { return false; }
		foreach ( $this->original_terms->private_facts()['groups'] as $group ) {
			if ( in_array( $this->original_header->profile(), [ 'legacy_fixed_base_v1', 'service_promise_v1' ], true ) ) {
				if ( $group['provider'] !== [ 'code' => 'legacy_fixed_base_v1', 'version' => 1 ]
					|| 'none' !== $group['promotion']['state'] || $group['promotion']['provider'] !== [ 'code' => 'native_no_delivery_promotion_v1', 'version' => 1 ]
					|| 'unavailable' !== $group['cost']['state'] || 'not_recorded' !== $group['route']['state']
					|| 'recorded' !== $group['native_tax_receipt']['state'] || 'recorded' !== $group['native_money_receipt']['state'] ) { return false; }
				continue;
			}
			if ( $group['provider'] !== [ 'code' => 'fixture_v1', 'version' => 1 ] ) { return false; }
			foreach ( [ 'promotion', 'cost', 'route' ] as $section ) {
				if ( isset( $group[$section]['provider'] ) && ( 1 !== $group[$section]['provider']['version'] || ! in_array( $group[$section]['provider']['code'], [ 'fixture_v1', 'fixture_none_v1', 'fixture_cost_v1', 'fixture_route_v1' ], true ) ) ) { return false; }
			}
		}
		return true;
	}
	private static function refuse(): never { throw new \DomainException( 'Delivery quote transition refused.' ); }
}
