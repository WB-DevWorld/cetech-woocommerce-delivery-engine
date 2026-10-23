<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryPromotion;

use CetechDeliveryEngine\Domain\DeliveryPromotion\{DeliveryPromotionPlan, FixedDeliveryPromotion};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteJson, QuoteOwner, QuoteShape, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory, OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\SqlFixedPromotionRuleEligibilityGuard;

/** Explicit server selection over published policy and funded accounts; never a reservation. */
final readonly class FixedPromotionCartPlanning implements \JsonSerializable {

	private const PROVIDER = 'sql_fixed_promotion_cart_v1';
	private \Closure $authorize;
	public function __construct( private OperationConnectionFactory $factory, private SqlFixedPromotionRuleEligibilityGuard $eligibility, private FixedPromotionBudgetAccountStore $store, callable $authorize_owner ) { $this->authorize = \Closure::fromCallable( $authorize_owner ); }

	/**
	 * Rules are complete, typed server-authored slots, never posted campaign or funder choices.
	 * @return array{plans:array,rules:array,account_slots:array}|null Complete detached slots, including explicit nulls.
	 */
	public function capture( QuoteOwner $owner, QuoteContext $context, QuoteTerms $terms, array $rules, QuoteTime $at ): ?array {
		$session = null;
		try {
			$rules = $this->rules( $context, $terms, $rules );
			if ( ! $this->authorized( $owner ) ) { return null; }
			$session = $this->factory->open();
			$binding = [ $session->site_id(), $session->table_prefix(), $session->charset_collate() ];
			$this->owned( $session, $owner, $binding, false );
			if ( ! $session->begin() ) { return null; }
			$this->owned( $session, $owner, $binding, true );
			$policy_tables = $this->eligibility->tables( $session ); $budget_tables = $this->store->tables( $session );
			$this->owned( $session, $owner, $binding, true );
			if ( ! array_is_list( $policy_tables ) || ! array_is_list( $budget_tables ) || [] === $policy_tables || [] === $budget_tables ) { return null; }
			$tables = array_merge( $policy_tables, $budget_tables );
			if ( [] === $tables || count( $tables ) > 32 ) { return null; }
			foreach ( $tables as $table ) { if ( ! is_string( $table ) || strlen( $table ) > 64 || ! str_starts_with( $table, $binding[1] ) || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table ) ) { return null; } }
			$tables = array_values( array_unique( $tables ) ); sort( $tables, SORT_STRING );
			if ( ! $session->validate_tables( $tables ) ) { return null; }
			$this->owned( $session, $owner, $binding, true );
			$selected = []; $amounts = [];
			foreach ( $rules as $slot ) {
				$key = $slot['component_key']; $rule = $slot['rule']; if ( null === $rule ) { continue; }
				$evidence = $this->eligibility->planning_evidence( $session, $owner, $context, $terms, $key, $rule, $at );
				$this->owned( $session, $owner, $binding, true ); if ( null === $evidence ) { return null; }
				$account = QuoteShape::digest( $evidence['account_key'] );
				$selected[$key] = $evidence;
				$amounts[$account] = isset( $amounts[$account] ) ? $amounts[$account]->add( $rule->discount() ) : $rule->discount();
			}
			ksort( $amounts, SORT_STRING ); $accounts = [];
			// Every shared pool is locked once and must fund the whole selected component set.
			foreach ( $amounts as $key => $amount ) {
				$account = $this->store->lock_account( $session, $key );
				$this->owned( $session, $owner, $binding, true );
				if ( null === $account || $account->site_id() !== $owner->site_id() || $account->account_key() !== $key || $account->remaining()->compare( $amount ) < 0 ) { return null; }
				$accounts[$key] = $account;
			}
			foreach ( $rules as $slot ) {
				if ( null === $slot['rule'] ) { continue; }
				$account = $accounts[$selected[$slot['component_key']]['account_key']]; $discount = $slot['rule']->discount();
				if ( $account->funding_source() !== $slot['rule']->private_facts()['funding_source'] || $account->limit()->currency() !== $discount->currency() || $account->limit()->precision() !== $discount->precision() ) { return null; }
			}
			$this->owned( $session, $owner, $binding, true );
			if ( ! $session->rollback() ) { return null; }
			$this->owned( $session, $owner, $binding, false );
			if ( ! $session->retire() || ! $session->is_retired() || $session->in_transaction() || [ $session->site_id(), $session->table_prefix(), $session->charset_collate() ] !== $binding ) { return null; }
			if ( ! $this->authorized( $owner ) || ! $session->is_retired() || $session->in_transaction() || [ $session->site_id(), $session->table_prefix(), $session->charset_collate() ] !== $binding ) { return null; }
			$session = null;
			$plans = []; $slots = [];
			foreach ( $rules as $slot ) {
				$key = $slot['component_key']; $rule = $slot['rule']; $plan = null; $account_key = null;
				if ( null !== $rule ) {
					$account_key = $selected[$key]['account_key'];
					$budget_digest = hash( 'sha256', 'cetech-fixed-promotion-cart-availability-v1:' . QuoteJson::encode( [ 'at' => $at->sql(), 'terms_digest' => $terms->digest(), 'account' => $accounts[$account_key]->private_facts(), 'requested' => $amounts[$account_key]->facts() ] ) );
					$plan = DeliveryPromotionPlan::from_evidence( $terms, $key, $rule, self::PROVIDER, 1, [ 'format_version' => 1, 'provider' => [ 'code' => self::PROVIDER, 'version' => 1 ], 'terms_digest' => $terms->digest(), 'component_key' => $key, 'rule_digest' => $rule->digest(), 'eligibility' => $selected[$key]['eligibility'], 'budget' => [ 'state' => 'available', 'evidence_digest' => $budget_digest ] ] );
					if ( ! $plan->planned() ) { return null; }
				}
				$plans[] = [ 'component_key' => $key, 'plan' => $plan ]; $slots[] = [ 'component_key' => $key, 'account_key' => $account_key ];
			}
			return [ 'plans' => $plans, 'rules' => $rules, 'account_slots' => $slots ];
		} catch ( \Throwable ) { return null; }
		finally {
			if ( null !== $session ) {
				try { $session->rollback(); } catch ( \Throwable ) {}
				try { $session->retire(); } catch ( \Throwable ) {}
			}
		}
	}

	private function rules( QuoteContext $context, QuoteTerms $terms, array $slots ): array {
		if ( 1 !== $context->format_version() || 1 !== $terms->format_version() || ! $context->checkout_acceptable() || ! $terms->checkout_acceptable() ) { QuoteShape::invalid(); }
		$keys = array_column( $context->private_facts()['groups'], 'component_key' );
		if ( $keys !== array_column( $terms->private_facts()['groups'], 'component_key' ) ) { QuoteShape::invalid(); }
		$expected = array_fill_keys( $keys, true ); $rules = []; $selected = 0;
		foreach ( QuoteShape::list( $slots, QuoteContext::MAX_GROUPS, 1 ) as $slot ) {
			$slot = QuoteShape::object( $slot ); QuoteShape::fields( $slot, [ 'component_key', 'rule' ] ); $key = QuoteShape::digest( $slot['component_key'] ); $rule = $slot['rule'];
			if ( isset( $rules[$key] ) || ! isset( $expected[$key] ) || ( null !== $rule && ! $rule instanceof FixedDeliveryPromotion ) ) { QuoteShape::invalid(); }
			if ( null !== $rule ) { if ( null !== DeliveryPromotionPlan::preflight( $terms, $key, $rule ) ) { QuoteShape::invalid(); } ++$selected; }
			$rules[$key] = [ 'component_key' => $key, 'rule' => $rule ];
		}
		if ( 0 === $selected || count( $rules ) !== count( $expected ) ) { QuoteShape::invalid(); }
		ksort( $rules, SORT_STRING ); return array_values( $rules );
	}
	private function authorized( QuoteOwner $owner ): bool { try { return true === ( $this->authorize )( $owner ); } catch ( \Throwable ) { return false; } }
	private function owned( OperationSession $session, QuoteOwner $owner, array $binding, bool $active ): void {
		if ( $session->is_retired() || $session->in_transaction() !== $active || [ $session->site_id(), $session->table_prefix(), $session->charset_collate() ] !== $binding || $binding[0] !== $owner->site_id() || '' === $binding[1] || strlen( $binding[1] ) > 30 || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $binding[1] ) ) { throw new \RuntimeException( 'Promotion planning unavailable.' ); }
	}
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion planning services are private.' ); }
	public function __serialize(): never { throw new \LogicException( 'Promotion planning services cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion planning services require explicit owned stores.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_cart_planning' => 'redacted' ]; }
}
