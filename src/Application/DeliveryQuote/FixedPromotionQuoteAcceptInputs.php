<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryPromotion\FixedPromotionNativeResult;

/** Ephemeral current-source ports for one internal acceptance; never part of recovery. */
final readonly class FixedPromotionQuoteAcceptInputs implements \JsonSerializable {

	private function __construct( private LegacyQuoteSourceSnapshot $source, private FixedPromotionNativeResult $native ) {}
	public static function from_sources( LegacyQuoteSourceSnapshot $original, FixedPromotionNativeResult $native ): self { return new self( $original, $native ); }
	public function original(): LegacyQuoteSourceSnapshot { return $this->source; }
	public function fresh_native(): FixedPromotionNativeResult { return $this->native; }
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion acceptance inputs require explicit private access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Promotion acceptance inputs cannot be persisted implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion acceptance inputs require fresh source ports.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_accept_inputs' => 'redacted' ]; }
}
