<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryPromotion\FixedPromotionNativeResult;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteId, QuoteOwner, QuoteShape, QuoteTerms, QuoteTime};

/** Transient native capture only; admission, allocation and issuance remain C03-owned. */
final readonly class FixedPromotionNativeCartPreparedCapture implements \JsonSerializable {
	private FixedPromotionQuoteProvider $prepared_provider;
	private array $complete_slots;
	public function __construct( private LegacyQuoteSourceSnapshot $source, private FixedPromotionNativeResult $native, array $rules, array $account_slots, private QuoteTime $deadline ) {
		$requests = $native->original_requests(); $header = $requests->header();
		if ( ! $source->plan()->owner()->equals( $header->owner() ) || ! hash_equals( $source->context()->digest(), $requests->original_context()->digest() ) || ! $source->local_state_unchanged() || ! $native->unchanged() || $deadline->compare( $header->created_at() ) <= 0 || $deadline->compare( $header->expires_at() ) > 0 ) { QuoteShape::invalid(); }
		$source->bind_context( $requests->original_context() );
		$this->prepared_provider = FixedPromotionQuoteProvider::from_native_result( $native, $rules );
		// Reuse the real command grammar for complete null/promoted account slots.
		$checked = QuoteIssueCommand::fixed_promotion( $header->owner(), $this->prepared_provider->context(), QuoteId::generate()->value(), $account_slots, $deadline );
		$this->complete_slots = $checked->promotion_account_slots();
	}
	public function owner(): QuoteOwner { return $this->native->original_requests()->header()->owner(); }
	public function context(): QuoteContext { return $this->prepared_provider->context(); }
	public function terms(): QuoteTerms { return $this->prepared_provider->terms(); }
	public function provider(): FixedPromotionQuoteProvider { return $this->prepared_provider; }
	public function registry(): QuoteProviderRegistry { return new QuoteProviderRegistry( [ $this->prepared_provider ] ); }
	public function original(): LegacyQuoteSourceSnapshot { return $this->source; }
	public function fresh_native(): FixedPromotionNativeResult { return $this->native; }
	public function account_slots(): array { return $this->complete_slots; }
	public function hold_until(): QuoteTime { return $this->deadline; }
	public function command( string $original_token ): QuoteIssueCommand { return QuoteIssueCommand::fixed_promotion( $this->owner(), $this->context(), $original_token, $this->complete_slots, $this->deadline ); }
	public function issue_inputs(): FixedPromotionQuoteIssueInputs { return FixedPromotionQuoteIssueInputs::from_sources( $this->source, $this->native ); }
	public function accept_inputs(): FixedPromotionQuoteAcceptInputs { return FixedPromotionQuoteAcceptInputs::from_sources( $this->source, $this->native ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Prepared promotion captures require explicit private access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Prepared promotion captures cannot be persisted implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Prepared promotion captures require fresh native sources.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_cart_capture' => 'redacted' ]; }
}
