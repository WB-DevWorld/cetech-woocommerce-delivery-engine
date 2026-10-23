<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptInputs, FixedPromotionQuoteBindInputs, FixedPromotionQuoteSealCommand, FixedPromotionQuoteVerifyInputs, FixedPromotionQuoteSealService, QuotePlacementSavedEvidenceGuard};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteJson, QuoteOwner};
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\{EmergencyControlStore, SqlFixedPromotionBudgetStore};
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteLifecycleProofFactory, QuoteLifecycleProofPublication};

require_once __DIR__ . '/PromotionQuoteVerifySqlStack.php';

/** Physical E15 sealed3/C03/binding/quote/ledger owners. Native/source/order/saved evidence ports are synthetic. */
final class PromotionQuoteSealSqlStack {
	public readonly QuoteLifecycleProofFactory $physical;
	public readonly PromotionQuoteIssueSqlFactory $factory;
	public readonly PromotionQuoteAcceptEligibilityPort $eligibility;
	public readonly PromotionQuoteAcceptPhaseObserver $observer;
	public readonly QuoteLifecycleProofPublication $publication;
	public readonly PromotionQuoteBindOrderPort $order;
	public readonly PromotionQuoteVerifySavedPort $saved;
	public readonly FixedPromotionQuoteSealService $service;
	public bool $allow = true;

	public function __construct( string $prefix, public readonly array $fixture, FixedPromotionQuoteSealCommand $command, int $clock = QuoteLifecycleProofFactory::NOW + 4, ?callable $configure = null, int $site_id = 1 ) {
		$this->physical = new QuoteLifecycleProofFactory( $prefix, $site_id ); $this->physical->clock = $clock;
		$this->physical->configure = null === $configure ? null : \Closure::fromCallable( $configure );
		$this->factory = new PromotionQuoteIssueSqlFactory( $this->physical );
		$this->eligibility = new PromotionQuoteAcceptEligibilityPort(); $this->observer = new PromotionQuoteAcceptPhaseObserver(); $this->publication = new QuoteLifecycleProofPublication();
		$original = $command->verification()->binding_command();
		$this->order = new PromotionQuoteBindOrderPort( $prefix, PromotionQuoteBindOrderPort::packet( $original->quote_record(), $original->binding()->row()['order_id'], $original->binding()->mapping() ) );
		$this->saved = new PromotionQuoteVerifySavedPort( $prefix, PromotionQuoteVerifySavedPort::packet( $command->verified_binding() ) );
		$this->service = new FixedPromotionQuoteSealService( $this->factory, new SqlFixedPromotionBudgetStore(), $this->eligibility,
			fn( QuoteOwner $owner, string $operation ): bool => $this->allow && 1 === $owner->site_id() && 'delivery_quote.seal' === $operation,
			observer: $this->observer, control: new EmergencyControlStore( static fn(): bool => false ), publication: $this->publication );
	}
	public function inputs(): FixedPromotionQuoteVerifyInputs {
		return FixedPromotionQuoteVerifyInputs::from_sources(
			FixedPromotionQuoteBindInputs::from_sources( FixedPromotionQuoteAcceptInputs::from_sources( $this->fixture['original'], $this->fixture['fresh'] ), $this->order ),
			$this->saved
		);
	}
	public function protected_writes(): array {
		$out = [];
		foreach ( $this->physical->transports as $transport ) { foreach ( $transport->sql as $sql ) {
			if ( 1 === preg_match( '/\A\s*(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\b/i', $sql )
				&& ( 1 === preg_match( '/delivery_engine_(?:delivery_quotes|promotion_budget_accounts|promotion_budget_reservations|delivery_quote_budget_windows)/', $sql ) || str_contains( $sql, 'e13_candidate_orders' ) || str_contains( $sql, 'e14_saved_snapshots' ) ) ) { $out[] = $sql; }
		} }
		return $out;
	}
	public function binding_updates(): int {
		$count = 0; foreach ( $this->physical->transports as $transport ) { foreach ( $transport->sql as $sql ) { if ( str_starts_with( $sql, 'UPDATE ' ) && str_contains( $sql, 'delivery_engine_delivery_quote_bindings' ) ) { ++$count; } } } return $count;
	}
}

