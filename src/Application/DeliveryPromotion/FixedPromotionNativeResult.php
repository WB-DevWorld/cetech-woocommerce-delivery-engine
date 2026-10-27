<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeReceipt;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteMoney, QuoteShape};

/** Original planning facts beside fresh native observations; neither is reissued as a quote. */
final readonly class FixedPromotionNativeResult implements \JsonSerializable {
	private function __construct( private FixedPromotionNativeRequests $requests, private QuoteNativeReceipt $receipt, private QuoteContext $native_context ) {}
	public static function from_captured( FixedPromotionNativeRequests $requests, QuoteNativeReceipt $receipt ): self {
		if ( ! $receipt->matches_owner( $requests->header()->owner() ) ) { QuoteShape::invalid(); }
		foreach ( $requests->native_requests() as $request ) {
			$actual = $receipt->group( $request->component_key )->final_money();
			// The retained capture can use a higher native precision; compare at its maximum scale.
			$expected = QuoteMoney::from_array( array_replace( $request->expected_ex_tax->facts(), [ 'precision' => 6 ] ) );
			$observed = QuoteMoney::from_array( array_replace( $actual->facts(), [ 'precision' => 6 ] ) );
			if ( ! $expected->equals( $observed ) ) { QuoteShape::invalid(); }
		}
		$context = $receipt->bind_context( $requests->original_context() );
		if ( ! $receipt->unchanged() ) { throw new \RuntimeException( 'Promoted native capture unavailable.' ); }
		return new self( $requests, $receipt, $context );
	}
	public function original_requests(): FixedPromotionNativeRequests { return $this->requests; }
	/** Private transient evidence only. Existing legacy provider tags do not describe the discount. */
	public function native_receipt(): QuoteNativeReceipt { return $this->receipt; }
	public function native_context(): QuoteContext { return $this->native_context; }
	public function unchanged(): bool { return $this->receipt->unchanged(); }
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion native results require explicit authorized access.' ); }
	public function __serialize(): array { throw new \LogicException( 'Promotion native results cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): void { throw new \LogicException( 'Promotion native results cannot be unserialized implicitly.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_native' => 'redacted' ]; }
}
