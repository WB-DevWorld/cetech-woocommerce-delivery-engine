<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionEligibilityBudgetGuard, FixedPromotionNativeCapture, FixedPromotionNativeRequests};
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptCommand, FixedPromotionQuoteAcceptInputs, FixedPromotionQuoteAcceptService, FixedPromotionQuoteProvider, QuoteCurrentEvidenceValidity};
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\DeliveryPromotion\{DeliveryPromotionPlan, FixedDeliveryPromotion};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteOwner, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\{OperationPhaseObserver, OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\{EmergencyControlStore, FixedPromotionBudgetSchema, SqlFixedPromotionBudgetStore};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\{FixedPromotionQuoteFixture as F, FixedPromotionQuoteFixtureSource};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteLifecycleProofFactory, QuoteLifecycleProofPublication};

require_once __DIR__ . '/PromotionQuoteIssueSqlStack.php';

/** Real owned SQL with explicitly synthetic source/native/eligibility ports. No WordPress boot. */
final class PromotionQuoteAcceptSqlStack {

	public readonly QuoteLifecycleProofFactory $physical;
	public readonly PromotionQuoteIssueSqlFactory $factory;
	public readonly PromotionQuoteAcceptEligibilityPort $eligibility;
	public readonly PromotionQuoteAcceptPhaseObserver $observer;
	public readonly QuoteLifecycleProofPublication $publication;
	public readonly FixedPromotionQuoteAcceptService $service;

	public function __construct( string $prefix, public readonly array $fixture = [], int $clock = QuoteLifecycleProofFactory::NOW + 1, ?callable $configure = null, int $site_id = 1 ) {
		$this->physical = new QuoteLifecycleProofFactory( $prefix, $site_id ); $this->physical->clock = $clock; $this->physical->configure = null === $configure ? null : \Closure::fromCallable( $configure );
		$this->factory = new PromotionQuoteIssueSqlFactory( $this->physical ); $this->eligibility = new PromotionQuoteAcceptEligibilityPort(); $this->observer = new PromotionQuoteAcceptPhaseObserver(); $this->publication = new QuoteLifecycleProofPublication();
		$this->service = new FixedPromotionQuoteAcceptService( $this->factory, new SqlFixedPromotionBudgetStore(), $this->eligibility, static fn( QuoteOwner $owner, string $operation ): bool => 1 === $owner->site_id() && 'delivery_quote.accept' === $operation, observer: $this->observer, control: new EmergencyControlStore( static fn(): bool => false ), publication: $this->publication );
	}
	public function inputs(): FixedPromotionQuoteAcceptInputs { return FixedPromotionQuoteAcceptInputs::from_sources( $this->fixture['original'], $this->fixture['fresh'] ); }

	/**
	 * A concurrent accepting process makes fresh synthetic native observations,
	 * using the saved original provenance. It never generates another issued quote.
	 */
	public static function sources_for( OperationSession $session, FixedPromotionQuoteAcceptCommand $command ): array {
		$fixture = PromotionQuoteIssueSqlPorts::capture( $session ); $capture = $command->context()->fixed_promotion_capture();
		if ( null === $capture ) { throw new \RuntimeException( 'Owned acceptance source fixture refused.' ); }
		$terms = $capture->original_terms(); $plans = []; $rules = []; $mapping = [];
		foreach ( $capture->private_facts()['groups'] as $group ) {
			$rule = null === $group['rule'] ? null : FixedDeliveryPromotion::from_array( $group['rule'] );
			$plans[] = [ 'component_key' => $group['component_key'], 'plan' => null === $rule ? null : DeliveryPromotionPlan::from_array( $terms, $rule, $group['plan'] ) ];
			$rules[] = [ 'component_key' => $group['component_key'], 'rule' => $rule ];
			$mapping[] = [ 'component_key' => $group['component_key'], 'legacy_group_id' => F::key() === $group['component_key'] ? 'in_store|delivery|20' : 'second|delivery|20' ];
		}
		$requests = FixedPromotionNativeRequests::from_captured( $capture->original_header(), $capture->original_context(), $terms, $plans, $mapping );
		$source = new FixedPromotionQuoteFixtureSource( $fixture['source']->data ); $fresh = ( new FixedPromotionNativeCapture( $source ) )->capture( $command->owner(), $requests );
		$provider = FixedPromotionQuoteProvider::from_native_result( $fresh, $rules );
		if ( $provider->context()->to_private_json() !== $command->context()->to_private_json() || $provider->terms()->to_private_json() !== $command->terms()->to_private_json() ) { throw new \RuntimeException( 'Owned acceptance capture differs from the saved original.' ); }
		$fixture['original'] = $fixture['original']->bind_context( $capture->original_context() ); $fixture['fresh'] = $fresh; $fixture['source'] = $source;
		return $fixture;
	}
}

/** Declared trusted eligibility seam; no assertion of physical merchant-policy certification. */
final class PromotionQuoteAcceptEligibilityPort implements FixedPromotionEligibilityBudgetGuard {
	public array $owners = [];
	public bool $allow = true;
	public bool $poison = false;
	public ?\Closure $on_proof = null;
	public function tables( OperationSession $session ): array { if ( $this->poison ) { throw new \LogicException( 'Historical recovery cannot request current eligibility.' ); } return FixedPromotionBudgetSchema::tables( $session->table_prefix() ); }
	public function validity_at( OperationSession $s, QuoteOwner $o, QuoteHeader $h, QuoteContext $c, QuoteTerms $t, QuoteTime $at ): ?QuoteCurrentEvidenceValidity { throw new \LogicException( 'Account-aware eligibility is required.' ); }
	public function validity_for_requests_at( OperationSession $s, QuoteOwner $o, QuoteHeader $h, QuoteContext $c, QuoteTerms $t, array $requests, QuoteTime $at ): ?QuoteCurrentEvidenceValidity {
		$this->owners[] = $s; if ( $this->poison ) { throw new \LogicException( 'Historical recovery cannot reevaluate eligibility.' ); }
		if ( null !== $this->on_proof ) { ( $this->on_proof )( $s, count( $this->owners ) ); }
		return $this->allow ? QuoteCurrentEvidenceValidity::capture( $at, $h->expires_at() ) : null;
	}
}

/** Bounded process barrier after staged effects, never a business mutation. */
final class PromotionQuoteAcceptPhaseObserver implements OperationPhaseObserver {
	public ?\Closure $callback = null;
	public function observe( string $phase, OperationIdentity $identity ): void { if ( null !== $this->callback ) { ( $this->callback )( $phase, $identity ); } }
}
