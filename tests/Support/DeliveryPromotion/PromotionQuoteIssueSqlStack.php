<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteIssueInputs, FixedPromotionQuoteIssueService, QuoteIssueCommand, QuoteProviderRegistry};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteOwner, QuoteTime};
use CetechDeliveryEngine\Infrastructure\Persistence\{EmergencyControlStore, SqlFixedPromotionBudgetStore};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteFixtures as Q, QuoteLifecycleProofFactory, QuoteLifecycleProofPublication};

require_once __DIR__ . '/PromotionQuoteIssueSqlPorts.php';

/** Explicit physical E10 wiring, never a production Plugin or native checkout registration. */
final class PromotionQuoteIssueSqlStack {
	public readonly QuoteLifecycleProofFactory $physical;
	public readonly PromotionQuoteIssueSqlFactory $factory;
	public readonly PromotionQuoteIssueEligibilityPort $eligibility;
	public readonly FixedPromotionQuoteIssueService $service;
	public array $budget_owners = [];
	public function __construct( string $prefix, public readonly array $fixture, int $clock = QuoteLifecycleProofFactory::NOW, ?callable $configure = null ) {
		$this->physical = new QuoteLifecycleProofFactory( $prefix ); $this->physical->clock = $clock; $this->physical->configure = null === $configure ? null : \Closure::fromCallable( $configure );
		$this->factory = new PromotionQuoteIssueSqlFactory( $this->physical ); $this->eligibility = new PromotionQuoteIssueEligibilityPort();
		$this->service = new FixedPromotionQuoteIssueService( $this->factory, new QuoteProviderRegistry( [ $fixture['provider'] ] ), new SqlFixedPromotionBudgetStore(), $this->eligibility, static fn( QuoteOwner $owner, string $operation ): bool => 1 === $owner->site_id() && 'delivery_quote.issue' === $operation, function( $session ): bool { $this->budget_owners[] = $session; return true; }, control: new EmergencyControlStore( static fn(): bool => false ), publication: new QuoteLifecycleProofPublication() );
	}
	public function original( string $token, ?array $slots = null, ?QuoteTime $deadline = null ): QuoteIssueCommand { return QuoteIssueCommand::fixed_promotion( F::owner(), $this->fixture['provider']->inner->context(), $token, $slots ?? $this->fixture['slots'], $deadline ?? Q::time()->plus_seconds( 45 ) ); }
	public function inputs(): FixedPromotionQuoteIssueInputs { return FixedPromotionQuoteIssueInputs::from_sources( $this->fixture['original'], $this->fixture['fresh'] ); }
}
