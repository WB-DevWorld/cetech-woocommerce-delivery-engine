<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Application\DeliveryPromotion\FixedPromotionEligibilityBudgetGuard;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteCurrentEvidenceValidity;
use CetechDeliveryEngine\Domain\DeliveryPromotion\{DeliveryPromotionPlan, FixedDeliveryPromotion, FixedPromotionBudgetRequest, FixedPromotionEligibilityFamily, FixedPromotionEligibilityReference};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteOwner, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleCandidate, RuleLifecycleEvaluator, RuleRecordCodec, RuleSnapshot, RuleState, RuleTime};

/** Explicit, unmounted current SQL policy adapter. Frozen planning facts are never authority. */
final readonly class SqlFixedPromotionRuleEligibilityGuard implements FixedPromotionEligibilityBudgetGuard {
	public function __construct( private FixedPromotionEligibilityFamily $family ) {}

	public function tables( OperationSession $session ): array {
		$prefix = $session->table_prefix();
		if ( $session->is_retired() || $session->site_id() < 1 || '' === $prefix || strlen( $prefix ) > 30 || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $prefix ) ) { throw new \RuntimeException( 'Promotion eligibility unavailable.' ); }
		$tables = RuleLifecycleSchema::tables( $prefix );
		if ( $session->is_retired() || $session->table_prefix() !== $prefix ) { throw new \RuntimeException( 'Promotion eligibility unavailable.' ); }
		return $tables;
	}

	/** This adapter refuses the old contract: it cannot prove which budget pool was requested. */
	public function validity_at( OperationSession $session, QuoteOwner $owner, QuoteHeader $header, QuoteContext $context, QuoteTerms $terms, QuoteTime $at ): ?QuoteCurrentEvidenceValidity { return null; }

	/** Planning returns only eligibility provenance, never a reservation or availability assertion. */
	public function planning_evidence( OperationSession $session, QuoteOwner $owner, QuoteContext $context, QuoteTerms $terms, string $component_key, FixedDeliveryPromotion $rule, QuoteTime $at ): ?array {
		try {
			$prefix = $session->table_prefix(); $this->assert_owned( $session, $owner, $prefix );
			if ( 1 !== $context->format_version() || 1 !== $terms->format_version() || ! $context->checkout_acceptable() || ! $terms->checkout_acceptable() || null !== DeliveryPromotionPlan::preflight( $terms, $component_key, $rule ) ) { return null; }
			$subject = $this->family->subject_for_component( $context, $terms, $component_key, $rule->private_facts()['code'] );
			$snapshot = $this->snapshot( $session, $owner, $prefix );
			[ $candidate ] = $this->selected( $snapshot, $subject, $rule, $at, $at->plus_seconds( QuoteHeader::TTL_SECONDS ) );
			$reference = FixedPromotionEligibilityReference::from_candidate( $candidate );
			$this->assert_owned( $session, $owner, $prefix );
			return [ 'eligibility' => [ 'state' => 'eligible', 'evidence_digest' => $reference->digest() ], 'account_key' => $reference->account_key(), 'reference' => $reference->private_facts() ];
		} catch ( \Throwable ) { return null; }
	}

	public function validity_for_requests_at( OperationSession $session, QuoteOwner $owner, QuoteHeader $header, QuoteContext $context, QuoteTerms $terms, array $requests, QuoteTime $at ): ?QuoteCurrentEvidenceValidity {
		try {
			$prefix = $session->table_prefix(); $this->assert_owned( $session, $owner, $prefix ); $header->assert_body( $context, $terms );
			if ( ! $owner->equals( $header->owner() ) || ! $header->valid_at( $at ) || 3 !== $header->format_version() || 3 !== $context->format_version() || 3 !== $terms->format_version() || 'fixed_promotion_native_v1' !== $header->profile() || 1 !== $header->profile_version() || 'checkout' !== $header->purpose() || ! array_is_list( $requests ) || [] === $requests || count( $requests ) > QuoteContext::MAX_GROUPS ) { return null; }
			$capture = $context->fixed_promotion_capture(); if ( null === $capture ) { return null; }
			$by_component = [];
			foreach ( $requests as $request ) {
				if ( ! $request instanceof FixedPromotionBudgetRequest ) { return null; }
				$key = $request->private_facts()['component_key']; if ( isset( $by_component[$key] ) ) { return null; } $by_component[$key] = $request;
			}
			$snapshot = $this->snapshot( $session, $owner, $prefix ); $until = $header->expires_at(); $seen = 0;
			foreach ( $capture->private_facts()['groups'] as $slot ) {
				if ( null === $slot['plan'] ) { continue; }
				++$seen; $key = $slot['component_key']; $request = $by_component[$key] ?? null; if ( null === $request ) { return null; }
				$rule = FixedDeliveryPromotion::from_array( $slot['rule'] );
				$subject = $this->family->subject_for_component( $capture->original_context(), $capture->original_terms(), $key, $rule->private_facts()['code'] );
				[ $candidate, $boundary ] = $this->selected( $snapshot, $subject, $rule, $at, $until );
				$reference = FixedPromotionEligibilityReference::from_candidate( $candidate );
				if ( ! hash_equals( $reference->digest(), $slot['plan']['evidence']['eligibility']['evidence_digest'] ) || $request->to_private_json() !== FixedPromotionBudgetRequest::from_captured( $header, $context, $terms, $key, $reference->account_key() )->to_private_json() ) { return null; }
				$until = $boundary; $this->assert_owned( $session, $owner, $prefix );
			}
			if ( $seen !== count( $requests ) ) { return null; }
			$this->assert_owned( $session, $owner, $prefix ); return QuoteCurrentEvidenceValidity::capture( $at, $until );
		} catch ( \Throwable ) { return null; }
	}

	/** The family guard serializes writers; each following row is a current locking read. */
	private function snapshot( OperationSession $session, QuoteOwner $owner, string $prefix ): RuleSnapshot {
		$this->assert_owned( $session, $owner, $prefix );
		if ( ! $session->validate_tables( $this->tables( $session ) ) ) { throw new \RuntimeException(); }
		$this->assert_owned( $session, $owner, $prefix );
		( new RuleLifecycleReadiness( $session ) )->verify(); $this->assert_owned( $session, $owner, $prefix );
		$f = $prefix . TableNames::PREFIX . RuleLifecycleSchema::GUARDS_SUFFIX; $l = $prefix . TableNames::PREFIX . RuleLifecycleSchema::RULES_SUFFIX; $v = $prefix . TableNames::PREFIX . RuleLifecycleSchema::VERSIONS_SUFFIX;
		$guard = $session->get_row( $session->prepare( "SELECT * FROM `{$f}` WHERE site_id=%d AND family_code=%s LIMIT 1 FOR UPDATE", $owner->site_id(), $this->family->family() ) );
		$this->assert_owned( $session, $owner, $prefix ); if ( ! is_array( $guard ) || 0 !== $session->errno() ) { throw new \RuntimeException(); } $guard_id = RuleRecordCodec::integer( $guard['id'] ?? null );
		$logicals = $session->get_results( $session->prepare( 'SELECT ' . $this->projection( RuleLifecycleSchema::RULES_SUFFIX, 'l' ) . " FROM `{$l}` l WHERE l.site_id=%d AND l.family_guard_id=%d ORDER BY l.id ASC LIMIT 1001 FOR UPDATE", $owner->site_id(), $guard_id ) );
		$this->assert_owned( $session, $owner, $prefix ); if ( ! is_array( $logicals ) || count( $logicals ) > 1000 || 0 !== $session->errno() ) { throw new \RuntimeException(); }
		$versions = $session->get_results( $session->prepare( 'SELECT ' . $this->projection( RuleLifecycleSchema::VERSIONS_SUFFIX, 'v' ) . " FROM `{$v}` v INNER JOIN `{$l}` l ON l.id=v.logical_rule_id AND l.site_id=v.site_id WHERE v.site_id=%d AND l.family_guard_id=%d ORDER BY v.id ASC LIMIT 2001 FOR UPDATE", $owner->site_id(), $guard_id ) );
		$this->assert_owned( $session, $owner, $prefix ); if ( ! is_array( $versions ) || count( $versions ) > 2000 || 0 !== $session->errno() ) { throw new \RuntimeException(); }
		$logicals = array_map( fn( array $row ): array => $this->bounded( RuleLifecycleSchema::RULES_SUFFIX, $row ), $logicals );
		$versions = array_map( fn( array $row ): array => $this->bounded( RuleLifecycleSchema::VERSIONS_SUFFIX, $row ), $versions );
		$full = RuleSnapshot::from_history_rows( $this->family, $guard, $logicals, $versions );
		// The full capture validates historical links; only live candidates take part in selection.
		return new RuleSnapshot( $this->family, $full->guard, $full->logicals, $full->versions, true, false );
	}

	/** Conservatively stop before any matching authored selection transition. */
	private function selected( RuleSnapshot $snapshot, array $subject, FixedDeliveryPromotion $rule, QuoteTime $at, QuoteTime $ceiling ): array {
		$when = RuleTime::parse( $at->sql() ); $decision = ( new RuleLifecycleEvaluator() )->evaluate_snapshot( $snapshot, $subject, $when );
		if ( ! $decision->complete || null === $decision->selected ) { throw new \RuntimeException(); }
		$selected = null; $until = $ceiling;
		foreach ( $snapshot->candidates() as $candidate ) {
			if ( $candidate->version->id === $decision->selected->version_id ) { $selected = $candidate; }
			if ( ! $this->family->match( $candidate->logical->scope, $subject )->matched || ! in_array( $candidate->version->state, [ RuleState::Published, RuleState::Scheduled ], true ) ) { continue; }
			if ( RuleState::Scheduled === $candidate->version->state && null !== $candidate->version->effective_from && $candidate->version->effective_from->compare( $when ) <= 0 ) { throw new \RuntimeException(); }
			foreach ( [ $candidate->version->effective_from, $candidate->version->effective_until, $candidate->version->published_at ] as $boundary ) {
				if ( null !== $boundary && $boundary->compare( $when ) > 0 && $boundary->epoch_microseconds() < $until->epoch_microseconds() ) { $until = QuoteTime::parse( $boundary->sql() ); }
			}
		}
		if ( ! $selected instanceof RuleCandidate || FixedPromotionEligibilityReference::from_candidate( $selected )->rule()->to_private_json() !== $rule->to_private_json() || $until->compare( $at ) <= 0 ) { throw new \RuntimeException(); }
		return [ $selected, $until ];
	}

	private function projection( string $suffix, string $alias ): string {
		$out = []; $limits = $this->limits( $suffix );
		foreach ( array_keys( RuleLifecycleSchema::columns( $suffix ) ) as $column ) {
			if ( isset( $limits[$column] ) ) { $out[] = "CASE WHEN OCTET_LENGTH({$alias}.`{$column}`)<=" . $limits[$column] . " THEN {$alias}.`{$column}` ELSE NULL END AS `{$column}`"; $out[] = "OCTET_LENGTH({$alias}.`{$column}`) AS `{$column}__bytes`"; }
			else { $out[] = "{$alias}.`{$column}` AS `{$column}`"; }
		}
		return implode( ',', $out );
	}
	private function bounded( string $suffix, array $row ): array {
		foreach ( $this->limits( $suffix ) as $column => $limit ) { $bytes = RuleRecordCodec::integer( $row[$column . '__bytes'] ?? null, 0 ); if ( $bytes > $limit || ! is_string( $row[$column] ?? null ) || strlen( $row[$column] ) !== $bytes ) { throw new \RuntimeException(); } unset( $row[$column . '__bytes'] ); }
		return $row;
	}
	private function limits( string $suffix ): array { return RuleLifecycleSchema::RULES_SUFFIX === $suffix ? [ 'scope_json' => 4096 ] : [ 'payload_json' => 16384, 'change_reason' => 512 ]; }
	private function assert_owned( OperationSession $session, QuoteOwner $owner, string $prefix ): void {
		if ( $session->is_retired() || ! $session->in_transaction() || $session->site_id() !== $owner->site_id() || $session->table_prefix() !== $prefix || '' === $prefix || strlen( $prefix ) > 30 || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $prefix ) ) { throw new \RuntimeException( 'Promotion eligibility unavailable.' ); }
	}
}
