<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

/** Ephemeral current and saved-order ports; never part of a recovery packet. */
final readonly class FixedPromotionQuoteVerifyInputs implements \JsonSerializable {
	private function __construct( private FixedPromotionQuoteBindInputs $current_inputs, private QuotePlacementSavedEvidenceGuard $saved ) {}
	public static function from_sources( FixedPromotionQuoteBindInputs $current, QuotePlacementSavedEvidenceGuard $saved ): self { return new self( $current, $saved ); }
	public function current(): FixedPromotionQuoteBindInputs { return $this->current_inputs; }
	public function saved_evidence(): QuotePlacementSavedEvidenceGuard { return $this->saved; }
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion verification inputs require explicit private access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Promotion verification inputs cannot be persisted implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion verification inputs require fresh trusted ports.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_verify_inputs' => 'redacted' ]; }
}
