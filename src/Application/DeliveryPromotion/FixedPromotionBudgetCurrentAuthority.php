<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryQuote\QuoteCurrentEvidenceValidity;
use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedPromotionBudgetRequest;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteOwner, QuoteShape, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Current held-allocation proof for one exact compiled quote, never a payment or write grant. */
final readonly class FixedPromotionBudgetCurrentAuthority implements FixedPromotionCurrentAuthority, \JsonSerializable {
	private const MAX_TABLES = 64;
	private function __construct( private QuoteHeader $header, private QuoteContext $context, private QuoteTerms $terms, private array $requests, private FixedPromotionBudgetStore $store, private FixedPromotionEligibilityGuard $eligibility ) {}

	/** Every component chooses its account explicitly, including null for unadjusted components. */
	public static function from_captured( QuoteHeader $header, QuoteContext $context, QuoteTerms $terms, array $accounts, FixedPromotionBudgetStore $store, FixedPromotionEligibilityGuard $eligibility ): self {
		$header->assert_body( $context, $terms );
		if ( 3 !== $header->format_version() || 3 !== $context->format_version() || 3 !== $terms->format_version() || 'fixed_promotion_native_v1' !== $header->profile() || 1 !== $header->profile_version() || 'checkout' !== $header->purpose() ) { QuoteShape::invalid(); }
		$capture = $context->fixed_promotion_capture(); if ( null === $capture ) { QuoteShape::invalid(); }
		$groups = []; foreach ( $capture->private_facts()['groups'] as $group ) { $groups[$group['component_key']] = null !== $group['plan']; }
		$seen = []; $requests = [];
		foreach ( QuoteShape::list( $accounts, QuoteContext::MAX_GROUPS, 1 ) as $row ) {
			$row = QuoteShape::object( $row ); QuoteShape::fields( $row, [ 'component_key', 'account_key' ] ); $key = QuoteShape::digest( $row['component_key'] );
			if ( isset( $seen[$key] ) || ! array_key_exists( $key, $groups ) || $groups[$key] !== ( null !== $row['account_key'] ) ) { QuoteShape::invalid(); } $seen[$key] = true;
			if ( null !== $row['account_key'] ) { $requests[] = FixedPromotionBudgetRequest::from_captured( $header, $context, $terms, $key, QuoteShape::digest( $row['account_key'] ) ); }
		}
		if ( count( $seen ) !== count( $groups ) || [] === $requests ) { QuoteShape::invalid(); }
		usort( $requests, static fn( FixedPromotionBudgetRequest $a, FixedPromotionBudgetRequest $b ): int => strcmp( $a->claim_key(), $b->claim_key() ) );
		return new self( $header, $context, $terms, $requests, $store, $eligibility );
	}

	public function tables( OperationSession $session ): array {
		if ( $session->is_retired() || $session->site_id() !== $this->header->owner()->site_id() ) { throw new \RuntimeException( 'Promotion authority unavailable.' ); }
		$prefix = $session->table_prefix(); if ( '' === $prefix || strlen( $prefix ) > 30 || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $prefix ) ) { throw new \RuntimeException( 'Promotion authority unavailable.' ); }
		$tables = [];
		foreach ( [ $this->eligibility->tables( $session ), $this->store->tables( $session ) ] as $part ) {
			if ( ! array_is_list( $part ) || [] === $part || count( $part ) > self::MAX_TABLES ) { throw new \RuntimeException( 'Promotion authority unavailable.' ); }
			foreach ( $part as $table ) { if ( ! is_string( $table ) || ! str_starts_with( $table, $prefix ) || strlen( $table ) > 64 || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table ) ) { throw new \RuntimeException( 'Promotion authority unavailable.' ); } $tables[$table] = true; }
		}
		if ( count( $tables ) > self::MAX_TABLES || $session->is_retired() || $session->site_id() !== $this->header->owner()->site_id() || $session->table_prefix() !== $prefix ) { throw new \RuntimeException( 'Promotion authority unavailable.' ); }
		$names = array_map( static fn( mixed $name ): string => (string) $name, array_keys( $tables ) ); sort( $names, SORT_STRING ); return $names;
	}

	/** Read-only same-owner proof: expiry never silently releases or reallocates a held claim. */
	public function validity_at( OperationSession $session, QuoteOwner $owner, QuoteHeader $header, QuoteContext $context, QuoteTerms $terms, QuoteTime $at ): ?QuoteCurrentEvidenceValidity {
		try {
			$prefix = $session->table_prefix();
			if ( ! $this->owned( $session, $prefix ) || $session->site_id() !== $owner->site_id() || ! $owner->equals( $this->header->owner() )
				|| $header->to_private_json() !== $this->header->to_private_json() || $context->to_private_json() !== $this->context->to_private_json() || $terms->to_private_json() !== $this->terms->to_private_json() || ! $this->header->valid_at( $at ) ) { return null; }
			if ( ! $session->validate_tables( $this->tables( $session ) ) || ! $this->owned( $session, $prefix ) ) { return null; }
			$eligible = $this->eligibility->validity_at( $session, $owner, $this->header, $this->context, $this->terms, $at );
			if ( ! $this->owned( $session, $prefix ) || null === $eligible || ! $eligible->valid_at( $at ) ) { return null; }
			$keys = []; foreach ( $this->requests as $request ) { $keys[$request->account_key()] = true; } $keys = array_keys( $keys ); sort( $keys, SORT_STRING );
			$accounts = []; foreach ( $keys as $key ) { $account = $this->store->lock_account( $session, $key ); if ( ! $this->owned( $session, $prefix ) || null === $account || $account->account_key() !== $key || $account->site_id() !== $owner->site_id() ) { return null; } $accounts[$key] = $account; }
			$totals = [];
			foreach ( $this->requests as $request ) {
				$key = $request->account_key(); $account = $accounts[$key]; $amount = $request->amount();
				if ( $account->funding_source() !== $request->funding_source() || $account->held()->compare( $amount ) < 0 ) { return null; }
				$totals[$key] = isset( $totals[$key] ) ? $totals[$key]->add( $amount ) : $amount;
			}
			foreach ( $totals as $key => $amount ) { if ( $accounts[$key]->held()->compare( $amount ) < 0 ) { return null; } }
			$intervals = [ $eligible, QuoteCurrentEvidenceValidity::capture( $at, $this->header->expires_at() ) ];
			foreach ( $this->requests as $request ) {
				$claim = $this->store->lock_reservation( $session, $request->claim_key() );
				if ( ! $this->owned( $session, $prefix ) || null === $claim || ! $claim->matches( $request ) || 'held' !== $claim->state() || ! $claim->is_held_at( $at ) ) { return null; }
				$intervals[] = QuoteCurrentEvidenceValidity::capture( $at, $claim->expires_at() );
			}
			return $this->owned( $session, $prefix ) ? QuoteCurrentEvidenceValidity::intersection( $at, ...$intervals ) : null;
		} catch ( \Throwable ) { return null; }
	}
	private function owned( OperationSession $session, string $prefix ): bool { return ! $session->is_retired() && $session->in_transaction() && $session->site_id() === $this->header->owner()->site_id() && $session->table_prefix() === $prefix; }
	public function jsonSerialize(): never { throw new \LogicException( 'Private campaign authority requires explicit authorized access.' ); }
	public function __serialize(): array { throw new \LogicException( 'Private campaign authority cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): void { throw new \LogicException( 'Private campaign authority cannot be reconstructed implicitly.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_budget_authority' => 'redacted' ]; }
}
