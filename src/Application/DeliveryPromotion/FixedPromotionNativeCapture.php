<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryQuote\{QuoteNativeCaptureSource, QuoteNativeReceiptCapture};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteOwner, QuoteShape};

/** Read-only bridge into the retained native capture, never a shipping/tax mutation. */
final readonly class FixedPromotionNativeCapture implements \JsonSerializable {
	public function __construct( private QuoteNativeCaptureSource $source ) {}
	public function capture( QuoteOwner $owner, FixedPromotionNativeRequests $requests ): FixedPromotionNativeResult {
		if ( ! $owner->equals( $requests->header()->owner() ) ) { QuoteShape::invalid(); }
		try {
			$receipt = ( new QuoteNativeReceiptCapture( $this->source ) )->capture( $owner, $requests->native_requests() );
			return FixedPromotionNativeResult::from_captured( $requests, $receipt );
		} catch ( \Throwable ) { throw new \RuntimeException( 'Promoted native capture unavailable.' ); }
	}
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion native capture cannot expose its source.' ); }
	public function __serialize(): array { throw new \LogicException( 'Promotion native capture cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): void { throw new \LogicException( 'Promotion native capture cannot be unserialized implicitly.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_native' => 'redacted' ]; }
}
