<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTerms;

/** Read-only preparation is no reservation, acceptance, order binding or payment. */
final readonly class LegacyQuotePreparedCapture implements \JsonSerializable {
	private LegacyFixedBaseQuoteProvider $prepared_provider;
	public function __construct( private QuoteOwner $quote_owner, private QuoteContext $quote_context, private QuoteTerms $quote_terms, private QuoteCurrentEvidenceGuard $current_guard ) { $this->prepared_provider = new LegacyFixedBaseQuoteProvider( $quote_owner, $quote_context, $quote_terms ); }
	public function owner(): QuoteOwner { return $this->quote_owner; }
	public function context(): QuoteContext { return $this->quote_context; }
	public function terms(): QuoteTerms { return $this->quote_terms; }
	public function provider(): LegacyFixedBaseQuoteProvider { return $this->prepared_provider; }
	public function registry(): QuoteProviderRegistry { return new QuoteProviderRegistry( [ $this->prepared_provider ] ); }
	public function guard(): QuoteCurrentEvidenceGuard { return $this->current_guard; }
	public function command( string $original_token ): QuoteIssueCommand { return QuoteIssueCommand::create( $this->quote_owner, $this->quote_context, LegacyFixedBaseQuoteProvider::CODE, 1, LegacyFixedBaseQuoteProvider::CODE, 1, $original_token ); }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized delivery quote capture projection is required.' ); }
	public function __serialize(): never { throw new \LogicException( 'Prepared delivery quote captures cannot be serialized generically.' ); }
}
