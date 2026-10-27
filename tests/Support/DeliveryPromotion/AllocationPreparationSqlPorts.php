<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionEligibilityBudgetGuard, FixedPromotionNativeCapture, FixedPromotionNativeRequests};
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteProvider, LegacyQuoteSourcePlan, QuoteCurrentEvidenceValidity};
use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedPromotionBudgetAllocationSet;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteOwner, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\{FixedPromotionBudgetSchema, LegacyQuoteSourceSnapshotReader};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\{FixedPromotionQuoteFixture as F, FixedPromotionQuoteFixtureSource};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{LegacyQuoteProviderFixtures as L, QuoteFixtures as Q};
use CetechDeliveryEngine\Tests\Unit\DeliveryQuote\FixedPromotionCurrentSession;

require_once dirname( __DIR__, 2 ) . '/Unit/DeliveryQuote/FixedPromotionCurrentEvidenceGuardTest.php';

/** Explicit source/native test ports over REAL budget/publication SQL. This is not physical Woo/source proof. */
final class AllocationPreparationSqlPorts {
	public static function capture( OperationSession $native ): array {
		$session = new AllocationPreparationMixedSession( $native ); $base = F::original_context(); $old_plan = L::plan();
		$plan = LegacyQuoteSourcePlan::create( F::owner(), $base, $old_plan->member_proofs(), $old_plan->rate_ranges(), $old_plan->fences() );
		$snapshot = ( new LegacyQuoteSourceSnapshotReader() )->capture( $session, F::owner(), $base, $plan, Q::time() ); $data = $base->private_facts();
		foreach ( $data['groups'] as &$group ) { $group['policy_digest'] = $snapshot->policy_digest(); $group['candidate_digest'] = $snapshot->candidate_digest( 20, 50, 'GHS' ); $group['candidate_count'] = $snapshot->candidate_count( 20, 50, 'GHS' ); } unset( $group );
		$context = QuoteContext::from_array( $data ); $snapshot = $snapshot->bind_context( $context ); $data = F::original_terms()->private_facts(); $data['groups'][0]['policy_digest'] = $snapshot->policy_digest(); $terms = QuoteTerms::from_array( $data );
		$requests = FixedPromotionNativeRequests::from_captured( F::original_header( $context, $terms ), $context, $terms, [ [ 'component_key' => F::key(), 'plan' => F::plan( $terms ) ] ], [ [ 'component_key' => F::key(), 'legacy_group_id' => 'in_store|delivery|20' ] ] );
		$facts = F::facts(); $facts['source_facts']['selectors']['table_prefix'] = $native->table_prefix(); $source = new FixedPromotionQuoteFixtureSource( $facts );
		$fresh = ( new FixedPromotionNativeCapture( $source ) )->capture( F::owner(), $requests ); $provider = FixedPromotionQuoteProvider::from_native_result( $fresh, F::rules() ); $header = F::header( $provider );
		$slots = [ [ 'component_key' => F::key(), 'account_key' => Q::digest( 'preparation physical budget' ) ] ];
		return [ 'session' => $session, 'original' => $snapshot, 'fresh' => $fresh, 'set' => FixedPromotionBudgetAllocationSet::from_captured( $header, $provider->context(), $provider->terms(), $slots ) ];
	}
}

/** Only retained source/native reads are synthetic; transaction, budget and publication reads/writes are native SQL. */
final class AllocationPreparationMixedSession implements OperationSession {
	private FixedPromotionCurrentSession $source;
	public int $physical_writes = 0;
	public function __construct( private OperationSession $native ) { $this->source = new FixedPromotionCurrentSession( [ L::card() ] ); }
	public function site_id(): int { return $this->native->site_id(); }
	public function table_prefix(): string { return $this->native->table_prefix(); }
	public function charset_collate(): string { return $this->native->charset_collate(); }
	public function begin(): bool { return $this->native->begin(); }
	public function commit(): OperationCommitResult { return $this->native->commit(); }
	public function rollback(): bool { return $this->native->rollback(); }
	public function retire(): bool { return $this->native->retire(); }
	public function is_retired(): bool { return $this->native->is_retired(); }
	public function in_transaction(): bool { return $this->native->in_transaction(); }
	public function validate_tables( array $tables ): bool {
		$physical = array_values( array_intersect( $tables, [ $this->table_prefix() . 'options', ...FixedPromotionBudgetSchema::tables( $this->table_prefix() ) ] ) );
		return [] === $physical || $this->native->validate_tables( $physical );
	}
	public function query( string $sql ): int|false { ++$this->physical_writes; return $this->native->query( $sql ); }
	public function get_row( string $sql ): array|null|false { return $this->native->get_row( $sql ); }
	public function get_results( string $sql ): array|false {
		if ( str_contains( $sql, 'promotion_budget_' ) || ( str_contains( $sql, 'option_name, CASE WHEN' ) && str_ends_with( $sql, 'FOR UPDATE' ) ) ) { return $this->native->get_results( $sql ); }
		return $this->source->get_results( str_replace( $this->table_prefix(), 'wp_', $sql ) );
	}
	public function prepare( string $sql, mixed ...$args ): string { return $this->native->prepare( $sql, ...$args ); }
	public function errno(): int { return $this->native->errno(); }
	public function insert_id(): int { return $this->native->insert_id(); }
}

/** Declared trusted eligibility port; denial on its second proof forces the final preparation fence to fail. */
final class AllocationPreparationEligibilityPort implements FixedPromotionEligibilityBudgetGuard {
	public int $proofs = 0;
	public bool $deny_final = true;
	public function tables( OperationSession $s ): array { return FixedPromotionBudgetSchema::tables( $s->table_prefix() ); }
	public function validity_at( OperationSession $s, QuoteOwner $o, QuoteHeader $h, QuoteContext $c, QuoteTerms $t, QuoteTime $at ): ?QuoteCurrentEvidenceValidity { throw new \LogicException( 'Preparation must use account-aware eligibility.' ); }
	public function validity_for_requests_at( OperationSession $s, QuoteOwner $o, QuoteHeader $h, QuoteContext $c, QuoteTerms $t, array $requests, QuoteTime $at ): ?QuoteCurrentEvidenceValidity {
		++$this->proofs;
		return $this->deny_final && $this->proofs > 1 ? null : QuoteCurrentEvidenceValidity::capture( $at, $h->expires_at() );
	}
}
