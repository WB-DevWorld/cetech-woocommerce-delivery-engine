<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

/** Ephemeral source/order ports for one bind attempt; never recovery or placement authority. */
final readonly class FixedPromotionQuoteBindInputs implements \JsonSerializable {

	private function __construct( private FixedPromotionQuoteAcceptInputs $current_sources, private FixedPromotionQuoteOrderEvidenceGuard $candidate_order ) {}
	public static function from_sources( FixedPromotionQuoteAcceptInputs $sources, FixedPromotionQuoteOrderEvidenceGuard $order ): self { return new self( $sources, $order ); }
	public function sources(): FixedPromotionQuoteAcceptInputs { return $this->current_sources; }
	public function order_evidence(): FixedPromotionQuoteOrderEvidenceGuard { return $this->candidate_order; }
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion binding inputs require explicit private access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Promotion binding inputs cannot be persisted implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion binding inputs require fresh trusted ports.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_bind_inputs' => 'redacted' ]; }
}
