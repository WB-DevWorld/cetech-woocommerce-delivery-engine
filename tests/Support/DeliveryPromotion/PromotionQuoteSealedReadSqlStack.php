<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptInputs, FixedPromotionQuoteBindInputs, FixedPromotionQuotePlacementEvidenceReader, FixedPromotionQuoteSealCommand, FixedPromotionQuoteVerifyInputs};
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Infrastructure\Persistence\{EmergencyControlStore, SqlFixedPromotionBudgetStore};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteLifecycleProofFactory;

require_once __DIR__ . '/PromotionQuoteSealSqlStack.php';

/** Physical five-receipt/binding/quote/ledger reads; native/source/order/saved ports are explicitly synthetic. */
final class PromotionQuoteSealedReadSqlStack {
	public readonly QuoteLifecycleProofFactory $physical;
	public readonly PromotionQuoteIssueSqlFactory $factory;
	public readonly PromotionQuoteAcceptEligibilityPort $eligibility;
	public readonly PromotionQuoteBindOrderPort $order;
	public readonly PromotionQuoteVerifySavedPort $saved;
	public readonly FixedPromotionQuotePlacementEvidenceReader $reader;
	public bool $allow = true;
	public int $authorizations = 0;
	public ?\Closure $on_authorize = null;

	public function __construct( string $prefix, public readonly array $fixture, FixedPromotionQuoteSealCommand $command, int $clock = QuoteLifecycleProofFactory::NOW + 5, ?callable $configure = null, int $site_id = 1 ) {
		$this->physical = new QuoteLifecycleProofFactory( $prefix, $site_id ); $this->physical->clock = $clock;
		$this->physical->configure = null === $configure ? null : \Closure::fromCallable( $configure );
		$this->factory = new PromotionQuoteIssueSqlFactory( $this->physical ); $this->eligibility = new PromotionQuoteAcceptEligibilityPort();
		$original = $command->verification()->binding_command();
		$this->order = new PromotionQuoteBindOrderPort( $prefix, PromotionQuoteBindOrderPort::packet( $original->quote_record(), $original->binding()->row()['order_id'], $original->binding()->mapping() ) );
		$this->saved = new PromotionQuoteVerifySavedPort( $prefix, PromotionQuoteVerifySavedPort::packet( $command->verified_binding() ) );
		$this->reader = new FixedPromotionQuotePlacementEvidenceReader( $this->factory, new SqlFixedPromotionBudgetStore(), $this->eligibility,
			function( QuoteOwner $owner, string $operation ): bool {
				++$this->authorizations; if ( null !== $this->on_authorize ) { ( $this->on_authorize )( $owner, $operation, $this->authorizations ); }
				return $this->allow && 1 === $owner->site_id() && 'delivery_quote.read' === $operation;
			}, control: new EmergencyControlStore( static fn(): bool => false ) );
	}
	public function inputs(): FixedPromotionQuoteVerifyInputs {
		return FixedPromotionQuoteVerifyInputs::from_sources( FixedPromotionQuoteBindInputs::from_sources( FixedPromotionQuoteAcceptInputs::from_sources( $this->fixture['original'], $this->fixture['fresh'] ), $this->order ), $this->saved );
	}
	/** No read may send DML, DDL, C03 completion or commit. */
	public function effect_sql(): array {
		$out = []; foreach ( $this->physical->transports as $transport ) { foreach ( $transport->sql as $sql ) { if ( 1 === preg_match( '/\A\s*(?:INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE|COMMIT)\b/i', $sql ) ) { $out[] = $sql; } } } return $out;
	}
}
